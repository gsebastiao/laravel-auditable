<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Contracts;

/**
 * Resolve o contexto da requisição/processo atual para carimbar na auditoria.
 *
 * REGRA DE OURO DO PACOTE: lemos o tenant e o usuário atuais, nunca os
 * estabelecemos. Estabelecer é trabalho da camada de infraestrutura de
 * tenancy (stancl/tenancy, spatie/laravel-multitenancy) ou do próprio app.
 *
 * A implementação padrão lê de auth() e de um callback configurável em
 * config('auditable.tenant.resolver'). Um consumidor que use stancl/tenancy
 * pode fornecer uma implementação que leia tenant()->id.
 */
interface ContextResolver
{
    /**
     * ID do usuário responsável pela ação, ou null se for uma ação de sistema.
     */
    public function userId(): int|string|null;

    /**
     * ID do tenant atual, ou null se tenancy estiver desativado ou fora de
     * contexto de tenant (ex.: comando de console no contexto central).
     */
    public function tenantId(): int|string|null;
}
