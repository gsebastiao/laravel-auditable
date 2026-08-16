<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

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
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PORTABILIDADE (SQLite / PostgreSQL / MySQL) — E O QUE APPLY() EXIGE
 * ─────────────────────────────────────────────────────────────────────────────
 * As subconsultas de JOIN não usam nenhuma função específica de motor — nada de
 * DATE_FORMAT, LOCATE, CONCAT ou SUBSTRING_INDEX (todas MySQL-only), que a
 * versão anterior desta classe embutia no SQL. A coluna `_at` sai crua do
 * banco; a formatação amigável (`$dateFormat`) e o encurtamento do nome (quando
 * $userColumn = 'name') acontecem depois, em PHP, sobre as linhas já
 * carregadas — via `afterQuery()` do Eloquent (Laravel 11+, ver PR #50587 do
 * framework). Isso elimina qualquer SQL específico de motor das subconsultas.
 *
 * afterQuery() é uma API do Eloquent\Builder — Query\Builder puro (o que você
 * pega em DB::table(...)) não a possui. Por isso apply() EXIGE um Eloquent
 * Builder e lança InvalidArgumentException se receber um Query Builder puro,
 * em vez de tentar adivinhar formatação de data/nome por motor de banco (o que
 * reintroduziria SQL não-portável, só que espalhado e não testado, para um
 * caso que nenhum exemplo deste README usa). Se seu caso de uso realmente
 * precisa de um Query Builder puro, monte a query com DB::table() normalmente
 * e formate created_at/created_by no PHP do seu lado — esta classe não cobre
 * esse caminho.
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
     * @param  Builder  $query
     *         A query da grelha — precisa ser um Eloquent Builder (o caso comum,
     *         Model::query()). Query Builder puro (DB::table()) não é aceito;
     *         ver nota de portabilidade na classe.
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
     *         Formato de data no padrão amigável (DD/MM/YYYY HH:i:s), aplicado em
     *         PHP sobre o valor já carregado (ver nota de portabilidade acima). A
     *         data sai JÁ FORMATADA como string — por isso os apelidos nunca
     *         colidem com colunas com cast.
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
     * @return Builder  A mesma query, com as colunas anexadas e um afterQuery()
     *         registrado para formatar `_at`/`_by` nas linhas.
     *
     * @throws InvalidArgumentException  Se $query for um Query Builder puro
     *         (sem afterQuery() nativo) — ver nota de portabilidade na classe.
     */
    public static function apply(
        Builder|QueryBuilder $query,
        string|Model $model,
        array $actions = ['created', 'updated'],
        string $userColumn = 'name',
        string $dateFormat = 'DD/MM/YYYY HH:i:s',
        ?string $primaryKey = null,
        ?string $prefix = null,
    ): Builder {
        if (! $query instanceof Builder) {
            throw new InvalidArgumentException(
                'AuditColumnJoiner::apply() exige um Eloquent Builder (Model::query()). '
                . 'Query Builder puro (DB::table()) não tem afterQuery() nativo, então a '
                . 'formatação de data/nome desta classe não tem como ser aplicada de forma '
                . 'portável — ver o docblock da classe, seção "Portabilidade".'
            );
        }

        $model = is_string($model) ? new $model() : $model;

        $auditTable = config('auditable.table', 'audits');
        $usersTable = config('auditable.users_table', 'users');
        $morphClass = $model->getMorphClass();
        $mainTable  = $model->getTable();
        $mainKey    = $primaryKey ?? $model->getKeyName();
        $prefix     = $prefix ?? config('auditable.column_prefix', self::DEFAULT_COLUMN_PREFIX);

        // Base para montar as subconsultas: sempre o Query Builder puro por
        // trás do Eloquent Builder recebido (via getQuery()), nunca o
        // DB::table() direto — assim a subconsulta herda a MESMA conexão do
        // Eloquent Builder ($query), inclusive quando o model usa uma
        // conexão não-default (config('auditable.connection'), tenancy
        // por-database, etc.). newQuery() garante uma instância nova e
        // limpa, sem herdar wheres/joins da query principal.
        $baseQuery = $query->getQuery();

        /** @var array<int, array{outputBy: string, outputAt: string}> $columns pares emitidos, para o afterQuery formatar */
        $columns = [];

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
            // (via id agregado) e devolve created_by + created_at CRU. O JOIN
            // interno reduz o histórico àquela única linha por registro, então o
            // LEFT JOIN externo com a tabela principal fica 1:1. Nenhuma função
            // de formatação de data aqui — ver nota de portabilidade na classe.
            $latestIds = $baseQuery->newQuery()
                ->from($auditTable)
                ->selectRaw("subject_id, {$aggregate}(id) as target_id")
                ->where('subject_type', $morphClass)
                ->where('event', $event)
                ->groupBy('subject_id');

            $latestRows = $baseQuery->newQuery()
                ->from("{$auditTable} as a")
                ->select('a.subject_id', 'a.created_by', 'a.created_at')
                ->joinSub($latestIds, 'picked', function ($join) {
                    $join->on('a.subject_id', '=', 'picked.subject_id')
                        ->on('a.id', '=', 'picked.target_id');
                })
                ->where('a.subject_type', $morphClass)
                ->where('a.event', $event);

            $query->leftJoinSub($latestRows, $alias, function ($join) use ($alias, $mainTable, $mainKey) {
                $join->on("{$alias}.subject_id", '=', "{$mainTable}.{$mainKey}");
            });

            // JOIN com users para traduzir created_by → nome/email exibível.
            $query->leftJoin(
                "{$usersTable} as {$userAias}",
                "{$userAias}.id",
                '=',
                "{$alias}.created_by"
            );

            [$outputBy, $outputAt] = self::outputNames($event, $prefix);

            $query->addSelect([
                "{$userAias}.{$userColumn} as {$outputBy}",
                "{$alias}.created_at as {$outputAt}",
            ]);

            $columns[] = ['outputBy' => $outputBy, 'outputAt' => $outputAt];
        }

        // Formatação em PHP, sobre as linhas já carregadas — substitui o
        // DATE_FORMAT/LOCATE/CONCAT/SUBSTRING_INDEX que a versão anterior
        // embutia no SQL (MySQL-only). afterQuery() roda uma vez por
        // resultado de query, não por linha buscada do banco, então não
        // reintroduz N+1. Cada linha do resultado é um Model hidratado, mas
        // as colunas extra do JOIN (audit_created_by, etc.) ficam acessíveis
        // como atributos dinâmicos normalmente, então a leitura/escrita
        // ->{$outputAt} funciona igual a um stdClass.
        $query->afterQuery(function ($results) use ($columns, $userColumn, $dateFormat) {
            $rows = $results instanceof Collection ? $results : collect($results);

            $rows->each(function ($row) use ($columns, $userColumn, $dateFormat) {
                foreach ($columns as ['outputBy' => $outputBy, 'outputAt' => $outputAt]) {
                    if (isset($row->{$outputAt})) {
                        $row->{$outputAt} = self::formatDate($row->{$outputAt}, $dateFormat);
                    }

                    if ($userColumn === 'name' && isset($row->{$outputBy})) {
                        $row->{$outputBy} = self::shortenName($row->{$outputBy});
                    }
                }
            });

            return $results;
        });

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
     * Encurta um nome completo para "primeiro + último" — um "João da Silva
     * Pereira" vira "João Pereira" na grelha, sem cortar informação essencial.
     * Sem espaço no valor (nome de uma palavra só, ou vazio/null), devolve como
     * veio. Substitui a expressão SQL LOCATE/CONCAT/SUBSTRING_INDEX (MySQL-only)
     * da versão anterior desta classe — mesmo resultado, calculado em PHP.
     */
    private static function shortenName(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return $name;
        }

        $parts = preg_split('/\s+/', trim($name));

        if ($parts === false || count($parts) < 2) {
            return $name;
        }

        return $parts[0] . ' ' . $parts[count($parts) - 1];
    }

    /**
     * Formata uma data/datetime crua (como devolvida pelo banco) no formato
     * amigável pedido (ex.: DD/MM/YYYY HH:i:s). null passa direto. Substitui o
     * DATE_FORMAT (MySQL-only) da versão anterior desta classe — mesmo
     * resultado, calculado em PHP, portátil entre SQLite/PostgreSQL/MySQL.
     *
     * Tokens amigáveis suportados (ordem de checagem do mais longo para o
     * mais curto, para YYYY não ser parcialmente consumido por Y):
     *   YYYY, YY, MM, DD, HH, mm, ss, H, i, s, Y, m, d, y
     */
    private static function formatDate(mixed $value, string $format): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);

        if ($timestamp === false) {
            // Valor não reconhecido como data — devolve cru em vez de mascarar
            // um problema de dados com uma string vazia ou um erro silencioso.
            return $value;
        }

        // Tokens amigáveis (estilo BaseModel original) → tokens de date()/PHP.
        // 'mm' = minuto e 'MM' = mês são propositalmente distintos (mesma
        // convenção do formato amigável original); a ordem de busca abaixo
        // (mais longo primeiro) evita que 'YYYY' seja parcialmente consumido
        // pela entrada de 'Y'.
        $map = [
            'YYYY' => 'Y', 'YY' => 'y',
            'MM' => 'm', 'DD' => 'd',
            'HH' => 'H', 'mm' => 'i', 'ss' => 's',
            'H' => 'H', 'i' => 'i', 's' => 's',
            'Y' => 'Y', 'm' => 'm', 'd' => 'd', 'y' => 'y',
        ];

        uksort($map, fn ($a, $b) => strlen($b) <=> strlen($a));

        $phpFormat = strtr($format, $map);

        return date($phpFormat, $timestamp);
    }
}