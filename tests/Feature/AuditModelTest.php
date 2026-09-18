<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Audit;
use Gsebastiao\Auditable\Models\Audit as AuditModel;
use Gsebastiao\Auditable\Tests\Fixtures\MinhaAuditoria;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AuditModelTest extends TestCase
{
    public function test_subclasse_com_table_propria_e_respeitada(): void
    {
        Schema::create('minha_auditoria', function ($table) {
            $table->id();
            $table->string('batch')->nullable();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('event');
            $table->json('changes')->nullable();
            $table->json('debug_info')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        config(['auditable.model' => MinhaAuditoria::class]);

        Produto::create(['nome' => 'Mesa', 'preco' => 1]);

        $this->assertSame(1, DB::table('minha_auditoria')->count());
        $this->assertSame(0, DB::table('audit_table')->count());
    }

    public function test_relacao_user_devolve_quem_fez(): void
    {
        DB::table('users')->insert(['id' => 1, 'name' => 'Maria']);
        config(['auditable.default_created_by' => 1]);

        Produto::create(['nome' => 'Mesa', 'preco' => 1]);

        $audit = AuditModel::with('user')->first();
        $this->assertSame('Maria', $audit->user->name);
        $this->assertSame('Maria', $audit->causer->name, 'causer() continua a funcionar como sinónimo');
    }

    public function test_acao_de_sistema_tem_user_nulo(): void
    {
        Produto::create(['nome' => 'Mesa', 'preco' => 1]);

        $this->assertNull(AuditModel::first()->user);
    }

    public function test_operations_devolve_todas_as_operacoes_do_registro(): void
    {
        $produto = Audit::transaction(fn () => Produto::create(['nome' => 'Mesa', 'preco' => 1]));
        Audit::transaction(function () use ($produto) {
            $produto->update(['preco' => 2]);
            Produto::create(['nome' => 'Outro produto da mesma operação', 'preco' => 9]);
        });
        Produto::create(['nome' => 'Sem relação', 'preco' => 5]);

        $this->assertSame(3, $produto->operations()->count(), 'as duas operações, com todas as linhas');
        $this->assertSame(3, Produto::operationsFor($produto->id)->count());
        $this->assertSame(2, $produto->operation()->count(), 'só a última operação');
        $this->assertSame(2, $produto->audits()->count(), 'só as linhas do próprio registro');
    }

    public function test_fachada_repassa_consultas_ao_model(): void
    {
        $produto = Audit::transaction(fn () => Produto::create(['nome' => 'Mesa', 'preco' => 1]));
        $batch = $produto->batchOf();

        $this->assertSame(1, Audit::inBatch($batch)->count());
        $this->assertSame(1, Audit::forRecord(Produto::class, $produto->id)->count());
        $this->assertSame(1, Audit::query()->count());
    }
}
