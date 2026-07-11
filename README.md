# Laravel Audit Table

**Auditoria automática para Eloquent que grava _labels legíveis_, não IDs crus.**

Toda vez que um model muda, o Auditable registra quem mudou, o quê, e quando — traduzindo chaves estrangeiras para nomes que um humano entende. Funciona por eventos do Eloquent, então você não muda uma linha da forma como já salva seus dados. Multitenancy é opcional e plugável.

<p>
  <a href="#-português">🇵🇹 Português</a> &nbsp;•&nbsp; <a href="#-english">🇬🇧 English</a>
</p>

<sub>Requer PHP 8.2+ · Laravel 11, 12 ou 13 · Licença MIT</sub>

---

## O problema que ele resolve

A maioria dos pacotes de auditoria grava isto quando um pedido muda de status:

```json
{ "status_id": { "old": 2, "new": 5 } }
```

E aí alguém abre o log e pergunta: _"o que é status 2? e 5?"_. Ninguém sabe sem ir ao banco.

O Auditable grava isto:

```json
{ "Status": { "old": "Aguardando pagamento", "new": "Enviado" } }
```

O mesmo evento. A diferença é que o log **se explica sozinho**. É para isso que o pacote existe.

---

# 🇵🇹 Português

## Instalação

```bash
composer require gsebastiao/laravel-auditable
```

Publique a configuração e a migration, depois rode a migration:

```bash
php artisan vendor:publish --tag=auditable-config
php artisan vendor:publish --tag=auditable-migrations
php artisan migrate
```

Pronto. Nada mais é obrigatório.

## Começando (2 minutos)

**Passo 1 —** Adicione o trait `Auditable` a qualquer model:

```php
use Illuminate\Database\Eloquent\Model;
use Gsebastiao\Auditable\Concerns\Auditable;

class Produto extends Model
{
    use Auditable;
}
```

**É só isso para começar.** A partir de agora, `create`, `update` e `delete`
deste model são auditados automaticamente:

```php
$produto = Produto::create(['nome' => 'Café', 'preco' => 20]);
$produto->update(['preco' => 25]);
```

**Passo 2 —** Consulte o histórico a qualquer momento:

```php
$produto->audits;   // coleção com todo o histórico do registro
```

Cada entrada traz o evento (`created`/`updated`/`deleted`), o que mudou, quem
fez, e quando. Sem configurar mais nada.

## Traduzindo IDs para nomes (o diferencial)

Se o seu model tem chaves estrangeiras, diga ao Auditable como transformá-las em
texto legível. Você faz isso adicionando **um método** ao model:

```php
use Gsebastiao\Auditable\Support\AuditOptions;
use Gsebastiao\Auditable\Support\ResolveMap;

class Produto extends Model
{
    use Auditable;

    public function getAuditOptions(): AuditOptions
    {
        return AuditOptions::defaults()->resolveMap([

            // status_id: busca o nome na tabela "status"
            'status_id' => ResolveMap::direct(
                label:  'Status',   // como aparece no log
                table:  'status',   // onde buscar
                column: 'nome',     // qual coluna é o texto
            ),

        ]);
    }
}
```

Agora, em vez de `status_id: 2 → 5`, o log grava `Status: "Ativo" → "Bloqueado"`.

### Os três modos de tradução

| Modo | Quando usar | Exemplo |
|------|-------------|---------|
| `direct` | A FK aponta direto para uma tabela com o nome | `status_id` → tabela `status` |
| `join` | Precisa navegar por tabelas intermediárias | `estado_id` → `estados` → `paises` |
| `alias` | Não é FK, só quer renomear o campo no log | `ativo` → "Situação" |

<details>
<summary><b>Ver exemplos de <code>join</code> e <code>alias</code></b></summary>

```php
AuditOptions::defaults()->resolveMap([

    // JOIN: resolver o nome do país a partir de estado_id,
    // navegando estados → paises
    'estado_id' => ResolveMap::join([
        ['table' => 'estados', 'key' => 'id'],
        ['table' => 'paises',
         'on'     => ['paises.id', '=', 'estados.pais_id'],
         'column' => 'nome',
         'label'  => 'País'],
    ]),

    // ALIAS: campo booleano que só precisa de um nome bonito no log
    'ativo' => ResolveMap::alias('Situação'),

]);
```

</details>

## Escolhendo o que auditar

O mesmo `getAuditOptions()` controla o resto. Tudo é opcional:

```php
AuditOptions::defaults()
    ->except(['updated_at', 'senha'])   // nunca audita estes campos
    ->only(['preco', 'status_id'])      // OU: audita só estes
    ->events(['updated', 'deleted'])    // ignora o "created"
    ->onlyDirty()                       // só grava o que de fato mudou (padrão)
    ->logEmpty(false);                  // não grava se nada mudou (padrão)
```

