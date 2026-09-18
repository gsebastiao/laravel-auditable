<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Fixtures;

enum Situacao: string
{
    case Ativo = 'ativo';
    case Inativo = 'inativo';
}
