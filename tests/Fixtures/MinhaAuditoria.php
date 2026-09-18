<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Fixtures;

use Gsebastiao\Auditable\Models\Audit;

class MinhaAuditoria extends Audit
{
    protected $table = 'minha_auditoria';
}
