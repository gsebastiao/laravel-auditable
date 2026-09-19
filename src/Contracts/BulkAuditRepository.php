<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Contracts;

/**
 * OPCIONAL: um AuditRepository que também sabe gravar muitas linhas de uma
 * vez. É usado por auditedUpdate()/auditedDelete(), que geram uma auditoria
 * por registro — gravar em bloco é muito mais rápido do que uma a uma.
 *
 * Repositórios que só implementam AuditRepository continuam a funcionar: o
 * pacote chama persist() linha a linha.
 */
interface BulkAuditRepository extends AuditRepository
{
    /**
     * Grava as linhas (mesmo formato de persist()). Não precisa devolver ids.
     *
     * @param  array<int, array<string, mixed>>  $payloads
     */
    public function persistMany(array $payloads): void;
}
