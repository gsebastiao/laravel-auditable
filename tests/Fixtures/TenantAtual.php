<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Fixtures;

/** Resolver de tenant "por classe" — o formato que funciona com config:cache. */
class TenantAtual
{
    public static int|string|null $valor = null;

    public function __invoke(): int|string|null
    {
        return self::$valor;
    }
}
