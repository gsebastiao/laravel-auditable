<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Gsebastiao\Auditable\Contracts\ContextResolver;

/**
 * Implementação padrão do ContextResolver.
 *
 * Usuário: lido do guard configurado (ou o default do app).
 * Tenant:  lido de um callback fornecido pelo consumidor em
 *          config('auditable.tenant.resolver'). O pacote NÃO sabe como o
 *          tenant é determinado — só invoca o callback. Isto mantém o pacote
 *          agnóstico e compatível com stancl/tenancy, spatie/multitenancy,
 *          ou uma coluna tenant_id no usuário autenticado.
 */
final class DefaultContextResolver implements ContextResolver
{
    /**
     * @param  (callable(): (int|string|null))|null  $tenantResolver
     */
    public function __construct(
        private AuthFactory $auth,
        private mixed $tenantResolver = null,
        private ?string $guard = null,
    ) {}

    public function userId(): int|string|null
    {
        return $this->auth->guard($this->guard)->id();
    }

    public function tenantId(): int|string|null
    {
        if ($this->tenantResolver === null) {
            return null;
        }

        return ($this->tenantResolver)();
    }
}
