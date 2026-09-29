<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Concerns;

use Gsebastiao\Auditable\AuditManager;
use Gsebastiao\Auditable\Support\AuditOptions;
use Gsebastiao\Auditable\Support\ManualAuditPayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Throwable;

/**
 * Torna um model auditável. Basta:
 *
 *   class Produto extends Model
 *   {
 *       use Auditable;
 *   }
 *
 * A partir daí create(), update(), save() e delete() gravam auditoria
 * sozinhos. Para personalizar, defina getAuditOptions() no model.
 */
trait Auditable
{
    /**
     * Estado CRU do registro imediatamente antes do último update,
     * capturado no evento `updating`. Usado por audit() para refazer o diff
     * depois de o Eloquent já ter sincronizado o "original".
     *
     * @var array<string, mixed>
     */
    protected array $auditOldAttributes = [];

    /**
     * true entre o `restoring` e o fim do save() que o restore() faz por
     * dentro. Serve para esse save() não gravar um 'updated' além do
     * 'restored' (a mesma operação ficava duas vezes no histórico).
     */
    protected bool $auditRestoring = false;

    public static function bootAuditable(): void
    {
        static::updating(function (Model $model): void {
            $model->auditOldAttributes = $model->getRawOriginal();
        });

        foreach (['created', 'updated', 'deleted'] as $event) {
            static::registerModelEvent($event, function (Model $model) use ($event): void {
                $options = $model->getAuditOptions();

                // O save() do restore() fica registado como 'restored', não
                // como 'updated' — mas só se 'restored' for gravado; senão o
                // 'updated' é o único rasto do restauro e mantém-se.
                if ($event === 'updated' && $model->auditRestoring && $options->allowsEvent('restored')) {
                    return;
                }

                if ($options->allowsEvent($event)) {
                    app(AuditManager::class)->record($model, $event);
                }
            });
        }

        // Só existe com SoftDeletes; e só é gravado se 'restored' estiver em events().
        if (method_exists(static::class, 'restored')) {
            static::registerModelEvent('restoring', function (Model $model): void {
                $model->auditRestoring = true;
            });

            // 'saved' corre sempre no fim do save(), mesmo quando o restore
            // não muda nada (e não há 'updated'): a marca nunca fica presa.
            static::saved(function (Model $model): void {
                $model->auditRestoring = false;
            });

            static::registerModelEvent('restored', function (Model $model): void {
                if ($model->getAuditOptions()->allowsEvent('restored')) {
                    app(AuditManager::class)->record($model, 'restored');
                }
            });
        }
    }

    /**
     * Sobrescreva no seu model para configurar a auditoria. O padrão audita
     * create/update/delete de todos os campos (menos password e remember_token).
     */
    public function getAuditOptions(): AuditOptions
    {
        return AuditOptions::defaults();
    }

    /** @internal usado pelo AuditManager */
    public function getAuditOldAttributes(): array
    {
        return $this->auditOldAttributes;
    }

    // ------------------------------------------------------------------
    // Consultar
    // ------------------------------------------------------------------

    /** Todas as auditorias DESTE registro: $produto->audits. */
    public function audits(): MorphMany
    {
        return $this->morphMany(config('auditable.model'), 'subject');
    }

    /**
     * Auditorias de um registro pelo id, sem carregá-lo:
     *   Produto::auditsFor(42)->get();
     *   Produto::auditsFor(42)->action('aprovado')->get();
     */
    public static function auditsFor(int|string $id): Builder
    {
        $model = config('auditable.model');

        return $model::query()->forRecord(static::class, $id)->latest()->orderByDesc('id');
    }

    /** Id do batch da última operação que tocou este registro. */
    public function batchOf(): ?string
    {
        return $this->audits()->latest()->orderByDesc('id')->value('batch');
    }

    /**
     * A ÚLTIMA operação completa em que este registro participou — as linhas
     * de TODAS as tabelas gravadas no mesmo batch.
     */
    public function operation(): Builder
    {
        return static::operationFor($this->getKey());
    }

    public static function operationFor(int|string $id): Builder
    {
        $model = config('auditable.model');

        return $model::operationOf(static::class, $id);
    }

