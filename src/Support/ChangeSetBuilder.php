<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

/**
 * Monta o conteúdo da coluna `changes` a partir de arrays simples de
 * atributos — não conhece Eloquent. Quem prepara os valores (crus, com JSON
 * já decodificado e campos criptografados mascarados) é o AuditManager.
 *
 * Formatos produzidos:
 *
 *   updated (diff):     ['preco' => ['old' => 20, 'new' => 25]]
 *   com resolveMap:     ['Status' => ['old' => ['id' => 2, 'label' => 'Ativo'],
 *                                     'new' => ['id' => 5, 'label' => 'Bloqueado']]]
 *   created/deleted:    ['nome' => 'Café', 'Status' => ['id' => 2, 'label' => 'Ativo']]
 */
final class ChangeSetBuilder
{
    /** Valor gravado no lugar de campos criptografados (casts "encrypted"). */
    public const MASK = '********';

    public function __construct(private LabelResolver $labels) {}

    /**
     * Diff entre dois estados.
     *
     * @param  array<string, mixed>     $new          Atributos depois da escrita.
     * @param  array<string, mixed>     $old          Atributos antes da escrita.
     * @param  array<int, string>|null  $changedKeys  Campos que o Eloquent confirmou como
     *                                                alterados. Null = descobrir comparando
     *                                                $old com $new (caminho do Query Builder).
     * @param  string|null              $connection   Conexão onde estão as tabelas do resolveMap.
     * @return array<string, mixed>
     */
    public function build(
        array $new,
        array $old,
        AuditOptions $options,
        ?array $changedKeys = null,
        ?string $connection = null,
    ): array {
        $fields = ($options->onlyDirty && $changedKeys !== null)
            ? $changedKeys
            : array_keys($new);

        $changes = [];

        foreach ($fields as $field) {
            if ($this->skip($field, $options)) {
                continue;
            }

            $newValue = $new[$field] ?? null;
            $oldValue = $old[$field] ?? null;

            if ($options->onlyDirty && $changedKeys === null && $this->equal($oldValue, $newValue)) {
                continue;
            }

            $map = $options->resolveMap[$field] ?? null;

            if ($map === null) {
                $changes[$field] = ['old' => $oldValue, 'new' => $newValue];

                continue;
            }

            if (($map['type'] ?? 'direct') === 'alias') {
                $changes[$map['label']] = ['old' => $oldValue, 'new' => $newValue];

                continue;
            }

            $changes[$this->labels->labelFor($map)] = [
                'old' => ['id' => $oldValue, 'label' => $this->labels->resolve($oldValue, $map, $connection)],
                'new' => ['id' => $newValue, 'label' => $this->labels->resolve($newValue, $map, $connection)],
            ];
        }

        return $changes;
    }

    /**
     * Retrato legível para created/deleted/restored (não há "antes" e "depois").
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function snapshot(array $attributes, AuditOptions $options, ?string $connection = null): array
    {
        $snapshot = [];

        foreach ($attributes as $field => $value) {
            if ($this->skip($field, $options)) {
                continue;
            }

            $map = $options->resolveMap[$field] ?? null;

            if ($map === null || ($map['type'] ?? 'direct') === 'alias') {
                $snapshot[$map['label'] ?? $field] = $value;

                continue;
            }

            $snapshot[$this->labels->labelFor($map)] = [
                'id' => $value,
                'label' => $this->labels->resolve($value, $map, $connection),
            ];
        }

        return $snapshot;
    }

    /**
     * Retrato CRU e INTEGRAL para reconstruir a linha depois de um hard delete.
     * Ignora only()/except() (a linha precisa voltar inteira) e não traduz
     * nada; só remove os campos de neverSnapshot().
     *
     * @param  array<string, mixed>  $attributes  Valores crus, como estão no banco (getRawOriginal()).
     * @return array<string, mixed>
     */
    public function fullSnapshot(array $attributes, AuditOptions $options): array
    {
        return array_diff_key($attributes, array_flip($options->neverSnapshot));
    }

    /**
     * Pré-carrega os labels do resolveMap para muitas linhas de uma vez (uma
     * consulta por campo, em vez de uma por valor). Usado pelas operações em
     * massa antes de montar as auditorias de um lote.
     *
     * @param  array<int, array<string, mixed>>  $rows  Atributos crus de cada linha.
     */
    public function warm(array $rows, AuditOptions $options, ?string $connection = null): void
    {
        foreach ($options->resolveMap as $field => $map) {
            $values = [];

            foreach ($rows as $row) {
                if (array_key_exists($field, $row)) {
                    $values[] = $row[$field];
                }
            }

            if ($values !== []) {
                $this->labels->warm($map, $values, $connection);
            }
        }
    }

    private function skip(string $field, AuditOptions $options): bool
    {
        if (in_array($field, $options->except, true) || in_array($field, $options->neverSnapshot, true)) {
            return true;
        }

        return $options->only !== null && ! in_array($field, $options->only, true);
    }

    /**
     * Comparação frouxa ("1" == 1), mas null e string vazia são diferentes.
     * Só é usada quando não há lista de campos alterados vinda do Eloquent.
     */
    private function equal(mixed $a, mixed $b): bool
    {
        if ($a === null xor $b === null) {
            return false;
        }

        return $a == $b;
    }
}
