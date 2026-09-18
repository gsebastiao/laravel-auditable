<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Gsebastiao\Auditable\Models\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Monta a resposta JSON que o widget JS (audit-table.init.js) espera, para
 * que a sua rota tenha uma linha só:
 *
 *   Route::post('/auditoria/produtos', fn (Request $r) =>
 *       AuditWidget::full(Produto::operationsFor($r->input('id')), recordId: $r->input('id'))
 *   )->middleware('auth');
 *
 * Aceita uma consulta (Produto::operationsFor($id), $produto->audits(),
 * Audit::inBatch($b)...) ou uma coleção já carregada. Carrega os usuários de
 * uma vez (sem N+1) e trata ações de sistema (created_by vazio) sem erro.
 */
final class AuditWidget
{
    /**
     * Resposta do GaAudit.full — as ações agrupadas por batch (operação), da
     * operação mais recente para a mais antiga.
     *
     * @param  Builder|Relation|Collection<int, Audit>  $audits
     * @return array{record_id: int|string|null, groups: array<int, array<string, mixed>>}
     */
    public static function full(
        Builder|Relation|Collection $audits,
        int|string|null $recordId = null,
        string $userColumn = 'name',
        string $dateFormat = 'd/m/Y H:i',
    ): array {
        $groups = self::load($audits)
            ->sortByDesc('id')
            ->groupBy(fn (Audit $audit) => (string) $audit->batch)
            ->map(fn (Collection $items, int|string $batch) => [
                'batch_id' => (string) $batch,
                'actions' => $items->sortBy('id')
                    ->map(fn (Audit $audit) => self::row($audit, $userColumn, $dateFormat) + [
                        'type' => $audit->isFailure() ? 'failed' : 'success',
                        'changes' => $audit->changes,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return ['record_id' => $recordId, 'groups' => $groups];
    }

    /**
     * Resposta do GaAudit.simple — lista direta (o quê, quem, quando), da
     * mais recente para a mais antiga.
     *
     * @param  Builder|Relation|Collection<int, Audit>  $audits
     * @return array{audits: array<int, array<string, mixed>>}
     */
    public static function simple(
        Builder|Relation|Collection $audits,
        string $userColumn = 'name',
        string $dateFormat = 'd/m/Y H:i',
    ): array {
        return [
            'audits' => self::load($audits)
                ->sortByDesc('id')
                ->map(fn (Audit $audit) => self::row($audit, $userColumn, $dateFormat))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Builder|Relation|Collection<int, Audit>  $audits
     * @return Collection<int, Audit>
     */
    private static function load(Builder|Relation|Collection $audits): Collection
    {
        if ($audits instanceof Builder || $audits instanceof Relation) {
            return $audits->with('user')->get();
        }

        if (method_exists($audits, 'loadMissing')) {
            $audits->loadMissing('user');
        }

        return $audits;
    }

    /** @return array{action: string, created_by: string, created_at: string|null} */
    private static function row(Audit $audit, string $userColumn, string $dateFormat): array
    {
        $user = $audit->user;

        return [
            'action' => (string) $audit->event,
            'created_by' => match (true) {
                $user !== null => (string) ($user->{$userColumn} ?? '#'.$audit->created_by),
                $audit->created_by === null => 'Sistema',
                default => '#'.$audit->created_by,
            },
            'created_at' => $audit->created_at?->format($dateFormat),
        ];
    }
}