> Senhas e tokens (`password`, `remember_token`) já são ignorados por padrão.

## Operações multi-tabela: um batch, uma história

Este é o cenário que dá sentido ao resto. Você cria um pedido — e junto com ele
entram o cliente, os itens, uma baixa de estoque. São **escritas em tabelas
diferentes**, mas fazem parte da **mesma operação**. Você quer poder olhar para
qualquer uma delas depois e reconstruir a operação inteira.

Envolva a operação em `Audit::transaction()` (ou `Audit::batch()` se não quiser
transação). Tudo que for auditado lá dentro — de qualquer model — recebe o
**mesmo batch**:

```php
use Gsebastiao\Auditable\Audit;

Audit::transaction(function () use ($dados) {
    $cliente = Cliente::create($dados['cliente']);
    $pedido  = Pedido::create(['cliente_id' => $cliente->id, ...]);

    foreach ($dados['itens'] as $item) {
        Item::create(['pedido_id' => $pedido->id, ...]);
    }
});
```

O cliente, o pedido e todos os itens ficam gravados sob um único batch. E como é
uma transação, **se qualquer parte falhar, tudo volta atrás** — escritas e
auditoria juntas.

### Recuperando a operação inteira a partir de um registro

Agora a parte que você descreveu: você tem **só o cliente** e quer ver tudo que
entrou junto com ele. Chame `operation()`:

```php
$cliente = Cliente::find($id);

$cliente->operation()->get();
// -> devolve as auditorias do cliente, do pedido E dos itens
//    (tudo o que compartilhou o batch)
```

Ou, se você só tem o id:

```php
Cliente::operationFor($id)->get();
```

Como o resultado é um query builder normal, você agrupa por tabela para exibir:

```php
$cliente->operation()->get()->groupBy('subject_type');
// [
//   'App\Models\Cliente' => [ ... ],
//   'App\Models\Pedido'  => [ ... ],
//   'App\Models\Item'    => [ ... ],
// ]
```

> **Precisa só do id do batch?** `$cliente->batchOf()` devolve o identificador da
> última operação daquele registro — útil para logs ou para passar adiante.

### Propagando o batch para filas

Se parte da operação roda numa job assíncrona e você quer que ela caia no mesmo
batch, passe o id para a job e reabra lá dentro:

```php
// Ao despachar:
ProcessarPedido::dispatch($pedido, Audit::currentBatch());

// Dentro da job:
public function handle(): void
{
    Audit::useBatch($this->batchId);
    // tudo auditado aqui entra no mesmo batch da operação original
}
```

## Ações customizadas (além de create/update/delete)

Os três eventos automáticos cobrem escritas no banco. Mas nem tudo que você quer
auditar é uma escrita — "aprovou o pedido", "reenviou o e-mail", "fez login",
"exportou". Para esses, chame `auditAction()` com o nome que quiser:

```php
$pedido->auditAction('aprovado');

$pedido->auditAction('email_reenviado', [
    'para' => $cliente->email,
    'via'  => 'ses',
]);
```

Fica no mesmo histórico dos eventos automáticos, com o nome que você deu.

## Consultar o histórico de um registro específico

`$produto->audits` te dá o histórico do model que você **já carregou**. Quando
você tem só o **id**, ou quer **filtrar**, use `auditsFor()` — que devolve um
query builder:

```php
// Tudo do registro 42, sem precisar carregar o Produto
Produto::auditsFor(42)->get();

// Só as aprovações
Produto::auditsFor(42)->action('aprovado')->get();

// A última alteração feita por um usuário
Produto::auditsFor(42)->byUser($userId)->latest()->first();

// Só as falhas deste registro
Produto::auditsFor(42)->failures()->get();
```

Filtros disponíveis: `action()`, `byUser()`, `failures()`, `inBatch()`.

## Colunas de auditoria numa listagem (DataTable)

As relações acima respondem "qual é o histórico **deste** registro?". Uma **grelha**
faz outra pergunta, sobre **muitos** registros de uma vez: "para cada linha desta
página, quem criou e quando? quem alterou por último e quando?". Resolver isso com
a relação seria um **N+1** — uma consulta de auditoria por linha exibida.

`AuditColumnJoiner` resolve de outro jeito: anexa `audit_created_by`,
`audit_created_at`, `audit_updated_by`, `audit_updated_at` como **colunas** na
própria query, via `LEFT JOIN` de subconsultas agregadas. Uma query só, sem N+1,
pronta para o DataTable ordenar e paginar.

