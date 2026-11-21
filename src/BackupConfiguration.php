<?php

namespace DriveSnap;

use DriveSnap\Models\Connection;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

class BackupConfiguration
{
    /** @return array<string, string> */
    public function binaries(): array
    {
        $options = ['default' => (string) config('drivesnap.backup_binary', 'mysqldump'), ...config('drivesnap.binaries', [])];

        return array_filter($options, static fn ($path, $key): bool => is_string($key) && $key !== '' && strlen($key) <= 80 && is_string($path) && $path !== '', ARRAY_FILTER_USE_BOTH);
    }

    public function selectedKey(): string
    {
        try {
            return Connection::find(1)?->binary_key ?: 'default';
        } catch (Throwable) {
            return 'default';
        }
    }

    public function binary(): string
    {
        $path = $this->binaries()[$this->selectedKey()] ?? null;
        $resolved = null;
        if (is_string($path)) {
            $resolved = strpbrk($path, '/\\') !== false
                ? (is_file($path) && is_executable($path) ? $path : null)
                : (new ExecutableFinder)->find($path);
        }
        if (! is_string($resolved)) {
            throw new RuntimeException('The selected approved dump tool is unavailable. Check server configuration and executable permissions.');
        }

        return $resolved;
    }

    public function version(): string
    {
        $result = Process::timeout(5)->run([$this->binary(), '--version']);
        if (! $result->successful() || ! preg_match('/(?:mysqldump|mariadb-dump).*?(?:Ver|Distrib)\s+([0-9][a-zA-Z0-9.\-]*)/i', $result->output(), $matches)) {
            throw new RuntimeException('The approved executable did not identify itself as mysqldump or mariadb-dump.');
        }

        return $matches[1];
    }

    public function dumpTimeout(): int
    {
        return (int) config('drivesnap.dump_timeout', 120);
    }

    public function jobTimeout(): int
    {
        return (int) config('drivesnap.job_timeout', 300);
    }

    /** @return list<string> */
    public function timeoutProblems(): array
    {
        $problems = [];
        $dump = filter_var(config('drivesnap.dump_timeout', 120), FILTER_VALIDATE_INT);
        $job = filter_var(config('drivesnap.job_timeout', 300), FILTER_VALIDATE_INT);
        if ($dump === false || $dump < 1 || $dump > 3600) {
            $problems[] = 'Dump timeout must be an integer between 1 and 3600 seconds.';
        }
        if ($job === false || $job < 30 || $job > 7200) {
            $problems[] = 'Job timeout must be an integer between 30 and 7200 seconds.';
        }
        if ($dump !== false && $job !== false && $dump + 30 >= $job) {
            $problems[] = 'Job timeout must exceed dump timeout by more than 30 seconds for encryption and upload.';
        }
        $connection = config('queue.connections.'.config('queue.default'), []);
        if (isset($connection['retry_after']) && (int) $connection['retry_after'] <= $job) {
            $problems[] = 'Queue retry_after must exceed the DriveSnap job timeout.';
        }

        return $problems;
    }

    public function validateTimeouts(): void
    {
        if ($problems = $this->timeoutProblems()) {
            throw new RuntimeException(implode(' ', $problems));
        }
    }

    public function fingerprint(): string
    {
        $database = config('drivesnap.connection') ?: config('database.default');
        try {
            $connected = Connection::find(1)?->connected() ?? false;
        } catch (Throwable) {
            $connected = false;
        }

        return hash('sha256', serialize([$this->selectedKey(), $this->binaries(), $this->dumpTimeout(), $this->jobTimeout(), config('queue.default'), config('drivesnap.queue'), $database, config('database.connections.'.$database.'.driver'), $connected, config('drivesnap.client_id'), config('queue.connections.'.config('queue.default').'.retry_after')]));
    }
}
