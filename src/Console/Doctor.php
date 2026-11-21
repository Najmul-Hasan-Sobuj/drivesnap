<?php

namespace DriveSnap\Console;

use DriveSnap\Readiness;
use Illuminate\Console\Command;

class Doctor extends Command
{
    protected $signature = 'drivesnap:doctor {--json : Print a machine-readable readiness report}';

    protected $description = 'Check approved dump tooling, database, storage, queue configuration and Google connectivity';

    public function handle(Readiness $readiness): int
    {
        $report = $readiness->diagnose();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Check', 'Status', 'Details'], array_map(
                static fn (array $check): array => [$check['label'], strtoupper($check['status']), $check['message'].(isset($check['last_seen_at']) ? ' Last seen: '.$check['last_seen_at'] : '')],
                $report['checks']
            ));
            $this->info('Checks reflect this CLI process. Heartbeats are observations, not proof that a process will keep running.');
        }

        return collect($report['checks'])->contains('status', 'error') ? self::FAILURE : self::SUCCESS;
    }
}
