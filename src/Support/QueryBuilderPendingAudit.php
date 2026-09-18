<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Gsebastiao\Auditable\AuditManager;
use Illuminate\Database\Eloquent\Model;

/**
 * Entrada de auditoria "pendente", devolvida por Audit::for(...) e por
 * DB::table(...)->audit(...). Configure encadeando e grave com ->save():
 *
 *   Audit::for('pessoa', $id, 'updated')
 *       ->changes(['estado_id' => ['old' => 3, 'new' => 7]])
 *       ->resolveMap(['estado_id' => ResolveMap::direct('Estado', 'estados')])
 *       ->save();
 *
 * NADA é gravado antes do save(). Chamar save() duas vezes grava só uma.
 *
 * Opções (except/only/events/onlyDirty/logEmpty/resolveMap):
 *   - Se $subjectType for a classe de um model com getAuditOptions(), essas
 *     opções são o ponto de partida e o que você encadear é aplicado por cima:
 *     except() e resolveMap() acrescentam; only(), events(), onlyDirty() e
 *     logEmpty() trocam o valor.
 *   - Sem model, parte de AuditOptions::defaults() quando você encadeia algo;
 *     sem nada encadeado, changes() é gravado exatamente como veio.
 *   - ->options($opcoes) substitui tudo de uma vez.
 */
final class QueryBuilderPendingAudit
{
    /** @var array<string, mixed> */
    private array $changesRaw = [];

    private ?AuditOptions $options = null;

    /** @var array<string, mixed>|null */
    private ?array $debugInfo = null;

    private ?string $batch = null;

    private string|int|null $createdBy = null;

    private string|int|null $tenantId = null;

    private ?string $createdAt = null;

    private ?string $updatedAt = null;

    private bool $saved = false;

    public function __construct(
        private readonly AuditManager $manager,
        private readonly string $subjectType,
        private readonly string|int $subjectId,
        private readonly string $event,
        private readonly ?AuditOptions $defaultOptions = null,
        private readonly ?string $connection = null,
    ) {}

    /**
     * @param  string       $subjectType  Nome livre ('pessoa') ou classe de um model (Pedido::class).
     * @param  string|null  $connection   Conexão onde estão as tabelas do resolveMap (padrão: a do
     *                                    model, se houver, senão a conexão padrão).
     */
    public static function make(string $subjectType, string|int $subjectId, string $event, ?string $connection = null): self
    {
        $options = null;

        if (is_subclass_of($subjectType, Model::class)) {
            $model = new $subjectType();
            $subjectType = $model->getMorphClass();
            $connection ??= $model->getConnectionName();

            if (method_exists($model, 'getAuditOptions')) {
                $options = $model->getAuditOptions();
            }
        }

        return new self(app(AuditManager::class), $subjectType, $subjectId, $event, $options, $connection);
    }

    /**
     * O que mudou. Dois formatos (detectados automaticamente):
     *   diff:    ['campo' => ['old' => 1, 'new' => 2], ...]
     *   retrato: ['campo' => valor, ...]
     *
     * @param  array<string, mixed>  $changes
     */
    public function changes(array $changes): self
    {
        $this->changesRaw = $changes;

        return $this;
    }

    /**
     * Acrescenta traduções de campos (as do model, se houver, continuam).
     *
     * @param array<string, array<string, mixed>> $map
     */
    public function resolveMap(array $map): self
    {
        $options = $this->ensureOptions();
        $options->resolveMap(array_merge($options->resolveMap, $map));

        return $this;
    }

    /** @param array<int, string> $attributes */
    public function except(array $attributes): self
    {
        $this->ensureOptions()->except($attributes);

        return $this;
    }

    /** @param array<int, string> $attributes */
    public function only(array $attributes): self
    {
        $this->ensureOptions()->only($attributes);

        return $this;
    }

    /** @param array<int, string> $events */
    public function events(array $events): self
    {
        $this->ensureOptions()->events($events);

        return $this;
    }

    public function onlyDirty(bool $value = true): self
    {
        $this->ensureOptions()->onlyDirty($value);

        return $this;
    }

    public function logEmpty(bool $value = true): self
    {
        $this->ensureOptions()->logEmpty($value);

        return $this;
    }

    /** Substitui todas as opções de uma vez (inclusive as herdadas do model). */
    public function options(AuditOptions $options): self
    {
        $this->options = clone $options;

        return $this;
    }

    /** @param array<string, mixed> $debugInfo */
    public function debugInfo(array $debugInfo): self
    {
        $this->debugInfo = $debugInfo;

        return $this;
    }

    public function batch(string $batch): self
    {
        $this->batch = $batch;

        return $this;
    }

    public function createdBy(string|int $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function tenantId(string|int $tenantId): self
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    public function createdAt(string $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function updatedAt(string $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /** Grava a entrada. Uma segunda chamada é ignorada. */
    public function save(): void
    {
        if ($this->saved) {
            return;
        }

        $this->saved = true;

        $this->manager->recordForQueryBuilder(
            subjectType: $this->subjectType,
            subjectId: $this->subjectId,
            event: $this->event,
            changesRaw: $this->changesRaw,
            options: $this->options ?? $this->defaultOptions,
            debugInfo: $this->debugInfo,
            batch: $this->batch,
            createdBy: $this->createdBy,
            tenantId: $this->tenantId,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            connection: $this->connection,
        );
    }

    private function ensureOptions(): AuditOptions
    {
        return $this->options ??= $this->defaultOptions !== null
            ? clone $this->defaultOptions
            : AuditOptions::defaults();
    }
}
