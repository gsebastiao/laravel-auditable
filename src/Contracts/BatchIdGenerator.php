<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Contracts;

/**
 * Gera o id que agrupa as auditorias de uma mesma operação (batch).
 *
 * O padrão é um ULID (26 caracteres, ordenável por tempo, sem consultar o
 * banco). Se trocar por outro formato, confirme que cabe na coluna `batch`
 * da migration (char(26) no padrão).
 */
interface BatchIdGenerator
{
    public function generate(): string;
}
