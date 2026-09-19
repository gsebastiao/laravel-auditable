<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Gsebastiao\Auditable\AuditManager;
use Gsebastiao\Auditable\Contracts\ContextResolver;
use Illuminate\Contracts\Queue\Job;

/**
 * Leva a operação (batch), o usuário e — se ligado — o tenant de quem
 * despacha uma job até dentro da job. Funciona sozinho, sem código nas jobs.
 *
 * Como:
 *   1. Ao despachar, o pacote acrescenta estes dados ao payload da job
 *      (Queue::createPayloadUsing).
 *   2. No worker, quando a job começa (evento JobProcessing), os dados são
 *      lidos do payload; quando ela termina (JobProcessed, JobFailed ou
 *      JobExceptionOccurred), são descartados.
 *   3. Enquanto a job corre, o pacote usa-os quando não há batch aberto,
 *      ninguém logado ou tenant identificado.
 *
 * Os dados só valem DURANTE a job: com o driver "sync" (a job corre dentro
 * da requisição), nada fica "preso" à requisição depois de a job acabar. Jobs
 * síncronas podem aninhar-se, por isso guardamos uma pilha.
 *
 * Vale para tudo o que passa pela fila: jobs, listeners, notificações e
 * e-mails com ShouldQueue. Liga/desliga em config('auditable.queue').
 */
final class QueueContext
{
    public const KEY = 'auditable';

    /** @var array<int, array<string, mixed>> Dados das jobs em execução, por job. */
    private static array $running = [];

    /**
     * O que acrescentar ao payload de uma job que está a ser despachada.
     *
     * @return array<string, array<string, int|string>>
     */
    public static function payload(): array
    {
        if (! config('auditable.enabled', true)) {
            return [];
        }

        $data = [];

        if (config('auditable.queue.propagate_batch', true)) {
            $data['batch'] = app(AuditManager::class)->currentBatch();
        }

        // rescue(): um erro no resolver do usuário/tenant nunca pode impedir
        // a aplicação de despachar uma job — é reportado e seguimos sem ele.
        if (config('auditable.queue.propagate_user', true)) {
            $data['user'] = rescue(fn () => app(ContextResolver::class)->userId());
        }

        if (config('auditable.queue.propagate_tenant', false)) {
            $data['tenant'] = rescue(fn () => app(ContextResolver::class)->tenantId());
        }

        $data = array_filter($data, static fn ($value) => is_int($value) || is_string($value));

        return $data === [] ? [] : [self::KEY => $data];
    }

    /** Evento JobProcessing: a job começou. */
    public static function jobStarted(Job $job): void
    {
        $data = $job->payload()[self::KEY] ?? [];
        $id = spl_object_id($job);

        // unset antes de gravar: a entrada vai para o fim da pilha.
        unset(self::$running[$id]);
        self::$running[$id] = is_array($data) ? $data : [];
    }

    /** Eventos JobProcessed / JobFailed / JobExceptionOccurred: a job terminou. */
    public static function jobFinished(Job $job): void
    {
        unset(self::$running[spl_object_id($job)]);
    }

    /** Batch herdado de quem despachou a job em curso (null fora de uma job). */
    public static function batch(): ?string
    {
        $batch = self::get('batch');

        return is_string($batch) ? $batch : null;
    }

    /** Usuário de quem despachou a job em curso. */
    public static function userId(): int|string|null
    {
        return self::id('user');
    }

    /** Tenant de quem despachou a job em curso (só com propagate_tenant). */
    public static function tenantId(): int|string|null
    {
        return self::id('tenant');
    }

    private static function id(string $key): int|string|null
    {
        $value = self::get($key);

        return is_int($value) || is_string($value) ? $value : null;
    }

    private static function get(string $key): mixed
    {
        if (self::$running === []) {
            return null;
        }

        $current = end(self::$running);

        return $current[$key] ?? null;
    }
}