```php
use Gsebastiao\Auditable\Support\AuditColumnJoiner;

// Na sua query de listagem:
$query = Produto::query()->where('ativo', 1);

AuditColumnJoiner::apply($query, Produto::class);
// agora cada linha traz: audit_created_by, audit_created_at, audit_updated_by, audit_updated_at
```

**Por que o prefixo `audit_`?** Porque `created_at`, `updated_at` e `deleted_at` são
colunas **nativas** do Eloquent, com cast automático de datetime. Se emitíssemos uma
coluna chamada `created_at`, ela colidiria com a nativa da própria tabela e o
Eloquent tentaria dar cast na string já formatada (`10/07/2026 14:30`) — e
quebraria. Prefixar **todas** as colunas na raiz elimina a colisão de vez e, de
quebra, mantém o par `_by`/`_at` sempre consistente — sem exceções nem sufixos
especiais. Toda ação sai igual: `audit_restored_by`/`audit_restored_at`,
`audit_aprovado_by`/`audit_aprovado_at`. O prefixo é configurável (parâmetro
`prefix:` ou `config('auditable.column_prefix')`).

**Por que só `created` e `updated` por padrão?** Numa grelha normal de um model com
`SoftDeletes`, o global scope já esconde os apagados — então uma coluna
`audit_deleted_by` ficaria sempre vazia, custando dois `JOIN` por linha à toa. Só
inclua `deleted`/`restored` quando a **própria grelha** for uma lixeira:

```php
// Grelha de lixeira: aí sim faz sentido "quem apagou / quando"
AuditColumnJoiner::apply(
    Produto::onlyTrashed(),
    Produto::class,
    actions: ['deleted', 'restored'],
);

// Exibir por email em vez de nome
AuditColumnJoiner::apply($query, Produto::class, userColumn: 'email');

// Ações de domínio também viram coluna (a última ocorrência)
AuditColumnJoiner::apply($query, Produto::class, actions: ['created', 'aprovado']);
// → audit_aprovado_by, audit_aprovado_at
```

Cada ação = **dois** `LEFT JOIN` (a subconsulta de auditoria + a tabela `users`).
Peça só o que a grelha vai mostrar. Índice recomendado na tabela de auditoria:
`(subject_type, event, subject_id, id)`.

> **Quando usar o quê:** MUITAS linhas, um resumo por linha → `AuditColumnJoiner`.
> UMA linha, o histórico todo → `$model->audits` / `auditsFor()`.

## Sobrevivendo a um hard delete: o retrato de restauro

A tabela de auditoria é **append-only e imutável** — de propósito, ela **não** usa
soft delete. Um registro de auditoria que pode ser apagado deixa de servir para
auditar quem apaga coisas. O que precisa de proteção é o **dado de negócio**, e a
proteção é outra.

No evento `deleted`, além do `changes` legível, o pacote grava em
`debug_info['restore']` um **retrato integral e cru** do registro — todos os campos
e valores, ignorando as restrições de `only()`/`except()` do log legível. É esse
retrato que permite reconstruir a linha mesmo depois de um **hard delete** (sem
`SoftDeletes`), em que a linha some de verdade da tabela de origem.

```php
// Alguém deu um hard delete num Produto. A linha sumiu — mas a auditoria guardou.
$audit = Produto::auditsFor($id)->action('deleted')->latest()->first();

$audit->isRestorable();   // true, se o retrato foi gravado
$produto = $audit->restore();   // a linha VOLTA à tabela original, com o id original
```

O retrato é cru (valores e FKs como eram), então o registro volta **idêntico**,
incluindo o id. Duas garantias importantes:

- **Segredos não voltam.** Campos em `neverSnapshot` (por padrão `password`,
  `remember_token`) **nunca** entram no retrato — nem para restaurar. Voltam nulos;
  trate-os no seu fluxo se preciso.
- **`changes` continua legível.** O retrato de restauro é técnico e vai para
  `debug_info` (do dev). O `changes` do delete continua sendo o snapshot legível,
  para humanos. Uma preocupação para leitura, outra para reconstrução — separadas.

```php
// Restaurar deixando o banco atribuir um id novo (evita conflito se o id foi reusado)
$produto = $audit->restore(withId: false);
```

Ligado por padrão. Se um model tiver campos volumosos que você não quer duplicar na
auditoria, desligue por model:

```php
public function getAuditOptions(): AuditOptions
{
    return AuditOptions::defaults()->fullSnapshotOnDelete(false);
}
```

Ou globalmente, em `config/auditable.php`, no bloco `restore`.


## Auditando falhas (o debug que só o dev vê)

Quando uma operação pode falhar e você quer registrar **por que** falhou, use
`auditFailure()` dentro do `catch`. Ele separa duas coisas:

