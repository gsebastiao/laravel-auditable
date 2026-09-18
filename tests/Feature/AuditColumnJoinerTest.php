<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Support\AuditColumnJoiner;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AuditColumnJoinerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Maria da Silva Pereira'],
            ['id' => 2, 'name' => 'João Santos'],
        ]);

        Carbon::setTestNow('2026-07-10 14:30:00');
        config(['auditable.default_created_by' => 1]);
        $mesa = Produto::create(['nome' => 'Mesa', 'preco' => 300]);
        Produto::create(['nome' => 'Cadeira', 'preco' => 80]);

        $this->app->forgetScopedInstances();
        config(['auditable.default_created_by' => 2]);
        Carbon::setTestNow('2026-07-11 09:00:00');
        $mesa->update(['preco' => 320]);
        Carbon::setTestNow();
    }

    public function test_acrescenta_as_colunas_sem_perder_as_do_model(): void
    {
        $query = Produto::query()->orderBy('id');
        AuditColumnJoiner::apply($query, Produto::class);

        $mesa = $query->first();

        $this->assertSame('Mesa', $mesa->nome);
        $this->assertSame(320, (int) $mesa->preco);
        $this->assertSame('Maria Pereira', $mesa->audit_created_by);
        $this->assertSame('10/07/2026 14:30:00', $mesa->audit_created_at);
        $this->assertSame('João Santos', $mesa->audit_updated_by);
        $this->assertSame('11/07/2026 09:00:00', $mesa->audit_updated_at);
    }

    public function test_registro_nunca_alterado_fica_com_updated_vazio(): void
    {
        $query = Produto::query()->where('nome', 'Cadeira');
        AuditColumnJoiner::apply($query, Produto::class);

        $cadeira = $query->first();

        $this->assertSame('Maria Pereira', $cadeira->audit_created_by);
        $this->assertNull($cadeira->audit_updated_by);
    }

    public function test_os_models_nao_ficam_marcados_como_alterados(): void
    {
        $query = Produto::query();
        AuditColumnJoiner::apply($query, Produto::class);

        foreach ($query->get() as $produto) {
            $this->assertFalse($produto->isDirty());
        }
    }

    public function test_funciona_com_db_table_e_colunas_sem_prefixo_de_tabela(): void
    {
        $query = DB::table('produtos')->where('preco', '>', 100)->orderBy('id');
        AuditColumnJoiner::apply($query, Produto::class, ['created'], dateFormat: 'd/m/Y');

        $linhas = $query->get();

        $this->assertCount(1, $linhas);
        $this->assertSame('Mesa', $linhas[0]->nome);
        $this->assertSame('10/07/2026', $linhas[0]->audit_created_at);
    }
}
