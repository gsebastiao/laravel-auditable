<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Anexa colunas de auditoria ("quem/quando") à query de LISTAGEM de um model.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POR QUE ISTO EXISTE — e por que é DIFERENTE de $model->audits
 * ─────────────────────────────────────────────────────────────────────────────
 * O resto do pacote responde à pergunta "qual é o HISTÓRICO deste registro?"
 * — e responde com LINHAS da tabela de auditoria (uma por evento), via relação
 * Eloquent ($model->audits, Model::auditsFor($id)). Isso é perfeito para um
 * drill-down: você abre um registro e vê tudo que aconteceu com ele.
 *
 * Mas uma GRELHA (DataTable) faz outra pergunta, sobre MUITOS registros de uma
 * vez: "para cada linha desta página, quem criou e quando? quem alterou por
 * último e quando?". Responder isso com a relação seria um N+1 clássico — uma
 * consulta de auditoria por linha exibida. Numa página de 50 registros, 50
 * idas ao banco só para preencher quatro colunas.
 *
 * Esta classe resolve isso de outra forma: adiciona `audit_created_by`,
 * `audit_created_at`, `audit_updated_by`, `audit_updated_at` (e outras ações, se
 * você pedir) como COLUNAS na própria query principal, via LEFT JOIN de
 * subconsultas agregadas. Uma query só, sem N+1, pronta para o DataTable ordenar
 * e paginar. O prefixo `audit_` (configurável) mantém o par _by/_at consistente
 * em toda ação e evita colisão com created_at/updated_at/deleted_at nativos.
 *
 * Regra prática de qual usar:
 *   • Histórico de UM registro  →  $model->audits / Model::auditsFor($id)
 *   • Colunas numa LISTAGEM     →  esta classe (AuditColumnJoiner)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * O QUE ESTA CLASSE **NÃO** É
 * ─────────────────────────────────────────────────────────────────────────────
 * Ela é para LEITURA/exibição. Ela não grava nada. A gravação continua 100% por
 * eventos do Eloquent (trait Auditable + AuditManager). Pense nela como uma view
 * de conveniência montada em tempo de query.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DETALHE POLIMÓRFICO (importante para quem vem do esquema antigo)
 * ─────────────────────────────────────────────────────────────────────────────
 * A tabela de auditoria deste pacote é POLIMÓRFICA: ela não guarda o nome da
 * tabela de origem, guarda `subject_type` (a morph class do model) e
 * `subject_id`. Por isso o filtro das subconsultas é POR MORPH CLASS, não por
 * nome de tabela. É o que garante que a auditoria do Produto não se misture com
 * a de outro model que porventura compartilhe ids. A morph class é resolvida
 * via $model->getMorphClass(), então morphMap customizado é respeitado.
 */
final class AuditColumnJoiner
{
    /**
     * Prefixo aplicado a TODAS as colunas de auditoria emitidas na grelha.
     *
     * Por que um prefixo em tudo, em vez de tratar caso a caso: `created_at`,
     * `updated_at` e `deleted_at` são colunas NATIVAS do Eloquent, com cast
     * automático de datetime. Se emitíssemos uma coluna com um desses nomes, ela
     * colidiria com a nativa da própria tabela do model e o Eloquent tentaria dar
     * cast na string já formatada — quebrando. Prefixar todas as colunas na raiz
     * (audit_created_at, audit_updated_at, …) elimina a colisão de vez e, de
     * quebra, mantém o par _by/_at SEMPRE consistente — sem exceções nem sufixos
     * especiais para umas ações e não outras. Também deixa explícito na grelha que
     * aquela coluna vem da auditoria, não da tabela.
     *
     * O prefixo é configurável (parâmetro $prefix / config('auditable.
     * column_prefix')) para quem quiser outro. String vazia é aceita, mas aí você
     * volta a assumir o risco de colisão com created_at/updated_at/deleted_at.
     */
    public const DEFAULT_COLUMN_PREFIX = 'audit_';

