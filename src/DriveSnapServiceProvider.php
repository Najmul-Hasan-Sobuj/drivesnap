<?php

namespace DriveSnap;

use DriveSnap\Console\DecryptBackup;
use DriveSnap\Console\Install;
use DriveSnap\Console\Prune;
use DriveSnap\Console\RunBackup;
use DriveSnap\Models\Connection;
use Illuminate\Console\Scheduling\Schedule;
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
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        if ($this->app->runningInConsole()) {
            $this->commands([Install::class, RunBackup::class, Prune::class, DecryptBackup::class]);
            $this->publishes([__DIR__.'/../config/drivesnap.php' => config_path('drivesnap.php')], 'drivesnap-config');
            $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'drivesnap-migrations');
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('drivesnap:run')->dailyAt(config('drivesnap.daily_at'))->timezone(config('drivesnap.timezone'))
                    ->when(fn (): bool => Connection::find(1)?->schedule_enabled === true)
                    ->withoutOverlapping()->onOneServer();
                $schedule->command('drivesnap:prune')->dailyAt('03:25')->timezone(config('drivesnap.timezone'))->withoutOverlapping()->onOneServer();
            });
        }
    }
}
