<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Audit;
use Gsebastiao\Auditable\Models\Audit as AuditModel;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\TestCase;

final class BatchAndSwitchTest extends TestCase
{
    public function test_use_batch_com_callback_continua_a_operacao_e_depois_fecha(): void
    {
        $produto = Audit::transaction(fn () => Produto::create(['nome' => 'Mesa', 'preco' => 1]));
        $batch = $produto->batchOf();

        // Como faria uma job da fila que recebeu o batch no construtor:
        Audit::useBatch($batch, fn () => $produto->auditAction('email_enviado'));

        $this->assertSame(2, AuditModel::inBatch($batch)->count());
        $this->assertNull(Audit::currentBatch(), 'o batch não fica "preso" depois do callback');
    }

    public function test_use_batch_nulo_abre_um_batch_novo(): void
    {
        $batch = Audit::useBatch(null, fn () => Audit::currentBatch());

        $this->assertIsString($batch);
        $this->assertNull(Audit::currentBatch());
    }

    public function test_without_auditing_nao_grava_nada_so_dentro_do_callback(): void
    {
        Audit::withoutAuditing(fn () => Produto::create(['nome' => 'Importado', 'preco' => 1]));
        Produto::create(['nome' => 'Normal', 'preco' => 1]);

        $this->assertSame(1, AuditModel::count());
    }

    public function test_desligado_na_config_nao_grava(): void
    {
        config(['auditable.enabled' => false]);

        Produto::create(['nome' => 'Mesa', 'preco' => 1])->auditAction('aprovado');

        $this->assertSame(0, AuditModel::count());
    }
}
