<?php

declare(strict_types=1);

use Gsebastiao\Auditable\Models\Audit;

return [

    /*
    |--------------------------------------------------------------------------
    | Ativar auditoria
    |--------------------------------------------------------------------------
    | Interruptor global. Útil para desligar em testes ou seeders.
    */
    'enabled' => env('AUDITABLE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Modelo de auditoria
    |--------------------------------------------------------------------------
    | Substituível: estenda Gsebastiao\Auditable\Models\Audit e aponte aqui
    | para customizar tabela, conexão ou relações.
    */
    'model' => Audit::class,

    /*
    |--------------------------------------------------------------------------
    | Tabela e conexão
    |--------------------------------------------------------------------------
    | 'connection' = null usa a conexão default do app. Definir uma conexão
    | específica é uma das formas de isolar auditoria por tenant no modo
    | tenancy-por-database.
    */
    'table' => 'audits',
    'connection' => env('AUDITABLE_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Tabela de usuários
    |--------------------------------------------------------------------------
    | Usada apenas pelo AuditColumnJoiner (colunas "quem/quando" em grelhas),
    | para traduzir user_id no nome/email exibível via JOIN. Não afeta a
    | gravação da auditoria — só a montagem de listagens.
    */
    'users_table' => 'users',

    /*
    |--------------------------------------------------------------------------
    | Prefixo das colunas de auditoria em grelhas
    |--------------------------------------------------------------------------
    | Usado apenas pelo AuditColumnJoiner. Prefixa TODAS as colunas emitidas
    | (audit_created_by, audit_created_at, audit_updated_by, …). O prefixo mantém
    | o par _by/_at consistente e evita colisão com as colunas nativas do Eloquent
    | created_at/updated_at/deleted_at (que têm cast automático de datetime).
    | String vazia remove o prefixo, mas reintroduz esse risco de colisão.
    */
    'column_prefix' => 'audit_',

    /*
    |--------------------------------------------------------------------------
    | Guard de autenticação
    |--------------------------------------------------------------------------
    | De qual guard extrair o usuário responsável. null = guard default.
    */
    'auth_guard' => null,

    /*
    |--------------------------------------------------------------------------
    | Multitenancy (POR COLUNA)
    |--------------------------------------------------------------------------
    | Use isto APENAS no modo single-database com uma coluna tenant_id.
    | Se você usa tenancy-por-database (stancl/tenancy, spatie/multitenancy
    | multi-db), deixe 'enabled' => false: o isolamento já vem da conexão.
    |
    | 'resolver' é como o pacote descobre o tenant atual. O pacote LÊ, nunca
    | ESTABELECE. Plugue aqui o que fizer sentido no seu app:
    |
    |   Auth:            fn () => auth()->user()?->tenant_id
    |   stancl/tenancy:  fn () => tenant()?->getTenantKey()
    |   spatie:          fn () => \Spatie\Multitenancy\Models\Tenant::current()?->id
    */
    'tenant' => [
        'enabled' => env('AUDITABLE_TENANT_ENABLED', false),
        'column' => 'tenant_id',
        'resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug de falhas
    |--------------------------------------------------------------------------
    | Controla o conteúdo do campo debug_info gravado por $model->auditFailure().
    | Esse campo é para o DESENVOLVEDOR — traz stack trace, SQL, request e
    | ambiente do erro. A mensagem amigável fica em 'changes' (essa sim o
    | usuário pode ver).
    |
    | 'include_database' adiciona driver e nome do banco ao debug. Host e
    | credenciais nunca são incluídos.
    */
    'debug' => [
        'include_database' => env('AUDITABLE_DEBUG_DB', true),
    ],

];
