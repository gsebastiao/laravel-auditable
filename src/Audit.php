<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable;

use Illuminate\Database\DatabaseManager;

/**
 * Ponto de entrada estático para agrupar auditorias.
 *
 * Fachada fina sobre o AuditManager resolvido do container (não precisa de
 * alias/registro), dando ao dev uma API curta para o caso mais importante:
 * costurar várias escritas, em várias tabelas, sob um único batch — e depois
 * recuperar a operação inteira a partir de qualquer um dos registros.
 */
final class Audit
{
    /**
     * Agrupa tudo o que for auditado dentro do callback sob um MESMO batch id.
     *
     * Toda auditoria disparada aqui dentro — created/updated/deleted de
     * QUALQUER model, ou auditAction()/auditFailure() manuais — recebe o mesmo
     * batch. Depois, pesquisando por qualquer um dos registros, você recupera a
     * operação inteira.
     *
     *   Audit::batch(function () {
     *       $produto = Produto::create([...]);   // batch X
     *       $cliente = Cliente::create([...]);   // batch X
     *       foreach ($itens as $i) {
     *           Item::create([...]);             // batch X
     *       }
     *   });
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
     * O caso mais comum: uma transação de banco cujas escritas devem ser
     * auditadas como UMA operação. Abre a transação E o batch juntos.
     *
     * Se qualquer coisa lá dentro lançar, o rollback do banco desfaz as
     * escritas — e as auditorias de sucesso, por estarem na mesma transação e
     * na mesma conexão, revertem junto. É a semântica "naquela transação" que o
     * BaseModel original tinha, agora explícita e opt-in.
     *
     *   Audit::transaction(function () {
     *       $pedido = Pedido::create([...]);
     *       $pedido->itens()->createMany([...]);
     *       $estoque->baixar([...]);
     *   }); // tudo sob o mesmo batch; se falhar, tudo volta atrás
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $db = app(DatabaseManager::class)->connection(config('auditable.connection'));

        return app(AuditManager::class)->batch(
            fn () => $db->transaction($callback),
        );
    }

    /**
     * O batch atualmente aberto, ou null. Útil para propagar para uma job na
     * fila: passe o id no payload da job e reabra com Audit::useBatch() lá.
     */
    public static function currentBatch(): ?string
    {
        return app(AuditManager::class)->currentBatch();
    }

    /**
     * Reabre um batch específico — tipicamente dentro de uma job, para que a
     * auditoria assíncrona fique no mesmo batch da operação que a disparou.
     */
    public static function useBatch(string $batchId): void
    {
        app(AuditManager::class)->useBatch($batchId);
    }
}
