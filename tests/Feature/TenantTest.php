<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Audit as AuditFacade;
use Gsebastiao\Auditable\Models\Audit;
use Gsebastiao\Auditable\Tests\Fixtures\Documento;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\Fixtures\TenantAtual;
use Gsebastiao\Auditable\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TenantTest extends TestCase
{
    protected function tearDown(): void
    {
        TenantAtual::$valor = null;
        parent::tearDown();
    }

    /** Recria a tabela de auditoria com a coluna de tenant (como faria um projeto novo). */
    private function ligarTenancy(string $coluna = 'tenant_id'): void
    {
        config([
            'auditable.tenant.enabled' => true,
            'auditable.tenant.column' => $coluna,
        ]);

        Schema::drop(config('auditable.table'));
        (require __DIR__.'/../../database/migrations/2026_01_01_000000_create_audits_table.php')->up();
        $this->app->forgetScopedInstances();
    }

    public function test_without_tenant_scope_remove_mesmo_o_filtro(): void
    {
        DB::table('documentos')->insert([
            ['tenant_id' => 1, 'titulo' => 'A'],
            ['tenant_id' => 2, 'titulo' => 'B'],
        ]);
        AuditFacade::resolveTenantUsing(fn () => 1);

        $this->assertSame(['A'], Documento::pluck('titulo')->all());
        $this->assertSame(['A', 'B'], Documento::withoutTenantScope()->orderBy('titulo')->pluck('titulo')->all());
    }

    public function test_preenche_o_tenant_ao_criar(): void
    {
        AuditFacade::resolveTenantUsing(fn () => 9);

        $this->assertSame(9, (int) Documento::create(['titulo' => 'X'])->tenant_id);
    }

    public function test_resolver_por_nome_de_classe_na_config(): void
    {
        config(['auditable.tenant.resolver' => TenantAtual::class]);
        TenantAtual::$valor = 2;
        DB::table('documentos')->insert([
            ['tenant_id' => 1, 'titulo' => 'A'],
            ['tenant_id' => 2, 'titulo' => 'B'],
        ]);

        $this->assertSame(['B'], Documento::pluck('titulo')->all());
    }

    public function test_coluna_de_tenant_personalizada_e_respeitada_na_auditoria(): void
    {
        $this->ligarTenancy('empresa_id');
        AuditFacade::resolveTenantUsing(fn () => 7);

        Produto::create(['nome' => 'Mesa', 'preco' => 1]);

        $this->assertSame(7, (int) Audit::withoutTenantScope()->first()->empresa_id);
    }

    public function test_auditorias_ficam_isoladas_por_tenant(): void
    {
        $this->ligarTenancy();

        AuditFacade::resolveTenantUsing(fn () => 1);
        Produto::create(['nome' => 'Do tenant 1', 'preco' => 1]);

        AuditFacade::resolveTenantUsing(fn () => 2);
        Produto::create(['nome' => 'Do tenant 2', 'preco' => 1]);

        $this->assertSame(1, Audit::count(), 'só vê as do tenant 2');
        $this->assertSame(2, Audit::withoutTenantScope()->count());
    }

    public function test_modo_estrito_sem_tenant_nao_mostra_nada(): void
    {
        DB::table('documentos')->insert(['tenant_id' => 1, 'titulo' => 'A']);

        $this->assertSame(1, Documento::count(), 'padrão: sem tenant identificado vê tudo');

        config(['auditable.tenant.strict' => true]);

        $this->assertSame(0, Documento::count());
    }
}
