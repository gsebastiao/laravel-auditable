# Laravel Auditable

[![License](https://img.shields.io/packagist/l/gsebastiao/laravel-auditable.svg)](LICENSE.md)
[![PHP Version](https://img.shields.io/packagist/php-v/gsebastiao/laravel-auditable.svg)](composer.json)
[![Laravel Framework](https://img.shields.io/packagist/dependency-v/gsebastiao/laravel-auditable/illuminate/support.svg)](composer.json)
[![Latest Version](https://img.shields.io/packagist/v/gsebastiao/laravel-auditable.svg)](https://packagist.org/packages/gsebastiao/laravel-auditable)

Guarde automaticamente **quem** mudou **o quê** e **quando** nos seus models
Eloquent — com nomes legíveis (`Status: Ativo → Bloqueado`) em vez de ids
soltos (`status_id: 1 → 3`).

Você adiciona uma linha ao model e, a partir daí, cada `create()`, `update()`
e `delete()` fica registrado. Com o histórico gravado, dá para montar telas
como esta:

| Quando | Quem | O quê | Alterações |
| --- | --- | --- | --- |
| 11/07/2026 09:00 | João Santos | updated | Preço: 20 → 25 · Status: Ativo → Bloqueado |
| 10/07/2026 14:30 | Maria Pereira | created | Nome: Café · Preço: 20 · Status: Ativo |

**O que o pacote faz por você**

- Registra criação, alteração e exclusão sozinho (e restauração, com SoftDeletes).
- Traduz chaves estrangeiras em nomes legíveis (`resolveMap`).
- Agrupa várias gravações de uma mesma ação do usuário numa só **operação** —
  inclusive o que os jobs em fila gravarem depois.
- Audita updates e deletes **em massa**, um registro de cada vez no histórico.
- Registra falhas com detalhes técnicos que só o desenvolvedor vê.
- Recria registros apagados a partir da auditoria.
- Mostra "criado por / alterado por" numa listagem com uma única consulta.
- Traz um modal de histórico pronto em JavaScript (opcional).
- Funciona com multitenancy (opcional).

---

## Sumário

1. [Requisitos](#requisitos)
2. [Instalação](#instalação)
3. [Primeiros passos](#primeiros-passos)
4. [O que é (e o que não é) auditado automaticamente](#o-que-é-e-o-que-não-é-auditado-automaticamente)
5. [Nomes legíveis com `resolveMap`](#nomes-legíveis-com-resolvemap)
6. [Escolher o que auditar](#escolher-o-que-auditar)
7. [Quem fez a alteração](#quem-fez-a-alteração)
8. [Agrupar uma operação (batch)](#agrupar-uma-operação-batch)
9. [Registrar ações próprias: `auditAction()`](#registrar-ações-próprias-auditaction)
10. [Personalizar a entrada automática: `audit()`](#personalizar-a-entrada-automática-audit)
11. [Auditar sem model: `Audit::for()`](#auditar-sem-model-auditfor)
12. [Update e delete em massa](#update-e-delete-em-massa)
13. [Consultar o histórico](#consultar-o-histórico)
14. [Mostrar o histórico numa tela Blade](#mostrar-o-histórico-numa-tela-blade)
15. [Página de pesquisa de auditoria (receita)](#página-de-pesquisa-de-auditoria-receita)
16. [Colunas "criado por / alterado por" numa listagem](#colunas-criado-por--alterado-por-numa-listagem)
17. [Modal de histórico pronto (JavaScript, opcional)](#modal-de-histórico-pronto-javascript-opcional)
18. [Restaurar um registro apagado](#restaurar-um-registro-apagado)
19. [Registrar falhas](#registrar-falhas)
20. [Multitenancy (opcional)](#multitenancy-opcional)
21. [Desligar a auditoria](#desligar-a-auditoria)
22. [Configuração completa](#configuração-completa)
23. [Personalização avançada](#personalização-avançada)
24. [Problemas comuns](#problemas-comuns)
25. [Referência rápida](#referência-rápida)

---

## Requisitos

- PHP 8.2 ou mais recente
- Laravel 11.4+, 12 ou 13
- Um banco suportado pelo Laravel (MySQL, MariaDB, PostgreSQL, SQLite ou SQL Server)

---

## Instalação

Só dois comandos são necessários: `composer require` e `php artisan migrate`.
Os dois `vendor:publish` no meio são opcionais. Rode na pasta do seu projeto:

```bash
composer require gsebastiao/laravel-auditable
```

```bash
php artisan vendor:publish --tag=auditable-config   # opcional
```

> **Este comando é opcional — pode pular.** Ele só copia o arquivo de
> configuração do pacote para o seu projeto (`config/auditable.php`), para você
> poder editá-lo. O pacote funciona igual sem ele, com os valores padrão. E se
> um dia quiser mudar alguma opção (nome da tabela, banco, multitenancy...),
> também não precisa dele: basta acrescentar variáveis `AUDITABLE_*` ao `.env`.
> Veja todas as opções e variáveis em [Configuração completa](#configuração-completa).

```bash
php artisan vendor:publish --tag=auditable-migrations   # opcional
```

> **Este comando também é opcional — a maioria dos projetos pode pular.** O
> pacote já traz a migration da tabela de auditoria e o `php artisan migrate`
> (o próximo comando) a executa sozinho, sem encher a pasta
> `database/migrations` do seu projeto. Só publique se quiser **editar** a
> migration antes de rodá-la — o caso típico é quando os ids dos seus models
> (ou dos usuários) são **UUID ou ULID**. Veja
> [Meus ids são UUID](#meus-ids-são-uuid-ou-ulid).

```bash
php artisan migrate
```

O que cada um faz:

1. **`composer require`** instala o pacote. O Laravel o encontra sozinho; não
   precisa registrar nada.
2. **`--tag=auditable-config`** (**opcional**) cria `config/auditable.php`, com
   todas as opções comentadas. Veja a explicação acima e a
   [Configuração completa](#configuração-completa).
3. **`--tag=auditable-migrations`** (**opcional**) copia a migration para
   `database/migrations/`, para você poder editá-la. Veja a explicação acima.
4. **`migrate`** cria a tabela (chamada `audit_table`, por padrão).

> **Vai mudar o nome da tabela** (`AUDITABLE_TABLE`) **ou ligar o
> multitenancy?** Faça-o **antes** do `migrate`.

---

## Primeiros passos

### 1. Adicione o trait `Auditable` ao model

```php
<?php

namespace App\Models;

use Gsebastiao\Auditable\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class Produto extends Model
{
    use Auditable;

    protected $fillable = ['nome', 'preco', 'status_id'];
}
```

Pronto. Não há mais nada a configurar.

### 2. Use o model como sempre usou

```php
$produto = Produto::create(['nome' => 'Café', 'preco' => 20, 'status_id' => 1]);

$produto->update(['preco' => 25]);

$produto->delete();
```

Cada uma dessas três linhas gravou uma linha na tabela de auditoria.

### 3. Veja o histórico

```php
$produto->audits()->latest()->get();   // as auditorias deste produto, das mais novas para as mais antigas
```

Para experimentar rapidamente sem criar tela nenhuma, use o Tinker:

```bash
php artisan tinker
```

```php
App\Models\Produto::auditsFor(1)->get(['event', 'changes']);
```

### O que fica gravado

Depois do exemplo acima, a tabela de auditoria tem estas três linhas
(simplificando):

| event | changes |
| --- | --- |
| `created` | `{"nome": "Café", "preco": 20, "status_id": 1, "id": 1}` |
| `updated` | `{"preco": {"old": 20, "new": 25}}` |
| `deleted` | `{"nome": "Café", "preco": 25, "status_id": 1, "id": 1}` |

Repare:

- No **`created`** e no **`deleted`** fica um retrato do registro (não há
  "antes" e "depois").
- No **`updated`** fica **só o que mudou**, no formato `{"old": ..., "new": ...}`.
- `password`, `remember_token`, `created_at` e `updated_at` do model **não**
  entram em `changes` (a própria auditoria já tem a sua data).

E estas são todas as colunas da tabela:

| Coluna | O que guarda |
| --- | --- |
| `id` | Número da auditoria |
| `batch` | Id da **operação** — as auditorias de uma mesma ação do usuário têm o mesmo `batch` ([saiba mais](#agrupar-uma-operação-batch)) |
| `subject_type` | Qual model foi alterado (ex.: `App\Models\Produto`) |
| `subject_id` | O id do registro alterado |
| `event` | `created`, `updated`, `deleted`, `restored` ou um nome seu (ex.: `aprovado`) |
| `changes` | O que mudou, pronto para ler |
| `debug_info` | Detalhes técnicos: dados de falhas e o retrato usado para restaurar um registro apagado |
| `created_by` | Id do usuário que estava logado (vazio = ação do sistema) |
| `created_at` / `updated_at` | Quando aconteceu |

---

## O que é (e o que não é) auditado automaticamente

A auditoria automática funciona pelos **eventos do Eloquent**. Por isso:

| Isto **é** auditado | Isto **não é** auditado |
| --- | --- |
| `Produto::create([...])` | `Produto::where(...)->update([...])` (update em massa) |
| `$produto->update([...])` | `Produto::where(...)->delete()` (delete em massa) |
| `$produto->save()` | `DB::table('produtos')->insert/update/delete(...)` |
| `$produto->delete()` | `Produto::insert([...])` e `Produto::upsert(...)` |
| `$produto->increment('estoque')` | `$produto->saveQuietly()` e código dentro de `Model::withoutEvents()` |
| `$produto->restore()` (SoftDeletes) | |

Para updates e deletes **em massa**, use as versões auditadas — a sintaxe é a
mesma, e cada registro afetado ganha a sua auditoria (veja
[Update e delete em massa](#update-e-delete-em-massa)):

```php
Produto::where('categoria_id', 3)->auditedUpdate(['ativo' => false]);
Produto::where('categoria_id', 3)->auditedDelete();
```

Para o resto da coluna da direita (`DB::table()`, tabelas sem model),
registre você mesmo com [`Audit::for()`](#auditar-sem-model-auditfor).

---

## Nomes legíveis com `resolveMap`

Sem ajuda, uma chave estrangeira aparece assim no histórico:

```json
{"status_id": {"old": 1, "new": 3}}
```

Ninguém sabe o que é o status 1 ou o 3. Com o `resolveMap`, você diz ao pacote
onde buscar o nome:

```php
<?php

namespace App\Models;

use Gsebastiao\Auditable\Concerns\Auditable;
use Gsebastiao\Auditable\Support\AuditOptions;
use Gsebastiao\Auditable\Support\ResolveMap;
use Illuminate\Database\Eloquent\Model;

class Produto extends Model
{
    use Auditable;

    public function getAuditOptions(): AuditOptions
    {
        return AuditOptions::defaults()
            ->resolveMap([
                'status_id' => ResolveMap::direct('Status', 'status', 'nome'),
            ]);
    }
}
```

Leia a linha do `resolveMap` assim: *"o campo `status_id` aparece com o nome
**Status**; o texto está na tabela **status**, coluna **nome**"*. O resultado:

```json
{"Status": {"old": {"id": 1, "label": "Ativo"}, "new": {"id": 3, "label": "Bloqueado"}}}
```

O id original continua guardado (`id`) ao lado do texto (`label`).

### As três formas

**`ResolveMap::direct()`** — o caso mais comum: o nome está na tabela para
onde a chave aponta.

```php
'status_id'    => ResolveMap::direct('Status', 'status', 'nome'),
'categoria_id' => ResolveMap::direct('Categoria', 'categorias', 'titulo'),

// Parâmetros: (nome no log, tabela, coluna com o texto = 'nome', coluna procurada = 'id', filtros extra = [])
'tipo_id'      => ResolveMap::direct('Tipo', 'tabelas_gerais', 'descricao', scope: ['grupo' => 'tipo_cliente']),
```

**`ResolveMap::join()`** — o nome está noutra tabela, a mais de uma ligação
de distância. Exemplo: o campo guarda `estado_id`, mas você quer mostrar o
**país** do estado:

```php
'estado_id' => ResolveMap::join([
    // 1º item: a tabela onde está o valor gravado no campo
    ['table' => 'estados', 'key' => 'id'],
    // itens seguintes: cada ligação (LEFT JOIN); no último, o que mostrar
    ['table' => 'paises',
     'on' => ['paises.id', '=', 'estados.pais_id'],
     'column' => 'nome',
     'label' => 'País'],
]),
```

**`ResolveMap::alias()`** — não consulta nada, só troca o nome do campo:

```php
'preco_venda' => ResolveMap::alias('Preço de venda'),
```

**Bom saber**

- As consultas são feitas no mesmo banco do model, e cada valor é consultado
  no máximo uma vez por requisição.
- Se a linha procurada não existir (ex.: o status foi apagado), o `label` fica
  `null` e o `id` continua lá.

---

## Escolher o que auditar

Tudo é configurado no método `getAuditOptions()` do model. Os métodos podem ser
encadeados:

```php
public function getAuditOptions(): AuditOptions
{
    return AuditOptions::defaults()
        ->events(['created', 'updated'])          // não registrar exclusões
        ->except(['observacao_interna'])          // nunca mostrar este campo
        ->resolveMap([
            'status_id' => ResolveMap::direct('Status', 'status', 'nome'),
        ]);
}
```

| Método | Padrão | Para que serve |
| --- | --- | --- |
| `events([...])` | `created`, `updated`, `deleted` | Quais eventos automáticos registrar. Acrescente `restored` se o model usa SoftDeletes: o `restore()` fica então numa só linha `restored`. Sem ele, o restauro aparece como `updated` (`deleted_at` → vazio). |
| `only([...])` | todos os campos | Registrar **só** estes campos. |
| `except([...])` | `password`, `remember_token` | Não mostrar estes campos em `changes`. O que você passar **soma-se** ao padrão. |
| `neverSnapshot([...])` | `password`, `remember_token` | Nunca guardar estes campos em lugar nenhum (nem em `changes`, nem no retrato de restauro). Use para segredos. |
| `onlyDirty(false)` | ligado | No `updated`, mostrar também os campos que **não** mudaram. |
| `logEmpty()` | desligado | Gravar a auditoria mesmo quando nada mudou. |
| `logTimestamps()` | desligado | Mostrar `created_at` e `updated_at` do model em `changes`. |
| `fullSnapshotOnDelete(false)` | ligado | Não guardar o retrato que permite [restaurar](#restaurar-um-registro-apagado) um registro apagado. |
| `resolveMap([...])` | vazio | [Nomes legíveis](#nomes-legíveis-com-resolvemap). |

**`except()` ou `neverSnapshot()`?**

- `except()` é para campos que só **poluem** o histórico. Eles continuam no
  retrato de restauro, para que o registro volte inteiro se for apagado.
- `neverSnapshot()` é para **segredos** (tokens, chaves de API): não ficam
  guardados em lugar nenhum da auditoria.

**Campos criptografados** (cast `encrypted`) aparecem como `********` em
`changes`: você vê que o campo mudou, mas o valor nunca vai para a auditoria
em texto claro.

---

## Quem fez a alteração

O id do usuário logado vai sozinho para a coluna `created_by`. Para ler:

```php
$audit->user?->name;   // null quando a ação foi do sistema
```

**Quando não há ninguém logado** (comandos `artisan`, filas, agendamentos,
webhooks), `created_by` fica vazio. Se preferir gravar sempre um usuário (por
exemplo, um usuário chamado "Sistema" com id 1), coloque no `.env`:

```dotenv
AUDITABLE_DEFAULT_CREATED_BY=1
```

**Usa outro guard de login** (ex.: `admin`)? Coloque no `.env`:

```dotenv
AUDITABLE_AUTH_GUARD=admin
```

**Num job da fila** ninguém está logado, mas o pacote resolve isso sozinho: a
job leva consigo o usuário que estava logado quando ela foi despachada, e as
auditorias feitas por ela ficam em nome dele. Vale também para listeners,
notificações e e-mails em fila. (Para desligar: `'propagate_user' => false`
na seção `queue` de `config/auditable.php`.)

Para gravar em nome de outra pessoa, use [`audit()`](#personalizar-a-entrada-automática-audit)
logo depois da gravação:

```php
$pedido = Pedido::create($dados)->audit(createdBy: $vendedor->id);
```

---

## Agrupar uma operação (batch)

Uma única ação do usuário costuma mexer em várias tabelas. Por exemplo,
"finalizar pedido" cria o pedido, cria os itens e baixa o estoque. Cada
gravação tem a sua auditoria, mas é útil saber que **todas fazem parte da
mesma operação**.

É para isso que serve a coluna `batch`. Tudo o que for gravado dentro de
`Audit::transaction()` recebe o mesmo `batch`:

```php
use Gsebastiao\Auditable\Audit;

$pedido = Audit::transaction(function () use ($dados) {
    $pedido = Pedido::create(['cliente_id' => $dados['cliente_id']]);

    foreach ($dados['itens'] as $item) {
        $pedido->itens()->create($item);
        Produto::find($item['produto_id'])->decrement('estoque', $item['quantidade']);
    }

    return $pedido;
});
```

`Audit::transaction()` faz duas coisas ao mesmo tempo:

1. Abre uma **transação de banco**: se qualquer linha lançar um erro, nada é
   gravado — nem os dados, nem as auditorias.
2. Dá o mesmo **batch** a todas as auditorias lá de dentro.

Se você só quer agrupar, sem transação, use `Audit::batch(fn () => ...)`.
E se o seu código já usa `DB::transaction()`, pode combinar os dois, em
qualquer ordem:

```php
DB::transaction(fn () => Audit::batch(function () {
    // ...
}));
```

### Ver a operação inteira

```php
$pedido->operation()->get();    // a ÚLTIMA operação em que o pedido participou (todas as tabelas)
$pedido->operations()->get();   // TODAS as operações do pedido, com todas as linhas de cada uma
$pedido->audits()->get();       // só as linhas do próprio pedido

Pedido::operationFor(42)->get();    // o mesmo, sem carregar o pedido
Pedido::operationsFor(42)->get();

Audit::inBatch($batch)->get();      // tudo de um batch
```

### Jobs em fila continuam a operação

Não é preciso fazer nada: um job despachado **durante** uma operação continua
nessa operação. Tudo o que ela gravar recebe o mesmo `batch` — e, como visto em
[Quem fez a alteração](#quem-fez-a-alteração), o mesmo autor.

```php
Audit::transaction(function () use ($dados) {
    $pedido = Pedido::create($dados);

    EmitirNotaFiscal::dispatch($pedido->id);   // uma job comum, sem código de auditoria
});
```

Um job despachado **fora** de uma operação começa a sua própria, como uma
requisição normal. Funciona com qualquer driver de fila (`database`, `redis`,
`sqs`, `sync`...) e também com listeners, notificações e e-mails em fila. Para
desligar: `'propagate_batch' => false` na seção `queue` da config.

> Controle manual, se precisar: `Audit::useBatch($batch, fn () => ...)` grava
> no batch indicado tudo o que estiver dentro do callback.

---

## Registrar ações próprias: `auditAction()`

Nem tudo o que importa é um `create`, `update` ou `delete`. Para registrar
qualquer outro acontecimento, dê-lhe um nome:

```php
$pedido->auditAction('aprovado');

$pedido->auditAction('email_reenviado', ['para' => $cliente->email]);

$relatorio->auditAction('exportado', ['formato' => 'PDF']);
```

O segundo parâmetro (opcional) vai para `changes`. **`auditAction()` sempre
cria uma linha nova.**

---

## Personalizar a entrada automática: `audit()`

Às vezes você quer que a auditoria automática saia **diferente**: com outro
autor, outro nome de evento ou informações extra. É para isso que existe
`audit()`:

```php
// Outro autor (ex.: num job da fila)
$pedido = Pedido::create($dados)->audit(createdBy: $this->userId);

// Outro nome de evento: em vez de "created", grava "importado"
$produto = Produto::create($linha)->audit(event: 'importado');

// Informação técnica extra
$produto->update($dados);
$produto->audit(debugInfo: ['origem' => 'api-parceiro']);
```

**As regras são simples:**

1. `audit()` **substitui** a entrada automática que **este objeto** acabou de
   gravar. Não cria uma segunda linha.
2. Se o objeto ainda não gravou nada (por exemplo, acabou de ser carregado com
   `find()`), `audit()` cria uma entrada nova.
3. O que você não passar continua automático.
4. É seguro com muitos usuários ao mesmo tempo: `audit()` só mexe na entrada
   do objeto em que foi chamado, nunca na de outra pessoa ou de outro registro.

**`audit()` ou `auditAction()`?**

| | `audit()` | `auditAction()` |
| --- | --- | --- |
| Cria uma linha nova? | Não: ajusta a entrada automática que o objeto acabou de gravar | Sempre |
| Para quê | Mudar autor, data, dados ou nome do evento da entrada automática | Registrar um acontecimento a mais |

**Todos os parâmetros de `audit()`** (todos opcionais, use por nome):

| Parâmetro | O que muda |
| --- | --- |
| `event` | O nome do evento gravado |
| `changes` | O conteúdo de `changes` (array) |
| `debugInfo` | O conteúdo de `debug_info` (array) |
| `createdBy` | O autor |
| `createdAt` / `updatedAt` | As datas (`'Y-m-d H:i:s'`) |
| `batch` | A operação |
| `subjectType` / `subjectId` | Grava a entrada em nome de **outro** registro |
| `tenantId` | O tenant (só com [multitenancy](#multitenancy-opcional) ligado) |

---

## Auditar sem model: `Audit::for()`

Use quando a gravação não passa pelo Eloquent: `DB::table()`, updates em
massa ou tabelas que não têm model.

```php
use Gsebastiao\Auditable\Audit;
use Gsebastiao\Auditable\Support\ResolveMap;
use Illuminate\Support\Facades\DB;

DB::table('pessoas')->where('id', $id)->update(['estado_id' => 7]);

Audit::for('pessoa', $id, 'updated')
    ->changes(['estado_id' => ['old' => 3, 'new' => 7]])
    ->resolveMap(['estado_id' => ResolveMap::direct('Estado', 'estados')])
    ->save();
```

- Os três parâmetros são: **o tipo** (um nome livre, como `'pessoa'`, ou a
  classe de um model), **o id** e **o evento**.
- **Nada é gravado antes do `->save()`.**
- `changes()` aceita o formato de alteração (`['campo' => ['old' => ..., 'new' => ...]]`)
  ou um retrato (`['campo' => valor]`). O pacote reconhece qual é.
- Se o tipo for a classe de um model (`Produto::class`), as opções do
  `getAuditOptions()` dele (como o `resolveMap`) são aproveitadas.
- Também pode encadear: `except()`, `only()`, `events()`, `onlyDirty()`,
  `logEmpty()`, `resolveMap()`, `debugInfo()`, `batch()`, `createdBy()`,
  `tenantId()`, `createdAt()` e `updatedAt()`.

> Para updates e deletes em massa num **model**, prefira `auditedUpdate()` e
> `auditedDelete()` (veja [Update e delete em massa](#update-e-delete-em-massa)):
> calculam o "antes" e o "depois" sozinhos.

**`Audit::for()` ou `DB::table()->audit()`?** Use `Audit::for()`. A forma
`DB::table('x')->audit(...)` existe por compatibilidade e faz o mesmo — a
tabela do `DB::table()` não é usada para nada. A única diferença é a conexão
onde o `resolveMap` procura os nomes: a do `DB::connection(...)` usado. Isso
só importa numa tabela sem model que esteja numa conexão que não é a padrão:

```php
DB::connection('legado')->table('pessoas')->audit('pessoa', $id, 'updated')
    ->changes(['estado_id' => ['old' => 3, 'new' => 7]])
    ->resolveMap(['estado_id' => ResolveMap::direct('Estado', 'estados')])
    ->save();
```

---

## Update e delete em massa

Um `update()` ou `delete()` em massa não gera auditoria (o Laravel não
dispara os eventos dos models). Use as versões auditadas — a sintaxe é a
mesma:

```php
Produto::where('categoria_id', 3)->auditedUpdate(['ativo' => false]);

Produto::where('created_at', '<', now()->subYears(5))->auditedDelete();
```

Cada registro afetado ganha a sua auditoria, com as mesmas regras de
`getAuditOptions()` (`resolveMap`, `except`, campos mascarados...), e todas
ficam na mesma operação. Os dois métodos devolvem quantos registros foram
afetados, como o `update()` e o `delete()` normais.

**Bom saber**

- O model precisa do trait `Auditable`.
- Trabalha em lotes de 500 registros. Em cada lote: lê os valores antigos
  (travando as linhas), faz um único update e grava as auditorias de uma vez.
  Se um lote falhar, ele volta atrás inteiro — dados e auditorias. Para outro
  tamanho: `->auditedUpdate([...], chunkSize: 1000)`.
- Aceita valores calculados pelo banco:
  `->auditedUpdate(['preco' => DB::raw('preco * 1.1')])`.
- Registros que já tinham o valor novo não ganham auditoria.
- Em models com SoftDeletes, `auditedDelete()` manda para a lixeira (como o
  `delete()`). Em models sem SoftDeletes, o retrato de restauro é gravado e
  `restore()` funciona como num delete normal.
- Tal como o `update()` em massa normal, **não dispara os eventos do
  Eloquent**: observers e listeners de `updated`/`deleted` não correm.
- É mais lento que o `update()` cru (lê e audita cada registro), mas muito
  mais rápido do que carregar e salvar os models um a um.
- Não aceita `limit()`/`offset()`: filtre com `where()`.

---

## Consultar o histórico

```php
use Gsebastiao\Auditable\Audit;

// De um registro
$produto->audits()->latest()->get();
Produto::auditsFor(42)->get();                      // sem carregar o produto (já vem do mais novo para o mais antigo)
Produto::auditsFor(42)->action('updated')->get();

// Da aplicação inteira
Audit::byUser(auth()->id())->latest()->limit(20)->get();
Audit::action(['aprovado', 'reprovado'])->get();
Audit::failures()->whereDate('created_at', today())->get();
Audit::forRecord(Produto::class, 42)->get();
```

Filtros disponíveis (todos encadeáveis, e ainda funcionam `where()`,
`latest()`, `paginate()` etc.):

| Filtro | O que traz |
| --- | --- |
| `action('updated')` ou `action([...])` | Pelo nome do evento |
| `byUser($id)` | Feitas por um usuário |
| `inBatch($batch)` | De uma operação |
| `failures()` | Só as [falhas](#registrar-falhas) |
| `forRecord(Produto::class, $id)` | De um registro |
| `filter([...])` | Vários de uma vez — para telas de pesquisa ([veja a receita](#página-de-pesquisa-de-auditoria-receita)) |

Em cada auditoria:

```php
$audit->user;            // o usuário (ou null)
$audit->subject;         // o registro auditado (null se foi apagado) — só em auditorias de models
$audit->changes;         // array com o que mudou
$audit->changeLines();   // o mesmo, em texto: ['Preco: 20 → 25', 'Status: Ativo → Bloqueado']
$audit->isFailure();     // foi uma falha?
```

> `$audit->subject` só funciona quando `subject_type` é um model. Numa auditoria
> gravada com um nome livre (`Audit::for('pessoa', ...)`), use `subject_type` e
> `subject_id` diretamente.

> `Audit::` (de `Gsebastiao\Auditable\Audit`) aceita qualquer consulta do model
> de auditoria. Se preferir usar o model diretamente, ele é
> `Gsebastiao\Auditable\Models\Audit`.

**Evite consultas repetidas**: ao listar auditorias com o nome do usuário,
carregue os usuários de uma vez com `with('user')`.

---

## Mostrar o histórico numa tela Blade

No controller:

```php
public function show(Produto $produto)
{
    $historico = $produto->audits()->with('user')->latest()->get();

    return view('produtos.show', compact('produto', 'historico'));
}
```

Na view:

```blade
<table>
    <thead>
        <tr><th>Quando</th><th>Quem</th><th>O quê</th><th>Alterações</th></tr>
    </thead>
    <tbody>
        @foreach ($historico as $audit)
            <tr>
                <td>{{ $audit->created_at->format('d/m/Y H:i') }}</td>
                <td>{{ $audit->user?->name ?? 'Sistema' }}</td>
                <td>{{ $audit->event }}</td>
                <td>
                    @foreach ($audit->changeLines() as $linha)
                        {{ $linha }}<br>
                    @endforeach
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
```

Quer um nome mais bonito para um campo (ex.: "Preço" em vez de "Preco")? Use
`ResolveMap::alias('Preço')` no `resolveMap`.

---

## Página de pesquisa de auditoria (receita)

Uma página para consultar **todas** as auditorias do sistema, com filtros. O
pacote não traz esta tela pronta — cada sistema tem o seu visual e as suas
permissões —, mas com o `filter()` ela fica curta. Copie e adapte.

**1. A permissão**, no `boot()` do `AppServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('ver-auditoria', fn ($user) => $user->is_admin);
```

> A auditoria guarda dados de todos os usuários e detalhes técnicos das
> falhas: esta página é só para administradores.

**2. A rota**, em `routes/web.php`:

```php
use Gsebastiao\Auditable\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/auditoria', function (Request $request) {
    $audits = Audit::filter($request->all())
        ->with('user')
        ->latest()
        ->orderByDesc('id')
        ->paginate(30)
        ->withQueryString();

    return view('auditoria.index', ['audits' => $audits]);
})->middleware('auth')->can('ver-auditoria');
```

**3. A view**, em `resources/views/auditoria/index.blade.php`:

```blade
<form method="GET">
    <select name="model">
        <option value="">Todos os registros</option>
        <option value="{{ App\Models\Produto::class }}" @selected(request('model') === App\Models\Produto::class)>Produtos</option>
        <option value="{{ App\Models\Pedido::class }}" @selected(request('model') === App\Models\Pedido::class)>Pedidos</option>
    </select>
    <input name="id" value="{{ request('id') }}" placeholder="Id do registro">
    <input name="event" value="{{ request('event') }}" placeholder="Evento (ex.: updated)">
    <input name="user" value="{{ request('user') }}" placeholder="Id do usuário">
    <input type="date" name="from" value="{{ request('from') }}">
    <input type="date" name="to" value="{{ request('to') }}">
    <label><input type="checkbox" name="failures" value="1" @checked(request('failures'))> Só falhas</label>
    <button>Filtrar</button>
</form>

<table>
    <thead>
        <tr><th>Quando</th><th>Quem</th><th>Registro</th><th>O quê</th><th>Alterações</th></tr>
    </thead>
    <tbody>
        @forelse ($audits as $audit)
            <tr>
                <td>{{ $audit->created_at->format('d/m/Y H:i') }}</td>
                <td>{{ $audit->user?->name ?? 'Sistema' }}</td>
                <td>{{ class_basename($audit->subject_type) }} #{{ $audit->subject_id }}</td>
                <td>{{ $audit->event }}</td>
                <td>
                    @foreach ($audit->changeLines() as $linha)
                        {{ $linha }}<br>
                    @endforeach
                </td>
            </tr>
        @empty
            <tr><td colspan="5">Nenhuma auditoria encontrada.</td></tr>
        @endforelse
    </tbody>
</table>

{{ $audits->links() }}
```

As chaves que o `filter()` entende:

| Chave | Filtra por |
| --- | --- |
| `user` | Id de quem fez |
| `event` | Evento (um texto, ou uma lista: `event[]=created&event[]=updated`) |
| `model` | Classe do model, ou o nome livre usado no `Audit::for()` |
| `id` | Id do registro (use junto com `model`) |
| `batch` | Id da operação |
| `from` / `to` | Período (datas inclusive) |
| `failures` | `1` = só as falhas |

Campos vazios e chaves desconhecidas (como `page` e `_token`) são ignorados, e
datas inválidas também — por isso dá para passar `$request->all()` direto.

---

## Colunas "criado por / alterado por" numa listagem

Numa listagem com muitos registros, buscar a auditoria de cada linha seria
lento. O `AuditColumnJoiner` acrescenta essas colunas à **mesma** consulta da
listagem:

```php
use App\Models\Produto;
use Gsebastiao\Auditable\Support\AuditColumnJoiner;

$query = Produto::query()->where('ativo', true);

AuditColumnJoiner::apply($query, Produto::class);

$produtos = $query->paginate(20);
```

Cada produto ganha quatro colunas:

```blade
@foreach ($produtos as $produto)
    {{ $produto->nome }}
    — criado por {{ $produto->audit_created_by }} em {{ $produto->audit_created_at }}
    — alterado por {{ $produto->audit_updated_by ?? '-' }} em {{ $produto->audit_updated_at ?? '-' }}
@endforeach
```

- `audit_created_*` vem da **primeira** auditoria `created`; `audit_updated_*`
  vem da **mais recente** `updated`. Registros nunca alterados ficam com `null`.
- O nome do usuário é encurtado para "Primeiro Último" ("Maria da Silva
  Pereira" → "Maria Pereira") e a data sai como `10/07/2026 14:30:00`.
- Funciona também com `DB::table('produtos')`.

Opções (todas por nome):

```php
AuditColumnJoiner::apply(
    $query,
    Produto::class,
    actions: ['created', 'updated', 'aprovado'],   // cada ação vira audit_<acao>_by e audit_<acao>_at
    userColumn: 'email',                           // coluna da tabela de usuários a mostrar
    dateFormat: 'DD/MM/YYYY',                      // ou null para a data crua
);
```

> **Requisito:** a tabela de auditoria precisa estar no mesmo banco da
> listagem. Não funciona se `AUDITABLE_CONNECTION` apontar para outro banco.

---

## Modal de histórico pronto (JavaScript, opcional)

> Esta seção é **opcional**. Tudo o que foi explicado até aqui funciona sem ela.

O pacote traz um arquivo JavaScript que abre um **modal** (janela por cima da
página) com o histórico de um registro. Não depende de jQuery, Bootstrap nem
de nenhuma outra biblioteca, e não precisa de HTML nenhum na página.

Há dois modais:

| Modal | Para quem | O que mostra |
| --- | --- | --- |
| `GaAudit.full` | Administradores/auditores | Histórico completo, agrupado por operação, com busca, filtro e paginação |
| `GaAudit.simple` | Qualquer usuário | Lista simples: o quê, quem e quando |

### Passo 1 — Copie o arquivo para `public/`

```bash
php artisan auditable:publish-js
```

O arquivo vai para `public/assets/js/audit-table.init.js`. Para outra pasta,
use `--path=js/auditoria`. Depois de atualizar o pacote, rode de novo com
`--force` para pegar a versão nova.

### Passo 2 — Inclua no layout

No layout Blade (ex.: `resources/views/layouts/app.blade.php`):

```blade
<head>
    {{-- ... --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body>
    {{-- ... --}}
    <script src="{{ asset('assets/js/audit-table.init.js') }}"></script>
</body>
```

A tag `csrf-token` é **obrigatória**: sem ela o Laravel recusa o pedido do
modal (erro 419).

### Passo 3 — Crie as rotas

Em `routes/web.php`. O `AuditWidget` já devolve o JSON no formato que o modal
espera:

```php
use App\Models\Produto;
use Gsebastiao\Auditable\Support\AuditWidget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Para o GaAudit.full
    Route::post('/auditoria/produtos/completo', function (Request $request) {
        $id = $request->integer('id');

        return AuditWidget::full(Produto::operationsFor($id), recordId: $id);
    });

    // Para o GaAudit.simple
    Route::post('/auditoria/produtos/resumo', function (Request $request) {
        return AuditWidget::simple(Produto::auditsFor($request->integer('id')));
    });
});
```

> **Quem pode ver o histórico completo?** Proteja a rota com uma permissão.
> Por exemplo, acrescente `->can('ver-auditoria')` depois do `Route::post(...)`
> e defina a regra no `boot()` do `AppServiceProvider`:
>
> ```php
> use Illuminate\Support\Facades\Gate;
>
> Gate::define('ver-auditoria', fn ($user) => $user->is_admin);
> ```

### Passo 4 — Abra o modal

Sem escrever JavaScript, com atributos `data-*`:

```blade
<button type="button"
        data-ga-audit="full"
        data-ga-audit-id="{{ $produto->id }}"
        data-ga-audit-url="/auditoria/produtos/completo"
        data-ga-audit-title="Histórico do produto">
    Ver histórico
</button>
```

Troque `full` por `simple` (e a URL) para o modal simples. O título é
opcional. Se preferir chamar pelo seu próprio JavaScript:

```js
GaAudit.full.open({
    endpoint: '/auditoria/produtos/completo',
    id: 42,
    title: 'Histórico do produto #42', // opcional
});
```

### Personalizar o visual

As cores usam variáveis CSS. Sobrescreva no CSS do seu site:

```css
:root {
    --ga-audit-accent: #7c3aed;   /* botões, foco e paginação */
    --ga-audit-radius: 4px;       /* cantos do modal */
}
```

Outras variáveis: `--ga-audit-bg`, `--ga-audit-text`, `--ga-audit-border`,
`--ga-audit-success`, `--ga-audit-danger` e `--ga-audit-font`.

Para trocar os ícones, defina-os depois de incluir o script:

```html
<script>
    GaAudit.icons.close = '<svg width="14" height="14">...</svg>';
</script>
```

Os textos da interface estão em português. Para outro idioma, edite o arquivo
publicado em `public/` (é JavaScript comum, sem compilação).

---

## Restaurar um registro apagado

Quando um registro é apagado, a auditoria `deleted` guarda um retrato completo
dele. Com esse retrato, o registro pode ser recriado:

```php
$audit = Produto::auditsFor($id)->action('deleted')->first();

$produto = $audit->restore();
```

O registro volta **exatamente** como estava no banco: o mesmo id, as mesmas
datas e os mesmos valores (inclusive JSON e campos criptografados).

Variações:

```php
$audit->restore(withId: false);   // recria com um id novo

// Campos de neverSnapshot() (como password) não foram guardados.
// Se a coluna for obrigatória, informe um valor:
$audit->restore(attributes: ['password' => Hash::make(Str::random(16))]);

$audit->isRestorable();           // esta auditoria pode ser restaurada?
```

**Bom saber**

- Só auditorias `deleted` podem ser restauradas.
- Se já existir um registro com o mesmo id, `restore()` lança um erro que
  explica o que fazer.
- Em models com **SoftDeletes**, o registro apagado continua na tabela (na
  "lixeira"): use o `$produto->restore()` do próprio Laravel. O restauro pela
  auditoria serve para exclusões definitivas (`forceDelete()` ou models sem
  SoftDeletes).
- O registro recriado gera uma auditoria `created` nova, então o histórico
  mostra "apagado" e depois "criado".

---

## Registrar falhas

Quando algo dá errado, você pode registrar a falha. O usuário vê uma mensagem
simples; os detalhes técnicos ficam guardados só para o desenvolvedor.

```php
try {
    $gateway->cobrar($fatura);
} catch (\Throwable $e) {
    $fatura->auditFailure('cobranca', $e, ['gateway' => 'mpesa'], 'Não foi possível concluir o pagamento.');

    throw $e;
}
```

Parâmetros: o nome da ação, a exceção, dados extra para investigar
(opcional) e a mensagem para o usuário (opcional; o padrão é
"A operação falhou.").

O que é gravado:

- **`changes`** — só a mensagem: `{"message": "Não foi possível concluir o pagamento."}`.
  Pode ser mostrada ao usuário.
- **`debug_info`** — para o desenvolvedor: classe e mensagem da exceção,
  arquivo e linha, as primeiras linhas do *stack trace*, os dados extra, a rota
  e o IP da requisição, e o nome do banco. Numa falha de SQL, a query é
  guardada **com `?` no lugar dos valores**. Senhas e endereço do banco nunca
  são gravados.

Para consultar:

```php
Audit::failures()->latest()->get();
$audit->isFailure();
```

> **Cuidado com transações.** Se você registrar a falha **dentro** de um
> `Audit::transaction()` e relançar o erro, a própria auditoria da falha é
> desfeita junto com a transação. Registre-a **fora**:
>
> ```php
> try {
>     Audit::transaction(function () use ($fatura) {
>         // ...
>     });
> } catch (\Throwable $e) {
>     $fatura->auditFailure('cobranca', $e);   // fora da transação: fica gravada
>
>     throw $e;
> }
> ```

---

## Multitenancy (opcional)

*Multitenancy* é quando um único sistema atende vários clientes (empresas,
escolas, lojas), e cada um só pode ver os seus dados. Se o seu sistema não é
assim, pule esta seção.

### Caso A — cada cliente tem o seu próprio banco

Não é preciso configurar nada no pacote. A auditoria de cada cliente vai para
o banco dele, porque usa a mesma conexão da aplicação. É o caso típico de
pacotes como `stancl/tenancy` e `spatie/laravel-multitenancy` no modo
multi-banco.

Só não se esqueça de que a **tabela** de auditoria precisa existir em cada
banco de cliente. Para isso, publique a migration
(`php artisan vendor:publish --tag=auditable-migrations`) e coloque a cópia
junto das migrations dos tenants (no `stancl/tenancy`, a pasta
`database/migrations/tenant`). O `php artisan migrate` normal continua a criar
também a tabela no banco central, com a migration do pacote.

### Caso B — um banco só, com uma coluna `tenant_id` em cada tabela

**1. Ligue o modo tenant *antes* de rodar a migration** (é ele que cria a
coluna `tenant_id` na tabela de auditoria):

```dotenv
AUDITABLE_TENANT_ENABLED=true
```

Se a tabela de auditoria já existe, crie uma migration para acrescentar a
coluna:

```php
Schema::table('audit_table', function (Blueprint $table) {
    $table->unsignedBigInteger('tenant_id')->nullable()->index();
});
```

**2. Diga ao pacote quem é o tenant atual**, no `boot()` do
`app/Providers/AppServiceProvider.php`:

```php
use Gsebastiao\Auditable\Audit;

public function boot(): void
{
    Audit::resolveTenantUsing(fn () => auth()->user()?->empresa_id);
}
```

O pacote só **pergunta** qual é o tenant; quem decide é a sua aplicação.

**3. Nos seus models com `tenant_id`, acrescente `BelongsToTenant`:**

```php
use Gsebastiao\Auditable\Concerns\Auditable;
use Gsebastiao\Auditable\Concerns\BelongsToTenant;

class Produto extends Model
{
    use Auditable, BelongsToTenant;
}
```

Com isso, `Produto::all()` só traz os produtos do tenant atual e
`Produto::create()` preenche o `tenant_id` sozinho. As auditorias também
passam a ser gravadas e filtradas por tenant.

**Ver todos os tenants** (relatórios internos, tarefas de manutenção):

```php
Produto::withoutTenantScope()->get();
Audit::withoutTenantScope()->get();
```

**Bom saber**

- **Sem tenant identificado** (ex.: um comando no terminal), as consultas
  **não são filtradas**. Para que não mostrem nada nesse caso, ligue
  `'strict' => true` em `config/auditable.php` (seção `tenant`).
- **A coluna tem outro nome?** Mude `'column'` em `config/auditable.php`. Se
  só um model for diferente, defina nele
  `public function tenantColumn(): string { return 'empresa_id'; }`.
- **Jobs em fila:** no worker ninguém está logado, então um resolver baseado em
  `auth()` não sabe o tenant — e, sem tenant, as consultas não são filtradas.
  Para a job ficar no tenant de quem a despachou, ligue
  `'propagate_tenant' => true` na seção `queue` de `config/auditable.php`.
- **Não coloque `BelongsToTenant` no model `User`** se o seu resolver usa
  `auth()->user()`: para descobrir o tenant, o Laravel precisaria carregar o
  usuário, que por sua vez precisaria do tenant — um ciclo sem fim.
- Prefere configurar tudo em `config/auditable.php`? Crie uma classe com o
  método `__invoke()` e indique o nome dela em `'resolver'`:

  ```php
  // app/Support/TenantAtual.php
  namespace App\Support;

  class TenantAtual
  {
      public function __invoke(): ?int
      {
          return auth()->user()?->empresa_id;
      }
  }
  ```

  ```php
  // config/auditable.php
  'resolver' => App\Support\TenantAtual::class,
  ```

  Não coloque uma função anônima (`fn () => ...`) direto na config: isso
  impede o `php artisan config:cache`.

---

## Desligar a auditoria

```php
use Gsebastiao\Auditable\Audit;

// Só um trecho de código (seeders, importações, correções em massa)
Audit::withoutAuditing(function () {
    Produto::factory()->count(500)->create();
});
```

```dotenv
# No sistema inteiro — por exemplo, no .env.testing
AUDITABLE_ENABLED=false
```

Para um model específico, deixe a lista de eventos vazia:
`AuditOptions::defaults()->events([])`.

---

## Configuração completa

Todas as opções de `config/auditable.php`. Os padrões funcionam; mude só o
que precisar.

### Configurar sem publicar o config

**Não é preciso publicar `config/auditable.php`.** O pacote carrega a sua
configuração sozinho, e cada opção pode ser mudada por uma variável no `.env`.
Só publique o config se quiser editar o arquivo diretamente.

| Variável do `.env` | Opção | Padrão | O que faz |
| --- | --- | --- | --- |
| `AUDITABLE_ENABLED` | `enabled` | `true` | Liga/desliga a auditoria no sistema inteiro |
| `AUDITABLE_TABLE` | `table` | `audit_table` | Nome da tabela de auditoria (mude antes da migration) |
| `AUDITABLE_CONNECTION` | `connection` | vazio | Conexão de banco da auditoria; vazio = a da aplicação |
| `AUDITABLE_MODEL` | `model` | `Gsebastiao\Auditable\Models\Audit` | Model da tabela de auditoria ([estender](#o-seu-próprio-model-de-auditoria)) |
| `AUDITABLE_AUTH_GUARD` | `auth_guard` | vazio | Guard de onde vem o usuário logado; vazio = o padrão |
| `AUDITABLE_USER_MODEL` | `user_model` | vazio | Model dos usuários para `$audit->user`; vazio = o do `config/auth.php` |
| `AUDITABLE_USER_TABLE` | `users_table` | `users` | Tabela de usuários usada pelo `AuditColumnJoiner` |
| `AUDITABLE_DEFAULT_CREATED_BY` | `default_created_by` | vazio | Usuário gravado quando ninguém está logado |
| `AUDITABLE_COLUMN_PREFIX` | `column_prefix` | `audit_` | Prefixo das colunas do `AuditColumnJoiner` |
| `AUDITABLE_TENANT_ENABLED` | `tenant.enabled` | `false` | Liga o [multitenancy por coluna](#multitenancy-opcional) |
| `AUDITABLE_TENANT_COLUMN` | `tenant.column` | `tenant_id` | Nome da coluna de tenant |
| `AUDITABLE_TENANT_RESOLVER` | `tenant.resolver` | vazio | Classe invocável que devolve o tenant atual |
| `AUDITABLE_TENANT_STRICT` | `tenant.strict` | `false` | Sem tenant identificado: `false` vê tudo, `true` não vê nada |
| `AUDITABLE_PROPAGATE_BATCH` | `queue.propagate_batch` | `true` | Jobs despachadas durante uma operação continuam-na |
| `AUDITABLE_PROPAGATE_USER` | `queue.propagate_user` | `true` | As auditorias de uma job ficam em nome de quem a despachou |
| `AUDITABLE_PROPAGATE_TENANT` | `queue.propagate_tenant` | `false` | A job usa o tenant de quem a despachou, se o resolver não identificar nenhum |
| `AUDITABLE_PUBLISH_PATH` | `js.publish_path` | `assets/js` | Pasta (dentro de `public/`) do widget JS |
| `AUDITABLE_DEBUG_DB` | `debug.include_database` | `true` | Grava conexão, driver e nome do banco nas falhas |

Exemplo de `.env` — coloque **só as linhas que quiser mudar** (aqui, os
valores padrão, mais um exemplo de conexão própria):

```dotenv
AUDITABLE_ENABLED=true
AUDITABLE_TABLE=audit_table
AUDITABLE_USER_TABLE=users
AUDITABLE_COLUMN_PREFIX=audit_
AUDITABLE_TENANT_ENABLED=false
AUDITABLE_TENANT_COLUMN=tenant_id
AUDITABLE_TENANT_STRICT=false
AUDITABLE_PROPAGATE_BATCH=true
AUDITABLE_PROPAGATE_USER=true
AUDITABLE_PROPAGATE_TENANT=false
AUDITABLE_PUBLISH_PATH=assets/js
AUDITABLE_DEBUG_DB=true

# Sem valor padrão (só descomente se precisar):
# AUDITABLE_CONNECTION=auditoria
# AUDITABLE_AUTH_GUARD=admin
# AUDITABLE_USER_MODEL=App\Models\Usuario
# AUDITABLE_DEFAULT_CREATED_BY=1
# AUDITABLE_TENANT_RESOLVER=App\Support\TenantAtual
# AUDITABLE_MODEL=App\Models\Auditoria
```

> **Não deixe uma variável com o valor em branco** (`AUDITABLE_CONNECTION=`):
> o Laravel a entende como texto vazio, não como "sem valor". Para voltar ao
> padrão, apague a linha. Com `php artisan config:cache`, rode-o de novo depois
> de mudar o `.env`.

**Guardar a auditoria noutro banco.** Crie a conexão em `config/database.php`
e indique-a no `.env` **antes** de rodar a migration:

```dotenv
AUDITABLE_CONNECTION=auditoria
```

Nesse caso, `Audit::transaction()` desfaz os dois bancos juntos em caso de
erro, mas o `AuditColumnJoiner` deixa de funcionar (ele precisa do mesmo
banco).

---

## Personalização avançada

Nada disto é necessário para o uso normal.

### O seu próprio model de auditoria

Para acrescentar relações ou métodos às auditorias:

```php
namespace App\Models;

use Gsebastiao\Auditable\Models\Audit as BaseAudit;

class Auditoria extends BaseAudit
{
    // opcional: outra tabela (senão usa config('auditable.table'))
    // protected $table = 'historico';
}
```

```php
// config/auditable.php
'model' => App\Models\Auditoria::class,
```

> Dentro da sua classe, leia a coluna com `$this->getAttribute('changes')`:
> `$this->changes` é uma propriedade interna do Eloquent com o mesmo nome.

### Gravar em outro lugar (fila, serviço externo)

Implemente `Gsebastiao\Auditable\Contracts\AuditRepository` (métodos
`persist`, `replace` e `forget` — a documentação de cada um está na
interface) e registre no `AppServiceProvider`:

```php
use Gsebastiao\Auditable\Contracts\AuditRepository;

$this->app->bind(AuditRepository::class, \App\Auditoria\GravarNaFila::class);
```

Se o destino não tiver ids, `persist()` pode devolver `null`; nesse caso,
`audit()` passa a criar sempre uma linha nova.

Para que `auditedUpdate()`/`auditedDelete()` gravem em bloco no seu destino,
implemente `Gsebastiao\Auditable\Contracts\BulkAuditRepository` (que acrescenta
o método `persistMany`). Sem ela, o pacote grava linha a linha com `persist()`.

### Outras peças substituíveis

| Interface | Responsável por | Padrão |
| --- | --- | --- |
| `Contracts\ContextResolver` | Descobrir o usuário e o tenant atuais | Lê o `auth()` e o resolver de tenant |
| `Contracts\BatchIdGenerator` | Gerar o id das operações | ULID (26 caracteres) |

Troque do mesmo jeito: `$this->app->bind(Interface::class, SuaClasse::class);`.

---

## Problemas comuns

### Não foi gravada nenhuma auditoria

Confira, nesta ordem:

1. O model tem `use Auditable;`?
2. A gravação passa pelo Eloquent? Updates e deletes **em massa** (use
   `auditedUpdate()`/`auditedDelete()`), `DB::table()`, `insert()`, `upsert()`
   e `saveQuietly()` não geram auditoria (veja
   [o que é auditado](#o-que-é-e-o-que-não-é-auditado-automaticamente)).
3. Algum valor mudou de verdade? Um `update()` com os mesmos valores não grava
   nada — o Laravel nem chega a salvar. E se só mudaram campos ignorados
   (`updated_at` ou os de `except()`), não sobra nada para registrar: use
   `logEmpty()` para gravar mesmo assim.
4. O evento está em `events()`? E a auditoria não está desligada
   (`AUDITABLE_ENABLED`, `Audit::withoutAuditing()`)?
5. A gravação aconteceu dentro de uma transação que foi desfeita?
6. Usa `php artisan config:cache`? Depois de mudar o `.env`, rode-o de novo.

### `created_by` está vazio

Ninguém estava logado quando a gravação aconteceu — por exemplo, num comando
`artisan` ou numa tarefa agendada. (Jobs despachadas por um usuário logado
já herdam o autor sozinhas.) Defina `AUDITABLE_DEFAULT_CREATED_BY` ou use
`audit(createdBy: ...)`. Veja [Quem fez a alteração](#quem-fez-a-alteração).

### O modal mostra "Não foi possível carregar"

- Erro **419**: falta a tag `<meta name="csrf-token">` no layout.
- Erro **404**: a URL em `data-ga-audit-url` não bate com a rota (confira com
  `php artisan route:list`).
- Erro **401/403**: a rota exige login ou permissão.
- Erro **500**: veja `storage/logs/laravel.log`.

Na aba **Rede** (Network) das ferramentas do navegador (F12) aparece o código
de erro do pedido.

### Meus ids são UUID ou ULID

Antes de rodar `php artisan migrate`, publique a migration
(`php artisan vendor:publish --tag=auditable-migrations`), abra-a e ajuste as
três linhas do topo:

```php
private string $subjectKeyType = 'uuid';   // ids dos seus models
private string $userKeyType = 'integer';   // ids dos usuários
private string $tenantKeyType = 'integer'; // ids dos tenants (se usar)
```

Valores aceitos: `'integer'`, `'uuid'`, `'ulid'` e `'string'`. Se a tabela já
existe, faça uma migration que altere as colunas `subject_id`, `created_by`
(e `tenant_id`).

### Aparece `********` no lugar de um valor

O campo usa o cast `encrypted`. É de propósito: o valor nunca vai para a
auditoria em texto claro.

### O `label` do `resolveMap` vem `null`

Não foi encontrada a linha procurada: confira o nome da tabela e da coluna no
`ResolveMap::direct()` e se o registro ainda existe.

### Erro ao usar `php artisan config:cache`

Há uma função anônima (`fn () => ...`) em `config/auditable.php`. Troque por
uma classe ou use `Audit::resolveTenantUsing()` (veja [Multitenancy](#multitenancy-opcional)).

---

## Referência rápida

Um resumo de tudo, para consulta (não é um arquivo para copiar inteiro):

```php
use Gsebastiao\Auditable\Audit;
use Gsebastiao\Auditable\Concerns\Auditable;
use Gsebastiao\Auditable\Concerns\BelongsToTenant;
use Gsebastiao\Auditable\Support\AuditColumnJoiner;
use Gsebastiao\Auditable\Support\AuditOptions;
use Gsebastiao\Auditable\Support\AuditWidget;
use Gsebastiao\Auditable\Support\ResolveMap;

// ── No model ──────────────────────────────────────────────────────────
use Auditable;                                  // auditoria automática
use BelongsToTenant;                            // (opcional) filtro por tenant

public function getAuditOptions(): AuditOptions
{
    return AuditOptions::defaults()
        ->events(['created', 'updated', 'deleted'])
        ->only([...])  ->except([...])  ->neverSnapshot([...])
        ->onlyDirty()  ->logEmpty()  ->logTimestamps()  ->fullSnapshotOnDelete()
        ->resolveMap([
            'status_id' => ResolveMap::direct('Status', 'status', 'nome'),
            'estado_id' => ResolveMap::join([...]),
            'preco'     => ResolveMap::alias('Preço'),
        ]);
}

// ── Registrar ─────────────────────────────────────────────────────────
$model->auditAction('aprovado', ['motivo' => '...']);           // linha nova
$model->auditFailure('cobranca', $e, ['extra' => 1], 'Mensagem'); // falha
$model->audit(event: 'importado', createdBy: 5);                 // ajusta a entrada automática
Audit::for('pessoa', $id, 'updated')->changes([...])->save();   // sem model
Model::where(...)->auditedUpdate(['campo' => 'valor']);          // update em massa, auditado
Model::where(...)->auditedDelete();                              // delete em massa, auditado

// ── Operações ─────────────────────────────────────────────────────────
Audit::transaction(fn () => ...);      // transação + mesmo batch
Audit::batch(fn () => ...);            // só o mesmo batch
Audit::currentBatch();                 // batch atual (ou null)
Audit::useBatch($batch, fn () => ...); // usar um batch específico (manual)
Audit::withoutAuditing(fn () => ...);  // não auditar este trecho

// ── Consultar ─────────────────────────────────────────────────────────
$model->audits();         Model::auditsFor($id);      // linhas do registro
$model->operation();      Model::operationFor($id);   // última operação
$model->operations();     Model::operationsFor($id);  // todas as operações
$model->batchOf();                                    // id da última operação

Audit::action('updated')  Audit::byUser($id)  Audit::inBatch($b)
Audit::failures()         Audit::forRecord(Model::class, $id)  Audit::withoutTenantScope()
Audit::filter($request->all())                        // vários filtros (telas de pesquisa)

$audit->user   $audit->subject   $audit->changes   $audit->changeLines()
$audit->isFailure()   $audit->isRestorable()   $audit->restore()

// ── Telas ─────────────────────────────────────────────────────────────
AuditColumnJoiner::apply($query, Model::class);           // colunas numa listagem
AuditWidget::full(Model::operationsFor($id), recordId: $id); // JSON do GaAudit.full
AuditWidget::simple(Model::auditsFor($id));                  // JSON do GaAudit.simple

// ── Multitenancy ──────────────────────────────────────────────────────
Audit::resolveTenantUsing(fn () => auth()->user()?->empresa_id);
Model::withoutTenantScope();
```

```bash
php artisan vendor:publish --tag=auditable-config       # config/auditable.php
```

```bash
php artisan vendor:publish --tag=auditable-migrations   # (opcional) copia a migration, para editá-la
```

```bash
php artisan auditable:publish-js [--path=...] [--force] # widget JS em public/
```

---

## Testes do pacote

```bash
composer install
composer test
```

## Atualizando de uma versão anterior

Veja o [CHANGELOG](CHANGELOG.md): ele lista o que mudou e o que ajustar no
seu código.

## Licença

MIT. Veja o arquivo [LICENSE](LICENSE).
