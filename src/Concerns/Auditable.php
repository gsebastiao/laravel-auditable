<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Gsebastiao\Auditable\AuditManager;
use Gsebastiao\Auditable\Support\AuditOptions;

/**
 * Torna um model Eloquent auditável.
 *
 * Uso:
 *
 *   class Produto extends Model
 *   {
 *       use Auditable;
 *
 *       public function getAuditOptions(): AuditOptions
 *       {
 *           return AuditOptions::defaults()
 *               ->except(['updated_at'])
 *               ->resolveMap([
 *                   'status_id' => ResolveMap::direct('Status', 'status', 'nome'),
 *               ]);
 *       }
 *   }
 *
 * Toda a auditoria acontece por eventos Eloquent — nada de DB::table() nem
 * métodos estáticos de escrita. Isto significa que:
 *   - casts e mutators são respeitados (lemos o model hidratado);
 *   - Global Scopes de tenancy são respeitados automaticamente;
 *   - save(), create(), update() e delete() são todos cobertos, sem que o
 *     consumidor precise trocar como escreve.
 *
 * O hook bootAuditable() é chamado pelo Eloquent automaticamente ao inicializar
 * o model (convenção boot{TraitName}), então o boot() do consumidor fica livre.
 */
trait Auditable
{
    /**
     * Guarda os atributos originais capturados no "updating", para comparar
     * no "updated". Necessário porque no "updated" o getOriginal() já reflete
     * o novo estado. Mesma técnica usada pela Spatie.
     *
     * @var array<string, mixed>
     */
    protected array $auditOldAttributes = [];

    /**
     * Ponto de override do consumidor. O default audita create/update/delete
     * de todos os atributos, sem resolveMap.
     */
    public function getAuditOptions(): AuditOptions
    {
        return AuditOptions::defaults();
    }

    public static function bootAuditable(): void
    {
        static::updating(function (Model $model): void {
            // Congela o estado anterior (com casts) antes da escrita.
            $model->auditOldAttributes = $model->getOriginal();
        });

        foreach (['created', 'updated', 'deleted'] as $event) {
            static::registerModelEvent($event, function (Model $model) use ($event): void {
                $options = $model->getAuditOptions();

                if (! in_array($event, $options->events, true)) {
                    return;
                }

                app(AuditManager::class)->record($model, $event, $model->auditOldAttributes);
            });
        }

        // Suporte a soft delete: audita o restore como um evento próprio, se o
        // model usar SoftDeletes e o consumidor incluir 'restored' nos eventos.
        if (method_exists(static::class, 'restored')) {
            static::registerModelEvent('restored', function (Model $model): void {
                $options = $model->getAuditOptions();

                if (! in_array('restored', $options->events, true)) {
                    return;
                }

                app(AuditManager::class)->record($model, 'restored', []);
            });
        }
    }

    /**
     * Relação inversa: todas as auditorias deste registro.
     */
    public function audits(): MorphMany
    {
        return $this->morphMany(config('auditable.model'), 'subject');
    }

    /**
     * O batch (id) da última operação que tocou este registro. Devolve só o
     * identificador; para as linhas em si, use operation().
     */
    public function batchOf(): ?string
    {
        return $this->audits()->latest()->value('batch');
    }

    /**
     * TUDO o que aconteceu na mesma operação que este registro — atravessando
     * tabelas. Exatamente o cenário que você descreveu: você tem o cliente em
     * mãos, chama isto, e recebe de volta o produto, os itens e o próprio
     * cliente — tudo o que foi gravado naquele mesmo batch.
     *
     *   $cliente = Cliente::find($id);
     *   $cliente->operation()->get();                 // as linhas da operação
     *   $cliente->operation()->get()->groupBy('subject_type'); // agrupado por tabela
     *
     * Versão estática, quando você só tem o id: Cliente::operationFor($id).
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function operation(): \Illuminate\Database\Eloquent\Builder
    {
        return static::operationFor($this->getKey());
    }

    /**
     * Histórico de UM registro específico, por id, SEM precisar carregá-lo.
     *
     * Devolve um query builder, então você filtra à vontade:
     *
     *   Produto::auditsFor(42)->get();                       // tudo do id 42
     *   Produto::auditsFor(42)->action('aprovado')->get();   // só aprovações
     *   Produto::auditsFor(42)->failures()->latest()->first(); // última falha
     *   Produto::auditsFor(42)->byUser($id)->get();          // por quem fez
     *
     * Para o registro que você já tem em mãos, prefira a relação: $produto->audits.
     */
    public static function auditsFor(int|string $id): \Illuminate\Database\Eloquent\Builder
    {
        $model = config('auditable.model');

        return $model::query()->forRecord(static::class, $id)->latest();
    }

    /**
     * A OPERAÇÃO INTEIRA a que um registro deste model pertenceu.
     *
     * Descobre o batch da auditoria mais recente do registro e devolve todas as
     * auditorias desse batch — de TODAS as tabelas envolvidas na operação. É o
     * "pesquiso o cliente e recebo o pedido + os itens que entraram junto".
     *
     *   Cliente::operationFor($clienteId)->get();
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function operationFor(int|string $id): \Illuminate\Database\Eloquent\Builder
    {
        $model = config('auditable.model');

        return $model::operationOf(static::class, $id);
    }

    /**
     * Registra uma AÇÃO DE DOMÍNIO arbitrária sobre este registro.
     *
     * Os eventos created/updated/deleted cobrem escritas do Eloquent. Mas
     * muita coisa que você quer auditar não é uma escrita: "aprovou", "reenviou
     * o e-mail", "exportou", "fez login". Isso não dispara evento de model
     * nenhum — então você chama isto explicitamente, com o nome que quiser.
     *
     * Equivale ao parâmetro $action livre que os métodos do BaseModel original
     * recebiam, mas desacoplado da escrita.
     *
     *   $pedido->auditAction('aprovado');
     *   $pedido->auditAction('email_reenviado', ['para' => $email, 'via' => 'ses']);
     *
     * @param  string                $action   Nome livre da ação.
     * @param  array<string, mixed>  $changes  Detalhes opcionais a registrar.
     */
    public function auditAction(string $action, array $changes = []): void
    {
        app(AuditManager::class)->recordAction($this, $action, $changes);
    }

    /**
     * Registra uma FALHA associada a este registro, com debug técnico completo.
     *
     * Use no catch de uma operação que você quis auditar mesmo quando dá errado.
     * O quê fica legível no log; o porquê técnico (stack trace, SQL, request,
     * ambiente) vai para debug_info — visível ao dev, escondido do usuário.
     *
     *   try {
     *       $fatura->update([...]);
     *   } catch (\Throwable $e) {
     *       $fatura->auditFailure('fatura_update', $e, ['payload' => $dados]);
     *       throw $e;
     *   }
     *
     * @param  array<string, mixed>  $context  Dados extra p/ o bloco de debug.
     */
    public function auditFailure(string $action, \Throwable $exception, array $context = []): void
    {
        app(AuditManager::class)->recordFailure($this, $action, $exception, $context);
    }
}
