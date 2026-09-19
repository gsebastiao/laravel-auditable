<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use BackedEnum;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Stringable;

/**
 * Executa as consultas descritas por um ResolveMap para transformar valores
 * crus (ids/códigos) em textos legíveis ("Ativo", "Portugal"...).
 *
 * As consultas correm na conexão onde estão as TABELAS DO SEU SISTEMA — a do
 * model auditado (ou a do Query Builder usado) — e não na conexão da
 * auditoria, que pode ser outra.
 *
 * Cada valor é consultado uma única vez por requisição/job (cache em
 * memória), o que evita N+1 quando o mesmo status aparece em várias linhas.
 * O cache tem um teto: numa job longa, com milhões de valores distintos, a
 * memória não cresce sem limite (o valor mais antigo sai quando enche).
 */
final class LabelResolver
{
    /** Quantos labels o cache guarda, no máximo. */
    public const MAX_ENTRIES = 10_000;

    /** @var array<string, string|null> */
    private array $cache = [];

    /**
     * @param  ConnectionResolverInterface|ConnectionInterface  $db  O gerenciador de conexões
     *         do Laravel (uso normal) ou uma conexão fixa (útil em testes).
     * @param  int  $maxEntries  Teto do cache (padrão: MAX_ENTRIES).
     */
    public function __construct(
        private ConnectionResolverInterface|ConnectionInterface $db,
        private int $maxEntries = self::MAX_ENTRIES,
    ) {}

    /**
     * @param  array<string, mixed>  $map
     */
    public function resolve(mixed $value, array $map, ?string $connection = null): ?string
    {
        $value = $this->normalize($value);

        if ($value === null || $value === '') {
            return null;
        }

        return match ($map['type'] ?? 'direct') {
            'direct' => $this->resolveDirect($value, $map, $connection),
            'join' => $this->resolveJoin($value, $map, $connection),
            default => null, // 'alias' não consulta o banco
        };
    }

    /**
     * Nome legível do campo (a chave usada em `changes`).
     *
     * @param  array<string, mixed>  $map
     */
    public function labelFor(array $map): string
    {
        if (($map['type'] ?? 'direct') === 'join' && ! empty($map['joins'])) {
            foreach (array_reverse($map['joins']) as $join) {
                if (isset($join['label'])) {
                    return $join['label'];
                }
                if (isset($join['column'])) {
                    return $join['column'];
                }
            }

            return 'unknown';
        }

        return $map['label'] ?? $map['column'] ?? 'unknown';
    }

    /**
     * Pré-carrega, numa consulta só (WHERE chave IN (...)), os labels de
     * muitos valores de um mapa `direct`. Usado pelas operações em massa para
     * não consultar valor a valor. Mapas `join`/`alias` são ignorados (esses
     * resolvem-se valor a valor, também com cache).
     *
     * Valores não encontrados não ficam em cache: seguem o caminho normal,
     * que também cobre diferenças de maiúsculas/minúsculas do banco.
     *
     * @param  array<string, mixed>  $map
     * @param  array<int, mixed>     $values
     */
    public function warm(array $map, array $values, ?string $connection = null): void
    {
        if (($map['type'] ?? 'direct') !== 'direct') {
            return;
        }

        $pending = [];

        foreach ($values as $value) {
            $value = $this->normalize($value);

            if ($value !== null && $value !== '' && ! array_key_exists($this->directKey($map, $value, $connection), $this->cache)) {
                $pending[(string) $value] = $value;
            }
        }

        $table = $map['table'];
        $key = $map['key'] ?? 'id';
        $column = $map['column'] ?? 'nome';

        foreach (array_chunk(array_values($pending), 500) as $chunk) {
            $query = $this->connection($connection)->table($table)
                ->select(["{$table}.{$key} as label_key", "{$table}.{$column} as label_value"])
                ->whereIn("{$table}.{$key}", $chunk);

            foreach ($map['scope'] ?? [] as $col => $val) {
                $query->where("{$table}.{$col}", $val);
            }

            $found = [];

            foreach ($query->get() as $row) {
                $found[(string) $row->label_key] = $this->toLabel($row->label_value);
            }

            foreach ($chunk as $value) {
                if (array_key_exists((string) $value, $found)) {
                    $this->remember($this->directKey($map, $value, $connection), $found[(string) $value]);
                }
            }
        }
    }

    /** @param array<string, mixed> $map */
    private function resolveDirect(string|int|float $value, array $map, ?string $connection): ?string
    {
        $table = $map['table'];
        $key = $map['key'] ?? 'id';
        $column = $map['column'] ?? 'nome';
        $scope = $map['scope'] ?? [];

        $cacheKey = $this->directKey($map, $value, $connection);

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $query = $this->connection($connection)->table($table)
            ->select($column)
            ->where("{$table}.{$key}", $value);

        foreach ($scope as $col => $val) {
            $query->where("{$table}.{$col}", $val);
        }

        $row = $query->first();

        return $this->remember($cacheKey, $this->toLabel($row?->{$column} ?? null));
    }

    /** @param array<string, mixed> $map */
    private function resolveJoin(string|int|float $value, array $map, ?string $connection): ?string
    {
        if (empty($map['joins'])) {
            return null;
        }

        $cacheKey = implode('|', ['join', (string) $connection, serialize($map['joins']), (string) $value]);

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $builder = null;
        $hasColumn = false;

        foreach ($map['joins'] as $join) {
            if ($builder === null) {
                $firstTable = $join['table'];
                $builder = $this->connection($connection)->table($firstTable)
                    ->where("{$firstTable}.".($join['key'] ?? 'id'), $value);
            } else {
                [$col1, $operator, $col2] = $join['on'];
                $builder->leftJoin($join['table'], $col1, $operator, $col2);
            }

            if (isset($join['column'])) {
                $hasColumn = true;
                $builder->addSelect("{$join['table']}.{$join['column']} as label");
            }
        }

        $label = ($builder !== null && $hasColumn) ? $this->toLabel($builder->first()?->label ?? null) : null;

        return $this->remember($cacheKey, $label);
    }

    /** @param array<string, mixed> $map */
    private function directKey(array $map, string|int|float $value, ?string $connection): string
    {
        return implode('|', [
            'direct',
            (string) $connection,
            $map['table'],
            $map['key'] ?? 'id',
            $map['column'] ?? 'nome',
            serialize($map['scope'] ?? []),
            (string) $value,
        ]);
    }

    private function remember(string $cacheKey, ?string $label): ?string
    {
        if (! array_key_exists($cacheKey, $this->cache) && count($this->cache) >= $this->maxEntries) {
            unset($this->cache[array_key_first($this->cache)]);
        }

        return $this->cache[$cacheKey] = $label;
    }

    private function connection(?string $name): ConnectionInterface
    {
        return $this->db instanceof ConnectionResolverInterface
            ? $this->db->connection($name)
            : $this->db;
    }

    /** Aceita escalares, enums e Stringable; qualquer outra coisa não é uma chave. */
    private function normalize(mixed $value): string|int|float|null
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return (int) $value;
        }

        return is_scalar($value) ? $value : null;
    }

    /** O label pode vir numérico do banco (ex.: um código); guardamos sempre como texto. */
    private function toLabel(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
