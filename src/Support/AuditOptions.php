<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

/**
 * Objeto de configuração por-model, retornado por getAuditOptions().
 *
 * Substitui o estado estático herdado do BaseModel original
 * ($autoAudit, $auditTable, etc.), que era compartilhado de forma insegura
 * entre models e entre requisições concorrentes. Cada model descreve o
 * próprio comportamento numa instância isolada, no estilo do LogOptions
 * da Spatie — mas estendido com o resolveMap, que é o diferencial deste
 * pacote sobre o activitylog.
 */
final class AuditOptions
{
    /** @var array<int, string> Eventos Eloquent a auditar. */
    public array $events = ['created', 'updated', 'deleted'];

    /** @var array<int, string>|null Se definido, audita apenas estes atributos. Null = todos. */
    public ?array $only = null;

    /** @var array<int, string> Atributos nunca auditados (senhas, tokens, etc.). */
    public array $except = ['password', 'remember_token'];

    /** Se true, só registra atributos que de fato mudaram (dirty). */
    public bool $onlyDirty = true;

    /** Se false, não grava auditoria quando não houve nenhuma mudança. */
    public bool $logEmpty = false;

    /**
     * Grava, no evento `deleted`, um SNAPSHOT INTEGRAL do registro em
     * debug_info['restore'] — todos os campos, inclusive os que only()/except()
     * escondem do diff legível. É a rede de segurança para HARD DELETE: se
     * alguém apagar a linha de verdade (sem SoftDeletes), a auditoria guarda o
     * retrato completo para reconstruir o registro depois.
     *
     * Por que em debug_info e não em changes: `changes` é o log LEGÍVEL, para
     * humanos ("Produto X foi apagado"); ele respeita only/except de propósito.
     * `debug_info['restore']` é o retrato CRU, para reconstrução programática —
     * separando "o que uma pessoa lê" de "o que o código usa para restaurar".
     *
     * Ligado por padrão: o custo é um snapshot só no delete, e o benefício é não
     * perder o dado num hard delete. Desligue com fullSnapshotOnDelete(false) se
     * o registro tiver campos volumosos que você não quer duplicar na auditoria.
     */
    public bool $fullSnapshotOnDelete = true;

    /**
     * Campos NUNCA incluídos no snapshot de restauro, mesmo sendo integral.
     * Segredos não devem sobreviver num retrato de auditoria. Somados ao
     * except() do model no momento do snapshot integral.
     *
     * @var array<int, string>
     */
    public array $neverSnapshot = ['password', 'remember_token'];

    /**
     * Mapa de resolução de labels legíveis. A joia do pacote.
     *
     * Formato: ['campo' => ResolveMap::direct(...) | ::join(...) | ::alias(...)]
     * Ver a classe ResolveMap.
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
        $this->events = $events;

        return $this;
    }

    /** @param array<int, string> $attributes */
    public function only(array $attributes): self
    {
        $this->only = $attributes;

        return $this;
    }

    /** @param array<int, string> $attributes */
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

    /** @param array<string, array<string, mixed>> $map */
    public function resolveMap(array $map): self
    {
        $this->resolveMap = $map;

        return $this;
    }

    /**
     * Liga/desliga o snapshot integral de restauro no delete (ver a propriedade).
     */
    public function fullSnapshotOnDelete(bool $value = true): self
    {
        $this->fullSnapshotOnDelete = $value;

        return $this;
    }

    /**
     * Adiciona campos à lista que nunca entra no snapshot de restauro (além dos
     * segredos padrão). Use para dados sensíveis específicos do seu domínio.
     *
     * @param array<int, string> $fields
     */
    public function neverSnapshot(array $fields): self
    {
        $this->neverSnapshot = array_values(array_unique([...$this->neverSnapshot, ...$fields]));

        return $this;
    }
}
