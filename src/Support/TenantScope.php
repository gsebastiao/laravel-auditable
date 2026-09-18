<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Gsebastiao\Auditable\Contracts\ContextResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtro global "WHERE tenant_id = <tenant atual>".
 *
 * Usado pelo trait BelongsToTenant (nos seus models) e pelo model de
 * auditoria quando config('auditable.tenant.enabled') está ligado.
 *
 * É uma classe com nome (e não uma classe anónima) para que
 * Model::withoutTenantScope() consiga removê-la.
 *
 * Sem tenant identificado (ex.: um comando de console no contexto central):
 *   - padrão: não filtra nada (vê todos os tenants);
 *   - com config('auditable.tenant.strict') = true: não devolve nenhuma linha.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(ContextResolver::class)->tenantId();

        if ($tenantId === null) {
            if (config('auditable.tenant.strict', false)) {
                $builder->whereRaw('1 = 0');
            }

            return;
        }

        $column = method_exists($model, 'tenantColumn')
            ? $model->tenantColumn()
            : (string) config('auditable.tenant.column', 'tenant_id');

        $builder->where($model->qualifyColumn($column), $tenantId);
    }
}
