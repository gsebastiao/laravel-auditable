<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Contracts;

/**
 * Gera o identificador que agrupa várias entradas de auditoria produzidas
 * pela mesma operação lógica (ex.: um update que afeta várias linhas, ou
 * uma transação que toca várias tabelas).
 *
 * A implementação padrão usa ULID — ordenável por tempo, sem colisão sob
 * concorrência e sem consultar o banco. Isto substitui o esquema
 * "uuid-0001" baseado em SELECT MAX do BaseModel original, que era uma
 * race condition e um gargalo em ambiente SaaS concorrente.
 */
interface BatchIdGenerator
{
    public function generate(): string;
}
