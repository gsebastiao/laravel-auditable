<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Audit;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\TestCase;
use Illuminate\Support\Carbon;
use RuntimeException;

/** Audit::filter($request->all()) — a base de uma tela de pesquisa. */
final class AuditFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-01 10:00:00');
        config(['auditable.default_created_by' => 1]);
        $mesa = Produto::create(['nome' => 'Mesa', 'preco' => 1]);

        $this->app->forgetScopedInstances();
        config(['auditable.default_created_by' => 2]);
        Carbon::setTestNow('2026-07-05 10:00:00');
        $mesa->update(['preco' => 2]);
        $mesa->auditFailure('sincronizar', new RuntimeException('x'));
        Audit::for('pessoa', 9, 'updated')->changes(['email' => ['old' => 'a', 'new' => 'b']])->save();
        Carbon::setTestNow();
    }

    /** @param array<string, mixed> $filtros */
    private function eventos(array $filtros): array
    {
        return Audit::filter($filtros)->orderBy('id')->pluck('event')->all();
    }

    public function test_sem_filtros_traz_tudo_e_ignora_chaves_vazias_ou_desconhecidas(): void
    {
        $this->assertCount(4, $this->eventos([]));
        $this->assertCount(4, $this->eventos(['user' => '', 'event' => null, 'page' => '2', '_token' => 'x', 'from' => 'não é data']));
    }

    public function test_por_usuario_evento_e_falhas(): void
    {
        $this->assertSame(['created'], $this->eventos(['user' => '1']));
        $this->assertSame(['updated', 'updated'], $this->eventos(['event' => 'updated']));
        $this->assertSame(['created', 'sincronizar'], $this->eventos(['event' => ['created', 'sincronizar']]));
        $this->assertSame(['sincronizar'], $this->eventos(['failures' => '1']));
    }

    public function test_por_registro_com_model_ou_nome_livre(): void
    {
        $this->assertSame(['created', 'updated', 'sincronizar'], $this->eventos(['model' => Produto::class, 'id' => 1]));
        $this->assertSame(['updated'], $this->eventos(['model' => 'pessoa']));
    }

    public function test_por_periodo_com_datas_inclusive(): void
    {
        $this->assertSame(['created'], $this->eventos(['to' => '2026-07-01']));
        $this->assertSame(['updated', 'sincronizar', 'updated'], $this->eventos(['from' => '2026-07-05', 'to' => '2026-07-05']));
    }
}
