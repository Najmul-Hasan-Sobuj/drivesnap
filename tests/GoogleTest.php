<?php

namespace DriveSnap\Tests;

use DriveSnap\GoogleDrive;
use DriveSnap\Models\Backup;
use DriveSnap\Models\Connection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GoogleTest extends TestCase
{
    public function test_oauth_rejects_missing_expired_and_reused_state(): void
    {
        Http::fake();
        $this->actingAs($this->actor())->get('/drivesnap/google/callback?state=wrong&code=code')->assertForbidden();
        $this->withSession(['drivesnap.oauth' => ['state' => 'test-state', 'expires' => now()->subSecond()->getTimestamp()]])
            ->get('/drivesnap/google/callback?state=test-state&code=code')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_verified_google_consent_stores_only_encrypted_tokens(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'expires_in' => 3600, 'scope' => GoogleDrive::SCOPE]),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'owner@example.test', 'email_verified' => true]),
        ]);
        $this->actingAs($this->actor())->withSession(['drivesnap.oauth' => ['state' => 'test-state', 'expires' => now()->addMinute()->getTimestamp()]])
            ->get('/drivesnap/google/callback?state=test-state&code=single-use')->assertRedirect('/drivesnap');
        $this->assertTrue(Connection::current()->connected());
        $this->assertSame('owner@example.test', Connection::current()->email);
        $this->get('/drivesnap/google/callback?state=test-state&code=single-use')->assertForbidden();
        $this->getJson('/drivesnap/status')->assertDontSee('test-refresh')->assertDontSee('test-access');
    }

    public function test_resumable_upload_sends_only_the_remaining_bytes_and_is_idempotent(): void
    {
        $connection = Connection::current();
        $connection->update(['email' => 'owner@example.test', 'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => now()->addHour(), 'folder_id' => 'folder']);
        $disk = Storage::disk('drivesnap');
        $disk->put('backups/archive.enc', str_repeat('encrypted-fixture-', 32768));
        $path = $disk->path('backups/archive.enc');
        $backup = Backup::create(['path' => 'backups/archive.enc', 'status' => 'uploading', 'driver' => 'sqlite', 'size' => filesize($path), 'sha256' => hash_file('sha256', $path), 'drive_file_id' => 'remote-file', 'upload_url' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=test-session']);
        Http::fake([
            'https://www.googleapis.com/drive/v3/files/remote-file*' => Http::sequence()->push([], 404)->push(['id' => 'remote-file', 'md5Checksum' => hash_file('md5', $path), 'appProperties' => ['backup' => $backup->id, 'drivesnap' => $connection->installation_id]], 200),
            'https://www.googleapis.com/upload/drive/v3/files*' => Http::sequence()->push([], 308, ['Range' => 'bytes=0-262143'])->push(['id' => 'remote-file'], 200),
        ]);
        app(GoogleDrive::class)->upload($backup);
        $this->assertSame($backup->size, $backup->fresh()->uploaded_bytes);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && $request->hasHeader('Content-Range', 'bytes 262144-'.($backup->size - 1).'/'.$backup->size) && strlen($request->body()) === $backup->size - 262144);
        app(GoogleDrive::class)->upload($backup->fresh());
        Http::assertSentCount(4);
    }

    public function test_untrusted_upload_locations_cannot_receive_google_tokens(): void
    {
        $connection = Connection::current();
        $connection->update(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => now()->addHour(), 'folder_id' => 'folder']);
        Storage::disk('drivesnap')->put('backups/test.enc', 'archive');
        $backup = Backup::create(['path' => 'backups/test.enc', 'size' => 7, 'sha256' => hash('sha256', 'archive'), 'drive_file_id' => 'file', 'upload_url' => 'https://evil.example/upload']);
        Http::fake(['https://www.googleapis.com/drive/v3/files/file*' => Http::response([], 404)]);
        try {
            app(GoogleDrive::class)->upload($backup);
            $this->fail('Untrusted endpoint accepted.');
        } catch (RuntimeException) {
        }
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'evil.example'));
    }

    public function test_failed_remote_deletion_preserves_the_record_and_local_archive(): void
    {
        $connection = Connection::current();
        $connection->update(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => now()->addHour()]);
        Storage::disk('drivesnap')->put('backups/test.enc', 'archive');
        $backup = Backup::create(['path' => 'backups/test.enc', 'drive_file_id' => 'file', 'status' => 'completed']);
        Http::fake(['https://www.googleapis.com/drive/v3/files/file' => Http::response([], 503)]);
        $this->actingAs($this->actor())->deleteJson('/drivesnap/backups/'.$backup->id)->assertUnprocessable();
        $this->assertDatabaseHas('drivesnap_backups', ['id' => $backup->id]);
        Storage::disk('drivesnap')->assertExists('backups/test.enc');
    }

    public function test_first_upload_creates_an_app_folder_and_refreshes_expired_access(): void
    {
        $connection = Connection::current();
        $connection->update(['refresh_token' => 'refresh', 'access_token' => 'expired', 'expires_at' => now()->subHour()]);
        Storage::disk('drivesnap')->put('backups/first.enc', 'encrypted-first');
        $backup = Backup::create(['path' => 'backups/first.enc', 'size' => 15, 'sha256' => hash('sha256', 'encrypted-first'), 'driver' => 'mysql']);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'renewed', 'expires_in' => 3600]),
            'https://www.googleapis.com/drive/v3/files/generateIds*' => Http::response(['ids' => ['new-file']]),
            'https://www.googleapis.com/drive/v3/files/new-file*' => Http::response([], 404),
            'https://www.googleapis.com/drive/v3/files' => Http::response(['id' => 'new-folder']),
            'https://www.googleapis.com/upload/drive/v3/files*' => Http::sequence()
                ->push([], 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=first'])
                ->push([], 308)
                ->push(['id' => 'new-file'], 200),
        ]);
        app(GoogleDrive::class)->upload($backup);
        $this->assertSame('new-folder', $connection->fresh()->folder_id);
        $this->assertSame('renewed', $connection->fresh()->access_token);
        $this->assertSame('new-file', $backup->fresh()->drive_file_id);
        $this->assertSame(15, $backup->fresh()->uploaded_bytes);
        $this->assertNull($backup->fresh()->upload_url);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && $request->body() === 'encrypted-first' && $request->hasHeader('Authorization', 'Bearer renewed'));
    }

    public function test_incomplete_google_consent_never_creates_a_connection(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh', 'scope' => 'openid email'])]);
        $this->actingAs($this->actor())->withSession(['drivesnap.oauth' => ['state' => 'state', 'expires' => now()->addMinute()->getTimestamp()]])
            ->get('/drivesnap/google/callback?state=state&code=code')->assertRedirect('/drivesnap')->assertSessionHasErrors('drivesnap');
        $this->assertFalse(Connection::current()->connected());
        Http::assertSentCount(1);
    }

    public function test_failed_revocation_preserves_credentials_and_schedule(): void
    {
        $connection = Connection::current();
        $connection->update(['refresh_token' => 'refresh', 'schedule_enabled' => true]);
        Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response([], 503)]);
        $this->actingAs($this->actor())->deleteJson('/drivesnap/google')->assertUnprocessable();
        $this->assertTrue($connection->fresh()->connected());
        $this->assertTrue($connection->fresh()->schedule_enabled);
    }
}
