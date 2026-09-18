<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Fakes;

/**
 * Query builder fake devolvido por FakeConnection::table() — o suficiente
 * para exercitar LabelResolver::resolveDirect() e ::resolveJoin() exatamente
 * como eles usam o builder real:
 *   table($t)->select($col)->where("{$t}.{$key}", $value)->first()
 *   table($t)->where(...)->leftJoin(...)->addSelect("{$t}.{$col} as label")->first()
 *
 * "Tabelas" são arrays associativos guardados em memória por FakeConnection;
 * este builder só filtra/junta/projeta sobre esses arrays, sem SQL nenhum.
 */
final class FakeQueryBuilder
{
    private array $wheres = [];
    private array $selects = [];

    /** @var array{table: string, column: string, as: string}|null */
    private ?array $extraSelectFrom = null;

    /** @var array{table: string, localCol: string}|null */
    private ?array $joined = null;

    public function __construct(
        private FakeConnection $db,
        private string $table,
    ) {}

    public function select(...$columns): static
    {
        $this->selects = $columns;

        return $this;
    }

    public function where($column, $value): static
    {
        // aceita "tabela.coluna" ou "coluna" — LabelResolver sempre manda
        // qualificado ("{$table}.{$key}"), mas aceitar ambos deixa o fake
        // mais robusto a pequenas variações de chamada.
        $col = str_contains($column, '.') ? explode('.', $column)[1] : $column;
        $this->wheres[$col] = $value;

        return $this;
    }

    public function leftJoin($table, $col1, $operator, $col2): static
    {
        $localCol = explode('.', $col2)[1] ?? $col2;
        $this->joined = ['table' => $table, 'localCol' => $localCol];

        return $this;
    }

    public function addSelect($expr): static
    {
        // formato exato usado por LabelResolver: "tabela.coluna as label"
        if (preg_match('/^([\w]+)\.([\w]+)\s+as\s+(\w+)$/', $expr, $m)) {
            $this->extraSelectFrom = ['table' => $m[1], 'column' => $m[2], 'as' => $m[3]];
        }

        return $this;
    }

    public function first(): ?object
    {
        $rows = $this->db->rowsFor($this->table);

        $matches = array_filter($rows, function ($row) {
            foreach ($this->wheres as $col => $val) {
                if (($row[$col] ?? null) != $val) {
                    return false;
                }
            }

            return true;
        });

        $row = reset($matches);

        if ($row === false) {
            return null;
        }

        $result = new \stdClass();

        foreach ($this->selects as $col) {
            $result->{$col} = $row[$col] ?? null;
        }

        if ($this->joined !== null && $this->extraSelectFrom !== null) {
            // join simples: pega o valor da FK na linha atual, busca a
            // linha correspondente na tabela unida, extrai a coluna pedida
            $fkValue = $row[$this->joined['localCol']] ?? null;
            $joinedRows = $this->db->rowsFor($this->joined['table']);
            $joinedRow = null;

            foreach ($joinedRows as $candidate) {
                if (($candidate['id'] ?? null) == $fkValue) {
                    $joinedRow = $candidate;
                    break;
                }
            }

            $result->{$this->extraSelectFrom['as']} = $joinedRow[$this->extraSelectFrom['column']] ?? null;
        } elseif ($this->extraSelectFrom !== null) {
            $result->{$this->extraSelectFrom['as']} = $row[$this->extraSelectFrom['column']] ?? null;
        }

        return $result;
    }
}
