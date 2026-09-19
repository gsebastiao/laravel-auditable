<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Models;

use Gsebastiao\Auditable\Support\ChangeSetBuilder;
use Gsebastiao\Auditable\Support\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * Uma linha da tabela de auditoria.
 *
 * Colunas: batch, subject_type, subject_id, event, changes, debug_info,
 * created_by, created_at, updated_at (e tenant_id, se ligado).
 *
 * Para estender (relações extra, outra tabela, outra conexão):
 *
 *   class MinhaAuditoria extends \Gsebastiao\Auditable\Models\Audit
 *   {
 *       protected $table = 'historico';   // opcional: senão usa config('auditable.table')
 *   }
 *
 * e aponte config('auditable.model') para ela.
 *
 * Atenção, dentro de uma subclasse: leia a coluna com
 * $this->getAttribute('changes'). $this->changes é uma propriedade interna
 * do Eloquent com o mesmo nome. (Fora da classe, $audit->changes funciona.)
 */
class Audit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'changes' => 'array',
        'debug_info' => 'array',
    ];

    protected static function booted(): void
    {
        if (config('auditable.tenant.enabled', false)) {
            static::addGlobalScope(new TenantScope());
        }
    }

    /** $table definido numa subclasse tem prioridade; senão, a config. */
    public function getTable(): string
    {
        return $this->table ?? (string) config('auditable.table', 'audit_table');
    }

    /** $connection definido numa subclasse tem prioridade; senão, a config. */
    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('auditable.connection');
    }

    public function tenantColumn(): string
    {
        return (string) config('auditable.tenant.column', 'tenant_id');
    }

    /** Consulta sem o filtro de tenant (relatórios centrais). */
    public static function withoutTenantScope(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }

    // ------------------------------------------------------------------
    // Relações
    // ------------------------------------------------------------------

    /** O registro auditado: $audit->subject. */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** Quem fez: $audit->user?->name. Null em ações de sistema. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(static::userModel(), 'created_by');
    }

    /** @deprecated use user() */
    public function causer(): BelongsTo
    {
        return $this->user();
    }

    /** @return class-string<Model> */
    public static function userModel(): string
    {
        return config('auditable.user_model')
            ?? config('auth.providers.users.model')
            ?? 'App\\Models\\User';
    }

    // ------------------------------------------------------------------
    // Leitura
    // ------------------------------------------------------------------

    /**
     * As alterações já em texto, prontas para mostrar numa tela:
     *
     *   ['Preco: 20 → 25', 'Status: Ativo → Bloqueado']
     *
     * Campos técnicos (id, created_at, updated_at, deleted_at,
     * remember_token) ficam de fora — a mesma regra do widget JS.
     *
     * @return array<int, string>
     */
    public function changeLines(): array
    {
        $hidden = ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];
        $lines = [];

        // $this->changes (sem getAttribute) seria a propriedade interna do
        // Eloquent com o mesmo nome, e não a coluna.
        foreach ((array) $this->getAttribute('changes') as $field => $value) {
            $field = (string) $field;

            if (in_array($field, $hidden, true)) {
                continue;
            }

            $label = $field === 'message' ? 'Mensagem' : static::fieldLabel($field);

            if ($field === 'password') {
                $lines[] = $label.': '.ChangeSetBuilder::MASK;
            } elseif (is_array($value) && array_key_exists('old', $value) && array_key_exists('new', $value)) {
                $lines[] = $label.': '.static::displayValue($value['old']).' → '.static::displayValue($value['new']);
            } else {
                $lines[] = $label.': '.static::displayValue($value);
            }
        }

        return $lines;
    }

    /** "status_id" → "Status Id" (o nome legível vem do resolveMap, se houver). */
    protected static function fieldLabel(string $field): string
    {
        $words = array_map(
            static fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1),
            explode(' ', str_replace('_', ' ', $field)),
        );

        return implode(' ', $words);
    }

    protected static function displayValue(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '', $value === [] => '(vazio)',
            is_bool($value) => $value ? 'Sim' : 'Não',
            is_array($value) && array_key_exists('label', $value) => (string) ($value['label'] ?? '') !== ''
                ? (string) $value['label']
                : (isset($value['id']) ? '#'.$value['id'] : '(vazio)'),
            is_array($value) && array_is_list($value) => implode(', ', array_map(static fn ($item) => static::displayValue($item), $value)),
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }

    // ------------------------------------------------------------------
    // Falhas e restauro
    // ------------------------------------------------------------------

    /** Esta linha foi gravada por auditFailure()? */
    public function isFailure(): bool
    {
        return is_array($this->debug_info) && isset($this->debug_info['error']);
    }

    /** Esta linha guarda um retrato capaz de recriar o registro apagado? */
    public function isRestorable(): bool
    {
        $debug = $this->debug_info;

        return is_array($debug)
            && isset($debug['restore']['attributes'])
            && is_array($debug['restore']['attributes']);
    }

    /**
     * Recria na tabela original o registro apagado (hard delete).
     *
     *   $audit = Produto::auditsFor($id)->action('deleted')->first();
     *   $produto = $audit->restore();
     *
     * Os valores voltam exatamente como estavam no banco (datas, JSON, campos
     * criptografados). Campos de neverSnapshot() (ex.: password) não foram
     * guardados: passe-os em $attributes se a coluna for obrigatória.
     *
     * @param  bool                  $withId      Recriar com o mesmo id (padrão) ou deixar o banco gerar outro.
     * @param  array<string, mixed>  $attributes  Valores extra/novos (passam pelos casts e mutators do model).
     *
     * @throws RuntimeException  Sem retrato, model desconhecido, ou id já ocupado.
     */
    public function restore(bool $withId = true, array $attributes = []): Model
    {
        if (! $this->isRestorable()) {
            throw new RuntimeException(
                'Esta auditoria não tem retrato de restauro. Só eventos "deleted" gravados '
                .'com fullSnapshotOnDelete ligado (o padrão) podem ser restaurados.'
            );
        }

        $restore = $this->debug_info['restore'];
        $type = (string) $restore['subject_type'];
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new RuntimeException(
                "Não encontrei o model \"{$type}\" para restaurar. Se usa morphMap, confirme que o alias continua registrado."
            );
        }

        /** @var Model $model */
        $model = new $class();

        if (! empty($restore['table'])) {
            $model->setTable($restore['table']);
        }

        $keyName = $restore['key_name'] ?? $model->getKeyName();
        $raw = $restore['attributes'];

        if (! $withId) {
            unset($raw[$keyName]);
        } elseif (isset($raw[$keyName]) && $class::query()->withoutGlobalScopes()->whereKey($raw[$keyName])->exists()) {
            $hint = in_array(SoftDeletes::class, class_uses_recursive($class), true)
                ? ' Se ele está na lixeira (SoftDeletes), use $model->restore() do próprio model.'
                : '';

            throw new RuntimeException(
                "Já existe um {$class} com {$keyName} = {$raw[$keyName]}. "
                .'Use restore(withId: false) para recriar com um id novo.'.$hint
            );
        }

        // Valores crus: nada de casts/mutators, para voltar idêntico ao que era.
        $model->setRawAttributes($raw);

        if ($attributes !== []) {
            $model->forceFill($attributes);
        }

        $model->save();

        return $model;
    }

    // ------------------------------------------------------------------
    // Filtros encadeáveis:  Audit::forRecord(Produto::class, 42)->action('aprovado')->get()
    // ------------------------------------------------------------------

    /** Auditorias de um registro (classe do model ou nome livre usado no Audit::for). */
    public function scopeForRecord(Builder $query, string $subjectType, int|string $subjectId): Builder
    {
        return $query
            ->where('subject_type', static::morphTypeOf($subjectType))
            ->where('subject_id', $subjectId);
    }

    /** Por nome de evento/ação: created, updated, aprovado... */
    public function scopeAction(Builder $query, string|array $action): Builder
    {
        return $query->whereIn('event', (array) $action);
    }

    /** Só as falhas gravadas por auditFailure(). */
    public function scopeFailures(Builder $query): Builder
    {
        return $query->whereNotNull('debug_info->error');
    }

    /** Feitas por um usuário. */
    public function scopeByUser(Builder $query, int|string $userId): Builder
    {
        return $query->where('created_by', $userId);
    }

    /** De um batch (uma operação). */
    public function scopeInBatch(Builder $query, string $batch): Builder
    {
        return $query->where('batch', $batch);
    }

    /**
     * Vários filtros de uma vez, para uma tela de pesquisa. Recebe
     * $request->all() diretamente: chaves vazias ou desconhecidas (page,
     * _token...) são ignoradas, e datas inválidas também.
     *
     *   Audit::filter($request->all())->latest()->paginate(30);
     *
     * Chaves:
     *   user      id de quem fez
     *   event     nome do evento (texto ou lista: ['created', 'updated'])
     *   model     classe do model ou nome livre usado no Audit::for()
     *   id        id do registro (use junto com model)
     *   batch     id da operação
     *   from, to  período, datas inclusive ('2026-07-01')
     *   failures  "1"/true = só as falhas
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $filled = static fn (string $key): bool => isset($filters[$key]) && $filters[$key] !== '' && $filters[$key] !== [];
        $isDate = static fn (string $key): bool => $filled($key) && is_string($filters[$key]) && strtotime($filters[$key]) !== false;

        if ($filled('user') && is_scalar($filters['user'])) {
            $query->byUser($filters['user']);
        }

        if ($filled('event')) {
            $query->action(is_array($filters['event']) ? array_values($filters['event']) : (string) $filters['event']);
        }

        if ($filled('model') && is_string($filters['model'])) {
            $query->where('subject_type', static::morphTypeOf($filters['model']));
        }

        if ($filled('id') && is_scalar($filters['id'])) {
            $query->where('subject_id', $filters['id']);
        }

        if ($filled('batch') && is_string($filters['batch'])) {
            $query->inBatch($filters['batch']);
        }

        if ($isDate('from')) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if ($isDate('to')) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        if (filter_var($filters['failures'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->failures();
        }

        return $query;
    }

    /**
     * A ÚLTIMA operação (batch) de que o registro participou, com as linhas
     * de todas as tabelas. Nenhuma auditoria = consulta vazia.
     */
    public static function operationOf(string $subjectType, int|string $subjectId): Builder
    {
        $batch = static::query()
            ->forRecord($subjectType, $subjectId)
            ->whereNotNull('batch')
            ->latest()
            ->orderByDesc('id')
            ->value('batch');

        if ($batch === null) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->inBatch($batch)->orderBy('id');
    }

    /**
     * TODAS as operações (batches) de que o registro participou, com as
     * linhas de todas as tabelas — o histórico completo.
     */
    public static function operationsOf(string $subjectType, int|string $subjectId): Builder
    {
        $batches = static::query()
            ->forRecord($subjectType, $subjectId)
            ->whereNotNull('batch')
            ->select('batch');

        return static::query()->whereIn('batch', $batches)->orderBy('id');
    }

    /** Classe de model → morph class (respeita morphMap); nome livre → ele mesmo. */
    public static function morphTypeOf(string $subjectType): string
    {
        return is_subclass_of($subjectType, Model::class)
            ? (new $subjectType())->getMorphClass()
            : $subjectType;
    }
}
