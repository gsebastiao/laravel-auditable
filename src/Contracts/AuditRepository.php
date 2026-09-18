<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Contracts;

/**
 * ONDE a auditoria é gravada. O pacote monta a entrada e entrega aqui.
 *
 * A implementação padrão grava na tabela de auditoria via Eloquent. Troque
 * por outra (fila, Elasticsearch, serviço externo...) no seu AppServiceProvider:
 *
 *   $this->app->bind(AuditRepository::class, MinhaAuditoriaNaFila::class);
 *
 * O $payload tem as chaves: batch, subject_type, subject_id, event, changes,
 * debug_info, created_by, created_at, updated_at (e a coluna de tenant, se
 * ligada). created_at/updated_at já vêm prontos ('Y-m-d H:i:s'): grave-os
 * como estão, sem deixar o ORM trocá-los por "agora".
 */
interface AuditRepository
{
    /**
     * Grava uma linha nova e devolve o id dela — ou null, se o destino não
     * tiver ids (fila, log externo). Com null, $model->audit() passa a inserir
     * sempre uma linha nova em vez de substituir a automática.
     *
     * @param  array<string, mixed>  $payload
     */
    public function persist(array $payload): int|string|null;

    /**
     * Substitui, pelo id, uma linha que ESTA requisição gravou há instantes
     * (é assim que $model->audit() personaliza a entrada automática sem
     * duplicar). Se a linha não existir mais, grava uma nova.
     *
     * @param  array<string, mixed>  $payload
     */
    public function replace(int|string $id, array $payload): void;

    /**
     * Remove, pelo id, uma linha que ESTA requisição gravou há instantes
     * (quando $model->audit() conclui que não há nada a registrar).
     * Não deve falhar se a linha já não existir.
     */
    public function forget(int|string $id): void;
}
