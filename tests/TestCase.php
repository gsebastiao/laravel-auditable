<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests;

use Gsebastiao\Auditable\Audit;
use Gsebastiao\Auditable\AuditableServiceProvider;
use Gsebastiao\Auditable\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * Base dos testes de Feature: Laravel real (via orchestra/testbench) com
 * SQLite em memória. Rode com: composer test
 */
abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [AuditableServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('auditable.enabled', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function tearDown(): void
    {
        Audit::resolveTenantUsing(null);
        Model::encryptUsing(null);

        parent::tearDown();
    }
}