- **`changes`** — uma mensagem amigável, que o usuário pode ver.
- **`debug_info`** — stack trace, SQL, request, ambiente. Só para o desenvolvedor.

```php
try {
    $fatura->update($dados);
} catch (\Throwable $e) {
    $fatura->auditFailure('fatura_update', $e, [
        'payload' => $dados,   // contexto extra que ajuda a investigar
    ]);

    throw $e;   // relança — auditar não engole o erro
}
```

Depois, para investigar:

```php
$falha = Fatura::auditsFor($id)->failures()->latest()->first();

$falha->changes;      // ['message' => 'A operação falhou.', 'error' => '...']
$falha->debug_info;   // trace, sql, request, ambiente — tudo o que você precisa
```

> O `debug_info` traz driver e nome do banco, mas **nunca host ou credenciais**.
> Detalhes de servidor só aparecem fora de produção.

## Multitenancy (opcional)

Se você tem um SaaS, há **dois cenários**. Escolha o seu:

### Cenário A — cada tenant tem seu próprio banco

Usa `stancl/tenancy`, `spatie/laravel-multitenancy` em modo multi-banco, ou
similar? **Você não precisa fazer nada.** Quando o seu pacote de tenancy troca a
conexão, a auditoria vai junto para o banco certo. Isolamento automático.

Se quiser forçar uma conexão específica para a auditoria:

```php
// config/auditable.php
'connection' => 'tenant',
```

### Cenário B — um banco só, com coluna `tenant_id`

Todos os tenants no mesmo banco, separados por uma coluna? Ative o modo por
coluna e diga ao pacote **como descobrir o tenant atual**:

```php
// config/auditable.php
'tenant' => [
    'enabled'  => true,
    'column'   => 'tenant_id',
    'resolver' => fn () => auth()->user()?->tenant_id,   // ajuste à sua realidade
],
```

Depois, use o trait `BelongsToTenant` nos models que devem ser isolados:

```php
use Gsebastiao\Auditable\Concerns\Auditable;
use Gsebastiao\Auditable\Concerns\BelongsToTenant;

class Produto extends Model
{
    use Auditable;
    use BelongsToTenant;   // filtra por tenant e preenche tenant_id sozinho
}
```

A partir daí, cada tenant só enxerga os próprios dados — e a auditoria de um
tenant nunca vaza para outro.

> **Regra do pacote:** ele **lê** qual é o tenant atual, nunca **decide**. Quem
> decide é a sua app ou o seu pacote de tenancy. Por isso o `resolver` é seu.

## Personalização avançada

<details>
<summary><b>Trocar onde/como a auditoria é gravada</b> (fila, serviço externo…)</summary>

Cada peça do pacote é uma interface com implementação padrão. Para trocar,
religue no seu `AppServiceProvider`:

| Interface | O que faz | Padrão |
|-----------|-----------|--------|
| `AuditRepository` | Persiste a auditoria | Grava via Eloquent |
| `BatchIdGenerator` | Agrupa operações relacionadas | ULID |
| `ContextResolver` | Descobre usuário e tenant atuais | `auth()` + seu resolver |

```php
use Gsebastiao\Auditable\Contracts\AuditRepository;

public function register(): void
{
    $this->app->bind(AuditRepository::class, MinhaAuditoriaNaFila::class);
}
```

</details>

<details>
<summary><b>Usar seu próprio model de auditoria</b> (outra tabela, relações extras…)</summary>

```php
use Gsebastiao\Auditable\Models\Audit as BaseAudit;

class Audit extends BaseAudit
{
    // suas relações, scopes, accessors…
}
```

```php
// config/auditable.php
'model' => App\Models\Audit::class,
```

</details>

<details>
<summary><b>Ligar/desligar auditoria globalmente</b> (testes, seeders…)</summary>

```php
// config/auditable.php
'enabled' => env('AUDITABLE_ENABLED', true),
```

```dotenv
# .env.testing
AUDITABLE_ENABLED=false
```

</details>

## Referência rápida

