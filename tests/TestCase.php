<?php

declare(strict_types=1);

namespace Andre\AiGateway\Tests;

use Andre\AiGateway\AiGatewayServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The default Laravel migrations Testbench ships give us a `users`
        // table (needed by the feature test's Sanctum user).
        $this->loadLaravelMigrations();

        // The package's own tables.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Sanctum's personal_access_tokens table. Defined inline rather than
        // relying on Sanctum's published migration so the suite is hermetic
        // under Testbench regardless of where Sanctum stores its migration.
        $this->createPersonalAccessTokensTable();
    }

    /**
     * @param  Application  $app
     * @return array<int,class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AiGatewayServiceProvider::class,
            SanctumServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('cache.default', 'array');

        $app['config']->set('ai-gateway.openrouter.api_key', 'test-key');
    }

    private function createPersonalAccessTokensTable(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function ($table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
}
