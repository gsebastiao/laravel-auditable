<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Gsebastiao\Auditable\Contracts\ContextResolver;

/**
 * Trait OPCIONAL para o caso de tenancy por coluna discriminadora
 * (single-database, uma coluna tenant_id por linha).
 *
 * NÃO use isto se você já usa tenancy por-database (stancl/tenancy,
 * spatie/multitenancy no modo multi-database): nesse cenário o isolamento é
 * feito pela troca de conexão e esta coluna seria redundante.
 *
 * O que faz:
 *   1. Aplica um Global Scope que filtra WHERE tenant_id = <atual> em toda
 *      query — portanto SÓ funciona porque a auditoria é Eloquent-nativa; era
 *      justamente o que o DB::table() do BaseModel original impedia.
 *   2. Preenche tenant_id automaticamente no creating.
 *
 * Quem é o tenant atual vem do ContextResolver — o pacote lê, nunca estabelece.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new class implements Scope {
            public function apply(Builder $builder, Model $model): void
            {
                $tenantId = app(ContextResolver::class)->tenantId();

                if ($tenantId !== null) {
                    $builder->where(
                        $model->getTable().'.'.$model->tenantColumn(),
                        $tenantId,
                    );
                }
            }
        });

        static::creating(function (Model $model): void {
            $column = $model->tenantColumn();

            if ($model->getAttribute($column) === null) {
                $tenantId = app(ContextResolver::class)->tenantId();

                if ($tenantId !== null) {
                    $model->setAttribute($column, $tenantId);
                }
            }
        });
    }

    /**
     * Nome da coluna de tenant. Override no model se necessário.
     */
    public function tenantColumn(): string
    {
        return config('auditable.tenant.column', 'tenant_id');
    }

    /**
     * Escape hatch: query sem o filtro de tenant (para jobs centrais,
     * relatórios cross-tenant, etc.). Uso deliberado e explícito.
     */
    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope('Gsebastiao\Auditable\Concerns\BelongsToTenant');
    }
}
