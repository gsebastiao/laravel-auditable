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

        // A migration corre com `php artisan migrate`, mesmo sem ser publicada.
        // Fica FORA do runningInConsole(): quem dispara o migrate a partir de
        // HTTP (Artisan::call('migrate') num instalador, num webhook de deploy
        // ou nos testes do projeto) tem de a ver na mesma.
        //
        // Se o projeto publicar a migration, o ficheiro mantém o mesmo nome; o
        // Laravel indexa as migrations pelo nome, por isso a cópia do projeto
        // substitui a do pacote e a tabela nunca é criada duas vezes.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/auditable.php' => config_path('auditable.php'),
        ], 'auditable-config');

        // Publicar é opcional: serve para quem quer editá-la (ex.: ids UUID).
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
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
}
