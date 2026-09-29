<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Fixtures;

use Gsebastiao\Auditable\Support\AuditOptions;

/**
 * Nota com os eventos por omissão (sem 'restored'), para testar que o
 * restore() continua a deixar rasto como 'updated'.
 */
class NotaSemRestored extends Nota
{
    public function getAuditOptions(): AuditOptions
    {
        return AuditOptions::defaults();
    }
}
