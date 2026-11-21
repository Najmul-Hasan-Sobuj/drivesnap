<?php

namespace DriveSnap\Console;

use DriveSnap\BackupActivity;
use DriveSnap\BackupConfiguration;
use DriveSnap\Jobs\CreateBackup;
use DriveSnap\Models\Backup;
use DriveSnap\Models\Connection;
use Illuminate\Console\Command;

class RunBackup extends Command
{
    protected $signature = 'drivesnap:run {--local : Keep this backup on the server only}';

    protected $description = 'Queue an encrypted database backup; upload to Drive when connected';

    public function handle(): int
    {
        if ($problems = app(BackupConfiguration::class)->timeoutProblems()) {
            $this->error(implode(' ', $problems));

            return self::FAILURE;
        }
        $backup = Backup::create(['upload_to_drive' => ! $this->option('local') && (Connection::find(1)?->connected() ?? false)]);
        event(new BackupActivity('requested', $backup));
        CreateBackup::dispatch($backup->id);
        $this->info('Backup queued: '.$backup->id);

        return self::SUCCESS;
    }
}
