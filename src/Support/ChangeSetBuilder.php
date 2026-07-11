<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

/**
 * Constrói o conjunto de mudanças (diff) entre estados de um model,
 * aplicando o ResolveMap para produzir labels legíveis.
 *
 * Corresponde ao método resolveMap() de comparação do BaseModel original,
 * agora separado da resolução de label em si (LabelResolver) e operando
 * sobre valores já passados pelos casts do Eloquent — não mais sobre
 * stdClass cru vindo de DB::table(), que era uma das fragilidades apontadas.
 */
final class ChangeSetBuilder
{
    public function __construct(private LabelResolver $labels) {}

    /**
     * @param  array<string, mixed>  $new     Atributos novos (já com casts).
     * @param  array<string, mixed>  $old     Atributos antigos (já com casts).
     * @param  AuditOptions          $options
     * @return array<string, mixed>          Diff indexado por label legível.
     */
    public function build(array $new, array $old, AuditOptions $options): array
    {
        $changes = [];

        foreach ($new as $field => $newValue) {
            if ($this->skip($field, $options)) {
                continue;
            }

            $oldValue = $old[$field] ?? null;

            if ($options->onlyDirty && $this->equal($oldValue, $newValue)) {
                continue;
            }

            $map = $options->resolveMap[$field] ?? null;

            if ($map === null) {
                $changes[$field] = ['old' => $oldValue, 'new' => $newValue];

                continue;
            }

            if (($map['type'] ?? 'direct') === 'alias') {
                $changes[$map['label']] = ['old' => $oldValue, 'new' => $newValue];

                continue;
            }

            $label = $this->labels->labelFor($map);
            $changes[$label] = [
                'old' => ['id' => $oldValue, 'label' => $this->labels->resolve($oldValue, $map)],
                'new' => ['id' => $newValue, 'label' => $this->labels->resolve($newValue, $map)],
            ];
        }

        return $changes;
    }

    /**
     * Snapshot para create/delete, onde não há "antes"/"depois" a comparar —
     * apenas o estado do registro, com FKs resolvidas.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function snapshot(array $attributes, AuditOptions $options): array
    {
        $snapshot = [];

        foreach ($attributes as $field => $value) {
            if ($this->skip($field, $options)) {
                continue;
            }

            $map = $options->resolveMap[$field] ?? null;

            if ($map === null || ($map['type'] ?? 'direct') === 'alias') {
                $label = $map['label'] ?? $field;
                $snapshot[$label] = $value;

                continue;
            }

            $label = $this->labels->labelFor($map);
            $snapshot[$label] = ['id' => $value, 'label' => $this->labels->resolve($value, $map)];
        }

        return $snapshot;
    }

    /**
     * Retrato CRU e INTEGRAL do registro, para reconstrução programática após um
     * hard delete. Diferente de snapshot():
     *
     *   • IGNORA only()/except() — o objetivo é restaurar a linha INTEIRA, não
     *     produzir um log legível. Campos que você esconde do diff ainda são
     *     necessários para recriar o registro fielmente.
     *   • NÃO resolve labels — guarda os VALORES CRUS (as FKs como ids), que são
     *     o que um insert de restauro precisa. Label é para humano ler; aqui é
     *     para o código reidratar.
     *   • Respeita apenas $options->neverSnapshot — segredos (senha, tokens) não
     *     sobrevivem nem num retrato de restauro.
     *
     * O resultado é um mapa simples campo → valor cru, pronto para alimentar um
     * Model::create()/insert() de recuperação.
     *
     * @param  array<string, mixed>  $attributes  Atributos crus do registro (getOriginal()).
     * @param  AuditOptions          $options
     * @return array<string, mixed>
     */
    public function fullSnapshot(array $attributes, AuditOptions $options): array
    {
        $snapshot = [];

        foreach ($attributes as $field => $value) {
            if (in_array($field, $options->neverSnapshot, true)) {
                continue;
            }

            $snapshot[$field] = $value;
        }

        return $snapshot;
    }

    private function skip(string $field, AuditOptions $options): bool
    {
        if (in_array($field, $options->except, true)) {
            return true;
        }

        if ($options->only !== null && ! in_array($field, $options->only, true)) {
            return true;
        }

        return false;
    }

    private function equal(mixed $a, mixed $b): bool
    {
        // Comparação frouxa evita falsos positivos entre "1" (banco) e 1 (cast),
        // mas distingue null de string vazia.
        if ($a === null xor $b === null) {
            return false;
        }

        return $a == $b;
    }
}
