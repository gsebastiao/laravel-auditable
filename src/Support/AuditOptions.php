<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

/**
 * Configuração de auditoria de UM model, devolvida por getAuditOptions().
 *
 *   public function getAuditOptions(): AuditOptions
 *   {
 *       return AuditOptions::defaults()
 *           ->except(['observacao_interna'])
 *           ->resolveMap(['status_id' => ResolveMap::direct('Status', 'status', 'nome')]);
 *   }
 *
 * Cada model tem a sua própria instância (nada é estático nem partilhado entre
 * models ou requisições). Todos os métodos devolvem $this, então encadeiam.
 */
final class AuditOptions
{
    /**
     * Os eventos "de escrita" que o Eloquent dispara sozinho. events() só
     * filtra ESTES; ações com nome livre (auditAction('aprovado'),
     * Audit::for(..., event: 'importado')) são sempre gravadas.
     */
    public const STANDARD_EVENTS = ['created', 'updated', 'deleted', 'restored'];

    /** @var array<int, string> Eventos automáticos a auditar ('restored' só existe com SoftDeletes). */
    public array $events = ['created', 'updated', 'deleted'];

    /** @var array<int, string>|null Se definido, audita apenas estes atributos. Null = todos. */
    public ?array $only = null;

    /**
     * Atributos que NUNCA aparecem no log legível (`changes`).
     * Atenção: except() não tira o campo do retrato de restauro — para isso
     * use neverSnapshot().
     *
     * @var array<int, string>
     */
    public array $except = ['password', 'remember_token'];

    /** Se true, no evento `updated` só entram os atributos que mudaram de verdade. */
    public bool $onlyDirty = true;

    /** Se false, não grava nada quando não sobrou nenhuma mudança para registar. */
    public bool $logEmpty = false;

    /**
     * Se false (padrão), as colunas created_at/updated_at do PRÓPRIO model não
     * aparecem no log legível — a linha de auditoria já tem a sua própria data,
     * e "updated_at: 10:00 → 10:05" em toda alteração só polui o histórico.
     */
    public bool $logTimestamps = false;

    /**
     * Grava, no evento `deleted`, um retrato INTEGRAL e CRU do registro em
     * debug_info['restore'] — é o que permite Audit::restore() reconstruir a
     * linha depois de um hard delete. Ignora only()/except() (a linha precisa
     * voltar inteira); só respeita neverSnapshot().
     */
    public bool $fullSnapshotOnDelete = true;

    /**
     * Campos que NUNCA são guardados na auditoria: nem no log legível, nem no
     * retrato de restauro. Use para segredos (senhas, tokens, chaves de API).
     *
     * @var array<int, string>
     */
    public array $neverSnapshot = ['password', 'remember_token'];

    /**
     * Tradução de chaves estrangeiras para nomes legíveis.
     * Formato: ['campo' => ResolveMap::direct(...) | ::join(...) | ::alias(...)].
     *
     * @var array<string, array<string, mixed>>
     */
    public array $resolveMap = [];

    public static function defaults(): self
    {
        return new self();
    }

    /** @param array<int, string> $events */
    public function events(array $events): self
    {
        $this->events = array_values($events);

        return $this;
    }

    /** @param array<int, string> $attributes */
    public function only(array $attributes): self
    {
        $this->only = array_values($attributes);

        return $this;
    }

    /**
     * Soma à lista padrão (password e remember_token continuam ignorados).
     *
     * @param array<int, string> $attributes
     */
    public function except(array $attributes): self
    {
        $this->except = array_values(array_unique([...$this->except, ...$attributes]));

        return $this;
    }

    public function logEmpty(bool $value = true): self
    {
        $this->logEmpty = $value;

        return $this;
    }

    public function onlyDirty(bool $value = true): self
    {
        $this->onlyDirty = $value;

        return $this;
    }

    public function logTimestamps(bool $value = true): self
    {
        $this->logTimestamps = $value;

        return $this;
    }

    /** @param array<string, array<string, mixed>> $map */
    public function resolveMap(array $map): self
    {
        $this->resolveMap = $map;

        return $this;
    }

    public function fullSnapshotOnDelete(bool $value = true): self
    {
        $this->fullSnapshotOnDelete = $value;

        return $this;
    }

    /**
     * Soma campos à lista que nunca é guardada (nem no log, nem no retrato).
     *
     * @param array<int, string> $fields
     */
    public function neverSnapshot(array $fields): self
    {
        $this->neverSnapshot = array_values(array_unique([...$this->neverSnapshot, ...$fields]));

        return $this;
    }

    /**
     * Um evento é filtrado por events()? Só os eventos automáticos são; nomes
     * livres passam sempre.
     */
    public function allowsEvent(string $event): bool
    {
        if (! in_array($event, self::STANDARD_EVENTS, true)) {
            return true;
        }

        return in_array($event, $this->events, true);
    }
}
