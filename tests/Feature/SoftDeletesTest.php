<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Models\Audit;
use Gsebastiao\Auditable\Tests\Fixtures\Nota;
use Gsebastiao\Auditable\Tests\TestCase;
use RuntimeException;

final class SoftDeletesTest extends TestCase
{
    public function test_lixeira_e_restauro_ficam_no_historico(): void
    {
        $nota = Nota::create(['texto' => 'Olá']);
        $nota->delete();
        $nota->restore();

        $this->assertSame(['created', 'deleted', 'updated', 'restored'], Audit::orderBy('id')->pluck('event')->all());
        $this->assertArrayHasKey('deleted_at', Audit::action('deleted')->first()->changes);
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
