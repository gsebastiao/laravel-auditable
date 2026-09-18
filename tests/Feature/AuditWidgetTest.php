<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Audit;
use Gsebastiao\Auditable\Support\AuditWidget;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AuditWidgetTest extends TestCase
{
    public function test_full_agrupa_por_operacao_da_mais_recente_para_a_mais_antiga(): void
    {
        DB::table('users')->insert(['id' => 1, 'name' => 'Maria']);

        $produto = Audit::transaction(fn () => Produto::create(['nome' => 'Mesa', 'preco' => 1]));

        config(['auditable.default_created_by' => 1]);
        $this->app->forgetScopedInstances();
        Audit::transaction(function () use ($produto) {
            $produto->update(['preco' => 2]);
            $produto->auditFailure('sincronizar', new RuntimeException('x'));
        });

        $json = AuditWidget::full(Produto::operationsFor($produto->id), recordId: $produto->id);

        $this->assertSame($produto->id, $json['record_id']);
        $this->assertCount(2, $json['groups']);

        [$recente, $antiga] = $json['groups'];
        $this->assertSame(['updated', 'sincronizar'], array_column($recente['actions'], 'action'));
        $this->assertSame(['success', 'failed'], array_column($recente['actions'], 'type'));
        $this->assertSame('Maria', $recente['actions'][0]['created_by']);
        $this->assertSame(['preco' => ['old' => 1, 'new' => 2]], $recente['actions'][0]['changes']);

        $this->assertSame('created', $antiga['actions'][0]['action']);
        $this->assertSame('Sistema', $antiga['actions'][0]['created_by']);
    }

    public function test_simple_lista_o_que_quem_e_quando(): void
    {
        $produto = Produto::create(['nome' => 'Mesa', 'preco' => 1]);
        $produto->auditAction('aprovado');

        $json = AuditWidget::simple($produto->audits());

        $this->assertSame(['aprovado', 'created'], array_column($json['audits'], 'action'));
        $this->assertSame(['action', 'created_by', 'created_at'], array_keys($json['audits'][0]));
    }
}
