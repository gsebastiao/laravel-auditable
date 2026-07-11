<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Gsebastiao\Auditable\Contracts\AuditRepository;

/**
 * Persistência padrão: grava via o modelo Eloquent configurado em
 * config('auditable.model'). Como esse modelo respeita config('auditable.connection'),
 * quem usa tenancy por-database ganha isolamento de auditoria sem tocar aqui.
 *
 * Quem precisar de fila, batching ou destino externo fornece a própria
 * implementação de AuditRepository e a religa no container.
 */
final class EloquentAuditRepository implements AuditRepository
{
    public function persist(array $payload): void
    {
        $model = config('auditable.model');

        $model::query()->create($payload);
    }
}
