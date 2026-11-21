<?php

namespace DriveSnap\Jobs;

use DriveSnap\Authorization;
use DriveSnap\BackupActivity;
use DriveSnap\BackupConfiguration;
use DriveSnap\GoogleDrive;
use DriveSnap\Models\Backup;
use DriveSnap\Snapshot;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class CreateBackup implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 1800;

    public function __construct(public string $backupId)
    {
        $this->timeout = max(1, app(BackupConfiguration::class)->jobTimeout());
        $this->uniqueFor = max(1800, ($this->timeout + 120) * $this->tries + 300);
        $this->onQueue(config('drivesnap.queue'));
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->backupId;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->backupId))->withPrefix('drivesnap:backup:')->shared()->releaseAfter(15)->expireAfter($this->timeout + 60)];
    }

    public function handle(Snapshot $snapshot, GoogleDrive $drive): void
    {
        $backup = Backup::find($this->backupId);
        if (! $backup || in_array($backup->status, ['completed', 'cancelled'], true)) {
            return;
        }
        if ($backup->requested_by_id !== null && ! Authorization::allows($backup->requestedBy, 'create')) {
            $backup->update(['status' => 'cancelled', 'error_code' => 'access_revoked']);

            return;
        }
        try {
            $backup->update(['status' => 'running', 'error_code' => null]);
            if (! $backup->sha256 || ! $backup->path || ! Storage::disk('drivesnap')->exists($backup->path)) {
                $snapshot->create($backup);
            }
            if ($backup->upload_to_drive) {
                $backup->update(['status' => 'uploading']);
                $drive->upload($backup);
            }
            $backup->update(['status' => 'completed', 'completed_at' => now(), 'error_code' => null]);
        } catch (Throwable) {
            $backup->update(['status' => 'retrying', 'error_code' => 'backup_or_upload_failed']);
            throw new RuntimeException('DriveSnap could not complete the backup. Check database permissions and Google authorization.');
        }
        event(new BackupActivity('completed', $backup));
    }

    public function failed(?Throwable $exception): void
    {
        $backup = Backup::find($this->backupId);
        if ($backup && ! in_array($backup->status, ['completed', 'cancelled'], true)) {
            $backup->update(['status' => 'failed', 'error_code' => 'backup_or_upload_failed']);
            event(new BackupActivity('failed', $backup));
        }
    }
}
