<?php

namespace DriveSnap\Tests;

use DriveSnap\EncryptedFile;
use DriveSnap\GoogleDrive;
use DriveSnap\Jobs\CreateBackup;
use DriveSnap\Models\Backup;
use DriveSnap\Models\Connection;
use DriveSnap\Snapshot;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BackupTest extends TestCase
{
    public function test_dashboard_is_closed_to_guests_and_users_without_access(): void
    {
        $this->getJson('/drivesnap/status')->assertUnauthorized();
        $this->actingAs($this->actor('denied'))->getJson('/drivesnap/status')->assertForbidden();
        $this->actingAs($this->actor('viewer'))->getJson('/drivesnap/status')->assertOk()
            ->assertJsonPath('can.view', true)->assertJsonPath('can.create', false)->assertJsonPath('backups.total', 0);
    }

    public function test_queue_requires_permissions_and_a_connected_destination(): void
    {
        Bus::fake();
        $this->actingAs($this->actor('viewer'))->postJson('/drivesnap/backups', ['upload_to_drive' => false])->assertForbidden();
        $this->actingAs($this->actor())->postJson('/drivesnap/backups', [])->assertUnprocessable()->assertJsonValidationErrors('upload_to_drive');
        $this->postJson('/drivesnap/backups', ['upload_to_drive' => true])->assertUnprocessable();
        $response = $this->postJson('/drivesnap/backups', ['upload_to_drive' => false])->assertAccepted();
        $id = $response->json('id');
        $this->assertDatabaseHas('drivesnap_backups', ['id' => $id, 'status' => 'queued', 'upload_to_drive' => false]);
        Bus::assertDispatched(CreateBackup::class, fn (CreateBackup $job): bool => $job->backupId === $id);
    }

    public function test_tokens_and_internal_file_paths_never_reach_the_dashboard(): void
    {
        $connection = Connection::current();
        $connection->update(['email' => 'test@example.test', 'refresh_token' => 'sensitive-test-refresh', 'access_token' => 'sensitive-test-access', 'expires_at' => now()->addHour()]);
        $backup = Backup::create(['status' => 'failed', 'path' => 'backups/private.enc', 'upload_url' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=private-session']);
        $response = $this->actingAs($this->actor())->getJson('/drivesnap/status')->assertOk();
        $response->assertDontSee('sensitive-test-refresh')->assertDontSee('sensitive-test-access')->assertDontSee('private-session')->assertDontSee('backups/private.enc');
        $this->assertNotSame('sensitive-test-refresh', DB::table('drivesnap_connections')->value('refresh_token'));
        $this->assertArrayNotHasKey('refresh_token', $connection->toArray());
        $this->assertArrayNotHasKey('upload_url', $backup->toArray());
    }

    public function test_pagination_and_settings_validation_are_real_server_contracts(): void
    {
        foreach (range(1, 12) as $number) {
            Backup::create(['status' => 'failed']);
        }
        $this->actingAs($this->actor())->getJson('/drivesnap/status?page=2')->assertOk()
            ->assertJsonPath('backups.current_page', 2)->assertJsonPath('backups.total', 12)->assertJsonCount(2, 'backups.data');
        $this->putJson('/drivesnap/settings', ['schedule_enabled' => true, 'retention_days' => 0])->assertUnprocessable();
        $this->putJson('/drivesnap/settings', ['schedule_enabled' => true, 'retention_days' => 14])->assertOk();
        $this->assertDatabaseHas('drivesnap_connections', ['id' => 1, 'schedule_enabled' => true, 'retention_days' => 14]);
    }

    public function test_revoked_requester_access_cancels_a_queued_backup(): void
    {
        $actor = $this->actor();
        $backup = new Backup(['upload_to_drive' => false]);
        $backup->requestedBy()->associate($actor);
        $backup->save();
        $actor->update(['role' => 'denied']);
        (new CreateBackup($backup->id))->handle(app(Snapshot::class), app(GoogleDrive::class));
        $this->assertSame('cancelled', $backup->fresh()->status);
        $this->assertSame([], Storage::disk('drivesnap')->allFiles());
    }

    public function test_private_download_and_deletion_require_current_permissions(): void
    {
        $disk = Storage::disk('drivesnap');
        $disk->put('backups/test.enc', 'test-archive');
        $backup = Backup::create(['status' => 'completed', 'driver' => 'sqlite', 'path' => 'backups/test.enc', 'size' => 12, 'sha256' => hash('sha256', 'test-archive')]);
        $this->actingAs($this->actor('viewer'))->get('/drivesnap/backups/'.$backup->id.'/download')->assertForbidden();
        $this->deleteJson('/drivesnap/backups/'.$backup->id)->assertForbidden();
        $this->actingAs($this->actor())->get('/drivesnap/backups/'.$backup->id.'/download')->assertDownload($backup->id.'.sqlite.enc')->assertHeader('Cache-Control', 'no-store, private');
        $this->deleteJson('/drivesnap/backups/'.$backup->id)->assertOk();
        $disk->assertMissing('backups/test.enc');
        $this->assertDatabaseMissing('drivesnap_backups', ['id' => $backup->id]);
    }

    public function test_sqlite_snapshot_can_be_authenticated_and_restored_without_touching_source(): void
    {
        $disk = Storage::disk('drivesnap');
        $source = $disk->path('source.sqlite');
        touch($source);
        config(['database.connections.source' => ['driver' => 'sqlite', 'database' => $source, 'prefix' => ''], 'drivesnap.connection' => 'source']);
        DB::connection('source')->statement('CREATE TABLE probe (value TEXT)');
        DB::connection('source')->insert('INSERT INTO probe VALUES (?)', ['বাংলা restore sample']);
        $backup = Backup::create(['upload_to_drive' => false]);
        (new CreateBackup($backup->id))->handle(app(Snapshot::class), app(GoogleDrive::class));
        $backup->refresh();
        $this->assertSame('completed', $backup->status);
        $restored = $disk->path('restored.sqlite');
        app(EncryptedFile::class)->decrypt($disk->path($backup->path), $restored);
        $pdo = new \PDO('sqlite:'.$restored);
        $this->assertSame('বাংলা restore sample', $pdo->query('SELECT value FROM probe')->fetchColumn());
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame('বাংলা restore sample', DB::connection('source')->table('probe')->value('value'));
        DB::purge('source');
    }

    public function test_downloading_archives_does_not_consume_the_creation_rate_limit(): void
    {
        Bus::fake();
        Storage::disk('drivesnap')->put('backups/rate.enc', 'archive');
        $backup = Backup::create(['status' => 'completed', 'path' => 'backups/rate.enc', 'sha256' => hash('sha256', 'archive')]);
        $this->actingAs($this->actor());
        foreach (range(1, 3) as $number) {
            $this->get('/drivesnap/backups/'.$backup->id.'/download')->assertDownload();
        }
        $this->postJson('/drivesnap/backups', ['upload_to_drive' => false])->assertAccepted();
        Bus::assertDispatched(CreateBackup::class);
    }
}
