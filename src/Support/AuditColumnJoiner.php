<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Acrescenta colunas "quem criou / quando" e "quem alterou / quando" à
 * consulta de uma LISTAGEM (DataTable, grelha, relatório), numa única query:
 *
 *   $query = Produto::query()->where('ativo', 1);
 *   AuditColumnJoiner::apply($query, Produto::class);
 *   $produtos = $query->paginate();
 *
 *   $produtos[0]->audit_created_by   // "Maria Pereira"
 *   $produtos[0]->audit_created_at   // "10/07/2026 14:30:00"
 *   $produtos[0]->audit_updated_by / audit_updated_at
 *
 * Para o histórico de UM registro use $model->audits; esta classe é para
 * MUITOS registros de uma vez (evita uma consulta de auditoria por linha).
 *
 * Como funciona: para cada ação pedida, um LEFT JOIN com uma subconsulta que
 * escolhe UMA linha de auditoria por registro (a primeira para `created`, a
 * mais recente para as outras) e já traz o nome do usuário. As colunas do
 * seu model continuam todas lá, e nenhuma coluna da tabela de usuários fica
 * solta na consulta (os seus where('nome', ...) não ficam ambíguos).
 *
 * Requisito: a tabela de auditoria tem de estar no MESMO banco da listagem
 * (não funciona se config('auditable.connection') apontar para outro banco).
 */
final class AuditColumnJoiner
{
    public const DEFAULT_COLUMN_PREFIX = 'audit_';

    /**
     * @param  EloquentBuilder|QueryBuilder  $query       A consulta da listagem (Model::query() ou DB::table()).
     * @param  class-string<Model>|Model|string  $model   O model listado — ou, para tabelas sem model,
     *                                                    o mesmo nome usado em Audit::for('nome', ...).
     * @param  array<int, string>  $actions               Eventos que viram colunas (created, updated, deleted, aprovado...).
     * @param  string              $userColumn            Coluna da tabela de usuários a mostrar (name, email...).
     *                                                    Com 'name', o nome é encurtado para "Primeiro Último".
     * @param  string|null         $dateFormat            DD, MM, YYYY, HH, mm, ss (ou tokens do date() do PHP).
     *                                                    Null = data crua do banco.
     * @param  string|null         $primaryKey            Chave da tabela listada (padrão: a do model, ou 'id').
     * @param  string|null         $prefix                Prefixo das colunas (padrão: config 'auditable.column_prefix').
     */
    public static function apply(
        EloquentBuilder|QueryBuilder $query,
        string|Model $model,
        array $actions = ['created', 'updated'],
        string $userColumn = 'name',
        ?string $dateFormat = 'DD/MM/YYYY HH:mm:ss',
        ?string $primaryKey = null,
        ?string $prefix = null,
    ): EloquentBuilder|QueryBuilder {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;

        if (is_string($model) && is_subclass_of($model, Model::class)) {
            $model = new $model();
        }

        [$fromTable, $fromAlias] = self::splitFrom((string) $base->from);
        $mainTable = $fromAlias ?? $fromTable;

        if ($model instanceof Model) {
            $morphClass = $model->getMorphClass();
            $mainKey = $primaryKey ?? $model->getKeyName();
        } else {
            $morphClass = $model;
            $mainKey = $primaryKey ?? 'id';
        }

        $auditModel = config('auditable.model');
        $auditTable = (new $auditModel())->getTable();
        $usersTable = (string) config('auditable.users_table', 'users');
        $prefix ??= (string) config('auditable.column_prefix', self::DEFAULT_COLUMN_PREFIX);

        // Sem select explícito, addSelect() abaixo descartaria o "*".
        if ($base->columns === null) {
            $base->select("{$mainTable}.*");
        }

        $outputs = [];

        foreach ($actions as $action) {
            $event = strtolower($action);
            $alias = self::actionAlias($event);
            $aggregate = $event === 'created' ? 'MIN' : 'MAX';

            $picked = $base->newQuery()
                ->from($auditTable)
                ->selectRaw("{$aggregate}(id) as target_id")
                ->where('subject_type', $morphClass)
                ->where('event', $event)
                ->groupBy('subject_id');

            $rows = $base->newQuery()
                ->from("{$auditTable} as a")
                ->joinSub($picked, 'picked', 'a.id', '=', 'picked.target_id')
                ->leftJoin("{$usersTable} as u", 'u.id', '=', 'a.created_by')
                ->select([
                    "a.subject_id as {$alias}_sid",
                    "a.created_at as {$alias}_at",
                    "u.{$userColumn} as {$alias}_by",
                ]);

            $base->leftJoinSub($rows, $alias, "{$alias}.{$alias}_sid", '=', "{$mainTable}.{$mainKey}");

            [$outputBy, $outputAt] = ["{$prefix}{$event}_by", "{$prefix}{$event}_at"];

            $base->addSelect([
                "{$alias}.{$alias}_by as {$outputBy}",
                "{$alias}.{$alias}_at as {$outputAt}",
            ]);

            $outputs[] = [$outputBy, $outputAt];
        }

        // Formata nas linhas cruas, ANTES de o Eloquent montar os models: assim
        // os models não ficam "alterados" com colunas que não existem na tabela.
        $base->afterQuery(function ($rows) use ($outputs, $userColumn, $dateFormat) {
            foreach ($rows as $row) {
                if (! is_object($row)) {
                    continue;
                }

                foreach ($outputs as [$outputBy, $outputAt]) {
                    if ($dateFormat !== null && isset($row->{$outputAt})) {
                        $row->{$outputAt} = self::formatDate($row->{$outputAt}, $dateFormat);
                    }

                    if ($userColumn === 'name' && isset($row->{$outputBy}) && is_string($row->{$outputBy})) {
                        $row->{$outputBy} = self::shortenName($row->{$outputBy});
                    }
                }
            }

            return $rows;
        });

        return $query;
    }

    /** @return array{0: string, 1: string|null} [tabela, apelido] */
    private static function splitFrom(string $from): array
    {
        if (preg_match('/^\s*(\S+)\s+as\s+(\S+)\s*$/i', $from, $m)) {
            return [$m[1], $m[2]];
        }

        return [trim($from), null];
    }

    /** Apelido curto e estável da subconsulta de cada ação. */
    private static function actionAlias(string $event): string
    {
        $canonical = ['created' => 'aud_c', 'updated' => 'aud_u', 'deleted' => 'aud_d', 'restored' => 'aud_r'];

        if (isset($canonical[$event])) {
            return $canonical[$event];
        }

        $slug = preg_replace('/[^a-z0-9]/', '', $event) ?: 'x';

        return 'aud_'.substr($slug, 0, 8);
    }

    /** "João da Silva Pereira" → "João Pereira". */
    private static function shortenName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));

        if ($parts === false || count($parts) < 2) {
            return $name;
        }

        return $parts[0].' '.$parts[count($parts) - 1];
    }

    /** Aceita DD/MM/YYYY HH:mm:ss (e também os tokens do date() do PHP). */
    private static function formatDate(mixed $value, string $format): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);

        if ($timestamp === false) {
            return $value;
        }

        return date(strtr($format, [
            'YYYY' => 'Y', 'YY' => 'y',
            'MM' => 'm', 'DD' => 'd',
            'HH' => 'H', 'mm' => 'i', 'ss' => 's',
        ]), $timestamp);
    }
}
