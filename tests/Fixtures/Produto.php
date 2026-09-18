<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Gsebastiao\Auditable\Concerns\Auditable;
use Gsebastiao\Auditable\Support\AuditOptions;
use Gsebastiao\Auditable\Support\ResolveMap;

class Produto extends Model
{
    use Auditable;

    protected $table = 'produtos';

    protected $guarded = [];

    public function getAuditOptions(): AuditOptions
    {
        return AuditOptions::defaults()
            ->resolveMap([
                'status_id' => ResolveMap::direct(label: 'Status', table: 'status', column: 'nome'),
            ]);
    }
}
