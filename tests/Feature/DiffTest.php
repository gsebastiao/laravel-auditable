<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Models\Audit;
use Gsebastiao\Auditable\Support\ChangeSetBuilder;
use Gsebastiao\Auditable\Tests\Fixtures\Cliente;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\Fixtures\ProdutoComTimestamps;
use Gsebastiao\Auditable\Tests\Fixtures\Situacao;
use Gsebastiao\Auditable\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** O que vai para `changes` em cada evento automático. */
final class DiffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Model::encryptUsing(new Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
    }

    private function novoCliente(): Cliente
    {
        return Cliente::create([
            'nome' => 'Ana',
            'meta' => ['vip' => true],
            'nascimento' => '1990-05-10',
            'situacao' => Situacao::Ativo,
            'segredo' => 'PIN-1234',
        ]);
    }

    public function test_update_registra_so_o_que_mudou_mesmo_com_casts(): void
    {
        $cliente = $this->novoCliente();
        $cliente->update(['nome' => 'Ana Maria']);

        $this->assertSame(
            ['nome' => ['old' => 'Ana', 'new' => 'Ana Maria']],
            Audit::action('updated')->first()->changes,
        );
    }

    public function test_campo_criptografado_nunca_vai_em_texto_claro(): void
    {
        $cliente = $this->novoCliente();
        $cliente->update(['segredo' => 'PIN-9999']);

        $created = Audit::action('created')->first();
        $updated = Audit::action('updated')->first();

        $this->assertSame(ChangeSetBuilder::MASK, $created->changes['segredo']);
        $this->assertSame(['old' => ChangeSetBuilder::MASK, 'new' => ChangeSetBuilder::MASK], $updated->changes['segredo']);

        $tudo = json_encode(Audit::all()->toArray());
        $this->assertFalse(str_contains($tudo, 'PIN-1234'), 'o valor antigo não pode aparecer na auditoria');
        $this->assertFalse(str_contains($tudo, 'PIN-9999'), 'o valor novo não pode aparecer na auditoria');
    }

    public function test_campo_json_aparece_decodificado(): void
    {
        $cliente = $this->novoCliente();
        $cliente->update(['meta' => ['vip' => false]]);

        $this->assertSame(['vip' => true], Audit::action('created')->first()->changes['meta']);
        $this->assertSame(
            ['old' => ['vip' => true], 'new' => ['vip' => false]],
            Audit::action('updated')->first()->changes['meta'],
        );
    }

    public function test_data_lida_do_banco_nao_aparece_como_alterada(): void
    {
        // Como o MySQL/PostgreSQL devolvem uma coluna DATE: "1990-05-10".
        DB::table('clientes')->insert(['id' => 1, 'nome' => 'Ana', 'nascimento' => '1990-05-10']);

        Cliente::find(1)->update(['nome' => 'Ana Maria']);

        $this->assertSame(['nome'], array_keys(Audit::action('updated')->first()->changes));
    }

    public function test_timestamps_do_model_ficam_fora_do_log_por_padrao(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $produto = Produto::create(['nome' => 'Mesa', 'preco' => 300]);
        Carbon::setTestNow('2026-01-01 10:05:00');
        $produto->update(['preco' => 350]);
        Carbon::setTestNow();

        $this->assertArrayNotHasKey('created_at', Audit::action('created')->first()->changes);
        $this->assertSame(['preco' => ['old' => 300, 'new' => 350]], Audit::action('updated')->first()->changes);
    }

    public function test_log_timestamps_inclui_created_at_e_updated_at(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $produto = ProdutoComTimestamps::create(['nome' => 'Mesa', 'preco' => 300]);
        Carbon::setTestNow('2026-01-01 10:05:00');
        $produto->update(['preco' => 350]);
        Carbon::setTestNow();

        $this->assertSame(
            ['old' => '2026-01-01 10:00:00', 'new' => '2026-01-01 10:05:00'],
            Audit::action('updated')->first()->changes['updated_at'],
        );
    }

    public function test_update_sem_mudanca_real_nao_grava_nada(): void
    {
        $produto = Produto::create(['nome' => 'Mesa', 'preco' => 300]);
        $produto->update(['preco' => 300]);

        $this->assertSame(0, Audit::action('updated')->count());
    }
}
