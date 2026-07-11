<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Modelo de auditoria. Substituível pelo consumidor via
 * config('auditable.model'), no mesmo padrão do Activity da Spatie.
 *
 * Tabela e conexão vêm da config. Definir uma conexão dedicada aqui é,
 * inclusive, uma das formas de isolar auditoria por tenant quando se usa
 * tenancy por-database: basta apontar para a conexão do tenant.
 */
class Audit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'changes' => 'array',
        'debug_info' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        $this->table = config('auditable.table', 'audits');
        $this->setConnection(config('auditable.connection'));

        parent::__construct($attributes);
    }

    /**
     * O registro auditado (polimórfico). Permite $audit->subject de volta ao
     * model original, e $model->audits() no sentido inverso via o trait.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * O usuário responsável. Resolvido de forma tardia para não acoplar o
     * pacote a uma classe de usuário específica.
     */
    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    // ---- Restauro de registros apagados (hard delete) ----

    /**
     * Esta entrada de auditoria carrega um retrato de restauro?
     *
     * Só entradas de `deleted` gravadas com fullSnapshotOnDelete ligado trazem,
     * em debug_info['restore'], o retrato integral do registro. É o que permite
     * reconstruir uma linha que foi apagada de VERDADE (sem SoftDeletes).
     */
    public function isRestorable(): bool
    {
        $debug = $this->debug_info;

        return is_array($debug)
            && isset($debug['restore']['attributes'])
            && is_array($debug['restore']['attributes']);
    }

    /**
     * Reconstrói o registro apagado a partir do retrato de restauro.
     *
     * Cenário: alguém deu um HARD DELETE num Produto. A linha sumiu da tabela,
     * mas a auditoria guardou o retrato integral. Você recupera assim:
     *
     *   $audit = Produto::auditsFor($id)->action('deleted')->latest()->first();
     *   $produto = $audit->restore();   // a linha volta à tabela original
     *
     * O retrato é CRU (valores e FKs como eram), então o registro volta idêntico,
     * incluindo o id original. Campos sensíveis (senha, tokens) não foram
     * gravados no retrato — então NÃO voltam; trate-os no seu fluxo se preciso.
     *
     * @param  bool  $withId  Recriar com o MESMO id original (padrão). Passe false
     *         para deixar o banco atribuir um id novo (evita conflito se algo já
     *         tiver reocupado aquele id).
     * @return Model  O registro recriado.
     *
     * @throws \RuntimeException  Se esta entrada não tiver retrato de restauro.
     */
    public function restore(bool $withId = true): Model
    {
        if (! $this->isRestorable()) {
            throw new \RuntimeException(
                'Esta auditoria não contém um retrato de restauro. '
                . 'Só eventos "deleted" gravados com fullSnapshotOnDelete ativo podem ser restaurados.'
            );
        }

        $restore    = $this->debug_info['restore'];
        $modelClass = $restore['subject_type'];
        $attributes = $restore['attributes'];

        /** @var Model $model */
        $model = new $modelClass();
        $keyName = $restore['key_name'] ?? $model->getKeyName();

        if (! $withId) {
            unset($attributes[$keyName]);
        }

        // Preenche sem disparar guarded/fillable: é uma reconstrução fiel, não
        // uma criação de usuário. forceFill + save mantém o registro idêntico.
        $model->forceFill($attributes);

        // Se vamos manter o id original, o insert precisa ser explícito para o
        // Eloquent não tratar como update de um model existente.
        if ($withId) {
            $model->exists = false;
            $model->wasRecentlyCreated = true;
        }

        $model->save();

        return $model;
    }

    // ---- Scopes de consulta ----
    // Encadeáveis: Audit::forRecord(Produto::class, 42)->action('aprovado')->get()

    /** Auditorias de um registro específico, por classe e id — sem carregar o model. */
    public function scopeForRecord(Builder $query, string $subjectType, int|string $subjectId): Builder
    {
        return $query
            ->where('subject_type', (new $subjectType)->getMorphClass())
            ->where('subject_id', $subjectId);
    }

    /** Filtra por nome de evento/ação (created, updated, aprovado, ...). */
    public function scopeAction(Builder $query, string|array $action): Builder
    {
        return $query->whereIn('event', (array) $action);
    }

    /** Apenas falhas (as que têm debug_info preenchido). */
    public function scopeFailures(Builder $query): Builder
    {
        return $query->whereNotNull('debug_info');
    }

    /** Auditorias de um usuário específico. */
    public function scopeByUser(Builder $query, int|string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /** Auditorias de um batch (uma operação lógica que tocou várias linhas). */
    public function scopeInBatch(Builder $query, string $batch): Builder
    {
        return $query->where('batch', $batch);
    }

    /**
     * A OPERAÇÃO INTEIRA a que um registro pertenceu.
     *
     * Dado um registro (classe + id), descobre o batch da sua auditoria mais
     * recente e devolve TODAS as auditorias desse batch — de todas as tabelas.
     * É o fecho do ciclo: você pesquisa pelo cliente e recebe de volta o
     * produto, os itens e tudo o mais que foi gravado naquela mesma operação.
     *
     *   // O cliente foi criado junto com um pedido e seus itens, num batch.
     *   Audit::operationOf(Cliente::class, $clienteId)->get();
     *   // -> traz as linhas do cliente, do pedido e dos itens.
     *
     * Se o registro tiver várias operações no histórico, considera a mais
     * recente. Para uma operação específica, use scopeInBatch() com o id do batch.
     */
    public static function operationOf(string $subjectType, int|string $subjectId): Builder
    {
        $morph = (new $subjectType)->getMorphClass();

        $batch = static::query()
            ->where('subject_type', $morph)
            ->where('subject_id', $subjectId)
            ->whereNotNull('batch')
            ->latest()
            ->value('batch');

        // Sem batch (registro nunca auditado, ou auditado fora de batch):
        // devolve um builder que não retorna nada, para o chamador poder ->get()
        // com segurança.
        if ($batch === null) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->inBatch($batch)->oldest();
    }
}
