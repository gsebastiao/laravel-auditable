<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Models\Audit;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\Fixtures\ProdutoSemCreated;
use Gsebastiao\Auditable\Tests\TestCase;

/** As regras de audit() e auditAction() descritas no README. */
final class ManualAuditRulesTest extends TestCase
{
    public function test_audit_com_evento_proprio_renomeia_a_entrada_automatica_sem_duplicar(): void
    {
        $produto = Produto::create(['nome' => 'Caneta', 'preco' => 100]);
        $produto->audit(event: 'importado');

        $this->assertSame(1, Audit::count());
        $this->assertSame('importado', Audit::first()->event);
        $this->assertSame('Caneta', Audit::first()->changes['nome'], 'o retrato automático do created foi mantido');
    }

    public function test_audit_personaliza_a_ultima_escrita_do_objeto(): void
    {
        $produto = Produto::create(['nome' => 'Caneta', 'preco' => 100]);
        $produto->update(['preco' => 120]);
        $produto->audit(debugInfo: ['origem' => 'painel']);

        $this->assertSame(2, Audit::count());
        $this->assertNull(Audit::action('created')->first()->debug_info);
        $this->assertSame(['origem' => 'painel'], Audit::action('updated')->first()->debug_info);
    }

    public function test_audit_action_sempre_acrescenta_uma_linha(): void
    {
        $produto = Produto::create(['nome' => 'Caneta', 'preco' => 100]);
        $produto->auditAction('aprovado', ['por' => 'gerente']);
        $produto->auditAction('aprovado');

        $this->assertSame(1, Audit::action('created')->count());
        $this->assertSame(2, Audit::action('aprovado')->count());
    }

    public function test_audit_nunca_substitui_a_linha_de_outro_registro(): void
    {
        // Antes (spl_object_id), um objeto novo podia herdar o id de um objeto
        // já destruído e audit() apagava a linha de outro registro.
        for ($i = 1; $i <= 30; $i++) {
            Produto::create(['nome' => "A{$i}", 'preco' => $i]);
            ProdutoSemCreated::create(['nome' => "B{$i}", 'preco' => $i])->audit(changes: ['origem' => 'import']);
        }

        $this->assertSame(30, Audit::where('subject_type', (new Produto())->getMorphClass())->count());
        $this->assertSame(30, Audit::where('subject_type', (new ProdutoSemCreated())->getMorphClass())->count());
    }
}
