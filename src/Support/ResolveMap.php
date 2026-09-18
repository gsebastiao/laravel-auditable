<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

/**
 * Traduz ids e códigos em nomes legíveis no log de auditoria.
 *
 *   sem resolveMap:  status_id: 2 → 5
 *   com resolveMap:  Status: Ativo → Bloqueado
 *
 * Use dentro de getAuditOptions() (ou de Audit::for(...)->resolveMap()):
 *
 *   ->resolveMap([
 *       'status_id'  => ResolveMap::direct('Status', 'status', 'nome'),
 *       'estado_id'  => ResolveMap::join([...]),
 *       'preco_venda' => ResolveMap::alias('Preço de venda'),
 *   ])
 *
 * As consultas correm na conexão do model auditado e cada valor é
 * consultado no máximo uma vez por requisição.
 */
final class ResolveMap
{
    /**
     * O caso mais comum: SELECT {column} FROM {table} WHERE {key} = valor.
     *
     *   ResolveMap::direct('Status', 'status', 'nome')
     *   ResolveMap::direct('Tipo', 'tabela_generica', 'descricao', scope: ['grupo' => 'tipo_cliente'])
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
     * Quando o nome está noutra tabela, a várias ligações de distância.
     * Exemplo: o campo guarda estado_id, mas você quer mostrar o PAÍS:
     *
     *   ResolveMap::join([
     *       ['table' => 'estados', 'key' => 'id'],                  // 1º elo: onde procurar o valor gravado
     *       ['table' => 'paises',                                   // elos seguintes: LEFT JOIN
     *        'on' => ['paises.id', '=', 'estados.pais_id'],
     *        'column' => 'nome', 'label' => 'País'],               // no último: o que mostrar e com que nome
     *   ])
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
     * Só troca o nome do campo no log (não consulta o banco):
     *   'preco_venda' => ResolveMap::alias('Preço de venda')
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