    /**
     * Aplica os JOINs de auditoria a uma query de listagem.
     *
     * @param  Builder|QueryBuilder  $query
     *         A query da grelha. Pode ser um Eloquent Builder (o caso comum,
     *         Model::query()) ou um Query Builder puro.
     *
     * @param  class-string<Model>|Model  $model
     *         O model (instância ou nome de classe) cuja listagem está sendo
     *         montada. É dele que sai a morph class e o nome/apelido da tabela
     *         principal para o ON do JOIN.
     *
     * @param  array<int, string>  $actions
     *         Quais eventos virar coluna. O default — created e updated — é o que
     *         faz sentido numa grelha normal: "quem criou/quando" e "quem alterou
     *         por último/quando". Veja a nota sobre `deleted`/`restored` abaixo.
     *
     * @param  string  $userColumn
     *         Qual coluna da tabela `users` exibir como "quem" (name, email,
     *         username…). Com 'name', aplica-se um encurtamento para
     *         "primeiro + último" nome (evita nomes gigantes na grelha).
     *
     * @param  string  $dateFormat
     *         Formato de data no padrão amigável (DD/MM/YYYY HH:i:s), convertido
     *         para o do MySQL internamente. A data sai JÁ FORMATADA como string
     *         — por isso os apelidos nunca colidem com colunas com cast.
     *
     * @param  string|null  $primaryKey
     *         Coluna id da tabela principal para casar o JOIN. Default: a
     *         chave primária do model.
     *
     * @param  string|null  $prefix
     *         Prefixo das colunas emitidas. Default: 'audit_' (ou o configurado em
     *         config('auditable.column_prefix')). Ex.: created → audit_created_by,
     *         audit_created_at. Passar '' remove o prefixo, mas reintroduz o risco de
     *         colisão com created_at/updated_at/deleted_at nativos — evite.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * SOBRE INCLUIR `deleted` / `restored`
     * ─────────────────────────────────────────────────────────────────────────
     * Por padrão NÃO incluímos, de propósito. Numa grelha comum de um model com
     * SoftDeletes, o global scope já esconde os registros apagados — então uma
     * coluna "audit_deleted_by/at" ficaria sempre vazia, custando dois JOINs por
     * linha à toa. Só faz sentido incluir `deleted`/`restored` quando a PRÓPRIA
     * grelha é uma lixeira (withTrashed()/onlyTrashed()). Nesse caso, passe
     * explicitamente: actions: ['created', 'updated', 'deleted', 'restored'].
     *
     * @return Builder|QueryBuilder  A mesma query, com as colunas anexadas.
     */
    public static function apply(
        Builder|QueryBuilder $query,
        string|Model $model,
        array $actions = ['created', 'updated'],
        string $userColumn = 'name',
        string $dateFormat = 'DD/MM/YYYY HH:i:s',
        ?string $primaryKey = null,
        ?string $prefix = null,
    ): Builder|QueryBuilder {
        $model = is_string($model) ? new $model() : $model;

        $auditTable  = config('auditable.table', 'audits');
        $usersTable  = config('auditable.users_table', 'users');
        $morphClass  = $model->getMorphClass();
        $mainTable   = $model->getTable();
        $mainKey     = $primaryKey ?? $model->getKeyName();
        $mysqlFormat = self::toMysqlDateFormat($dateFormat);
        $prefix      = $prefix ?? config('auditable.column_prefix', self::DEFAULT_COLUMN_PREFIX);

        foreach ($actions as $action) {
            $event    = strtolower($action);
            $alias    = self::actionAlias($event);
            $userAias = "{$alias}_u";

            // Qual auditoria representa a ação:
            //   • created  → a PRIMEIRA (menor id)  — quando o registro nasceu.
            //   • os demais → a MAIS RECENTE (maior id) — último update, último
            //     delete, último restore, ou a última ocorrência de uma ação de
            //     domínio nomeada.
            $aggregate = ($event === 'created') ? 'MIN' : 'MAX';

            // Subconsulta: para cada subject_id, pega a linha-alvo daquele evento
            // (via id agregado) e devolve user_id + a data já formatada. O JOIN
            // interno reduz o histórico àquela única linha por registro, então o
            // LEFT JOIN externo com a tabela principal fica 1:1.
            //
            // SEGURANÇA: subject_type e event entram como BINDINGS (?), nunca
            // concatenados. Aparecem duas vezes cada (subconsulta interna + WHERE
            // externo), então são quatro placeholders, na ordem em que o SQL os lê.
            // O nome do formato de data (mysqlFormat) e os identificadores de
            // tabela vêm da config/model — não de input — e ficam interpolados.
            $subquery = DB::raw("(
                SELECT
                    a.subject_id,
                    a.user_id,
                    DATE_FORMAT(a.created_at, '{$mysqlFormat}') AS action_at
                FROM {$auditTable} a
                INNER JOIN (
                    SELECT subject_id, {$aggregate}(id) AS target_id
                    FROM {$auditTable}
                    WHERE subject_type = ?
                        AND event = ?
                    GROUP BY subject_id
                ) picked
                    ON picked.subject_id = a.subject_id
                   AND picked.target_id = a.id
                WHERE a.subject_type = ?
                    AND a.event = ?
            ) {$alias}");

            $query->leftJoin($subquery, "{$alias}.subject_id", '=', "{$mainTable}.{$mainKey}");

            // Bindings da subconsulta de join, na ordem dos ? acima.
            $bindTarget = $query instanceof Builder ? $query->getQuery() : $query;
            $bindTarget->addBinding([$morphClass, $event, $morphClass, $event], 'join');

            // JOIN com users para traduzir user_id → nome/email exibível.
            $query->leftJoin(
                "{$usersTable} as {$userAias}",
                "{$userAias}.id",
                '=',
                "{$alias}.user_id"
            );

            [$outputBy, $outputAt] = self::outputNames($event, $prefix);

            $query->addSelect(
                self::userColumnExpression($userColumn, $userAias, $outputBy),
                DB::raw("{$alias}.action_at AS {$outputAt}")
            );
        }

        return $query;
    }

