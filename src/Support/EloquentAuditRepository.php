<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Gsebastiao\Auditable\Contracts\BulkAuditRepository;

/**
 * Gravação padrão: usa o model de config('auditable.model') — e portanto a
 * tabela e a conexão configuradas.
 *
 * Os timestamps automáticos do Eloquent ficam desligados nestas gravações:
 * created_at/updated_at já vêm prontos no $payload (inclusive quando o dev
 * os força em audit(createdAt: ...)).
 */
final class EloquentAuditRepository implements BulkAuditRepository
{
    /**
     * Linhas por INSERT. Com ~10 colunas por linha fica abaixo do limite de
     * parâmetros de todos os bancos (o do SQL Server é 2100).
     */
    private const INSERT_CHUNK = 100;

    public function persist(array $payload): int|string|null
    {
        $model = config('auditable.model');

        $audit = new $model();
        $audit->timestamps = false;
        $audit->forceFill($payload)->save();

        return $audit->getKey();
    }

    /**
     * Grava em bloco (auditedUpdate()/auditedDelete()). Cada linha passa pelos
     * casts do model — o JSON e as datas saem iguais aos de persist() —, mas
     * não pelos seus eventos (creating/created), como em qualquer insert em massa.
     */
    public function persistMany(array $payloads): void
    {
        if ($payloads === []) {
            return;
        }

        $model = config('auditable.model');
        $template = new $model();

        $rows = array_map(
            static fn (array $payload) => (new $model())->forceFill($payload)->getAttributes(),
            $payloads,
        );

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $template->getConnection()->table($template->getTable())->insert($chunk);
        }
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
