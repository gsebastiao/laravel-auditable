<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Gsebastiao\Auditable\AuditManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use LogicException;

/**
 * Update e delete EM MASSA com auditoria:
 *
 *   Produto::where('categoria_id', 3)->auditedUpdate(['ativo' => false]);
 *   Produto::where('created_at', '<', now()->subYear())->auditedDelete();
 *
 * Um update()/delete() em massa normal não dispara os eventos do Eloquent e
 * por isso não gera auditoria. Estas versões gravam UMA auditoria por
 * registro, todas na mesma operação (batch), sem carregar e salvar os models
 * um a um.
 *
 * Como funciona, lote a lote (500 registros por padrão):
 *   1. numa transação, lê o lote e trava as linhas (SELECT ... FOR UPDATE),
 *      para o "antes" ser exatamente o que vai ser alterado;
 *   2. faz UM update/delete para o lote inteiro;
 *   3. relê os valores novos (inclusive os calculados pelo banco, ex. DB::raw);
 *   4. grava as auditorias do lote em bloco, com os labels do resolveMap
 *      pré-carregados numa consulta só.
 * Cada lote é atômico: se algo falhar, o lote volta atrás (dados e auditoria).
 *
 * Tal como update()/delete(), NÃO dispara os eventos do Eloquent (saving,
 * updated, deleted...) — observers e listeners desses eventos não correm.
 */
final class BulkAudit
{
    public static function register(): void
    {
        if (! Builder::hasGlobalMacro('auditedUpdate')) {
            Builder::macro('auditedUpdate', function (array $values, int $chunkSize = 500): int {
                /** @var Builder $this */
                return (new BulkAudit($this, $chunkSize))->update($values);
            });
        }

        if (! Builder::hasGlobalMacro('auditedDelete')) {
            Builder::macro('auditedDelete', function (int $chunkSize = 500): int {
                /** @var Builder $this */
                return (new BulkAudit($this, $chunkSize))->delete();
            });
        }
    }

    public function __construct(
        private readonly Builder $query,
        private readonly int $chunkSize = 500,
    ) {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('O tamanho do lote (chunkSize) precisa ser maior que zero.');
        }
    }

    /**
     * @param  array<string, mixed>  $values  Os mesmos valores que passaria a update().
     * @return int  Registros afetados (o mesmo número que update() devolveria).
     */
    public function update(array $values): int
    {
        $model = $this->auditableModel();
        $options = $model->getAuditOptions();
        $manager = app(AuditManager::class);

        if ($values === []) {
            return 0;
        }

        if (! $manager->isEnabled() || ! $options->allowsEvent('updated')) {
            return $this->query->update($values);
        }

        $columns = $this->columnsTouchedBy($values, $model);
        $total = 0;

        $manager->batch(function () use ($model, $options, $manager, $values, $columns, &$total) {
            $this->eachLockedChunk($model, function (Collection $chunk) use ($model, $options, $manager, $values, $columns, &$total) {
                $ids = $chunk->modelKeys();

                $total += $model->newQueryWithoutScopes()->whereKey($ids)->update($values);

                $after = $model->newQueryWithoutScopes()
                    ->whereKey($ids)
                    ->get(array_values(array_unique([$model->getKeyName(), ...$columns])))
                    ->keyBy(static fn (Model $row) => (string) $row->getKey());

                $pairs = [];

                foreach ($chunk as $before) {
                    $row = $after->get((string) $before->getKey());

                    if ($row !== null) {
                        $pairs[] = [$before, $row->getAttributes()];
                    }
                }

                $manager->recordBulkUpdate($pairs, $options);
            });
        });

        return $total;
    }

    /**
     * Com SoftDeletes, os registros vão para a lixeira (como delete()).
     *
     * @return int  Registros apagados.
     */
    public function delete(): int
    {
        $model = $this->auditableModel();
        $options = $model->getAuditOptions();
        $manager = app(AuditManager::class);

        if (! $manager->isEnabled() || ! $options->allowsEvent('deleted')) {
            return (int) $this->query->delete();
        }

        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);
        $total = 0;

        $manager->batch(function () use ($model, $options, $manager, $softDeletes, &$total) {
            $this->eachLockedChunk($model, function (Collection $chunk) use ($model, $options, $manager, $softDeletes, &$total) {
                $ids = $chunk->modelKeys();

                // newQuery() (com os scopes) para o SoftDeletes transformar o delete em "lixeira".
                $total += (int) $model->newQuery()->whereKey($ids)->delete();

                // Com SoftDeletes a linha continua lá: relemos para registrar o deleted_at.
                $snapshots = $softDeletes
                    ? $model->newQueryWithoutScopes()->whereKey($ids)->get()
                    : $chunk;

                $manager->recordBulkDelete($snapshots->all(), $options);
            });
        });

        return $total;
    }

    /**
     * Percorre a consulta lote a lote, pela chave primária (os filtros podem
     * deixar de bater depois do update sem que nenhum registro seja saltado).
     * Cada lote corre numa transação, com as linhas travadas.
     *
     * @param  callable(Collection<int, Model>): void  $callback
     */
    private function eachLockedChunk(Model $model, callable $callback): void
    {
        $manager = app(AuditManager::class);
        $key = $model->getQualifiedKeyName();
        $lastId = null;

        do {
            $count = $manager->transaction(function () use ($model, $key, $callback, &$lastId) {
                $chunk = $this->query->clone()
                    ->setEagerLoads([])
                    ->reorder()
                    ->select($model->qualifyColumn('*'))
                    ->forPageAfterId($this->chunkSize, $lastId, $key)
                    ->lockForUpdate()
                    ->get();

                if ($chunk->isEmpty()) {
                    return 0;
                }

                $callback($chunk);
                $lastId = $chunk->last()->getKey();

                return $chunk->count();
            }, $model->getConnectionName());
        } while ($count === $this->chunkSize);
    }

    private function auditableModel(): Model
    {
        $model = $this->query->getModel();

        if (! method_exists($model, 'getAuditOptions')) {
            throw new LogicException(sprintf(
                '%s não usa o trait Auditable. Acrescente "use Auditable;" ao model, ou use update()/delete() normais.',
                $model::class,
            ));
        }

        $base = $this->query->getQuery();

        if ($base->limit !== null || $base->offset !== null) {
            throw new LogicException(
                'auditedUpdate()/auditedDelete() processam todos os registros da consulta: '
                .'tire limit()/offset() e filtre com where().'
            );
        }

        return $model;
    }

    /**
     * Colunas que o update altera: 'meta->vip' é a coluna 'meta'; 'produtos.preco'
     * é 'preco'; e updated_at, que o Eloquent acrescenta sozinho.
     *
     * @param  array<string, mixed>  $values
     * @return array<int, string>
     */
    private function columnsTouchedBy(array $values, Model $model): array
    {
        $columns = [];

        foreach (array_keys($values) as $column) {
            $column = (string) $column;

            if (str_contains($column, '->')) {
                $column = strstr($column, '->', true);
            }

            if (str_contains($column, '.')) {
                $column = substr($column, strrpos($column, '.') + 1);
            }

            $columns[] = $column;
        }

        if ($model->usesTimestamps() && $model->getUpdatedAtColumn() !== null) {
            $columns[] = $model->getUpdatedAtColumn();
        }

        return array_values(array_unique($columns));
    }
}
