<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Gsebastiao\Auditable\Contracts\AuditRepository;
use Gsebastiao\Auditable\Contracts\BatchIdGenerator;
use Gsebastiao\Auditable\Contracts\ContextResolver;
use Gsebastiao\Auditable\Support\ChangeSetBuilder;
use Gsebastiao\Auditable\Support\DebugInfoCollector;
use Gsebastiao\Auditable\Support\DefaultContextResolver;
use Gsebastiao\Auditable\Support\EloquentAuditRepository;
use Gsebastiao\Auditable\Support\LabelResolver;
use Gsebastiao\Auditable\Support\UlidBatchIdGenerator;

final class AuditableServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/auditable.php', 'auditable');

        // Cada contrato tem uma implementação padrão, toda substituível pelo
        // consumidor bastando religar o binding no próprio AppServiceProvider.

        $this->app->bind(BatchIdGenerator::class, UlidBatchIdGenerator::class);

        $this->app->bind(AuditRepository::class, EloquentAuditRepository::class);

        $this->app->bind(ContextResolver::class, function ($app) {
            return new DefaultContextResolver(
                auth: $app->make(AuthFactory::class),
                tenantResolver: config('auditable.tenant.resolver'),
                guard: config('auditable.auth_guard'),
            );
        });

        $this->app->bind(LabelResolver::class, function ($app) {
            $connection = $app->make(DatabaseManager::class)
                ->connection(config('auditable.connection'));

            return new LabelResolver($connection);
        });

        $this->app->bind(ChangeSetBuilder::class, function ($app) {
            return new ChangeSetBuilder($app->make(LabelResolver::class));
        });

        $this->app->bind(DebugInfoCollector::class, function ($app) {
            return new DebugInfoCollector(
                db: $app->make(DatabaseManager::class),
                request: $app->bound('request') ? $app->make('request') : null,
            );
        });

        // O AuditManager guarda o batch corrente; scoped garante uma instância
        // por requisição/job, isolando batches entre operações concorrentes.
        $this->app->scoped(AuditManager::class, function ($app) {
            return new AuditManager(
                repository: $app->make(AuditRepository::class),
                context: $app->make(ContextResolver::class),
                batchIds: $app->make(BatchIdGenerator::class),
                changes: $app->make(ChangeSetBuilder::class),
                debug: $app->make(DebugInfoCollector::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/auditable.php' => config_path('auditable.php'),
            ], 'auditable-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_audits_table.php.stub'
                    => $this->migrationPath('create_audits_table'),
            ], 'auditable-migrations');
        }
    }

    private function migrationPath(string $name): string
    {
        return database_path('migrations/'.date('Y_m_d_His').'_'.$name.'.php');
    }
}
