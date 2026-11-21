<?php

namespace DriveSnap\Console;

use Illuminate\Console\Command;

class Install extends Command
{
    protected $signature = 'drivesnap:install {--stack=vue : vue, react or svelte} {--page= : Inertia page name} {--force : Replace published UI files (configuration is preserved)}';

    protected $description = 'Publish DriveSnap configuration, migrations and an Inertia dashboard';

    public function handle(): int
    {
        $stack = $this->option('stack');
        $page = $this->option('page') ?: config('drivesnap.page');
        if (! in_array($stack, ['vue', 'react', 'svelte'], true) || ! is_string($page) || ! preg_match('/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/', $page)) {
            $this->error('Choose vue, react or svelte and a safe relative page name.');

            return self::FAILURE;
        }
        $this->call('vendor:publish', ['--tag' => ['drivesnap-config'], '--no-interaction' => true]);
        $migrationTags = [];
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migration) {
            $name = substr(basename($migration), 18);
            if (glob(database_path('migrations/*_'.$name)) === []) {
                $migrationTags[] = 'drivesnap-migration-'.$name;
            }
        }
        if ($migrationTags !== []) {
            $this->call('vendor:publish', ['--tag' => $migrationTags, '--no-interaction' => true]);
        }
        $extension = ['vue' => 'vue', 'react' => 'jsx', 'svelte' => 'svelte'][$stack];
        $files = [
            resource_path('js/pages/'.$page.'.'.$extension) => str_replace(['__IMPORT__', '../../drivesnap/dashboard.css'], [str_repeat('../', substr_count($page, '/') + 1).'drivesnap/dashboard.js', str_repeat('../', substr_count($page, '/') + 1).'drivesnap/dashboard.css'], file_get_contents(__DIR__.'/../../resources/stubs/Dashboard.'.$extension)),
            resource_path('js/drivesnap/dashboard.js') => file_get_contents(__DIR__.'/../../resources/js/dashboard.js'),
            resource_path('js/drivesnap/dashboard.css') => file_get_contents(__DIR__.'/../../resources/js/dashboard.css'),
            resource_path('js/drivesnap/dashboard.d.ts') => file_get_contents(__DIR__.'/../../resources/js/dashboard.d.ts'),
        ];
        foreach ($files as $path => $content) {
            if (file_exists($path) && ! $this->option('force')) {
                $this->warn('Kept existing file: '.$path);

                continue;
            }
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $content);
        }
        $this->info('DriveSnap UI published. Set drivesnap.page to '.$page.', define your authorization gates, run php artisan migrate, and rebuild your frontend.');

        return self::SUCCESS;
    }
}
