<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Audit;
use Gsebastiao\Auditable\Models\Audit as AuditModel;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Auditoria gravada num banco diferente do banco da aplicação. */
final class ConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.auditoria' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'auditable.connection' => 'auditoria',
        ]);

        (require __DIR__.'/../../database/migrations/create_audits_table.php.stub')->up();
        $this->app->forgetScopedInstances();
    }

    public function test_as_auditorias_vao_para_a_conexao_configurada(): void
    {
        Produto::create(['nome' => 'Mesa', 'preco' => 1]);

        $this->assertSame(1, DB::connection('auditoria')->table('audit_table')->count());
        $this->assertSame(0, DB::connection('testing')->table('audit_table')->count());
    }

    public function test_transaction_desfaz_os_dados_e_a_auditoria(): void
    {
        try {
            Audit::transaction(function () {
                Produto::create(['nome' => 'Mesa', 'preco' => 1]);

                throw new RuntimeException('falhou no meio');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Produto::count());
        $this->assertSame(0, AuditModel::count());
    }

    public function test_resolve_map_consulta_o_banco_do_model(): void
    {
        DB::table('status')->insert(['id' => 1, 'nome' => 'Ativo']);

        Produto::create(['nome' => 'Mesa', 'preco' => 1, 'status_id' => 1]);

        $this->assertSame(['id' => 1, 'label' => 'Ativo'], AuditModel::first()->changes['Status']);
    }
}
