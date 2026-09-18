<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Contracts;

/**
 * Responde a duas perguntas no momento em que uma auditoria é gravada:
 * "quem fez?" e "em que tenant?".
 *
 * Regra do pacote: lemos o usuário e o tenant atuais, nunca os definimos.
 * Quem define é a sua aplicação (login) e o seu pacote de tenancy.
 *
 * A implementação padrão (DefaultContextResolver) lê o auth() do Laravel e o
 * resolver de tenant que você configurar. Para trocar, religue no seu
 * AppServiceProvider:
 *
 *   $this->app->bind(ContextResolver::class, MeuContexto::class);
 */
interface ContextResolver
{
    /** Id do usuário responsável, ou null para ação de sistema. */
    public function userId(): int|string|null;

    /** Id do tenant atual, ou null se não houver tenant (ou tenancy desligado). */
    public function tenantId(): int|string|null;
}
