<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Gsebastiao\Auditable\Support\AuditOptions;

/**
 * AuditOptions é 100% puro (nenhuma dependência de Illuminate) — testável
 * como qualquer classe PHP comum, sem TestCase do Testbench nem banco.
 */
final class AuditOptionsTest extends TestCase
{
    public function test_defaults(): void
    {
        $opts = AuditOptions::defaults();

        $this->assertSame(['created', 'updated', 'deleted'], $opts->events);
        $this->assertNull($opts->only);
        $this->assertSame(['password', 'remember_token'], $opts->except);
        $this->assertTrue($opts->onlyDirty);
        $this->assertFalse($opts->logEmpty);
        $this->assertTrue($opts->fullSnapshotOnDelete);
        $this->assertSame([], $opts->resolveMap);
    }

    public function test_except_merges_with_defaults_without_losing_password_and_remember_token(): void
    {
        $opts = AuditOptions::defaults()->except(['senha', 'token_2fa']);

        $this->assertSame(['password', 'remember_token', 'senha', 'token_2fa'], $opts->except);
    }

    public function test_except_dedupes_when_repeating_a_default(): void
    {
        $opts = AuditOptions::defaults()->except(['password']);

        $this->assertSame(['password', 'remember_token'], $opts->except);
    }

    public function test_only_sets_the_exact_list(): void
    {
        $opts = AuditOptions::defaults()->only(['preco', 'status_id']);

        $this->assertSame(['preco', 'status_id'], $opts->only);
    }

    public function test_events_replaces_the_default_list(): void
    {
        $opts = AuditOptions::defaults()->events(['updated', 'deleted']);

        $this->assertSame(['updated', 'deleted'], $opts->events);
    }

    public function test_only_dirty_can_be_turned_off(): void
    {
        $opts = AuditOptions::defaults()->onlyDirty(false);

        $this->assertFalse($opts->onlyDirty);
    }

    public function test_log_empty_can_be_turned_on(): void
    {
        $opts = AuditOptions::defaults()->logEmpty(true);

        $this->assertTrue($opts->logEmpty);
    }

    public function test_resolve_map_is_stored_as_given(): void
    {
        $map = ['status_id' => ['type' => 'direct', 'table' => 'status']];

        $opts = AuditOptions::defaults()->resolveMap($map);

        $this->assertSame($map, $opts->resolveMap);
    }
}
