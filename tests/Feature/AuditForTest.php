<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Audit;
use Gsebastiao\Auditable\Models\Audit as AuditModel;
use Gsebastiao\Auditable\Support\ResolveMap;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Auditoria sem model: Audit::for(...) e DB::table(...)->audit(...). */
final class AuditForTest extends TestCase
{
    public function test_audit_for_grava_uma_tabela_sem_model(): void
    {
        Audit::for('pessoa', 10, 'updated')
            ->changes(['email' => ['old' => 'a@x.com', 'new' => 'b@x.com']])
            ->save();

        $audit = AuditModel::first();

        $this->assertSame('pessoa', $audit->subject_type);
        $this->assertSame(10, (int) $audit->subject_id);
        $this->assertSame(['email' => ['old' => 'a@x.com', 'new' => 'b@x.com']], $audit->changes);
    }

    public function test_encadear_except_mantem_o_resolve_map_do_model(): void
    {
        DB::table('status')->insert([['id' => 1, 'nome' => 'Ativo'], ['id' => 2, 'nome' => 'Inativo']]);

        Audit::for(Produto::class, 5, 'updated')
            ->changes([
                'status_id' => ['old' => 1, 'new' => 2],
                'custo' => ['old' => 1, 'new' => 2],
            ])
            ->except(['custo'])
            ->save();

        $this->assertSame(
            ['Status' => ['old' => ['id' => 1, 'label' => 'Ativo'], 'new' => ['id' => 2, 'label' => 'Inativo']]],
            AuditModel::first()->changes,
        );
    }

    public function test_evento_livre_e_gravado_mesmo_com_opcoes_do_model(): void
    {
        Audit::for(Produto::class, 5, 'reajuste')->changes(['preco' => ['old' => 1, 'new' => 2]])->save();

        $this->assertSame(['preco' => ['old' => 1, 'new' => 2]], AuditModel::action('reajuste')->first()->changes);
    }

    public function test_label_numerico_nao_quebra(): void
    {
        Schema::create('codigos', function ($table) {
            $table->id();
            $table->integer('codigo');
        });
        DB::table('codigos')->insert(['id' => 1, 'codigo' => 4711]);

        Audit::for('item', 1, 'created')
            ->changes(['codigo_id' => 1])
            ->resolveMap(['codigo_id' => ResolveMap::direct('Código', 'codigos', 'codigo')])
            ->save();

        $this->assertSame(['Código' => ['id' => 1, 'label' => '4711']], AuditModel::first()->changes);
    }

    public function test_macro_do_query_builder_continua_a_funcionar(): void
    {
        DB::table('produtos')->audit(Produto::class, 3, 'updated')
            ->changes(['preco' => ['old' => 1, 'new' => 2]])
            ->save();

        $this->assertSame((new Produto())->getMorphClass(), AuditModel::first()->subject_type);
    }
}
