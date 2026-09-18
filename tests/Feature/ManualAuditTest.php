<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Models\Audit;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\TestCase;

final class ManualAuditTest extends TestCase
{
    public function test_audit_replaces_the_automatic_row_instead_of_duplicating(): void
    {
        $produto = Produto::create(['nome' => 'Caneta', 'preco' => 100]);

        // O hook 'created' já gravou uma linha automática. audit() logo em
        // seguida, na MESMA instância, deve SUBSTITUIR essa linha — não
        // adicionar uma segunda.
        $produto->audit(changes: ['motivo' => 'Ajuste manual']);

        $rows = Audit::where('subject_type', $produto->getMorphClass())
            ->where('subject_id', $produto->id)
            ->where('event', 'created')
            ->get();

        $this->assertCount(1, $rows, 'audit() must not create a duplicate row for the same event');
        $this->assertSame(['motivo' => 'Ajuste manual'], $rows->first()->changes);
    }

    public function test_audit_called_alone_without_prior_write_inserts_a_new_row(): void
    {
        $produto = Produto::create(['nome' => 'Lápis', 'preco' => 50]);

        // Uma NOVA instância do mesmo registro — sem rastro de nenhuma
        // escrita automática nesta instância — deve INSERIR, não tentar
        // substituir algo que não gravou.
        $fresh = Produto::find($produto->id);
        $fresh->audit(event: 'aprovado', changes: ['ok' => true]);

        $autoRows = Audit::where('subject_type', $produto->getMorphClass())
            ->where('subject_id', $produto->id)
            ->where('event', 'created')
            ->count();

        $approvedRows = Audit::where('subject_type', $produto->getMorphClass())
            ->where('subject_id', $produto->id)
            ->where('event', 'aprovado')
            ->count();

        $this->assertSame(1, $autoRows, 'the original created row must be untouched');
        $this->assertSame(1, $approvedRows, 'audit() on a fresh instance must insert a new row');
    }

    public function test_audit_from_one_instance_never_touches_another_instances_row_for_the_same_subject(): void
    {
        // Simula duas "requisições" concorrentes editando o MESMO produto:
        // duas instâncias PHP diferentes, mesmo subject_id.
        $produto = Produto::create(['nome' => 'Original', 'preco' => 10]);

        $instanceA = Produto::find($produto->id);
        $instanceB = Produto::find($produto->id);

        $instanceA->update(['nome' => 'Editado por A']);
        $instanceB->update(['nome' => 'Editado por B']);

        $updatedRows = Audit::where('subject_type', $produto->getMorphClass())
            ->where('subject_id', $produto->id)
            ->where('event', 'updated')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $updatedRows, 'each update() call must produce its own row');

        // audit() em A só pode substituir a linha que A gravou.
        $instanceA->audit(changes: ['nota' => 'A customizou']);

        $stillUpdatedRows = Audit::where('subject_type', $produto->getMorphClass())
            ->where('subject_id', $produto->id)
            ->where('event', 'updated')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $stillUpdatedRows, 'audit() must not create a 3rd row nor delete the other user\'s row');

        $bRow = $stillUpdatedRows->firstWhere('id', $updatedRows->last()->id);
        $this->assertNotNull($bRow, 'user B\'s row must still exist, untouched');
        $this->assertNotSame(['nota' => 'A customizou'], $bRow->changes, 'user B\'s row must not have been overwritten by A\'s audit() call');
    }

    public function test_audit_created_at_and_updated_at_overrides_are_independent(): void
    {
        $produto = Produto::create(['nome' => 'Relógio', 'preco' => 200]);

        $produto->audit(
            changes: ['x' => 1],
            createdAt: '2020-01-01 00:00:00',
        );

        $row = Audit::where('subject_type', $produto->getMorphClass())
            ->where('subject_id', $produto->id)
            ->where('event', 'created')
            ->first();

        $this->assertSame('2020-01-01 00:00:00', $row->created_at->format('Y-m-d H:i:s'));
        $this->assertNotSame('2020-01-01 00:00:00', $row->updated_at->format('Y-m-d H:i:s'), 'overriding createdAt alone must not drag updatedAt to the same value');
    }

    public function test_audit_resolves_fk_labels_the_same_way_automatic_events_do(): void
    {
        \Illuminate\Support\Facades\DB::table('status')->insert([
            ['id' => 1, 'nome' => 'Ativo'],
            ['id' => 2, 'nome' => 'Inativo'],
        ]);

        $produto = Produto::create(['nome' => 'Mesa', 'preco' => 300, 'status_id' => 1]);
        $produto->update(['status_id' => 2]);

        // audit() sem changes explícito, na MESMA instância que fez o
        // update(), deve recalcular com o MESMO resolveMap do model
        // (Status: Ativo -> Inativo), não um diff cru de ids — e deve
        // SUBSTITUIR a linha automática de 'updated', não duplicar.
        $produto->audit();

        $updatedRows = Audit::where('subject_type', $produto->getMorphClass())
            ->where('subject_id', $produto->id)
            ->where('event', 'updated')
            ->get();

        $this->assertCount(1, $updatedRows, 'audit() without changes must still replace, not duplicate, the automatic updated row');

        $this->assertSame(
            ['Status' => ['old' => ['id' => 1, 'label' => 'Ativo'], 'new' => ['id' => 2, 'label' => 'Inativo']]],
            $updatedRows->first()->changes,
        );
    }
}
