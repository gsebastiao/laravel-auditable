<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Gsebastiao\Auditable\Audit;
use Gsebastiao\Auditable\Contracts\ContextResolver;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use InvalidArgumentException;

/**
 * Implementação padrão do ContextResolver.
 *
 * Usuário, por esta ordem:
 *   1. o usuário autenticado no guard configurado (ou no guard padrão);
 *   2. dentro de uma job: quem a despachou (ver QueueContext);
 *   3. config('auditable.default_created_by').
 *
 * Tenant, por esta ordem:
 *   1. config('auditable.tenant.resolver') — uma classe invocável — ou
 *      Audit::resolveTenantUsing(fn () => ...) no AppServiceProvider;
 *   2. dentro de uma job, se o resolver não identificar nenhum: o tenant de
 *      quem a despachou (só com config('auditable.queue.propagate_tenant')).
 *   O pacote nunca decide qual é o tenant; só pergunta.
 */
final class DefaultContextResolver implements ContextResolver
{
    public function __construct(
        private AuthFactory $auth,
        private ?string $guard = null,
        private mixed $tenantResolver = null,
        private int|string|null $defaultUserId = null,
    ) {}

    public function userId(): int|string|null
    {
        return $this->auth->guard($this->guard)->id()
            ?? QueueContext::userId()
            ?? $this->normalizeId($this->defaultUserId);
    }

    public function tenantId(): int|string|null
    {
        return $this->resolveTenant() ?? QueueContext::tenantId();
    }

    private function resolveTenant(): int|string|null
    {
        $resolver = $this->tenantResolver ?? Audit::tenantResolver();

        if ($resolver === null) {
            return null;
        }

        if (is_string($resolver) && class_exists($resolver)) {
            $resolver = app($resolver);
        }

        if (! is_callable($resolver)) {
            throw new InvalidArgumentException(
                'config(\'auditable.tenant.resolver\') precisa ser o nome de uma classe com o método __invoke(), '
                .'por exemplo App\\Support\\TenantAtual::class. Veja a seção "Multitenancy" do README.'
            );
        }

        return $this->normalizeId($resolver());
    }

    /** "" vira null e "5" vira 5 — valores vindos do .env chegam sempre como texto. */
    private function normalizeId(mixed $id): int|string|null
    {
        if ($id === null || $id === '') {
            return null;
        }

        if (is_string($id) && ctype_digit($id)) {
            return (int) $id;
        }

        return is_int($id) || is_string($id) ? $id : (string) $id;
    }
}