    /**
     * Nomes das colunas de saída para uma ação. O prefixo é aplicado a TODAS as
     * colunas de forma uniforme, o que garante o par _by/_at consistente em toda
     * ação e, ao mesmo tempo, evita colisão com created_at/updated_at/deleted_at
     * nativos do Eloquent (nenhuma coluna emitida usa esses nomes crus).
     *
     * O resultado é estável e previsível (com o prefixo default 'audit_'):
     *   created  → audit_created_by,  audit_created_at
     *   updated  → audit_updated_by,  audit_updated_at
     *   deleted  → audit_deleted_by,  audit_deleted_at
     *   restored → audit_restored_by, audit_restored_at
     *   aprovado → audit_aprovado_by, audit_aprovado_at
     *
     * @param  string  $event   Nome da ação, já em minúsculas.
     * @param  string  $prefix  Prefixo a aplicar (ex.: 'audit_'). Pode ser ''.
     * @return array{0: string, 1: string}  [colunaUsuario, colunaData]
     */
    private static function outputNames(string $event, string $prefix): array
    {
        return ["{$prefix}{$event}_by", "{$prefix}{$event}_at"];
    }

    /**
     * Alias curto e único da subconsulta de uma ação. Mapeia os quatro eventos
     * canônicos para apelidos estáveis; para ações de domínio arbitrárias, deriva
     * um prefixo do próprio nome (mantendo-o determinístico).
     */
    private static function actionAlias(string $event): string
    {
        static $canonical = [
            'created'  => 'aud_c',
            'updated'  => 'aud_u',
            'deleted'  => 'aud_d',
            'restored' => 'aud_r',
        ];

        if (isset($canonical[$event])) {
            return $canonical[$event];
        }

        // Ação de domínio (aprovado, exportado…): prefixo derivado, sem espaços
        // nem caracteres problemáticos para um alias SQL.
        $slug = preg_replace('/[^a-z0-9]/', '', $event) ?: 'x';

        return 'aud_' . substr($slug, 0, 8);
    }

    /**
     * Expressão SQL da coluna "quem". Para a coluna `name`, encurta para
     * "primeiro + último" nome — um "João da Silva Pereira" vira "João Pereira"
     * na grelha, sem cortar informação essencial. Para qualquer outra coluna
     * (email, username), devolve o valor direto.
     */
    private static function userColumnExpression(
        string $userColumn,
        string $userAlias,
        string $outputBy,
    ): \Illuminate\Database\Query\Expression {
        if ($userColumn === 'name') {
            return DB::raw("CASE
                WHEN LOCATE(' ', {$userAlias}.name) > 0
                THEN CONCAT(
                    SUBSTRING_INDEX({$userAlias}.name, ' ', 1),
                    ' ',
                    SUBSTRING_INDEX({$userAlias}.name, ' ', -1)
                )
                ELSE {$userAlias}.name
            END AS {$outputBy}");
        }

        return DB::raw("{$userAlias}.{$userColumn} AS {$outputBy}");
    }

    /**
     * Converte um formato de data amigável (DD/MM/YYYY HH:i:s) para o do MySQL
     * (%d/%m/%Y %H:%i:%s). Tabela de mapeamento herdada do BaseModel original,
     * mantida por compatibilidade de quem já usava aqueles tokens.
     */
    private static function toMysqlDateFormat(string $format): string
    {
        $map = array_merge(
            ['DD' => '%d', 'MM' => '%m', 'YYYY' => '%Y', 'YY' => '%y'],
            ['mm' => '%i', 'd' => '%d', 'm' => '%m', 'y' => '%y', 'Y' => '%Y'],
            ['H' => '%H', 'HH' => '%H', 'i' => '%i', 's' => '%s', 'ss' => '%s'],
        );

        return strtr($format, $map);
    }
}
