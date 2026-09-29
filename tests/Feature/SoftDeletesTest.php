<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Models\Audit;
use Gsebastiao\Auditable\Tests\Fixtures\Nota;
use Gsebastiao\Auditable\Tests\Fixtures\NotaSemRestored;
use Gsebastiao\Auditable\Tests\TestCase;
use RuntimeException;

final class SoftDeletesTest extends TestCase
{
    public function test_lixeira_e_restauro_ficam_no_historico(): void
    {
        $nota = Nota::create(['texto' => 'Olá']);
        $nota->delete();
        $nota->restore();

        // O restore() faz um save() por dentro, mas fica só a linha 'restored'.
        $this->assertSame(['created', 'deleted', 'restored'], Audit::orderBy('id')->pluck('event')->all());
        $this->assertArrayHasKey('deleted_at', Audit::action('deleted')->first()->changes);
    }

    public function test_sem_restored_nos_eventos_o_restauro_fica_como_updated(): void
    {
        $nota = NotaSemRestored::create(['texto' => 'Olá']);
        $nota->delete();
        $nota->restore();

        // Sem 'restored' activo, o restore nunca pode ficar sem rasto.
        $this->assertSame(['created', 'deleted', 'updated'], Audit::orderBy('id')->pluck('event')->all());
        $this->assertArrayHasKey('deleted_at', Audit::action('updated')->first()->changes);
    }

    public function test_update_depois_do_restauro_continua_a_ser_gravado(): void
    {
        $nota = Nota::create(['texto' => 'Olá']);
        $nota->delete();
        $nota->restore();
        $nota->update(['texto' => 'Adeus']);

        $this->assertSame(['created', 'deleted', 'restored', 'updated'], Audit::orderBy('id')->pluck('event')->all());
        $this->assertArrayHasKey('texto', Audit::action('updated')->first()->changes);
    }

    public function test_restore_de_registo_que_nao_estava_apagado_nao_engole_o_update_seguinte(): void
    {
        $nota = Nota::create(['texto' => 'Olá']);
        $nota->restore(); // não muda nada: o save() não dispara 'updated'
        $nota->update(['texto' => 'Adeus']);

        $this->assertContains('updated', Audit::orderBy('id')->pluck('event')->all());
    }

    public function test_restore_da_auditoria_aponta_o_restore_do_model_se_estiver_na_lixeira(): void
    {
        $nota = Nota::create(['texto' => 'Olá']);
        $nota->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SoftDeletes');

        Audit::action('deleted')->first()->restore();
    }

    public function test_force_delete_pode_ser_restaurado_pela_auditoria(): void
    {
        $nota = Nota::create(['texto' => 'Olá']);
        $nota->forceDelete();

        Audit::action('deleted')->first()->restore();

        $this->assertSame('Olá', Nota::find($nota->id)->texto);
    }
}
