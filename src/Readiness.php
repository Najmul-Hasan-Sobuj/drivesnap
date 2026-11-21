<?php

namespace DriveSnap;

use Closure;
use DriveSnap\Models\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class Readiness
{
    public function __construct(private BackupConfiguration $configuration) {}

    public function workerSignal(string $connection, ?string $queues): void
    {
        if ($connection === config('queue.default') && in_array(config('drivesnap.queue'), array_map('trim', explode(',', $queues ?? '')), true)) {
            $this->rememberSignal('drivesnap.worker_heartbeat');
        }
    }

    public function schedulerSignal(): void
    {
        $this->rememberSignal('drivesnap.scheduler_heartbeat');
    }

    private function rememberSignal(string $key): void
    {
        try {
            Cache::put($key, now()->getTimestamp(), now()->addSeconds(max(180, $this->configuration->jobTimeout() + 120)));
        } catch (Throwable) {
            // Diagnostics must never interrupt a worker or scheduler.
        }
    }

    private function cached(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (Throwable) {
            return null;
        }
    }

    public function summary(): array
    {
        $report = $this->cached('drivesnap.doctor');
        if (! is_array($report) || ($report['fingerprint'] ?? null) !== $this->configuration->fingerprint()) {
            $report = ['checked_at' => null, 'checks' => array_map(
                static fn (array $item): array => ['key' => $item[0], 'label' => $item[1], 'status' => 'warning', 'message' => 'Not checked yet. Run php artisan drivesnap:doctor.'],
                [['binary', 'Dump tool'], ['database', 'Database access'], ['storage', 'Private storage'], ['cache', 'Cache'], ['timeouts', 'Timeout configuration'], ['google', 'Google Drive']]
            )];
        }

        return ['checked_at' => $report['checked_at'], 'checks' => [...$report['checks'], ...$this->signals()]];
    }

    private function signals(): array
    {
        $inline = config('queue.connections.'.config('queue.default').'.driver') === 'sync';
        $worker = $inline
            ? ['key' => 'worker', 'label' => 'Backup worker', 'status' => 'ok', 'message' => 'Synchronous queue: backups run in the requesting process.']
            : $this->signal('worker', 'Backup worker', 'drivesnap.worker_heartbeat', max(90, $this->configuration->jobTimeout() + 30), 'No recent backup-worker signal. Start a worker listening to the configured backup queue.');
        $scheduler = $this->signal('scheduler', 'Scheduler', 'drivesnap.scheduler_heartbeat', 150, 'No recent scheduler signal. Run Laravel schedule:run every minute.');

        return [$worker, $scheduler];
    }

    private function signal(string $key, string $label, string $cacheKey, int $freshFor, string $missing): array
    {
        $timestamp = $this->cached($cacheKey);
        $age = is_int($timestamp) ? now()->getTimestamp() - $timestamp : null;
        $fresh = $age !== null && $age >= 0 && $age <= $freshFor;

        return ['key' => $key, 'label' => $label, 'status' => $fresh ? 'ok' : 'warning', 'message' => $fresh ? 'A recent heartbeat was observed.' : $missing,
            'last_seen_at' => is_int($timestamp) ? now()->setTimestamp($timestamp)->toISOString() : null];
    }

    public function diagnose(): array
    {
        $fingerprint = $this->configuration->fingerprint();
        $driver = config('database.connections.'.(config('drivesnap.connection') ?: config('database.default')).'.driver');
        $checks = [];
        $checks[] = $driver === 'sqlite'
            ? ['key' => 'binary', 'label' => 'Dump tool', 'status' => 'ok', 'message' => 'SQLite uses VACUUM INTO; no external dump executable is required.']
            : $this->probe('binary', 'Dump tool', fn (): string => 'Approved tool version '.$this->configuration->version().'.', 'Approved dump tool is missing, not executable, or did not report a supported version.');
        $checks[] = $this->probe('database', 'Database access', function () use ($driver): string {
            if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
                throw new RuntimeException;
            }
            DB::connection()->select('select 1');
            DB::connection(config('drivesnap.connection'))->select('select 1');

            return 'Source and metadata connections are reachable. A successful backup is still required to verify dump privileges.';
        }, 'Database access failed. Supported source drivers: MySQL, MariaDB and SQLite.');
        $checks[] = $this->probe('storage', 'Private storage', function (): string {
            $disk = Storage::disk('drivesnap');
            $path = 'tmp/doctor-'.Str::uuid();
            try {
                $disk->put($path, 'DriveSnap storage probe');
                chmod($disk->path($path), 0600);
                if ($disk->get($path) !== 'DriveSnap storage probe') {
                    throw new RuntimeException;
                }

                return 'Private storage is readable and writable.';
            } finally {
                $disk->delete($path);
            }
        }, 'Private storage could not complete a read/write check.');
        $checks[] = $this->probe('cache', 'Cache', function (): string {
            $key = 'drivesnap.doctor-probe.'.Str::uuid();
            try {
                Cache::put($key, true, 10);
                if (Cache::get($key) !== true) {
                    throw new RuntimeException;
                }
                $lock = Cache::lock($key.'.lock', 5);
                if (! $lock->get()) {
                    throw new RuntimeException;
                }
                $lock->release();

                return 'Cache reads, writes and atomic locks are available.';
            } finally {
                Cache::forget($key);
            }
        }, 'Cache reads, writes or atomic locking failed.');
        $problems = $this->configuration->timeoutProblems();
        $checks[] = ['key' => 'timeouts', 'label' => 'Timeout configuration', 'status' => $problems ? 'error' : 'ok',
            'message' => $problems ? implode(' ', $problems) : 'Dump '.$this->configuration->dumpTimeout().'s; job '.$this->configuration->jobTimeout().'s. Configure the process monitor and SQS visibility timeout separately when applicable.'];
        $checks[] = $this->googleCheck();
        $report = ['fingerprint' => $fingerprint, 'checked_at' => now()->toISOString(), 'checks' => $checks];
        try {
            Cache::put('drivesnap.doctor', $report, now()->addHour());
        } catch (Throwable) {
            // The failed cache probe already records this failure.
        }

        return ['checked_at' => $report['checked_at'], 'checks' => [...$checks, ...$this->signals()]];
    }

    private function googleCheck(): array
    {
        $result = ['key' => 'google', 'label' => 'Google Drive'];
        try {
            $connection = Connection::find(1);
            if (! $connection?->connected()) {
                return [...$result, 'status' => 'warning', 'message' => 'Not connected. Local backups remain available.'];
            }
            $response = app(GoogleDrive::class)->client($connection)->get('https://www.googleapis.com/drive/v3/about', ['fields' => 'kind']);

            return [...$result, 'status' => $response->successful() ? 'ok' : 'error',
                'message' => $response->successful() ? 'Google authorization and Drive API are reachable.'
                    : ($response->json('error.errors.0.reason') === 'accessNotConfigured' ? 'Enable Google Drive API in the OAuth client project.' : 'Google Drive check failed. Review consent and API access.')];
        } catch (Throwable) {
            return [...$result, 'status' => 'error', 'message' => 'Google Drive check failed. Review OAuth configuration and connectivity.'];
        }
    }

    private function probe(string $key, string $label, Closure $callback, string $failure): array
    {
        try {
            return compact('key', 'label') + ['status' => 'ok', 'message' => $callback()];
        } catch (Throwable) {
            return compact('key', 'label') + ['status' => 'error', 'message' => $failure];
        }
    }
}