```php
// No model
use Gsebastiao\Auditable\Concerns\Auditable;          // torna auditável
use Gsebastiao\Auditable\Concerns\BelongsToTenant;    // isolamento por tenant (opcional)

// Nas opções (getAuditOptions)
AuditOptions::defaults()
    ->resolveMap([...])   // traduz FKs
    ->except([...])       // ignora campos
    ->only([...])         // ou: só estes campos
    ->events([...])       // quais eventos auditar
    ->onlyDirty()         // só o que mudou
    ->logEmpty(false);    // pular logs vazios

// Modos de tradução
ResolveMap::direct(label, table, column);   // FK → tabela
ResolveMap::join([...]);                    // por tabelas intermediárias
ResolveMap::alias(label);                   // só renomear

// Registrar (além dos eventos automáticos)
$model->auditAction('aprovado', [...]);            // ação de domínio nomeada
$model->auditFailure('op', $exception, [...]);     // falha com debug técnico

// Consultar histórico
$model->audits;                             // do model já carregado
Model::auditsFor($id);                      // por id (query builder)
    ->action('aprovado')                    // filtros encadeáveis:
    ->byUser($userId)
    ->failures()
    ->inBatch($batch);

// Colunas de auditoria numa listagem (DataTable) — sem N+1
use Gsebastiao\Auditable\Support\AuditColumnJoiner;
AuditColumnJoiner::apply($query, Model::class);                      // created_/updated_ by/at
AuditColumnJoiner::apply($query, Model::class, actions: ['deleted']); // p/ grelha de lixeira
AuditColumnJoiner::apply($query, Model::class, userColumn: 'email');  // "quem" por email

// Restaurar um registro após HARD delete (retrato em debug_info['restore'])
$audit = Model::auditsFor($id)->action('deleted')->latest()->first();
$audit->isRestorable();                     // tem retrato de restauro?
$audit->restore();                          // reconstrói com o id original
$audit->restore(withId: false);             // reconstrói com id novo

// Operações multi-tabela (um batch costura tudo)
use Gsebastiao\Auditable\Audit;
Audit::transaction(fn () => /* várias escritas */);   // transação + batch juntos
Audit::batch(fn () => /* várias escritas */);         // só o batch, sem transação
$model->operation()->get();                 // toda a operação, a partir de 1 registro
Model::operationFor($id)->get();            // idem, só com o id
$model->batchOf();                          // só o id do batch
Audit::currentBatch();                      // batch aberto (p/ propagar a filas)
Audit::useBatch($batchId);                  // reabrir batch (dentro de uma job)
```

## Licença

MIT. Use à vontade.

---

# 🇬🇧 English

## Installation

```bash
composer require gsebastiao/laravel-auditable
```

Publish the config and migration, then run the migration:

```bash
php artisan vendor:publish --tag=auditable-config
php artisan vendor:publish --tag=auditable-migrations
php artisan migrate
```

That's it. Nothing else is required.

## Getting started (2 minutes)

**Step 1 —** Add the `Auditable` trait to any model:

```php
use Illuminate\Database\Eloquent\Model;
use Gsebastiao\Auditable\Concerns\Auditable;

class Product extends Model
{
    use Auditable;
}
```

**That's all you need to start.** From now on, `create`, `update` and `delete`
on this model are audited automatically:

```php
$product = Product::create(['name' => 'Coffee', 'price' => 20]);
$product->update(['price' => 25]);
```

**Step 2 —** Read the history whenever you want:

```php
$product->audits;   // collection with the full history of the record
```

Each entry carries the event (`created`/`updated`/`deleted`), what changed, who
did it, and when. No further setup.

## Turning IDs into names (the whole point)

If your model has foreign keys, tell Auditable how to turn them into readable
text. You do that by adding **one method** to the model:

```php
use Gsebastiao\Auditable\Support\AuditOptions;
use Gsebastiao\Auditable\Support\ResolveMap;

class Product extends Model
{
    use Auditable;

    public function getAuditOptions(): AuditOptions
    {
        return AuditOptions::defaults()->resolveMap([

            // status_id: look up the name in the "statuses" table
            'status_id' => ResolveMap::direct(
                label:  'Status',     // how it shows in the log
                table:  'statuses',   // where to look
                column: 'name',       // which column is the text
            ),

        ]);
    }
}
```

Now, instead of `status_id: 2 → 5`, the log records `Status: "Active" → "Blocked"`.

### The three translation modes

| Mode | When to use | Example |
|------|-------------|---------|
| `direct` | The FK points straight to a table with the name | `status_id` → `statuses` table |
| `join` | You need to walk through intermediate tables | `state_id` → `states` → `countries` |
| `alias` | Not an FK, you just want to rename the field | `active` → "Status" |

<details>
<summary><b>See <code>join</code> and <code>alias</code> examples</b></summary>

```php
AuditOptions::defaults()->resolveMap([

    // JOIN: resolve the country name from state_id,
    // walking states → countries
    'state_id' => ResolveMap::join([
        ['table' => 'states', 'key' => 'id'],
        ['table' => 'countries',
         'on'     => ['countries.id', '=', 'states.country_id'],
         'column' => 'name',
         'label'  => 'Country'],
    ]),

    // ALIAS: a boolean field that just needs a nice label in the log
    'active' => ResolveMap::alias('Status'),

]);
```

</details>

## Choosing what to audit

