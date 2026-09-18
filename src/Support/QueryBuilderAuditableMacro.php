<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Illuminate\Database\Query\Builder;

/**
 * Registra ->audit(...) no Query Builder (DB::table(...)):
 *
 *   DB::table('pedidos')->audit(Pedido::class, $id, 'updated')
 *       ->changes(['status_id' => ['old' => 2, 'new' => 5]])
 *       ->save();
 *
 * É equivalente a Audit::for(...); a única diferença é que os labels do
 * resolveMap são consultados na mesma conexão do DB::table() usado.
 * A tabela e os where() do builder NÃO são usados — quem identifica o
 * registro auditado são subjectType e subjectId.
 */
final class QueryBuilderAuditableMacro
{
    public static function register(): void
    {
        if (Builder::hasMacro('audit')) {
            return;
        }

        Builder::macro('audit', function (string $subjectType, string|int $subjectId, string $event): QueryBuilderPendingAudit {
            /** @var Builder $this */
            return QueryBuilderPendingAudit::make($subjectType, $subjectId, $event, $this->getConnection()->getName());
        });
    }
}
