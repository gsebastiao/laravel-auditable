<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Fixtures;

use Gsebastiao\Auditable\Concerns\Auditable;
use Gsebastiao\Auditable\Support\AuditOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Nota extends Model
{
    use Auditable, SoftDeletes;

    protected $table = 'notas';

    protected $guarded = [];

    public function getAuditOptions(): AuditOptions
    {
        return AuditOptions::defaults()->events(['created', 'updated', 'deleted', 'restored']);
    }
}
