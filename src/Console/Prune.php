<?php

namespace DriveSnap\Console;

use DriveSnap\BackupActivity;
use DriveSnap\GoogleDrive;
use DriveSnap\Models\Backup;
use DriveSnap\Models\Connection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class Prune extends Command
{
    protected $signature = 'drivesnap:prune {--dry-run : List eligible backups without deleting}';

    protected $description = 'Apply retention while preserving the newest completed backup';

    public function handle(GoogleDrive $drive): int
    {
        $disk = Storage::disk('drivesnap');
        if (! $this->option('dry-run')) {
            foreach ($disk->files('tmp') as $file) {
                if ($disk->lastModified($file) < now()->subHour()->getTimestamp()) {
                    $disk->delete($file);
                }
            }
        }
        $latest = Backup::where('status', 'completed')->latest('completed_at')->first();
        if (! $latest) {
            $this->info('No completed backup; nothing pruned.');

            return self::SUCCESS;
        }
        $connection = Connection::find(1);
        if ((! $latest->path || ! $disk->exists($latest->path)) && (! $latest->drive_file_id || ! $connection?->connected())) {
            $this->error('No accessible latest backup; retention is paused.');

            return self::FAILURE;
        }
        $query = Backup::where('status', 'completed')->where('id', '!=', $latest->id)
            ->where('completed_at', '<', now()->subDays($connection?->retention_days ?? 30));
        foreach ($query->orderBy('id')->lazyById(100) as $backup) {
            $this->line(($this->option('dry-run') ? 'Eligible: ' : 'Pruning: ').$backup->id);
            if ($this->option('dry-run')) {
                continue;
            }
            $lock = Cache::lock('drivesnap:backup:'.$backup->id, 360);
            if (! $lock->get()) {
                continue;
            }
            try {
                $drive->delete($backup);
                if ($backup->path) {
                    $disk->delete($backup->path);
                }
                $backup->delete();
                event(new BackupActivity('deleted', $backup));
            } catch (\Throwable) {
                $this->error('Retention stopped because a backup could not be deleted safely.');

                return self::FAILURE;
            } finally {
                $lock->release();
            }
        }

        return self::SUCCESS;
    }
}