    /**
     * TODAS as operações em que este registro participou (cada batch com as
     * linhas de todas as tabelas). É o histórico completo, pronto para
     * agrupar por batch.
     */
    public function operations(): Builder
    {
        return static::operationsFor($this->getKey());
    }

    public static function operationsFor(int|string $id): Builder
    {
        $model = config('auditable.model');

        return $model::operationsOf(static::class, $id);
    }

    // ------------------------------------------------------------------
    // Registrar manualmente
    // ------------------------------------------------------------------

    /**
     * Grava uma ação com nome livre (sempre uma linha NOVA):
     *   $pedido->auditAction('aprovado');
     *   $pedido->auditAction('email_reenviado', ['para' => $email]);
     *
     * @param  array<string, mixed>  $changes
     */
    public function auditAction(string $action, array $changes = []): void
    {
        app(AuditManager::class)->recordAction($this, $action, $changes);
    }

    /**
     * Grava uma falha. `changes` recebe só $message (segura para o usuário);
     * a exceção, o SQL e o trace vão para `debug_info` (só para o dev).
     *
     *   try { ... } catch (\Throwable $e) {
     *       $fatura->auditFailure('pagamento', $e, ['gateway' => 'mpesa']);
     *       throw $e;
     *   }
     *
     * @param  array<string, mixed>  $context  Dados extra para investigar (vão para debug_info).
     */
    public function auditFailure(string $action, Throwable $exception, array $context = [], ?string $message = null): void
    {
        app(AuditManager::class)->recordFailure($this, $action, $exception, $context, $message);
    }

    /**
     * Personaliza a entrada automática que ESTE objeto acabou de gravar.
     *
     *   $pedido = Pedido::create($dados)->audit(createdBy: $vendedor->id);
     *
     * Regras:
     *   - Substitui a última entrada automática deste objeto (não cria outra).
     *   - Se o objeto ainda não gravou nada, cria uma entrada nova.
     *   - Tudo o que não for passado continua automático.
     *   - $event só muda o nome gravado ("created" vira "importado", por exemplo).
     *
     * Para ACRESCENTAR uma linha em vez de substituir, use auditAction().
     *
     * @param  string|null              $batch        Força o batch.
     * @param  class-string|Model|null  $subjectType  Grava a entrada em nome de OUTRO model.
     * @param  string|int|null          $subjectId    Id desse outro registro.
     * @param  string|null              $event        Nome do evento gravado.
     * @param  array<string,mixed>|null $changes      Conteúdo de `changes` (padrão: automático).
     * @param  array<string,mixed>|null $debugInfo    Conteúdo de `debug_info` (padrão: automático).
     * @param  string|int|null          $createdBy    Autor (padrão: usuário logado).
     * @param  string|int|null          $tenantId     Tenant (só com tenancy por coluna ligado).
     * @param  string|null              $createdAt    Data 'Y-m-d H:i:s' (padrão: agora).
     * @param  string|null              $updatedAt    Data 'Y-m-d H:i:s' (padrão: agora).
     */
    public function audit(
        ?string $batch = null,
        string|Model|null $subjectType = null,
        string|int|null $subjectId = null,
        ?string $event = null,
        ?array $changes = null,
        ?array $debugInfo = null,
        string|int|null $createdBy = null,
        string|int|null $tenantId = null,
        ?string $createdAt = null,
        ?string $updatedAt = null,
    ): static {
        $resolvedSubjectType = match (true) {
            $subjectType === null => null,
            $subjectType instanceof Model => $subjectType->getMorphClass(),
            is_subclass_of($subjectType, Model::class) => (new $subjectType())->getMorphClass(),
            default => $subjectType,
        };

        app(AuditManager::class)->recordManual($this, new ManualAuditPayload(
            batch: $batch,
            subjectType: $resolvedSubjectType,
            subjectId: $subjectId,
            event: $event,
            changes: $changes,
            debugInfo: $debugInfo,
            createdBy: $createdBy,
            tenantId: $tenantId,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        ));

        return $this;
    }
}
