<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Concerns;

use Gsebastiao\Auditable\Contracts\ContextResolver;
use Gsebastiao\Auditable\Support\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * OPCIONAL — só para multitenancy "um banco, uma coluna tenant_id".
 *
 * Coloque nos SEUS models que pertencem a um tenant:
 *
 *   class Produto extends Model
 *   {
 *       use Auditable, BelongsToTenant;
 *   }
 *
 * O que faz:
 *   1. Toda consulta ganha "WHERE produtos.tenant_id = <tenant atual>".
 *   2. Ao criar, preenche tenant_id sozinho (se você não tiver preenchido).
 *
 * Quem é o tenant atual vem de config('auditable.tenant.resolver') ou de
 * Audit::resolveTenantUsing(...). O pacote só LÊ esse valor, nunca o define.
 *
 * Não use se cada tenant tem o seu próprio banco (stancl/tenancy,
 * spatie/laravel-multitenancy multi-banco): aí o isolamento já vem da conexão.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Model $model): void {
            $column = $model->tenantColumn();

            if ($model->getAttribute($column) !== null) {
                return;
            }

            $tenantId = app(ContextResolver::class)->tenantId();

            if ($tenantId !== null) {
                $model->setAttribute($column, $tenantId);
            }
        });
    }

    /** Nome da coluna de tenant. Sobrescreva no model se for diferente. */
    public function tenantColumn(): string
    {
        return (string) config('auditable.tenant.column', 'tenant_id');
    }

    /**
     * Consulta SEM o filtro de tenant (relatórios centrais, jobs de manutenção).
     * Uso deliberado: você está a pedir dados de todos os tenants.
     */
    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope(TenantScope::class);
    }
}
