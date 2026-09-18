<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Fixtures;

use Gsebastiao\Auditable\Concerns\Auditable;
use Gsebastiao\Auditable\Support\AuditOptions;
use Illuminate\Database\Eloquent\Model;

class ProdutoComTimestamps extends Model
{
    use Auditable;

    protected $table = 'produtos';

    protected $guarded = [];

    public function getAuditOptions(): AuditOptions
    {
        return AuditOptions::defaults()->logTimestamps();
    }
}