The same `getAuditOptions()` controls the rest. Everything is optional:

```php
AuditOptions::defaults()
    ->except(['updated_at', 'secret'])   // never audit these fields
    ->only(['price', 'status_id'])       // OR: audit only these
    ->events(['updated', 'deleted'])     // skip "created"
    ->onlyDirty()                        // only record what actually changed (default)
    ->logEmpty(false);                   // don't record if nothing changed (default)
```

> Passwords and tokens (`password`, `remember_token`) are ignored by default.

## Multi-table operations: one batch, one story

This is the scenario that ties everything together. You create an order — and
along with it come the customer, the line items, a stock decrement. These are
**writes across different tables**, but they're part of the **same operation**.
You want to look at any one of them later and reconstruct the whole thing.

Wrap the operation in `Audit::transaction()` (or `Audit::batch()` if you don't
want a transaction). Everything audited inside — from any model — gets the
**same batch**:

```php
use Gsebastiao\Auditable\Audit;

Audit::transaction(function () use ($data) {
    $customer = Customer::create($data['customer']);
    $order    = Order::create(['customer_id' => $customer->id, ...]);

    foreach ($data['items'] as $item) {
        Item::create(['order_id' => $order->id, ...]);
    }
});
```

The customer, the order and all items are recorded under a single batch. And
because it's a transaction, **if any part fails, everything rolls back** — writes
and audit trail together.

### Recovering the whole operation from a single record

Now the part you described: you have **just the customer** and want to see
everything that came in with it. Call `operation()`:

```php
$customer = Customer::find($id);

$customer->operation()->get();
// -> returns the audits for the customer, the order AND the items
//    (everything that shared the batch)
```

Or, if you only have the id:

```php
Customer::operationFor($id)->get();
```

Since the result is a normal query builder, group by table to display it:

```php
$customer->operation()->get()->groupBy('subject_type');
// [
//   'App\Models\Customer' => [ ... ],
//   'App\Models\Order'    => [ ... ],
//   'App\Models\Item'     => [ ... ],
// ]
```

> **Just need the batch id?** `$customer->batchOf()` returns the identifier of
> that record's latest operation — handy for logs or passing along.

### Propagating the batch to queues

If part of the operation runs in an async job and you want it in the same batch,
pass the id to the job and reopen it there:

```php
// When dispatching:
ProcessOrder::dispatch($order, Audit::currentBatch());

// Inside the job:
public function handle(): void
{
    Audit::useBatch($this->batchId);
    // everything audited here joins the original operation's batch
}
```

## Custom actions (beyond create/update/delete)

The three automatic events cover database writes. But not everything you want to
audit is a write — "approved the order", "resent the email", "logged in",
"exported". For those, call `auditAction()` with whatever name you want:

```php
$order->auditAction('approved');

$order->auditAction('email_resent', [
    'to'  => $customer->email,
    'via' => 'ses',
]);
```

It lands in the same history as the automatic events, under the name you gave.

## Querying a specific record's history

`$product->audits` gives you the history of a model you **already loaded**. When
you only have the **id**, or want to **filter**, use `auditsFor()` — it returns a
query builder:

```php
// Everything for record 42, without loading the Product
Product::auditsFor(42)->get();

// Only approvals
Product::auditsFor(42)->action('approved')->get();

// The last change made by a user
Product::auditsFor(42)->byUser($userId)->latest()->first();

// Only failures for this record
Product::auditsFor(42)->failures()->get();
```

Available filters: `action()`, `byUser()`, `failures()`, `inBatch()`.

## Audit columns in a listing (DataTable)

The relations above answer "what is **this** record's history?". A **grid** asks a
different question about **many** records at once: "for each row on this page, who
created it and when? who last changed it and when?". Answering that with the
relation would be an **N+1** — one audit query per displayed row.

`AuditColumnJoiner` solves it differently: it attaches `audit_created_by`,
`audit_created_at`, `audit_updated_by`, `audit_updated_at` as **columns** on the
query itself, via `LEFT JOIN`s of aggregated subqueries. One query, no N+1, ready
for the DataTable to sort and paginate.

```php
use Gsebastiao\Auditable\Support\AuditColumnJoiner;

// On your listing query:
$query = Product::query()->where('active', 1);

AuditColumnJoiner::apply($query, Product::class);
// each row now carries: audit_created_by, audit_created_at, audit_updated_by, audit_updated_at
```

