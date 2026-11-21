<?php

namespace DriveSnap;

use DriveSnap\Console\DecryptBackup;
use DriveSnap\Console\Doctor;
use DriveSnap\Console\Install;
use DriveSnap\Console\Prune;
use DriveSnap\Console\RunBackup;
use DriveSnap\Models\Connection;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class DriveSnapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/drivesnap.php', 'drivesnap');
        config(['filesystems.disks.drivesnap' => ['driver' => 'local', 'root' => storage_path('app/private/drivesnap'), 'throw' => true, 'serve' => false]]);
    }

    public function boot(): void
    {
        Queue::looping(fn (Looping $event) => app(Readiness::class)->workerSignal($event->connectionName, $event->queue));
        Queue::before(fn (JobProcessing $event) => app(Readiness::class)->workerSignal($event->connectionName, $event->job->getQueue()));
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        if ($this->app->runningInConsole()) {
            $this->commands([Install::class, RunBackup::class, Prune::class, DecryptBackup::class, Doctor::class]);
            $this->publishes([__DIR__.'/../config/drivesnap.php' => config_path('drivesnap.php')], 'drivesnap-config');
            foreach (glob(__DIR__.'/../database/migrations/*.php') as $migration) {
                $name = substr(basename($migration), 18);
                $this->publishesMigrations([$migration => database_path('migrations/'.basename($migration))], ['drivesnap-migrations', 'drivesnap-migration-'.$name]);
            }
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->call(fn () => app(Readiness::class)->schedulerSignal())->name('drivesnap-heartbeat')->everyMinute()->withoutOverlapping()->onOneServer();
                $schedule->command('drivesnap:run')->dailyAt(config('drivesnap.daily_at'))->timezone(config('drivesnap.timezone'))
                    ->when(fn (): bool => Connection::find(1)?->schedule_enabled === true)
                    ->withoutOverlapping()->onOneServer();
                $schedule->command('drivesnap:prune')->dailyAt('03:25')->timezone(config('drivesnap.timezone'))->withoutOverlapping()->onOneServer();
            });
        }
    }
}
