<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Fixtures;

use Gsebastiao\Auditable\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Model com vários casts, para testar que o diff não inventa mudanças. */
class Cliente extends Model
{
    use Auditable;

    protected $table = 'clientes';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'nascimento' => 'date',
        'situacao' => Situacao::class,
        'segredo' => 'encrypted',
    ];
}
