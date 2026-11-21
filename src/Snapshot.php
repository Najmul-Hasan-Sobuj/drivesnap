<?php

namespace DriveSnap;

use DriveSnap\Models\Backup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class Snapshot
{
    public function create(Backup $backup): void
    {
        $connection = DB::connection(config('drivesnap.connection'));
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('Supported database drivers: MySQL, MariaDB and SQLite.');
        }
        $configuration = app(BackupConfiguration::class);
        $configuration->validateTimeouts();
        $binary = $driver === 'sqlite' ? null : $configuration->binary();
        $disk = Storage::disk('drivesnap');
        $disk->makeDirectory('tmp');
        $disk->makeDirectory('backups');
        chmod($disk->path('tmp'), 0700);
        $temporary = tempnam($disk->path('tmp'), '.snapshot-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot allocate a private snapshot file.');
        }
        chmod($temporary, 0600);
        $credentials = null;
        $path = 'backups/'.$backup->id.'.enc';
        try {
            if ($driver === 'sqlite') {
                unlink($temporary);
                $connection->statement('VACUUM INTO '.$connection->getPdo()->quote($temporary));
                chmod($temporary, 0600);
            } else {
                $credentials = tempnam($disk->path('tmp'), '.credentials-');
                if ($credentials === false) {
                    throw new RuntimeException('Cannot prepare database client configuration.');
                }
                chmod($credentials, 0600);
                $quote = static fn (string $value): string => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value).'"';
                $options = "[client]\n";
                foreach (['host', 'port', 'username', 'password', 'unix_socket'] as $key) {
                    $value = (string) $connection->getConfig($key);
                    if ($value !== '') {
                        $option = match ($key) {
                            'username' => 'user', 'unix_socket' => 'socket', default => $key
                        };
                        $options .= $option.'='.$quote($value)."\n";
                    }
                }
                file_put_contents($credentials, $options);
                $result = Process::timeout($configuration->dumpTimeout())->run([$binary, '--defaults-extra-file='.$credentials, '--single-transaction', '--skip-lock-tables', '--no-tablespaces', '--hex-blob', '--result-file='.$temporary, '--', $connection->getDatabaseName()]);
                if (! $result->successful()) {
                    throw new RuntimeException('Database snapshot failed. Check dump binary and database privileges.');
                }
            }
            if (! is_file($temporary) || filesize($temporary) === 0) {
                throw new RuntimeException('The database snapshot is empty.');
            }
            $backup->update(['path' => $path, 'sha256' => null]);
            $disk->delete($path);
            app(EncryptedFile::class)->encrypt($temporary, $disk->path($path));
            $backup->update(['path' => $path, 'driver' => $driver, 'size' => filesize($disk->path($path)), 'sha256' => hash_file('sha256', $disk->path($path))]);
        } finally {
            foreach ([$temporary, $credentials] as $file) {
                if (is_string($file) && is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
