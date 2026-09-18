<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Audit as AuditFacade;
use Gsebastiao\Auditable\Models\Audit;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FailureTest extends TestCase
{
    private function falhaDeBanco(): QueryException
    {
        try {
            DB::table('produtos')->insert(['nome' => 'x', 'coluna_que_nao_existe' => '123.456.789-00']);
        } catch (QueryException $e) {
            return $e;
        }

        $this->fail('a query deveria ter falhado');
    }

    public function test_changes_da_falha_so_tem_uma_mensagem_segura(): void
    {
        $produto = Produto::create(['nome' => 'Caneta', 'preco' => 100]);
        $produto->auditFailure('sincronizar', $this->falhaDeBanco());

        $falha = Audit::action('sincronizar')->first();

        $this->assertSame(['message' => 'A operação falhou.'], $falha->changes);
    }

    public function test_mensagem_propria_para_o_usuario(): void
    {
        $produto = Produto::create(['nome' => 'Caneta', 'preco' => 100]);
        $produto->auditFailure('pagamento', new RuntimeException('timeout gateway'), message: 'Pagamento recusado.');

        $this->assertSame(['message' => 'Pagamento recusado.'], Audit::action('pagamento')->first()->changes);
    }

    public function test_debug_info_nao_guarda_os_valores_da_query(): void
    {
        $produto = Produto::create(['nome' => 'Caneta', 'preco' => 100]);
        $produto->auditFailure('sincronizar', $this->falhaDeBanco(), ['tentativa' => 2]);

        $debug = Audit::action('sincronizar')->first()->debug_info;

        $this->assertStringContainsString('?', $debug['error']['sql']);
        $this->assertSame('testing', $debug['error']['connection']);
        $this->assertSame(['tentativa' => 2], $debug['context']);
        $this->assertFalse(str_contains(json_encode($debug), '123.456.789-00'), 'o valor enviado na query não pode ser gravado');
    }

    public function test_failures_devolve_so_as_falhas(): void
    {
        $produto = Produto::create(['nome' => 'Caneta', 'preco' => 100]);
        $produto->auditFailure('pagamento', new RuntimeException('x'));
        Produto::create(['nome' => 'Lápis', 'preco' => 10])->delete(); // tem debug_info (retrato), não é falha

        $this->assertSame(['pagamento'], Audit::failures()->pluck('event')->all());
        $this->assertSame(['pagamento'], AuditFacade::failures()->pluck('event')->all());
        $this->assertTrue(Audit::action('pagamento')->first()->isFailure());
        $this->assertFalse(Audit::action('deleted')->first()->isFailure());
    }
}
