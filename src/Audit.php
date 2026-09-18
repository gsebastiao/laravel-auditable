<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable;

use Closure;
use Gsebastiao\Auditable\Support\QueryBuilderPendingAudit;

/**
 * Ponto de entrada único do pacote. Não precisa de alias nem de registro.
 *
 *   use Gsebastiao\Auditable\Audit;
 *
 *   Audit::transaction(fn () => ...);        // várias escritas = uma operação
 *   Audit::for('pedido', 5, 'aprovado')      // auditar sem model Eloquent
 *       ->changes(['motivo' => '...'])->save();
 *   Audit::inBatch($id)->get();              // consultas: qualquer método do
 *   Audit::failures()->latest()->get();      // model de auditoria funciona aqui
 *
 * @method static \Illuminate\Database\Eloquent\Builder query()
 * @method static \Illuminate\Database\Eloquent\Builder forRecord(string $subjectType, int|string $subjectId)
 * @method static \Illuminate\Database\Eloquent\Builder inBatch(string $batch)
 * @method static \Illuminate\Database\Eloquent\Builder action(string|array $action)
 * @method static \Illuminate\Database\Eloquent\Builder byUser(int|string $userId)
 * @method static \Illuminate\Database\Eloquent\Builder failures()
 * @method static \Illuminate\Database\Eloquent\Builder operationOf(string $subjectType, int|string $subjectId)
 * @method static \Illuminate\Database\Eloquent\Builder operationsOf(string $subjectType, int|string $subjectId)
 * @method static \Illuminate\Database\Eloquent\Builder withoutTenantScope()
 */
final class Audit
{
    private static ?Closure $tenantResolver = null;

    /**
     * Tudo o que for auditado dentro do callback recebe o MESMO batch (id da
     * operação). Não abre transação — use transaction() para isso.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function batch(callable $callback): mixed
    {
        return app(AuditManager::class)->batch($callback);
    }

    /**
     * Abre uma transação de banco E um batch ao mesmo tempo. Se algo lançar
     * uma exceção, as escritas e as auditorias são desfeitas juntas.
     *
     * A transação é aberta na conexão onde os seus dados são gravados (a
     * conexão padrão, ou a que você passar em $connection). Se a auditoria
     * usar uma conexão própria (config 'auditable.connection'), ela também
     * entra numa transação, para voltar atrás junto.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function transaction(callable $callback, ?string $connection = null): mixed
    {
        $db = app('db');
        $data = $db->connection($connection);
        $audit = $db->connection(config('auditable.connection'));
        $callback = Closure::fromCallable($callback);

        $run = $data->getName() === $audit->getName()
            ? fn () => $data->transaction($callback)
            : fn () => $data->transaction(fn () => $audit->transaction($callback));

        return app(AuditManager::class)->batch($run);
    }

    /**
     * O batch aberto neste momento, ou null. Use para passar a operação a uma
     * job da fila (ver useBatch()).
     */
    public static function currentBatch(): ?string
    {
        return app(AuditManager::class)->currentBatch();
    }

    /**
     * Continua um batch existente — tipicamente dentro de uma job:
     *
     *   Audit::useBatch($this->batchId, function () {
     *       // tudo aqui entra no mesmo batch da operação original
     *   });
     *
     * Com callback, o batch anterior é restaurado no fim. Sem callback, o
     * batch fica ativo até ao fim da requisição/job. $batchId null é aceito
     * (a job foi despachada fora de um batch): nesse caso abre-se um novo.
     *
     * @template T
     * @param  (callable(): T)|null  $callback
     * @return T|null
     */
    public static function useBatch(?string $batchId, ?callable $callback = null): mixed
    {
        return app(AuditManager::class)->useBatch($batchId, $callback);
    }

    /**
     * Executa o callback sem gravar nenhuma auditoria (seeders, importações,
     * correções em massa). Só vale para o que corre dentro do callback.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutAuditing(callable $callback): mixed
    {
        return app(AuditManager::class)->withoutAuditing($callback);
    }

    /**
     * Grava uma auditoria SEM precisar de um model Eloquent — para escritas
     * feitas com DB::table() ou tabelas que não têm model.
     *
     *   Audit::for('pessoa', $id, 'updated')
     *       ->changes(['estado_id' => ['old' => 3, 'new' => 7]])
     *       ->save();          // nada é gravado antes do save()
     *
     * $subjectType pode ser um nome livre ('pessoa') ou a classe de um model
     * (Pedido::class) — nesse caso as opções de getAuditOptions() do model
     * são reaproveitadas.
     */
    public static function for(string $subjectType, string|int $subjectId, string $event): QueryBuilderPendingAudit
    {
        return QueryBuilderPendingAudit::make($subjectType, $subjectId, $event);
    }

    /**
     * Diz ao pacote como descobrir o tenant atual (multitenancy por coluna).
     * Chame no boot() do seu AppServiceProvider:
     *
     *   Audit::resolveTenantUsing(fn () => auth()->user()?->empresa_id);
     *
     * Ao contrário de uma closure dentro de config/auditable.php, isto não
     * impede o `php artisan config:cache`.
     */
    public static function resolveTenantUsing(?callable $resolver): void
    {
        self::$tenantResolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    /** @internal usado pelo DefaultContextResolver */
    public static function tenantResolver(): ?Closure
    {
        return self::$tenantResolver;
    }

    /**
     * Qualquer outro método é repassado ao model de auditoria configurado,
     * então Audit::inBatch(...), Audit::where(...), Audit::latest() etc.
     * funcionam sem importar o model.
     *
     * @param  array<int, mixed>  $parameters
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        $model = config('auditable.model');

        return $model::$method(...$parameters);
    }
}
