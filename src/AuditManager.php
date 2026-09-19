<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable;

use Closure;
use Gsebastiao\Auditable\Contracts\AuditRepository;
use Gsebastiao\Auditable\Contracts\BatchIdGenerator;
use Gsebastiao\Auditable\Contracts\BulkAuditRepository;
use Gsebastiao\Auditable\Contracts\ContextResolver;
use Gsebastiao\Auditable\Support\AuditOptions;
use Gsebastiao\Auditable\Support\ChangeSetBuilder;
use Gsebastiao\Auditable\Support\DebugInfoCollector;
use Gsebastiao\Auditable\Support\ManualAuditPayload;
use Gsebastiao\Auditable\Support\QueueContext;
use Illuminate\Database\Eloquent\Model;
use Throwable;
use WeakMap;

/**
 * O "motor" do pacote: monta cada entrada de auditoria (quem, o quê, quando,
 * em que operação) e entrega ao AuditRepository para gravar.
 *
 * Há UMA instância por requisição/job (registrada como "scoped" no
 * container), por isso o batch aberto e o rastreio de audit() nunca se
 * misturam entre usuários diferentes.
 */
final class AuditManager
{
    private ?string $currentBatch = null;

    private bool $paused = false;

    /**
     * Última entrada gravada AUTOMATICAMENTE para cada objeto de model
     * (created/updated/deleted/restored). É o que audit() personaliza.
     *
     * WeakMap: a chave é o próprio objeto, e a entrada desaparece sozinha
     * quando o objeto sai da memória — nunca aponta para a linha de outro
     * registro, mesmo em jobs longos.
     *
     * @var WeakMap<Model, array{event: string, id: int|string}>
     */
    private WeakMap $lastAutomatic;

    public function __construct(
        private AuditRepository $repository,
        private ContextResolver $context,
        private BatchIdGenerator $batchIds,
        private ChangeSetBuilder $changes,
        private DebugInfoCollector $debug,
    ) {
        $this->lastAutomatic = new WeakMap();
    }

    // ------------------------------------------------------------------
    // Batches e interruptores
    // ------------------------------------------------------------------

    /**
     * Tudo o que for auditado dentro do callback recebe o mesmo batch. Se já
     * houver um batch aberto, reaproveita-o (batches aninhados = um só).
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function batch(callable $callback): mixed
    {
        // Um batch já aberto — ou herdado de quem despachou esta job — é reaproveitado.
        if ($this->currentBatch() !== null) {
            return $callback();
        }

        $this->currentBatch = $this->batchIds->generate();

        try {
            return $callback();
        } finally {
            $this->currentBatch = null;
        }
    }

    /** Abre um batch sem callback (feche com endBatch()). Prefira batch(). */
    public function beginBatch(): string
    {
        return $this->currentBatch ??= (QueueContext::batch() ?? $this->batchIds->generate());
    }

    public function endBatch(): void
    {
        $this->currentBatch = null;
    }

    /**
     * Continua um batch existente. Com callback, o batch anterior é
     * restaurado no fim; sem callback, fica ativo até ao fim da requisição.
     *
     * @template T
     * @param  (callable(): T)|null  $callback
     * @return T|null
     */
    public function useBatch(?string $batchId, ?callable $callback = null): mixed
    {
        if ($callback === null) {
            $this->currentBatch = $batchId;

            return null;
        }

        $previous = $this->currentBatch;
        $this->currentBatch = $batchId ?? $this->currentBatch() ?? $this->batchIds->generate();

        try {
            return $callback();
        } finally {
            $this->currentBatch = $previous;
        }
    }

    /**
     * O batch em uso: o aberto neste processo ou, dentro de uma job, o
     * herdado de quem a despachou (ver QueueContext).
     */
    public function currentBatch(): ?string
    {
        return $this->currentBatch ?? QueueContext::batch();
    }

