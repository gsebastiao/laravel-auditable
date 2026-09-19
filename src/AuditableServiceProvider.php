<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable;

use Gsebastiao\Auditable\Console\Commands\PublishAuditTableJs;
use Gsebastiao\Auditable\Contracts\AuditRepository;
use Gsebastiao\Auditable\Contracts\BatchIdGenerator;
use Gsebastiao\Auditable\Contracts\ContextResolver;
use Gsebastiao\Auditable\Support\BulkAudit;
use Gsebastiao\Auditable\Support\ChangeSetBuilder;
use Gsebastiao\Auditable\Support\DebugInfoCollector;
use Gsebastiao\Auditable\Support\DefaultContextResolver;
use Gsebastiao\Auditable\Support\EloquentAuditRepository;
use Gsebastiao\Auditable\Support\LabelResolver;
use Gsebastiao\Auditable\Support\QueryBuilderAuditableMacro;
use Gsebastiao\Auditable\Support\QueueContext;
use Gsebastiao\Auditable\Support\UlidBatchIdGenerator;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\ServiceProvider;

final class AuditableServiceProvider extends ServiceProvider
{
    private const MIGRATION = '2026_01_01_000000_create_audits_table.php';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/auditable.php', 'auditable');

        // Cada peça tem uma implementação padrão; troque religando o binding
        // no seu AppServiceProvider (veja "Personalização avançada" no README).
        $this->app->bind(BatchIdGenerator::class, UlidBatchIdGenerator::class);
        $this->app->bind(AuditRepository::class, EloquentAuditRepository::class);

        $this->app->bind(ContextResolver::class, fn ($app) => new DefaultContextResolver(
            auth: $app->make(AuthFactory::class),
            guard: config('auditable.auth_guard'),
            tenantResolver: config('auditable.tenant.resolver'),
            defaultUserId: config('auditable.default_created_by'),
        ));

        // O LabelResolver recebe o gerenciador de conexões (e não uma conexão
        // fixa) para consultar cada tabela no banco do model auditado.
        $this->app->bind(LabelResolver::class, fn ($app) => new LabelResolver($app->make(DatabaseManager::class)));

        $this->app->bind(ChangeSetBuilder::class, fn ($app) => new ChangeSetBuilder($app->make(LabelResolver::class)));

        $this->app->bind(DebugInfoCollector::class, fn ($app) => new DebugInfoCollector(
            db: $app->make(DatabaseManager::class),
            request: $app->bound('request') ? $app->make('request') : null,
        ));

        // Uma instância por requisição/job: o batch aberto e o rastreio de
        // audit() nunca se misturam entre usuários.
        $this->app->scoped(AuditManager::class, fn ($app) => new AuditManager(
            repository: $app->make(AuditRepository::class),
            context: $app->make(ContextResolver::class),
            batchIds: $app->make(BatchIdGenerator::class),
            changes: $app->make(ChangeSetBuilder::class),
            debug: $app->make(DebugInfoCollector::class),
        ));
    }

    public function boot(): void
    {
        // DB::table(...)->audit(...) e Model::where(...)->auditedUpdate()/auditedDelete().
        QueryBuilderAuditableMacro::register();
        BulkAudit::register();

        // Jobs despachadas levam o batch, o usuário (e, se ligado, o tenant)
        // de quem as despachou; valem só enquanto a job corre.
        Queue::createPayloadUsing(fn () => QueueContext::payload());

        $events = $this->app['events'];
        $events->listen(JobProcessing::class, fn (JobProcessing $event) => QueueContext::jobStarted($event->job));
        $events->listen(
            [JobProcessed::class, JobFailed::class, JobExceptionOccurred::class],
            fn (JobProcessed|JobFailed|JobExceptionOccurred $event) => QueueContext::jobFinished($event->job),
        );

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/auditable.php' => config_path('auditable.php'),
        ], 'auditable-config');

        // A migration corre com `php artisan migrate`, mesmo sem ser publicada.
        // Publicar é opcional: serve para quem quer editá-la (ex.: ids UUID).
        $this->loadUnpublishedMigrations();

        $this->publishes([
            __DIR__.'/../database/migrations/'.self::MIGRATION => $this->migrationPath(self::MIGRATION),
        ], 'auditable-migrations');

        // Widget JS opcional. O destino respeita config('auditable.js.publish_path');
        // o comando auditable:publish-js faz o mesmo e aceita --path.
        $this->publishes([
            __DIR__.'/plugin/audit-table.init.js' => public_path(
                trim((string) config('auditable.js.publish_path', 'assets/js'), '/').'/audit-table.init.js'
            ),
        ], 'auditable-js');

        $this->commands([PublishAuditTableJs::class]);
    }

    /**
     * Carrega a migration do pacote, a não ser que o projeto já tenha uma
     * cópia publicada (com este nome ou com outro timestamp): nesse caso
     * vale a cópia do projeto, e a tabela nunca é criada duas vezes.
     */
    private function loadUnpublishedMigrations(): void
    {
        if ($this->publishedMigration(self::MIGRATION) === null) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations/'.self::MIGRATION);
        }
    }

    /** A cópia já publicada no projeto (qualquer timestamp), ou null. */
    private function publishedMigration(string $file): ?string
    {
        $name = substr($file, strlen('2026_01_01_000000_'));

        return (glob(database_path("migrations/*_{$name}")) ?: [])[0] ?? null;
    }

    /**
     * Destino da publicação: o mesmo nome do arquivo do pacote (assim o
     * Laravel o reconhece como a mesma migration), ou a cópia que o projeto
     * já tem — publicar de novo nunca cria uma segunda migration.
     */
    private function migrationPath(string $file): string
    {
        return $this->publishedMigration($file) ?? database_path('migrations/'.$file);
    }
}
