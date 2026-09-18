<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Gsebastiao\Auditable\Support\AuditOptions;
use Gsebastiao\Auditable\Support\ChangeSetBuilder;
use Gsebastiao\Auditable\Support\LabelResolver;
use Gsebastiao\Auditable\Tests\Fakes\FakeConnection;

/**
 * ChangeSetBuilder só trabalha com arrays e recebe um LabelResolver, aqui
 * construído com FakeConnection (uma ConnectionInterface em memória). Não
 * precisa de banco real nem de boot do Laravel.
 */
final class ChangeSetBuilderTest extends TestCase
{
    private ChangeSetBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ChangeSetBuilder(new LabelResolver(new FakeConnection()));
    }

    public function test_build_detects_changed_field_and_skips_unchanged_by_default(): void
    {
        $diff = $this->builder->build(
            new: ['nome' => 'João', 'idade' => 30],
            old: ['nome' => 'Joao', 'idade' => 30],
            options: AuditOptions::defaults(),
        );

        $this->assertSame(['nome' => ['old' => 'Joao', 'new' => 'João']], $diff);
    }

    public function test_build_with_only_dirty_false_keeps_unchanged_fields(): void
    {
        $diff = $this->builder->build(
            new: ['idade' => 30],
            old: ['idade' => 30],
            options: AuditOptions::defaults()->onlyDirty(false),
        );

        $this->assertSame(['idade' => ['old' => 30, 'new' => 30]], $diff);
    }

    public function test_build_respects_except_default_password_is_never_audited(): void
    {
        $diff = $this->builder->build(
            new: ['nome' => 'João', 'password' => 'nova'],
            old: ['nome' => 'Joao', 'password' => 'antiga'],
            options: AuditOptions::defaults(),
        );

        $this->assertSame(['nome' => ['old' => 'Joao', 'new' => 'João']], $diff);
    }

    public function test_build_respects_only(): void
    {
        $diff = $this->builder->build(
            new: ['nome' => 'João', 'idade' => 31, 'cidade' => 'Maputo'],
            old: ['nome' => 'Joao', 'idade' => 30, 'cidade' => 'Beira'],
            options: AuditOptions::defaults()->only(['idade']),
        );

        $this->assertSame(['idade' => ['old' => 30, 'new' => 31]], $diff);
    }

    public function test_build_loosely_compares_old_and_new(): void
    {
        // "1" (string vinda do banco) vs 1 (int já castado) não deve contar
        // como mudança real.
        $diff = $this->builder->build(
            new: ['ativo' => 1],
            old: ['ativo' => '1'],
            options: AuditOptions::defaults(),
        );

        $this->assertSame([], $diff);
    }

    public function test_build_treats_null_and_empty_string_as_different(): void
    {
        $diff = $this->builder->build(
            new: ['apelido' => ''],
            old: ['apelido' => null],
            options: AuditOptions::defaults(),
        );

        $this->assertSame(['apelido' => ['old' => null, 'new' => '']], $diff);
    }

    public function test_snapshot_excludes_password_by_default(): void
    {
        $snapshot = $this->builder->snapshot(
            attributes: ['nome' => 'Maria', 'password' => 'secreta'],
            options: AuditOptions::defaults(),
        );

        $this->assertSame(['nome' => 'Maria'], $snapshot);
    }

    public function test_full_snapshot_drops_never_snapshot_fields(): void
    {
        $snapshot = $this->builder->fullSnapshot(
            attributes: ['nome' => 'Maria', 'password' => 'secreta', 'cidade' => 'Maputo'],
            options: AuditOptions::defaults(),
        );

        $this->assertSame(['nome' => 'Maria', 'cidade' => 'Maputo'], $snapshot);
    }

    public function test_full_snapshot_ignores_only_because_restore_needs_the_whole_row(): void
    {
        $snapshot = $this->builder->fullSnapshot(
            attributes: ['nome' => 'Maria', 'cidade' => 'Maputo'],
            options: AuditOptions::defaults()->only(['nome']), // deve ser IGNORADO
        );

        $this->assertSame(['nome' => 'Maria', 'cidade' => 'Maputo'], $snapshot);
    }
}