    /**
     * Transação de banco + batch. A transação é aberta na conexão dos dados
     * ($connection, ou a padrão); se a auditoria usa outra conexão, ela entra
     * numa transação aninhada para ser desfeita junto.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback, ?string $connection = null): mixed
    {
        $db = app('db');
        $data = $db->connection($connection);
        $audit = $db->connection(config('auditable.connection'));
        $callback = Closure::fromCallable($callback);

        $run = $data->getName() === $audit->getName()
            ? fn () => $data->transaction($callback)
            : fn () => $data->transaction(fn () => $audit->transaction($callback));

        return $this->batch($run);
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function withoutAuditing(callable $callback): mixed
    {
        $previous = $this->paused;
        $this->paused = true;

        try {
            return $callback();
        } finally {
            $this->paused = $previous;
        }
    }

    public function isEnabled(): bool
    {
        return ! $this->paused && (bool) config('auditable.enabled', true);
    }

    // ------------------------------------------------------------------
    // Gravação
    // ------------------------------------------------------------------

    /**
     * Evento automático do Eloquent (chamado pelo trait Auditable).
     *
     * @param  array<string, mixed>|null  $oldAttributes  Mantido por compatibilidade; o
     *         estado anterior é lido do próprio model.
     */
    public function record(Model $model, string $event, ?array $oldAttributes = null): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $options = $this->optionsFor($model);

        if ($options === null) {
            return;
        }

        [$changes, $debugInfo] = $this->automaticChangesAndDebug($model, $event, $options, $oldAttributes);

        if ($changes === [] && ! $options->logEmpty) {
            return;
        }

        $id = $this->repository->persist($this->payload($model, $event, $changes, $debugInfo));

