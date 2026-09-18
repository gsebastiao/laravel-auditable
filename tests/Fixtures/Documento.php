<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Fixtures;

use Gsebastiao\Auditable\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Documento extends Model
{
    use BelongsToTenant;

    protected $table = 'documentos';

    protected $guarded = [];

    public $timestamps = false;
}
