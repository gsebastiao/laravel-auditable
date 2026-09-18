<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Gsebastiao\Auditable\Contracts\AuditRepository;

/**
 * Gravação padrão: usa o model de config('auditable.model') — e portanto a
 * tabela e a conexão configuradas.
 *
 * Os timestamps automáticos do Eloquent ficam desligados nestas gravações:
 * created_at/updated_at já vêm prontos no $payload (inclusive quando o dev
 * os força em audit(createdAt: ...)).
 */
final class EloquentAuditRepository implements AuditRepository
{
    public function persist(array $payload): int|string|null
    {
        $model = config('auditable.model');

        $audit = new $model();
        $audit->timestamps = false;
        $audit->forceFill($payload)->save();

        return $audit->getKey();
    }

    public function replace(int|string $id, array $payload): void
    {
        $model = config('auditable.model');

        // Sem scopes: o id veio desta mesma requisição, é a linha certa.
        $existing = $model::query()->withoutGlobalScopes()->find($id);

        if ($existing === null) {
            $this->persist($payload);

            return;
        }

        $existing->timestamps = false;
        $existing->forceFill($payload)->save();
    }

    public function forget(int|string $id): void
    {
        $model = config('auditable.model');

        $model::query()->withoutGlobalScopes()->find($id)?->delete();
    }
}