**Why the `audit_` prefix?** Because `created_at`, `updated_at` and `deleted_at` are
**native** Eloquent columns with automatic datetime casting. If we emitted a column
named `created_at`, it would collide with the table's native one and Eloquent would
try to cast the already-formatted string (`2026-07-10 14:30`) — and break. Prefixing
**every** column at the root removes the collision entirely and, as a bonus, keeps
the `_by`/`_at` pair consistent across all actions — no exceptions, no special
suffixes. Every action comes out the same: `audit_restored_by`/`audit_restored_at`,
`audit_approved_by`/`audit_approved_at`. The prefix is configurable (`prefix:` param
or `config('auditable.column_prefix')`).

**Why only `created` and `updated` by default?** In a normal grid of a model with
`SoftDeletes`, the global scope already hides deleted rows — so an `audit_deleted_by`
column would always be empty, costing two `JOIN`s per row for nothing. Only include
`deleted`/`restored` when the **grid itself** is a trash bin:

```php
// Trash-bin grid: here "who deleted / when" makes sense
AuditColumnJoiner::apply(
    Product::onlyTrashed(),
    Product::class,
    actions: ['deleted', 'restored'],
);

// Show by email instead of name
AuditColumnJoiner::apply($query, Product::class, userColumn: 'email');

// Domain actions become columns too (the latest occurrence)
AuditColumnJoiner::apply($query, Product::class, actions: ['created', 'approved']);
// → audit_approved_by, audit_approved_at
```

Each action = **two** `LEFT JOIN`s (the audit subquery + the `users` table). Ask
only for what the grid will show. Recommended index on the audit table:
`(subject_type, event, subject_id, id)`.

> **Which to use:** MANY rows, one summary per row → `AuditColumnJoiner`. ONE row,
> the whole history → `$model->audits` / `auditsFor()`.

## Surviving a hard delete: the restore snapshot

The audit table is **append-only and immutable** — by design, it does **not** use
soft delete. An audit record that can be deleted stops being useful for auditing who
deletes things. What needs protecting is the **business data**, and that protection
is separate.

On the `deleted` event, besides the readable `changes`, the package writes a **full
raw snapshot** of the record into `debug_info['restore']` — every field and value,
ignoring the `only()`/`except()` restrictions of the readable log. That snapshot is
what lets you rebuild the row even after a **hard delete** (no `SoftDeletes`), where
the row truly disappears from the source table.

```php
// Someone hard-deleted a Product. The row is gone — but the audit kept it.
$audit = Product::auditsFor($id)->action('deleted')->latest()->first();

$audit->isRestorable();   // true, if the snapshot was written
$product = $audit->restore();   // the row COMES BACK, with its original id
```

The snapshot is raw (values and FKs as they were), so the record returns
**identical**, id included. Two important guarantees:

- **Secrets don't come back.** Fields in `neverSnapshot` (by default `password`,
  `remember_token`) **never** enter the snapshot — not even to restore. They return
  null; handle them in your flow if needed.
- **`changes` stays readable.** The restore snapshot is technical and goes to
  `debug_info` (dev-facing). The delete's `changes` remains the readable snapshot,
  for humans. One concern for reading, another for rebuilding — kept apart.

```php
// Restore letting the DB assign a fresh id (avoids conflict if the id was reused)
$product = $audit->restore(withId: false);
```

On by default. If a model has bulky fields you don't want duplicated into the audit,
turn it off per model:

```php
public function getAuditOptions(): AuditOptions
{
    return AuditOptions::defaults()->fullSnapshotOnDelete(false);
}
```

Or globally, in `config/auditable.php`, under the `restore` block.


## Auditing failures (the debug only the dev sees)

When an operation can fail and you want to record **why**, use `auditFailure()`
inside the `catch`. It separates two things:

- **`changes`** — a friendly message, which the user can see.
- **`debug_info`** — stack trace, SQL, request, environment. Developer only.

```php
try {
    $invoice->update($data);
} catch (\Throwable $e) {
    $invoice->auditFailure('invoice_update', $e, [
        'payload' => $data,   // extra context that helps you investigate
    ]);

    throw $e;   // rethrow — auditing doesn't swallow the error
}
```

Then, to investigate:

```php
$failure = Invoice::auditsFor($id)->failures()->latest()->first();

$failure->changes;      // ['message' => 'The operation failed.', 'error' => '...']
$failure->debug_info;   // trace, sql, request, environment — everything you need
```

> `debug_info` includes the driver and database name, but **never host or
> credentials**. Server details only show outside production.

## Multitenancy (optional)

If you run a SaaS, there are **two scenarios**. Pick yours:

### Scenario A — each tenant has its own database

Using `stancl/tenancy`, `spatie/laravel-multitenancy` in multi-database mode, or
similar? **You don't need to do anything.** When your tenancy package switches the
connection, auditing follows to the right database. Isolation is automatic.

To force a specific connection for auditing:

```php
// config/auditable.php
'connection' => 'tenant',
```

