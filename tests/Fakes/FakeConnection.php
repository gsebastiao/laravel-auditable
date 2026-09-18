<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Fakes;

use Closure;
use Illuminate\Database\ConnectionInterface;

/**
 * ConnectionInterface em memória para os testes de unidade de
 * LabelResolver/ChangeSetBuilder (não precisam de banco nem de boot do
 * Laravel — só do illuminate/database instalado pelo composer).
 *
 * Só table() faz algo útil; o resto existe para cumprir a interface do
 * Laravel 11, 12 e 13 (o 13 acrescentou $fetchUsing em select/cursor).
 */
final class FakeConnection implements ConnectionInterface
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $tables = [];

    public function seed(string $table, array $rows): void
    {
        $this->tables[$table] = $rows;
    }

    public function rowsFor(string $table): array
    {
        return $this->tables[$table] ?? [];
    }

    public function table($table, $as = null)
    {
        return new FakeQueryBuilder($this, $table);
    }

    public function raw($value) { return $value; }
    public function selectOne($query, $bindings = [], $useReadPdo = true) { return null; }
    public function scalar($query, $bindings = [], $useReadPdo = true) { return null; }
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []) { return []; }
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []) { yield from []; }
    public function insert($query, $bindings = []) { return true; }
    public function update($query, $bindings = []) { return 0; }
    public function delete($query, $bindings = []) { return 0; }
    public function statement($query, $bindings = []) { return true; }
    public function affectingStatement($query, $bindings = []) { return 0; }
    public function unprepared($query) { return true; }
    public function prepareBindings(array $bindings) { return $bindings; }
    public function transaction(Closure $callback, $attempts = 1) { return $callback($this); }
    public function beginTransaction() {}
    public function commit() {}
    public function rollBack($toLevel = null) {}
    public function transactionLevel() { return 0; }
    public function pretend(Closure $callback) { return []; }
    public function getDatabaseName() { return 'fake'; }
}
