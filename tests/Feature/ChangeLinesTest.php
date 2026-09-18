<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Models\Audit;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\TestCase;
use Illuminate\Support\Facades\DB;

final class ChangeLinesTest extends TestCase
{
    public function test_texto_pronto_para_mostrar(): void
    {
        DB::table('status')->insert([['id' => 1, 'nome' => 'Ativo'], ['id' => 3, 'nome' => 'Bloqueado']]);

        $produto = Produto::create(['nome' => 'Café', 'preco' => 20, 'status_id' => 1]);
        $produto->update(['preco' => 25, 'status_id' => 3]);
        $produto->auditAction('revisado', ['ok' => true, 'tags' => ['a', 'b'], 'nota' => null]);

        $this->assertSame(['Nome: Café', 'Preco: 20', 'Status: Ativo'], Audit::action('created')->first()->changeLines());
        $this->assertSame(['Preco: 20 → 25', 'Status: Ativo → Bloqueado'], Audit::action('updated')->first()->changeLines());
        $this->assertSame(['Ok: Sim', 'Tags: a, b', 'Nota: (vazio)'], Audit::action('revisado')->first()->changeLines());
    }
}