### Scenario B — one database, with a `tenant_id` column

All tenants in the same database, separated by a column? Enable column mode and
tell the package **how to find the current tenant**:

```php
// config/auditable.php
'tenant' => [
    'enabled'  => true,
    'column'   => 'tenant_id',
    'resolver' => fn () => auth()->user()?->tenant_id,   // adjust to your setup
],
```

Then use the `BelongsToTenant` trait on the models that must be isolated:

```php
use Gsebastiao\Auditable\Concerns\Auditable;
use Gsebastiao\Auditable\Concerns\BelongsToTenant;

class Product extends Model
{
    use Auditable;
    use BelongsToTenant;   // filters by tenant and fills tenant_id on its own
}
```

From then on, each tenant only sees its own data — and one tenant's audit trail
never leaks into another's.

> **Package rule:** it **reads** which tenant is current, it never **decides**.
> Your app or your tenancy package decides. That's why the `resolver` is yours.

## Advanced customization

<details>
<summary><b>Change where/how audits are stored</b> (queue, external service…)</summary>

Every piece of the package is an interface with a default implementation. To
swap one, rebind it in your `AppServiceProvider`:

| Interface | What it does | Default |
|-----------|--------------|---------|
| `AuditRepository` | Persists the audit entry | Writes via Eloquent |
| `BatchIdGenerator` | Groups related operations | ULID |
| `ContextResolver` | Finds current user and tenant | `auth()` + your resolver |

```php
use Gsebastiao\Auditable\Contracts\AuditRepository;

public function register(): void
{
    $this->app->bind(AuditRepository::class, MyQueuedAudit::class);
}
```

</details>

<details>
<summary><b>Use your own audit model</b> (other table, extra relations…)</summary>

```php
use Gsebastiao\Auditable\Models\Audit as BaseAudit;

class Audit extends BaseAudit
{
    // your relations, scopes, accessors…
}
```

```php
// config/auditable.php
'model' => App\Models\Audit::class,
```

</details>

<details>
<summary><b>Toggle auditing globally</b> (tests, seeders…)</summary>

```php
// config/auditable.php
'enabled' => env('AUDITABLE_ENABLED', true),
```

```dotenv
# .env.testing
AUDITABLE_ENABLED=false
```

</details>

## Quick reference

```php
// On the model
use Gsebastiao\Auditable\Concerns\Auditable;          // makes it auditable
use Gsebastiao\Auditable\Concerns\BelongsToTenant;    // per-tenant isolation (optional)

// In the options (getAuditOptions)
AuditOptions::defaults()
    ->resolveMap([...])   // translate FKs
    ->except([...])       // ignore fields
    ->only([...])         // or: only these fields
    ->events([...])       // which events to audit
    ->onlyDirty()         // only what changed
    ->logEmpty(false);    // skip empty logs

// Translation modes
ResolveMap::direct(label, table, column);   // FK → table
ResolveMap::join([...]);                    // through intermediate tables
ResolveMap::alias(label);                   // just rename

// Record (beyond the automatic events)
$model->auditAction('approved', [...]);            // named domain action
$model->auditFailure('op', $exception, [...]);     // failure with tech debug

// Read history
$model->audits;                             // of the already-loaded model
Model::auditsFor($id);                      // by id (query builder)
    ->action('approved')                    // chainable filters:
    ->byUser($userId)
    ->failures()
    ->inBatch($batch);

// Audit columns in a listing (DataTable) — no N+1
use Gsebastiao\Auditable\Support\AuditColumnJoiner;
AuditColumnJoiner::apply($query, Model::class);                      // created_/updated_ by/at
AuditColumnJoiner::apply($query, Model::class, actions: ['deleted']); // for a trash-bin grid
AuditColumnJoiner::apply($query, Model::class, userColumn: 'email');  // "who" by email

// Restore a record after a HARD delete (snapshot in debug_info['restore'])
$audit = Model::auditsFor($id)->action('deleted')->latest()->first();
$audit->isRestorable();                     // has a restore snapshot?
$audit->restore();                          // rebuild with the original id
$audit->restore(withId: false);             // rebuild with a fresh id

// Multi-table operations (one batch ties it all)
use Gsebastiao\Auditable\Audit;
Audit::transaction(fn () => /* several writes */);   // transaction + batch together
Audit::batch(fn () => /* several writes */);         // batch only, no transaction
$model->operation()->get();                 // the whole operation, from 1 record
Model::operationFor($id)->get();            // same, with just the id
$model->batchOf();                          // just the batch id
Audit::currentBatch();                      // open batch (to propagate to queues)
Audit::useBatch($batchId);                  // reopen batch (inside a job)
```

## License

MIT. Use it freely.
