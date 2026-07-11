<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

/**
 * Factory para configurações de resolução de labels legíveis na auditoria.
 *
 * O diferencial deste pacote sobre o spatie/laravel-activitylog: em vez de
 * gravar apenas o valor cru de uma FK (ex.: status_id: 2 -> 5), resolve o
 * label humano correspondente (ex.: Status: "Ativo" -> "Bloqueado").
 *
 * Três modos, herdados do BaseModel original mas expostos como uma API
 * fluente e nomeada:
 *
 *   direct — busca o label numa tabela pelo valor da FK (caso mais comum).
 *   join   — navega tabelas intermediárias via LEFT JOIN encadeado.
 *   alias  — não consulta o banco; apenas renomeia o campo no log.
 */
final class ResolveMap
{
    /**
     * Resolução direta: SELECT {column} FROM {table} WHERE {key} = valor.
     *
     * @param  string       $label   Nome legível exibido no log (ex.: "Status").
     * @param  string       $table   Tabela onde buscar o label.
     * @param  string       $column  Coluna cujo valor vira o label (ex.: "nome").
     * @param  string       $key     Coluna de busca na tabela alvo (default "id").
     * @param  array<string, mixed> $scope  Filtros WHERE extra (ex.: tabelas de status genéricas).
     * @return array<string, mixed>
     */
    public static function direct(
        string $label,
        string $table,
        string $column = 'nome',
        string $key = 'id',
        array $scope = [],
    ): array {
        return [
            'type' => 'direct',
            'label' => $label,
            'table' => $table,
            'column' => $column,
            'key' => $key,
            'scope' => $scope,
        ];
    }

    /**
     * Resolução por joins encadeados. Cada elo é um array:
     *   ['table' => ..., 'key' => ...]                        (primeiro elo, âncora pelo valor)
     *   ['table' => ..., 'on' => [col1, op, col2],
     *    'column' => ..., 'label' => ...]                     (elos seguintes; label/column no último)
     *
     * @param  array<int, array<string, mixed>> $joins
     * @return array<string, mixed>
     */
    public static function join(array $joins): array
    {
        return [
            'type' => 'join',
            'joins' => $joins,
        ];
    }

    /**
     * Apenas renomeia o campo no log, sem consultar o banco.
     * Útil para campos que não são FK mas merecem um nome legível.
     *
     * @return array<string, mixed>
     */
    public static function alias(string $label): array
    {
        return [
            'type' => 'alias',
            'label' => $label,
        ];
    }
}
