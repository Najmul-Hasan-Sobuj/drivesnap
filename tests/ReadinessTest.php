<?php

namespace DriveSnap\Tests;

use DriveSnap\BackupConfiguration;
use DriveSnap\Jobs\CreateBackup;
use DriveSnap\Models\Backup;
use DriveSnap\Models\Connection;
use DriveSnap\Readiness;
use DriveSnap\Snapshot;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ReadinessTest extends TestCase
{
    public function test_doctor_checks_sqlite_without_a_dump_tool_and_removes_its_private_probe(): void
    {
        Process::fake();
        Http::preventStrayRequests();
        $this->artisan('drivesnap:doctor')->assertSuccessful();
        $report = app(Readiness::class)->summary();
        $checks = collect($report['checks'])->keyBy('key');
        $this->assertSame('ok', $checks['binary']['status']);
        $this->assertSame('ok', $checks['database']['status']);
        $this->assertSame('ok', $checks['storage']['status']);
        $this->assertSame('ok', $checks['cache']['status']);
        $this->assertNotNull($report['checked_at']);
        $this->assertSame([], Storage::disk('drivesnap')->allFiles());
        Process::assertNothingRan();
    }

    public function test_web_settings_accept_only_server_approved_tool_keys_and_require_manage_access(): void
    {
        Process::fake();
        config(['drivesnap.binaries' => ['Approved MySQL' => PHP_BINARY]]);
        $settings = ['schedule_enabled' => false, 'retention_days' => 30, 'binary_key' => 'Approved MySQL'];
        $this->actingAs($this->actor('viewer'))->putJson('/drivesnap/settings', $settings)->assertForbidden();
        $this->actingAs($this->actor())->putJson('/drivesnap/settings', [...$settings, 'binary_key' => '/tmp/unapproved-program'])->assertUnprocessable();
        $this->putJson('/drivesnap/settings', $settings)->assertOk();
        $this->assertSame('Approved MySQL', Connection::current()->binary_key);
        $this->getJson('/drivesnap/status')->assertOk()
            ->assertJsonPath('configuration.binary_key', 'Approved MySQL')->assertDontSee(PHP_BINARY);
        Process::assertNothingRan();
    }

    public function test_binary_version_probe_executes_only_the_configured_program_with_a_fixed_argument(): void
    {
        config(['drivesnap.backup_binary' => PHP_BINARY]);
        Process::fake(['*' => Process::result(output: 'mysqldump  Ver 8.4.9 for macos')]);
        $this->assertSame('8.4.9', app(BackupConfiguration::class)->version());
        Process::assertRan(fn ($process): bool => $process->command === [PHP_BINARY, '--version']);
    }

    public function test_missing_approved_binary_is_rejected_before_any_process_runs(): void
    {
        config(['drivesnap.backup_binary' => '/missing/drivesnap-dump-program']);
        Process::fake();
        try {
            app(BackupConfiguration::class)->version();
            $this->fail('A missing program was accepted.');
        } catch (RuntimeException) {
            Process::assertNothingRan();
        }
    }

    public function test_unsafe_timeout_configuration_blocks_backups_and_is_reported_by_doctor(): void
    {
        Bus::fake();
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 90, 'drivesnap.job_timeout' => 300]);
        $this->actingAs($this->actor())->postJson('/drivesnap/backups', ['upload_to_drive' => false])
            ->assertUnprocessable()->assertJsonPath('message', 'Queue retry_after must exceed the DriveSnap job timeout.');
        $this->artisan('drivesnap:run', ['--local' => true])->assertFailed();
        $this->artisan('drivesnap:doctor', ['--json' => true])->assertFailed();
        $this->assertDatabaseCount('drivesnap_backups', 0);
        Bus::assertNothingDispatched();
    }

    public function test_snapshot_refuses_a_dump_timeout_that_would_exhaust_the_job_budget(): void
    {
        config(['drivesnap.dump_timeout' => 290, 'drivesnap.job_timeout' => 300]);
        $backup = Backup::create(['upload_to_drive' => false]);
        try {
            app(Snapshot::class)->create($backup);
            $this->fail('An unsafe timeout budget was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Job timeout must exceed dump timeout', $exception->getMessage());
            $this->assertSame([], Storage::disk('drivesnap')->allFiles());
        }
    }

    public function test_worker_heartbeats_are_scoped_to_the_configured_connection_and_queue_and_expire(): void
    {
        $this->freezeTime();
        config(['queue.default' => 'database']);
        event(new Looping('redis', 'backups'));
        event(new Looping('database', 'default'));
        $this->assertNull(Cache::get('drivesnap.worker_heartbeat'));
        event(new Looping('database', 'mail, backups'));
        $this->assertSame(now()->getTimestamp(), Cache::get('drivesnap.worker_heartbeat'));
        $checks = collect(app(Readiness::class)->summary()['checks'])->keyBy('key');
        $this->assertSame('ok', $checks['worker']['status']);
        $this->travel(7)->minutes();
        $checks = collect(app(Readiness::class)->summary()['checks'])->keyBy('key');
        $this->assertSame('warning', $checks['worker']['status']);
    }

    public function test_only_the_real_scheduled_heartbeat_updates_scheduler_readiness(): void
    {
        $this->assertNull(Cache::get('drivesnap.scheduler_heartbeat'));
        app(Readiness::class)->summary();
        $this->assertNull(Cache::get('drivesnap.scheduler_heartbeat'));
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'drivesnap-heartbeat');
        $this->assertNotNull($event);
        $event->run($this->app);
        $checks = collect(app(Readiness::class)->summary()['checks'])->keyBy('key');
        $this->assertSame('ok', $checks['scheduler']['status']);
    }

    public function test_doctor_does_not_leak_provider_details_or_tokens_and_invalidates_stale_reports(): void
    {
        Connection::current()->update(['access_token' => 'sensitive-access', 'refresh_token' => 'sensitive-refresh', 'expires_at' => now()->addHour()]);
        Http::preventStrayRequests();
        Http::fake(['https://www.googleapis.com/drive/v3/about*' => Http::response(['private' => 'provider-private-detail'], 503)]);
        $report = app(Readiness::class)->diagnose();
        $encoded = json_encode($report);
        $this->assertStringNotContainsString('sensitive-', $encoded);
        $this->assertStringNotContainsString('provider-private-detail', $encoded);
        $checks = collect($report['checks'])->keyBy('key');
        $this->assertSame('error', $checks['google']['status']);
        Http::assertSentCount(1);
        config(['drivesnap.job_timeout' => 900]);
        $this->assertNull(app(Readiness::class)->summary()['checked_at']);
    }

    public function test_larger_valid_timeout_reaches_the_real_queue_payload_and_snapshot_completes(): void
    {
        config(['drivesnap.dump_timeout' => 600, 'drivesnap.job_timeout' => 900]);
        $observed = null;
        Queue::createPayloadUsing(function ($connection, $queue, $payload) use (&$observed): array {
            $observed = $payload['timeout'];

            return [];
        });
        try {
            $backup = Backup::create(['upload_to_drive' => false]);
            CreateBackup::dispatch($backup->id);
            $this->assertSame(900, $observed);
            $this->assertSame('completed', $backup->fresh()->status);
        } finally {
            Queue::createPayloadUsing(null);
        }
    }
}
