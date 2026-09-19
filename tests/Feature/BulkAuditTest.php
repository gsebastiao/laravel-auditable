<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Audit as AuditFacade;
use Gsebastiao\Auditable\Models\Audit;
use Gsebastiao\Auditable\Tests\Fixtures\Cliente;
use Gsebastiao\Auditable\Tests\Fixtures\Documento;
use Gsebastiao\Auditable\Tests\Fixtures\Nota;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\Fixtures\Situacao;
use Gsebastiao\Auditable\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Model::where(...)->auditedUpdate() / ->auditedDelete(). */
final class BulkAuditTest extends TestCase
{
    /** Produtos criados SEM auditoria (como dados que já existiam). */
    private function produtos(int $quantidade): void
    {
        DB::table('status')->insert([['id' => 1, 'nome' => 'Ativo'], ['id' => 2, 'nome' => 'Inativo']]);

        for ($i = 1; $i <= $quantidade; $i++) {
            DB::table('produtos')->insert(['id' => $i, 'nome' => "P{$i}", 'preco' => $i * 10, 'status_id' => 1]);
        }
    }

    /** @return array<int, int> */
    private function idsAuditados(string $evento): array
    {
        return Audit::action($evento)->orderBy('subject_id')->pluck('subject_id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_uma_auditoria_por_registro_todas_na_mesma_operacao(): void
    {
        $this->produtos(5);

        $afetados = Produto::where('preco', '<=', 30)->auditedUpdate(['status_id' => 2]);

        $this->assertSame(3, $afetados);
        $this->assertSame([1, 2, 3], $this->idsAuditados('updated'));
        $this->assertSame(1, Audit::query()->distinct()->count('batch'));
        $this->assertSame(
            ['Status' => ['old' => ['id' => 1, 'label' => 'Ativo'], 'new' => ['id' => 2, 'label' => 'Inativo']]],
            Audit::action('updated')->first()->changes,
        );
    }

    public function test_lotes_pequenos_com_filtro_que_deixa_de_bater(): void
    {
        $this->produtos(7);

        Produto::where('preco', '<', 1000)->auditedUpdate(['preco' => 1000], chunkSize: 2);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->idsAuditados('updated'));
        $this->assertSame(0, DB::table('produtos')->where('preco', '<', 1000)->count());
    }

    public function test_valor_calculado_pelo_banco(): void
    {
        $this->produtos(2);

        Produto::query()->auditedUpdate(['preco' => DB::raw('preco + 5')]);

        $this->assertSame(['preco' => ['old' => 10, 'new' => 15]], Audit::forRecord(Produto::class, 1)->first()->changes);
    }

    public function test_quem_ja_tinha_o_valor_nao_ganha_auditoria(): void
    {
        $this->produtos(3);
        DB::table('produtos')->where('id', 2)->update(['status_id' => 2]);

        Produto::query()->auditedUpdate(['status_id' => 2]);

        $this->assertSame([1, 3], $this->idsAuditados('updated'));
    }

    public function test_labels_numa_consulta_e_auditorias_num_insert_por_lote(): void
    {
        $this->produtos(6);
        $consultasAoStatus = 0;
        $insertsDeAuditoria = 0;
        DB::listen(function ($query) use (&$consultasAoStatus, &$insertsDeAuditoria) {
            $consultasAoStatus += (int) str_contains($query->sql, 'from "status"');
            $insertsDeAuditoria += (int) str_starts_with($query->sql, 'insert into "audit_table"');
        });

        Produto::query()->auditedUpdate(['status_id' => 2], chunkSize: 3);

        $this->assertSame(1, $consultasAoStatus, 'os dois status vêm numa consulta; o segundo lote usa o cache');
        $this->assertSame(2, $insertsDeAuditoria, 'um INSERT por lote');
        $this->assertSame(6, Audit::action('updated')->count());
    }

    public function test_delete_em_massa_guarda_retrato_e_permite_restaurar(): void
    {
        $this->produtos(3);

        $apagados = Produto::where('preco', '>=', 20)->auditedDelete();

        $this->assertSame(2, $apagados);
        $this->assertSame([2, 3], $this->idsAuditados('deleted'));
        $audit = Audit::forRecord(Produto::class, 3)->first();
        $this->assertSame('P3', $audit->changes['nome']);

        $audit->restore();
        $this->assertSame('P3', Produto::find(3)->nome);
    }

    public function test_delete_em_massa_com_soft_deletes_vai_para_a_lixeira(): void
    {
        Nota::create(['texto' => 'A']);
        Nota::create(['texto' => 'B']);

        Nota::query()->auditedDelete();

        $this->assertSame(0, Nota::count());
        $this->assertSame(2, Nota::withTrashed()->count());
        $this->assertNotNull(Audit::action('deleted')->first()->changes['deleted_at']);
    }

    public function test_dentro_de_uma_operacao_usa_o_batch_dela(): void
    {
        $this->produtos(2);

        AuditFacade::transaction(function () {
            Produto::create(['nome' => 'Novo', 'preco' => 1]);
            Produto::where('id', '<=', 2)->auditedUpdate(['preco' => 99]);
        });

        $this->assertSame(3, Audit::count());
        $this->assertSame(1, Audit::query()->distinct()->count('batch'));
    }

    public function test_sem_auditoria_faz_so_o_update(): void
    {
        $this->produtos(2);

        AuditFacade::withoutAuditing(fn () => Produto::query()->auditedUpdate(['preco' => 1]));

        $this->assertSame(0, Audit::count());
        $this->assertSame(2, DB::table('produtos')->where('preco', 1)->count());
    }

    public function test_model_sem_o_trait_da_um_erro_claro(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('use Auditable;');

        Documento::query()->auditedUpdate(['titulo' => 'x']);
    }

    public function test_limit_nao_e_aceito(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('limit()');

        Produto::query()->limit(2)->auditedUpdate(['preco' => 1]);
    }

    public function test_casts_nao_geram_falsas_alteracoes_e_segredos_ficam_mascarados(): void
    {
        Model::encryptUsing(new Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
        AuditFacade::withoutAuditing(fn () => Cliente::create([
            'nome' => 'Ana', 'meta' => ['vip' => true], 'nascimento' => '1990-05-10',
            'situacao' => Situacao::Ativo, 'segredo' => 'PIN-1234',
        ]));

        Cliente::query()->auditedUpdate(['nome' => 'Ana Maria']);

        $this->assertSame(['nome' => ['old' => 'Ana', 'new' => 'Ana Maria']], Audit::action('updated')->first()->changes);
        $this->assertFalse(str_contains(json_encode(Audit::all()->toArray()), 'PIN-1234'));
    }

    public function test_chave_dentro_de_coluna_json(): void
    {
        AuditFacade::withoutAuditing(fn () => Cliente::create(['nome' => 'Ana', 'meta' => ['vip' => true, 'nivel' => 2]]));

        Cliente::query()->auditedUpdate(['meta->vip' => false]);

        $this->assertSame(
            ['meta' => ['old' => ['vip' => true, 'nivel' => 2], 'new' => ['vip' => false, 'nivel' => 2]]],
            Audit::action('updated')->first()->changes,
        );
    }
}
