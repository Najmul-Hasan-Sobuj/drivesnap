<?php

namespace DriveSnap\Tests;

use DriveSnap\GoogleDrive;
use DriveSnap\Jobs\CreateBackup;
use DriveSnap\Models\Backup;
use DriveSnap\Models\Connection;
use DriveSnap\Snapshot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class InstallAndRetentionTest extends TestCase
{
    public function test_repeated_installation_does_not_duplicate_schema_or_overwrite_ui(): void
    {
        $directory = database_path('migrations');
        $before = glob($directory.'/*drivesnap*.php') ?: [];
        try {
            $this->artisan('drivesnap:install', ['--stack' => 'vue', '--page' => 'admin/backups/Index'])->assertSuccessful();
            $configuration = file_get_contents(config_path('drivesnap.php'));
            $this->artisan('drivesnap:install', ['--stack' => 'vue', '--page' => 'admin/backups/Index', '--force' => true])->assertSuccessful();
            $this->assertSame($configuration, file_get_contents(config_path('drivesnap.php')));
            $first = glob($directory.'/*drivesnap*.php');
            $this->artisan('drivesnap:install', ['--stack' => 'vue', '--page' => 'admin/backups/Index'])->assertSuccessful();
            $this->assertCount(count(glob(__DIR__.'/../database/migrations/*.php')), $first);
            $this->assertStringContainsString('_create_drivesnap_tables.php', basename($first[0]));
            $this->assertSame($first, glob($directory.'/*drivesnap*.php'));
            $page = file_get_contents(resource_path('js/pages/admin/backups/Index.vue'));
            $this->assertStringContainsString('../../../drivesnap/dashboard.js', $page);
            $this->assertStringContainsString('../../../drivesnap/dashboard.css', $page);
            $this->artisan('drivesnap:install', ['--page' => '../outside'])->assertFailed();
        } finally {
            foreach (array_diff(glob($directory.'/*drivesnap*.php') ?: [], $before) as $file) {
                unlink($file);
            }
        }
    }

    public function test_portable_agent_skill_is_opt_in_and_preserves_existing_project_instructions(): void
    {
        $target = base_path('.agents/skills/drivesnap-development/SKILL.md');
        $before = is_file($target) ? file_get_contents($target) : null;
        $instructions = base_path('AGENTS.md');
        $previousInstructions = is_file($instructions) ? file_get_contents($instructions) : null;
        try {
            if (is_file($target)) {
                unlink($target);
            }
            file_put_contents($instructions, 'Existing project instructions');
            $this->artisan('drivesnap:install')->assertSuccessful();
            $this->assertFileDoesNotExist($target);
            $this->artisan('vendor:publish', ['--tag' => 'drivesnap-skill'])->assertSuccessful();
            $source = file_get_contents(__DIR__.'/../resources/boost/skills/drivesnap-development/SKILL.md');
            $this->assertSame($source, file_get_contents($target));
            $this->assertSame('Existing project instructions', file_get_contents($instructions));
            file_put_contents($target, 'Customized skill');
            $this->artisan('vendor:publish', ['--tag' => 'drivesnap-skill'])->assertSuccessful();
            $this->assertSame('Customized skill', file_get_contents($target));
            $this->artisan('vendor:publish', ['--tag' => 'drivesnap-skill', '--force' => true])->assertSuccessful();
            $this->assertSame($source, file_get_contents($target));
        } finally {
            if ($before === null) {
                if (is_file($target)) {
                    unlink($target);
                }
            } else {
                file_put_contents($target, $before);
            }
            if ($previousInstructions === null) {
                unlink($instructions);
            } else {
                file_put_contents($instructions, $previousInstructions);
            }
        }
    }

    public function test_retention_keeps_the_last_completed_backup_even_when_it_is_old(): void
    {
        $disk = Storage::disk('drivesnap');
        $disk->put('backups/old.enc', 'old');
        $disk->put('backups/new.enc', 'new');
        $old = Backup::create(['status' => 'completed', 'path' => 'backups/old.enc', 'completed_at' => now()->subDays(60)]);
        $latest = Backup::create(['status' => 'completed', 'path' => 'backups/new.enc', 'completed_at' => now()->subDays(40)]);
        $this->artisan('drivesnap:prune', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseHas('drivesnap_backups', ['id' => $old->id]);
        $this->artisan('drivesnap:prune')->assertSuccessful();
        $this->assertDatabaseMissing('drivesnap_backups', ['id' => $old->id]);
        $this->assertDatabaseHas('drivesnap_backups', ['id' => $latest->id]);
        $disk->assertExists('backups/new.enc');
    }

    public function test_provider_failures_are_sanitized_and_terminal_failure_is_visible(): void
    {
        $backup = Backup::create(['upload_to_drive' => false]);
        $snapshot = $this->mock(Snapshot::class);
        $snapshot->shouldReceive('create')->once()->andThrow(new RuntimeException('private-provider-detail'));
        $job = new CreateBackup($backup->id);
        try {
            $job->handle($snapshot, app(GoogleDrive::class));
            $this->fail('A provider failure was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('private-provider-detail', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame('retrying', $backup->fresh()->status);
        $job->failed(null);
        $this->assertSame('failed', $backup->fresh()->status);
    }

    public function test_remote_download_authenticates_archive_bytes_before_serving(): void
    {
        $connection = Connection::current();
        $connection->update(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => now()->addHour()]);
        $archive = 'encrypted archive fixture';
        $backup = Backup::create(['path' => 'backups/remote.enc', 'drive_file_id' => 'remote', 'status' => 'completed', 'driver' => 'mysql', 'size' => strlen($archive), 'sha256' => hash('sha256', $archive)]);
        Http::fake(['*' => Http::sequence()->push($archive, 200)->push('tampered', 200)]);
        $this->actingAs($this->actor())->get('/drivesnap/backups/'.$backup->id.'/download')->assertDownload();
        Storage::disk('drivesnap')->assertExists('backups/remote.enc');
        Storage::disk('drivesnap')->delete('backups/remote.enc');
        $this->getJson('/drivesnap/backups/'.$backup->id.'/download')->assertUnprocessable();
        Storage::disk('drivesnap')->assertMissing('backups/remote.enc');
        Storage::disk('drivesnap')->assertMissing('backups/remote.enc.download');
    }
}
