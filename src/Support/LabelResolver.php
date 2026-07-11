<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Illuminate\Database\ConnectionInterface;

/**
 * Executa as consultas descritas por um ResolveMap para traduzir valores
 * crus (IDs/codes) em labels legíveis.
 *
 * Consolida a lógica de resolveLabel()/getLabelFromMap() do BaseModel
 * original num serviço isolado e testável, e ativa o cache por-request que
 * lá foi declarado ($labelCache) mas nunca chegou a ser usado — evitando
 * N+1 quando o mesmo valor aparece em várias linhas de um batch.
 */
final class LabelResolver
{
    /** @var array<string, string|null> Cache por-request: "type|table|key|col|scope|valor" => label. */
    private array $cache = [];

    public function __construct(private ConnectionInterface $db) {}

    /**
     * Resolve o label de um valor segundo o mapa dado.
     *
     * @param  array<string, mixed>  $map
     */
    public function resolve(mixed $value, array $map): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $type = $map['type'] ?? 'direct';

        return match ($type) {
            'direct' => $this->resolveDirect($value, $map),
            'join' => $this->resolveJoin($value, $map),
            default => null, // 'alias' não resolve via banco
        };
    }

    /**
     * Nome legível do campo (a chave sob a qual a mudança é indexada no log).
     *
     * @param  array<string, mixed>  $map
     */
    public function labelFor(array $map): string
    {
        $type = $map['type'] ?? 'direct';

        if ($type === 'join' && ! empty($map['joins'])) {
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

    /** @param array<string, mixed> $map */
    private function resolveDirect(mixed $value, array $map): ?string
    {
        $table = $map['table'];
        $key = $map['key'] ?? 'id';
        $column = $map['column'] ?? 'nome';
        $scope = $map['scope'] ?? [];

        $cacheKey = 'direct|'.$table.'|'.$key.'|'.$column.'|'.serialize($scope).'|'.$value;

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $query = $this->db->table($table)
            ->select($column)
            ->where("{$table}.{$key}", $value);

        foreach ($scope as $col => $val) {
            $query->where("{$table}.{$col}", $val);
        }

        $row = $query->first();

        return $this->cache[$cacheKey] = $row?->{$column};
    }

    /** @param array<string, mixed> $map */
    private function resolveJoin(mixed $value, array $map): ?string
    {
        if (empty($map['joins'])) {
            return null;
        }

        $builder = null;
        $lastColumn = null;

        foreach ($map['joins'] as $join) {
            if ($builder === null) {
                $firstTable = $join['table'];
                $key = $join['key'] ?? 'id';
                $builder = $this->db->table($firstTable)
                    ->where("{$firstTable}.{$key}", $value);
            } else {
                [$col1, $operator, $col2] = $join['on'];
                $builder->leftJoin($join['table'], $col1, $operator, $col2);
            }

            if (isset($join['column'])) {
                $lastColumn = $join['column'];
                $builder->addSelect("{$join['table']}.{$lastColumn} as label");
            }
        }

        if ($builder && $lastColumn) {
            return $builder->first()?->label;
        }

        return null;
    }
}
