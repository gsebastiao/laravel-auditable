<?php

declare(strict_types=1);

use Gsebastiao\Auditable\Models\Audit;

/*
|--------------------------------------------------------------------------
| Configuração do laravel-auditable
|--------------------------------------------------------------------------
| Tudo aqui tem um valor padrão que funciona. Só mude o que precisar.
| Depois de mudar, se usa `php artisan config:cache`, rode-o de novo.
*/

return [

    /*
    | Liga/desliga a auditoria no sistema inteiro.
    | Útil em testes: AUDITABLE_ENABLED=false no .env.testing.
    | Para desligar só um trecho de código: Audit::withoutAuditing(fn () => ...).
    */
    'enabled' => env('AUDITABLE_ENABLED', true),

    /*
    | Tabela onde as auditorias são gravadas.
    | Se mudar, faça-o ANTES de rodar a migration.
    */
    'table' => 'audit_table',

    /*
    | Conexão de banco da auditoria. null = a conexão padrão da aplicação
    | (recomendado). Se apontar para outro banco:
    |   - Audit::transaction() desfaz os dois bancos juntos em caso de erro;
    |   - AuditColumnJoiner deixa de funcionar (precisa do mesmo banco).
    */
    'connection' => env('AUDITABLE_CONNECTION'),

    /*
    | Model usado para ler e gravar a tabela de auditoria. Troque só se
    | estender Gsebastiao\Auditable\Models\Audit para acrescentar algo.
    */
    'model' => Audit::class,

    /*
    |--------------------------------------------------------------------------
    | Usuários
    |--------------------------------------------------------------------------
    | auth_guard:         de que guard vem o usuário logado (null = o padrão).
    | default_created_by: id gravado quando NÃO há ninguém logado (comandos,
    |                     filas, cron, webhooks). null = fica vazio ("Sistema").
    | user_model:         model dos usuários, para $audit->user.
    |                     null = config('auth.providers.users.model').
    | users_table:        tabela dos usuários, usada pelo AuditColumnJoiner.
    */
    'auth_guard' => null,

    'default_created_by' => env('AUDITABLE_DEFAULT_CREATED_BY', env('AUDITABLE_DEFAULT_created_by')),

    'user_model' => null,

    'users_table' => 'users',

    /*
    | Prefixo das colunas criadas pelo AuditColumnJoiner nas listagens
    | (audit_created_by, audit_created_at, ...). Evita choque com as colunas
    | created_at/updated_at da sua própria tabela.
    */
    'column_prefix' => 'audit_',

    /*
    |--------------------------------------------------------------------------
    | Multitenancy por coluna (opcional)
    |--------------------------------------------------------------------------
    | Use SÓ se todos os clientes (tenants) partilham o mesmo banco e cada
    | linha tem uma coluna tenant_id. Se cada tenant tem o seu próprio banco
    | (stancl/tenancy, spatie/laravel-multitenancy), deixe desligado.
    |
    | enabled:  grava e filtra a coluna de tenant na auditoria. Ligue ANTES de
    |           rodar a migration (a coluna só é criada se estiver ligado).
    | column:   nome da coluna de tenant.
    | resolver: como descobrir o tenant atual — o nome de uma classe com
    |           __invoke(), por exemplo App\Support\TenantAtual::class.
    |           Alternativa: Audit::resolveTenantUsing(fn () => ...) no
    |           AppServiceProvider. (Não coloque uma função anónima aqui:
    |           isso impede o `php artisan config:cache`.)
    | strict:   false = sem tenant identificado, as consultas veem todos os
    |           tenants (ex.: painel central). true = não veem nada.
    */
    'tenant' => [
        'enabled' => env('AUDITABLE_TENANT_ENABLED', false),
        'column' => 'tenant_id',
        'resolver' => null,
        'strict' => false,
    ],

    /*
    | Widget JS opcional (modal de histórico). Pasta, dentro de public/, para
    | onde `php artisan auditable:publish-js` copia o arquivo.
    */
    'js' => [
        'publish_path' => 'assets/js',
    ],

    /*
    | Falhas registradas com $model->auditFailure(...).
    | include_database: grava o nome da conexão, o driver e o nome do banco no
    | debug_info. Host, porta, usuário e senha NUNCA são gravados.
    */
    'debug' => [
        'include_database' => env('AUDITABLE_DEBUG_DB', true),
    ],

];
