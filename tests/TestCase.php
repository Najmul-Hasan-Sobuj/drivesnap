<?php

namespace DriveSnap\Tests;

use DriveSnap\DriveSnapServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class, DriveSnapServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('drivesnap.client_id', 'test-client');
        $app['config']->set('drivesnap.client_secret', 'test-client-secret');
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn () => 'Sign in')->name('login');
    }

    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__.'/../database/migrations/2026_11_21_000000_create_drivesnap_tables.php')->up();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Gate::define('viewDriveSnap', fn (User $user): bool => in_array($user->role, ['manager', 'viewer'], true));
        Gate::define('manageDriveSnap', fn (User $user): bool => $user->role === 'manager');
        Storage::fake('drivesnap');
    }

    protected function actor(string $role = 'manager'): User
    {
        return User::create(['name' => 'Package test', 'email' => uniqid('test-').'@example.test', 'role' => $role, 'is_active' => true]);
    }
}
