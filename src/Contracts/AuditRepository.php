<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Contracts;

/**
 * Persiste uma entrada de auditoria.
 *
 * Este é o principal ponto de extensão do pacote. A implementação padrão
 * grava numa tabela via Eloquent, mas um consumidor pode fornecer a sua
 * própria — por exemplo, para despachar para uma fila, escrever num serviço
 * externo (Datadog, Elasticsearch), ou rotear para a conexão do tenant atual.
 *
 * O pacote nunca decide *onde* a auditoria vive; apenas monta o payload e
 * delega a persistência a esta interface.
 */
interface AuditRepository
{
    /**
     * Grava uma entrada de auditoria já montada.
     *
     * @param  array<string, mixed>  $payload  Campos normalizados prontos para persistir.
     */
    public function persist(array $payload): void;
}