        if ($id !== null) {
            $this->lastAutomatic[$model] = ['event' => $event, 'id' => $id];
        }
    }

    /**
     * Ação com nome livre: $model->auditAction('aprovado', [...]).
     * Sempre cria uma linha NOVA.
     *
     * @param  array<string, mixed>  $changes
     */
    public function recordAction(Model $model, string $action, array $changes = []): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->repository->persist($this->payload($model, $action, $changes, null));
    }

    /**
     * Falha: em `changes` só vai uma mensagem segura para mostrar ao usuário;
     * os detalhes técnicos (exceção, SQL sem valores, trace) vão para
     * `debug_info`, que é só para o desenvolvedor.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordFailure(
        Model $model,
        string $action,
        Throwable $exception,
        array $context = [],
        ?string $message = null,
    ): void {
        if (! $this->isEnabled()) {
            return;
        }

        $changes = ['message' => $message ?? 'A operação falhou.'];

        $this->repository->persist(
            $this->payload($model, $action, $changes, $this->debug->collect($exception, $context))
        );
    }

    /**
     * $model->audit(...): personaliza a ÚLTIMA entrada automática que ESTE
     * objeto gravou (created/updated/...). Se ele ainda não gravou nenhuma
     * (eventos desligados, objeto recém-carregado), cria uma entrada nova.
     *
     * O que não for passado em $overrides é calculado como o hook automático
     * calcularia; $overrides->event só muda o NOME do evento gravado.
     */
    public function recordManual(Model $model, ManualAuditPayload $overrides): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $tracked = $this->takeLastAutomatic($model);
        $baseEvent = $tracked['event'] ?? $this->inferEvent($model);
        $options = $this->optionsFor($model);

        [$autoChanges, $autoDebug] = $options !== null
            ? $this->automaticChangesAndDebug($model, $baseEvent, $options)
            : [[], null];

        $changes = $overrides->changes ?? $autoChanges;
        $debugInfo = $overrides->debugInfo ?? $autoDebug;

        // Nada a registar (e o dev não passou changes explicitamente): se o
        // hook já tinha gravado uma linha, ela sai — audit() decide a entrada.
        if ($overrides->changes === null && $changes === [] && ! ($options?->logEmpty ?? false)) {
            if ($tracked !== null) {
                $this->repository->forget($tracked['id']);
            }

            return;
        }

        $payload = $this->payload($model, $overrides->event ?? $baseEvent, $changes, $debugInfo);

        foreach ([
            'batch' => $overrides->batch,
            'subject_type' => $overrides->subjectType,
            'subject_id' => $overrides->subjectId,
            'created_by' => $overrides->createdBy,
            'created_at' => $overrides->createdAt,
            'updated_at' => $overrides->updatedAt,
        ] as $column => $value) {
            if ($value !== null) {
                $payload[$column] = $value;
            }
        }

        if ($this->tenancyEnabled() && $overrides->tenantId !== null) {
            $payload[$this->tenantColumn()] = $overrides->tenantId;
        }

        if ($tracked !== null) {
            $this->repository->replace($tracked['id'], $payload);

            return;
        }

        $this->repository->persist($payload);
    }

    /**
     * Versão sem model (Audit::for(...) e DB::table(...)->audit(...)).
     * Sempre insere uma linha nova.
     *
     * Regras de $options (as mesmas de getAuditOptions()):
     *   - events() só filtra created/updated/deleted/restored; nomes livres passam;
     *   - except/only/onlyDirty/logEmpty/resolveMap são aplicados a $changesRaw.
     *
     * @param  array<string, mixed>       $changesRaw  Diff ['campo' => ['old' => .., 'new' => ..]]
     *                                                 ou retrato ['campo' => valor].
     * @param  array<string, mixed>|null  $debugInfo
     */
    public function recordForQueryBuilder(
        string $subjectType,
        string|int $subjectId,
        string $event,
        array $changesRaw,
        ?AuditOptions $options,
        ?array $debugInfo,
        ?string $batch,
        string|int|null $createdBy,
        string|int|null $tenantId,
        ?string $createdAt,
        ?string $updatedAt,
        ?string $connection = null,
    ): void {
        if (! $this->isEnabled()) {
            return;
        }

        if ($options !== null && ! $options->allowsEvent($event)) {
            return;
        }

        $changes = $options === null
            ? $changesRaw
            : $this->applyOptionsToRawChanges($changesRaw, $options, $connection);

        if ($options !== null && $changes === [] && ! $options->logEmpty) {
            return;
        }

        $now = $this->now();

        $payload = [
            'batch' => $batch ?? $this->currentBatch() ?? $this->batchIds->generate(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event' => $event,
            'changes' => $changes,
            'debug_info' => $debugInfo,
            'created_by' => $createdBy ?? $this->context->userId(),
            'created_at' => $createdAt ?? $now,
            'updated_at' => $updatedAt ?? $now,
        ];

        if ($this->tenancyEnabled()) {
            $payload[$this->tenantColumn()] = $tenantId ?? $this->context->tenantId();
        }

        $this->repository->persist($payload);
    }

    /**
     * @internal usado por auditedUpdate().
     *
     * Cada par é [model lido do banco ANTES do update, atributos crus DEPOIS].
     * O diff sai igual ao do evento automático `updated`: quem decide o que
     * mudou é o próprio Eloquent (getDirty()), com os mesmos casts e máscaras.
     *
     * @param  array<int, array{0: Model, 1: array<string, mixed>}>  $pairs
     */
    public function recordBulkUpdate(array $pairs, AuditOptions $options): void
    {
        if (! $this->isEnabled() || $pairs === []) {
            return;
        }

        $this->changes->warm(
            [...array_map(static fn (array $pair) => $pair[0]->getRawOriginal(), $pairs), ...array_column($pairs, 1)],
            $options,
            $pairs[0][0]->getConnectionName(),
        );

        $payloads = [];

        foreach ($pairs as [$model, $after]) {
            $before = $model->getRawOriginal();

            $model->setRawAttributes(array_merge($model->getAttributes(), $after));
            $model->syncChanges();

            [$changes, $debugInfo] = $this->automaticChangesAndDebug($model, 'updated', $options, $before);

            if ($changes !== [] || $options->logEmpty) {
                $payloads[] = $this->payload($model, 'updated', $changes, $debugInfo);
            }
        }

        $this->persistMany($payloads);
    }

    /**
     * @internal usado por auditedDelete().
     *
     * Cada model traz o estado a registrar: o de antes do delete ou, com
     * SoftDeletes, o de depois (já com deleted_at).
     *
     * @param  array<int, Model>  $models
     */
    public function recordBulkDelete(array $models, AuditOptions $options): void
    {
        $models = array_values($models);

        if (! $this->isEnabled() || $models === []) {
            return;
        }

        $this->changes->warm(
            array_map(static fn (Model $model) => $model->getRawOriginal(), $models),
            $options,
            $models[0]->getConnectionName(),
        );

        $payloads = [];

        foreach ($models as $model) {
            [$changes, $debugInfo] = $this->automaticChangesAndDebug($model, 'deleted', $options);

            if ($changes !== [] || $options->logEmpty) {
                $payloads[] = $this->payload($model, 'deleted', $changes, $debugInfo);
            }
        }

        $this->persistMany($payloads);
    }

    // ------------------------------------------------------------------
    // Montagem
    // ------------------------------------------------------------------

    /** Grava em bloco quando o repositório sabe; senão, linha a linha. */
    private function persistMany(array $payloads): void
    {
        if ($payloads === []) {
            return;
        }

        if ($this->repository instanceof BulkAuditRepository) {
            $this->repository->persistMany($payloads);

            return;
        }

        foreach ($payloads as $payload) {
            $this->repository->persist($payload);
        }
    }

    /**
     * @param  array<string, mixed>       $changes
     * @param  array<string, mixed>|null  $debugInfo
     * @return array<string, mixed>
     */
    private function payload(Model $model, string $event, array $changes, ?array $debugInfo): array
    {
        $now = $this->now();

        $payload = [
            'batch' => $this->currentBatch() ?? $this->batchIds->generate(),
            'subject_type' => $model->getMorphClass(),
            'subject_id' => $model->getKey(),
            'event' => $event,
            'changes' => $changes,
            'debug_info' => $debugInfo,
            'created_by' => $this->context->userId(),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($this->tenancyEnabled()) {
            $payload[$this->tenantColumn()] = $this->context->tenantId();
        }

        return $payload;
    }

    /**
     * Calcula `changes` e `debug_info` de um evento automático.
     *
     * Todos os valores saem CRUS (como estão no banco), para que o "antes" e o
     * "depois" sejam sempre comparáveis. Exceções pensadas para leitura:
     * colunas com cast array/json são decodificadas e colunas com cast
     * encrypted são mascaradas (nunca vão em texto claro para a auditoria).
     *
     * @param  array<string, mixed>|null  $oldAttributes
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    private function automaticChangesAndDebug(
        Model $model,
        string $event,
        AuditOptions $options,
        ?array $oldAttributes = null,
    ): array {
        $readableOptions = $this->withoutModelTimestamps($model, $options);
        $connection = $model->getConnectionName();

        $changes = match ($event) {
            'updated' => $this->changes->build(
                new: $this->readable($model, $model->getAttributes()),
                old: $this->readable($model, $oldAttributes ?? $this->oldAttributesOf($model)),
                options: $readableOptions,
                changedKeys: array_keys($model->getChanges()),
                connection: $connection,
            ),
            'deleted' => $this->changes->snapshot($this->readable($model, $model->getRawOriginal()), $readableOptions, $connection),
            default => $this->changes->snapshot($this->readable($model, $model->getAttributes()), $readableOptions, $connection),
        };

        $debugInfo = null;

        if ($event === 'deleted' && $options->fullSnapshotOnDelete) {
            $debugInfo = [
                'restore' => [
                    'subject_type' => $model->getMorphClass(),
                    'subject_id' => $model->getKey(),
                    'table' => $model->getTable(),
                    'key_name' => $model->getKeyName(),
                    'connection' => $model->getConnectionName(),
                    'attributes' => $this->changes->fullSnapshot($model->getRawOriginal(), $options),
                ],
            ];
        }

        return [$changes, $debugInfo];
    }

    /**
     * Valores crus → valores para o log legível.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function readable(Model $model, array $attributes): array
    {
        $casts = $model->getCasts();

        foreach ($attributes as $key => $value) {
            $cast = $casts[$key] ?? null;

            if (! is_string($cast) || $value === null) {
                continue;
            }

            $type = strtolower($cast);

            if (str_contains($type, 'encrypted')) {
                $attributes[$key] = ChangeSetBuilder::MASK;

                continue;
            }

            $base = explode(':', $type)[0];
            $isJson = in_array($base, ['array', 'json', 'object', 'collection'], true)
                || str_ends_with($base, 'asarrayobject')
                || str_ends_with($base, 'ascollection');

            if ($isJson && is_string($value)) {
                $decoded = json_decode($value, true);
                $attributes[$key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
            }
        }

        return $attributes;
    }

    /** Estado antes do último update, capturado no evento `updating` pelo trait. */
    private function oldAttributesOf(Model $model): array
    {
        if (method_exists($model, 'getAuditOldAttributes')) {
            $old = $model->getAuditOldAttributes();

            if ($old !== []) {
                return $old;
            }
        }

        return $model->getRawOriginal();
    }

    /** created_at/updated_at do model ficam fora do log legível, salvo logTimestamps(). */
    private function withoutModelTimestamps(Model $model, AuditOptions $options): AuditOptions
    {
        if ($options->logTimestamps || ! $model->usesTimestamps()) {
            return $options;
        }

        $columns = array_filter(
            [$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()],
            fn ($column) => $column !== null && ($options->only === null || ! in_array($column, $options->only, true)),
        );

        if ($columns === []) {
            return $options;
        }

        $copy = clone $options;
        $copy->except = array_values(array_unique([...$copy->except, ...$columns]));

        return $copy;
    }

    /**
     * @param  array<string, mixed>  $changesRaw
     * @return array<string, mixed>
     */
    private function applyOptionsToRawChanges(array $changesRaw, AuditOptions $options, ?string $connection): array
    {
        if ($this->looksLikeDiff($changesRaw)) {
            return $this->changes->build(
                new: array_map(static fn (array $pair) => $pair['new'], $changesRaw),
                old: array_map(static fn (array $pair) => $pair['old'], $changesRaw),
                options: $options,
                connection: $connection,
            );
        }

        return $this->changes->snapshot($changesRaw, $options, $connection);
    }

    /** @param array<string, mixed> $changesRaw */
    private function looksLikeDiff(array $changesRaw): bool
    {
        if ($changesRaw === []) {
            return false;
        }

        foreach ($changesRaw as $value) {
            if (! is_array($value) || ! array_key_exists('old', $value) || ! array_key_exists('new', $value)) {
                return false;
            }
        }

        return true;
    }

    private function optionsFor(Model $model): ?AuditOptions
    {
        return method_exists($model, 'getAuditOptions') ? $model->getAuditOptions() : null;
    }

    /** @return array{event: string, id: int|string}|null */
    private function takeLastAutomatic(Model $model): ?array
    {
        if (! isset($this->lastAutomatic[$model])) {
            return null;
        }

        $entry = $this->lastAutomatic[$model];
        unset($this->lastAutomatic[$model]);

        return $entry;
    }

    /** Evento provável quando o objeto não gravou nada automaticamente. */
    private function inferEvent(Model $model): string
    {
        if (! $model->exists) {
            return 'deleted';
        }

        if ($model->getChanges() !== []) {
            return 'updated';
        }

        return $model->wasRecentlyCreated ? 'created' : 'updated';
    }

    private function tenancyEnabled(): bool
    {
        return (bool) config('auditable.tenant.enabled', false);
    }

    private function tenantColumn(): string
    {
        return (string) config('auditable.tenant.column', 'tenant_id');
    }

    private function now(): string
    {
        return now()->format('Y-m-d H:i:s');
    }
}
