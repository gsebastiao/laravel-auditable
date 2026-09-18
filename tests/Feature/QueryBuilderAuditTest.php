<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Gsebastiao\Auditable\Models\Audit;
use Gsebastiao\Auditable\Support\AuditOptions;
use Gsebastiao\Auditable\Support\ResolveMap;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\TestCase;

final class QueryBuilderAuditTest extends TestCase
{
    public function test_nothing_is_persisted_until_save_is_called(): void
    {
        $produto = Produto::create(['nome' => 'Caneta', 'preco' => 100]);

        DB::table('produtos')->where('id', $produto->id)
            ->audit(subjectType: Produto::class, subjectId: $produto->id, event: 'ajuste_manual')
            ->changes(['preco' => ['old' => 100, 'new' => 120]]);
        // Note: ->save() NOT called.

        $rows = Audit::where('event', 'ajuste_manual')->count();

        $this->assertSame(0, $rows, 'nothing should be written before save() is called');
    }

    public function test_save_persists_the_entry(): void
    {
        $produto = Produto::create(['nome' => 'Caneta', 'preco' => 100]);

        DB::table('produtos')->where('id', $produto->id)->update(['preco' => 120]);

        DB::table('produtos')->where('id', $produto->id)
            ->audit(subjectType: Produto::class, subjectId: $produto->id, event: 'ajuste_manual')
            ->changes(['preco' => ['old' => 100, 'new' => 120]])
            ->save();

        $row = Audit::where('event', 'ajuste_manual')->first();

        $this->assertNotNull($row);
        $this->assertSame(['preco' => ['old' => 100, 'new' => 120]], $row->changes);
    }

    public function test_query_builder_audit_reuses_model_resolve_map_when_subject_type_is_a_model_class(): void
    {
        DB::table('status')->insert([
            ['id' => 1, 'nome' => 'Ativo'],
            ['id' => 2, 'nome' => 'Inativo'],
        ]);

        $produto = Produto::create(['nome' => 'Mesa', 'preco' => 300, 'status_id' => 1]);

        DB::table('produtos')->where('id', $produto->id)->update(['status_id' => 2]);

        // Produto::getAuditOptions() já define resolveMap para status_id —
        // sem encadear ->resolveMap() aqui, deve reaproveitar automaticamente.
        DB::table('produtos')->where('id', $produto->id)
            ->audit(subjectType: Produto::class, subjectId: $produto->id, event: 'updated')
            ->changes(['status_id' => ['old' => 1, 'new' => 2]])
            ->save();

        $row = Audit::where('subject_type', $produto->getMorphClass())
            ->where('subject_id', $produto->id)
            ->where('event', 'updated')
            ->first();

        $this->assertSame(
            ['Status' => ['old' => ['id' => 1, 'label' => 'Ativo'], 'new' => ['id' => 2, 'label' => 'Inativo']]],
            $row->changes,
        );
    }

    public function test_query_builder_audit_works_for_a_table_with_no_model_using_resolve_map_join(): void
    {
        // Cenário do pedido do usuário: uma tabela SEM Model, resolvendo um
        // ResolveMap::join() (estado -> pais) sem nenhum Eloquent envolvido.
        DB::table('produtos')->insert(['id' => 1, 'nome' => 'placeholder', 'preco' => 0]);
        // reaproveita a migration de teste: cria as tabelas auxiliares aqui mesmo.
        \Illuminate\Support\Facades\Schema::create('estados', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->unsignedBigInteger('pais_id');
        });
        \Illuminate\Support\Facades\Schema::create('paises', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('nome');
        });
        \Illuminate\Support\Facades\Schema::create('pessoas', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('estado_id');
        });

        DB::table('paises')->insert([
            ['id' => 1, 'nome' => 'Brasil'],
            ['id' => 2, 'nome' => 'Portugal'],
        ]);
        DB::table('estados')->insert([
            ['id' => 3, 'nome' => 'São Paulo', 'pais_id' => 1],
            ['id' => 7, 'nome' => 'Lisboa', 'pais_id' => 2],
        ]);
        $pessoaId = DB::table('pessoas')->insertGetId(['estado_id' => 3]);

        DB::table('pessoas')->where('id', $pessoaId)->update(['estado_id' => 7]);

        DB::table('pessoas')->where('id', $pessoaId)
            ->audit(subjectType: 'pessoa', subjectId: $pessoaId, event: 'updated')
            ->changes(['estado_id' => ['old' => 3, 'new' => 7]])
            ->resolveMap([
                'estado_id' => ResolveMap::join([
                    ['table' => 'estados', 'key' => 'id'],
                    ['table' => 'paises',
                        'on' => ['paises.id', '=', 'estados.pais_id'],
                        'column' => 'nome',
                        'label' => 'País'],
                ]),
            ])
            ->save();

        $row = Audit::where('subject_type', 'pessoa')
            ->where('subject_id', $pessoaId)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($row, 'a table with no Model at all must still be auditable via the Query Builder');
        $this->assertSame(
            ['País' => ['old' => ['id' => 3, 'label' => 'Brasil'], 'new' => ['id' => 7, 'label' => 'Portugal']]],
            $row->changes,
        );
    }

    public function test_query_builder_audit_always_inserts_never_replaces(): void
    {
        // Sem model hidratado, não há hook automático — cada save() deve
        // sempre inserir uma linha nova, mesmo chamando duas vezes para o
        // mesmo subject_type+subject_id+event.
        DB::table('produtos')->insert(['id' => 1, 'nome' => 'placeholder', 'preco' => 0]);

        DB::table('produtos')->where('id', 1)
            ->audit(subjectType: Produto::class, subjectId: 1, event: 'ajuste')
            ->changes(['a' => 1])
            ->save();

        DB::table('produtos')->where('id', 1)
            ->audit(subjectType: Produto::class, subjectId: 1, event: 'ajuste')
            ->changes(['b' => 2])
            ->save();

        $count = Audit::where('event', 'ajuste')->count();

        $this->assertSame(2, $count, 'Query Builder audit() has no automatic hook to replace, so it must always insert');
    }

    public function test_events_filter_prevents_writing_an_excluded_event(): void
    {
        DB::table('produtos')->insert(['id' => 1, 'nome' => 'placeholder', 'preco' => 0]);

        DB::table('produtos')->where('id', 1)
            ->audit(subjectType: Produto::class, subjectId: 1, event: 'created')
            ->changes(['a' => 1])
            ->events(['updated', 'deleted']) // 'created' not included
            ->save();

        $count = Audit::where('event', 'created')
            ->where('subject_id', 1)
            ->count();

        $this->assertSame(0, $count, 'events() must filter out events not in the list, just like the Eloquent hooks do');
    }
}
