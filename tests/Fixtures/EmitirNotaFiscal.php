<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Uma job comum: não sabe nada de auditoria. */
class EmitirNotaFiscal implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public int $produtoId) {}

    public function handle(): void
    {
        Produto::findOrFail($this->produtoId)->auditAction('nota_fiscal_emitida');
    }
}
