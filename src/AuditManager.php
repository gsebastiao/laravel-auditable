<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable;

use Illuminate\Database\Eloquent\Model;
use Gsebastiao\Auditable\Contracts\AuditRepository;
use Gsebastiao\Auditable\Contracts\BatchIdGenerator;
use Gsebastiao\Auditable\Contracts\ContextResolver;
use Gsebastiao\Auditable\Support\ChangeSetBuilder;
use Gsebastiao\Auditable\Support\DebugInfoCollector;
use Throwable;

/**
 * Orquestra a montagem de uma entrada de auditoria a partir de um evento de
 * model, e delega a persistência ao AuditRepository.
 *
 * É aqui que as camadas se encontram: o diff legível (ChangeSetBuilder), o
 * contexto de quem/qual-tenant (ContextResolver) e o agrupamento (BatchId)
 * são combinados num payload, mas *onde* isso é gravado permanece decisão do
 * repository — mantendo o pacote agnóstico à estratégia de tenancy.
 */
final class AuditManager
{
    /** Batch corrente, mantido por instância do container (scoped), não estático. */
    private ?string $currentBatch = null;

    public function __construct(
        private AuditRepository $repository,
        private ContextResolver $context,
        private BatchIdGenerator $batchIds,
        private ChangeSetBuilder $changes,
        private DebugInfoCollector $debug,
    ) {}

    /**
     * Agrupa todas as auditorias produzidas dentro do callback sob um mesmo
     * batch id. Substitui o self::$currentBatchId estático do original por um
     * escopo explícito e seguro sob concorrência.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function batch(callable $callback): mixed
    {
        // Se já há um batch aberto, aninha: tudo aqui dentro herda o MESMO batch.
        // É o que garante que uma operação composta (produto + cliente + itens),
        // mesmo que cada parte chame batch() por conta própria, fique sob um id só.
        if ($this->currentBatch !== null) {
            return $callback();
        }

        $this->currentBatch = $this->batchIds->generate();

        try {
            return $callback();
        } finally {
            $this->currentBatch = null;
        }
    }

    /**
     * Abre um batch e devolve o id gerado, sem callback. Para quando você não
     * tem um bloco fechado — por exemplo, começar o batch num ponto e fechá-lo
     * noutro. Prefira batch() com callback sempre que possível.
     */
    public function beginBatch(): string
    {
        return $this->currentBatch ??= $this->batchIds->generate();
    }

    /**
     * Fecha o batch aberto por beginBatch().
     */
    public function endBatch(): void
    {
        $this->currentBatch = null;
    }

    /**
     * Força um batch id específico (ex.: propagar o mesmo batch para uma job na
     * fila, ou correlacionar com um id externo). Devolve um "handle" que, ao ser
     * descartado, restaura o batch anterior — mas normalmente usa-se com batch().
     */
    public function useBatch(string $batchId): void
    {
        $this->currentBatch = $batchId;
    }

    /**
     * O batch atualmente aberto (dentro de um batch()), ou null fora dele.
     */
    public function currentBatch(): ?string
    {
        return $this->currentBatch;
    }

    /**
     * Registra a auditoria de um evento de model.
     *
     * @param  array<string, mixed>  $oldAttributes  Estado anterior (updates).
     */
    public function record(Model $model, string $event, array $oldAttributes): void
    {
        if (! config('auditable.enabled', true)) {
            return;
        }

        $options = method_exists($model, 'getAuditOptions')
            ? $model->getAuditOptions()
            : null;

        if ($options === null) {
            return;
        }

        // Monta o diff/snapshot conforme o tipo de evento.
        $changes = match ($event) {
            'updated' => $this->changes->build($model->getAttributes(), $oldAttributes, $options),
            'created', 'restored' => $this->changes->snapshot($model->getAttributes(), $options),
            'deleted' => $this->changes->snapshot($model->getOriginal(), $options),
            default => [],
        };

        if (empty($changes) && ! $options->logEmpty) {
            return;
        }

        // Rede de segurança para HARD DELETE: no delete, além do `changes`
        // legível, guardamos um retrato INTEGRAL e cru do registro em
        // debug_info['restore']. Se a linha for apagada de verdade (sem
        // SoftDeletes), este retrato é o que permite reconstruí-la. Só o delete
        // recebe isto, e só se o model não tiver desligado a opção.
        $debugInfo = null;

        if ($event === 'deleted' && $options->fullSnapshotOnDelete) {
            $debugInfo = [
                'restore' => [
                    'subject_type' => $model->getMorphClass(),
                    'subject_id'   => $model->getKey(),
                    'table'        => $model->getTable(),
                    'key_name'     => $model->getKeyName(),
                    'attributes'   => $this->changes->fullSnapshot($model->getOriginal(), $options),
                ],
            ];
        }

        $this->persist($model, $event, $changes, $debugInfo);
    }

    /**
     * Registra uma ação de domínio nomeada (não é evento do Eloquent).
     * Chamado por $model->auditAction('aprovado', [...]).
     *
     * @param  array<string, mixed>  $changes
     */
    public function recordAction(Model $model, string $action, array $changes = []): void
    {
        if (! config('auditable.enabled', true)) {
            return;
        }

        $this->persist($model, $action, $changes);
    }

    /**
     * Registra uma FALHA: o "o quê" legível fica em changes, o "porquê" técnico
     * (trace, SQL, request, ambiente) vai para debug_info — que só o dev lê.
     * Esta separação é o que o BaseModel original fazia e o esqueleto não tinha.
     *
     * Chamado por $model->auditFailure('fatura_update', $e, [...]).
     *
     * @param  array<string, mixed>  $context
     */
    public function recordFailure(Model $model, string $action, Throwable $exception, array $context = []): void
    {
        if (! config('auditable.enabled', true)) {
            return;
        }

        // changes = mensagem amigável (visível ao usuário).
        $changes = [
            'message' => 'A operação falhou.',
            'error' => $exception->getMessage(),
        ];

        // debug_info = tudo o que o dev precisa para investigar (escondido do usuário).
        $debugInfo = $this->debug->collect($exception, $context);

        $this->persist($model, $action, $changes, $debugInfo);
    }

    /**
     * Monta o payload comum e delega ao repository. Ponto único onde o carimbo
     * de batch/user/tenant e a regra do tenant_id são aplicados.
     *
     * @param  array<string, mixed>       $changes
     * @param  array<string, mixed>|null  $debugInfo
     */
    private function persist(Model $model, string $event, array $changes, ?array $debugInfo = null): void
    {
        $payload = [
            'batch' => $this->currentBatch ?? $this->batchIds->generate(),
            'subject_type' => $model->getMorphClass(),
            'subject_id' => $model->getKey(),
            'event' => $event,
            'changes' => $changes,
            'debug_info' => $debugInfo,
            'user_id' => $this->context->userId(),
            'tenant_id' => $this->context->tenantId(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // tenant_id só entra se tenancy por-coluna estiver ativo.
        if (! config('auditable.tenant.enabled', false)) {
            unset($payload['tenant_id']);
        }

        $this->repository->persist($payload);
    }
}
