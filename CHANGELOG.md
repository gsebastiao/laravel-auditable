# Changelog

## [Não lançado]

---

## [2.2.0] — 2026-09-29

### ⚠️ Mudança que pode exigir ajustes no seu código

- **O `restore()` do SoftDeletes passa a gravar uma só linha.** O Laravel faz
  um `save()` dentro do `restore()`, e o pacote gravava esse save como
  `updated` (`deleted_at` → vazio) além do `restored` — a mesma operação
  aparecia duas vezes no histórico, e em batches diferentes quando não havia
  um batch aberto. Com `restored` em `events()`, fica agora só a linha
  `restored`. Se tem código ou relatórios que contavam esse `updated`, passe a
  olhar para o `restored`.

  Sem `restored` em `events()` (o padrão), nada muda: o restauro continua a
  ficar registado como `updated`, para nunca ficar sem rasto.

### Testes

- `SoftDeletesTest` cobre o restauro com e sem `restored` em `events()`, um
  `update()` feito logo a seguir ao restauro e um `restore()` de um registo que
  não estava na lixeira (em nenhum dos casos um `update()` seguinte fica por
  gravar). A suíte passou para 121 testes.

---

## [2.1.2] — 2026-09-20

Agrupa as alterações publicadas de 2.0.0 a 2.1.2.

Esta versão corrige bugs encontrados numa revisão completa do pacote (todos
cobertos agora por testes), simplifica a API e traz um README reescrito para
iniciantes. Acrescenta também integração automática com filas, updates e
deletes em massa auditados e filtros para telas de pesquisa. A suíte de
testes passou de 37 para 118 testes e foi executada no Laravel 11.4, 11.x,
12.x e 13.x.

### ⚠️ Mudanças que podem exigir ajustes no seu código

1. **Laravel 11.4 ou mais recente.** O `AuditColumnJoiner` usa
   `afterQuery()`, que só existe a partir do Laravel 11.4.

2. **`lockForUpdate` foi removido** de `$model->audit()` e de
   `DB::table()->audit()`. Na macro ele não fazia nada; no model relia a
   linha, mas não protegia o valor "antigo" como a documentação dizia.
   Remova o argumento das chamadas.

3. **`changes` guarda os valores como estão no banco.** Antes o "antes" e o
   "depois" eram lidos de formas diferentes, e campos com cast (array, enum,
   date, encrypted) apareciam sempre como alterados. Agora:
   - datas aparecem como no banco (`2026-07-10 14:30:00`, não `2026-07-10T14:30:00.000000Z`);
   - enums aparecem pelo valor (`ativo`);
   - campos `array`/`json` aparecem já decodificados;
   - campos `encrypted` aparecem como `********` (antes o valor ia em texto claro).

4. **`created_at` e `updated_at` do model saem de `changes` por padrão.** Para
   voltar ao comportamento anterior, use `->logTimestamps()` no
   `getAuditOptions()`.

5. **`changes` de uma falha tem só a mensagem.** `auditFailure()` grava
   `{"message": "..."}` (a chave `error`, que expunha o SQL com os valores e o
   host do banco, saiu). Os detalhes continuam em `debug_info.error`, agora
   com `sql` (com `?` no lugar dos valores) e `connection`. Há um 4.º
   parâmetro opcional para a mensagem mostrada ao usuário.

6. **`failures()` devolve só falhas.** Antes devolvia também todos os
   `deleted` (que têm `debug_info` por causa do retrato de restauro).

7. **`audit()` com `event:` já não duplica.** `audit()` sempre personaliza a
   última entrada automática do objeto; `event:` só muda o nome gravado.
   Antes, um evento diferente de `created`/`updated` criava uma segunda linha.

8. **`restore()` recria o registro com os valores crus.** Nada passa por
   casts ou mutators (antes, datas mudavam de fuso e campos criptografados
   eram criptografados duas vezes). Nova assinatura:
   `restore(bool $withId = true, array $attributes = [])`. Se o id já existir,
   o erro explica o que fazer. Retratos gravados pela versão anterior podem
   conter datas em ISO/UTC: confira-os antes de restaurar.

9. **`causer()` → `user()`.** `causer()` devolvia sempre `null`. `user()` é uma
   relação `BelongsTo` de verdade (aceita `with('user')`). `causer()` continua
   a existir como sinónimo, mas está obsoleto.

10. **`neverSnapshot()` também tira os campos de `changes`.** Um campo secreto
    não fica guardado em lugar nenhum da auditoria.

11. **Widget JS: o formato de `changes` é `{"old": ..., "new": ...}`.** É o
    formato que o pacote sempre gravou; o `[old, new]` documentado antes
    nunca batia com ele. Republique o arquivo:
    `php artisan auditable:publish-js --force`. Para as rotas, use o novo
    `AuditWidget::full()` / `AuditWidget::simple()`.

12. **Resolver de tenant na config: nome de classe, não closure.** Uma closure
    em `config/auditable.php` impedia o `php artisan config:cache`. Use uma
    classe invocável (`App\Support\TenantAtual::class`) ou
    `Audit::resolveTenantUsing(fn () => ...)` no `AppServiceProvider`.

13. **`events()` só filtra os eventos automáticos.** No `Audit::for()` e em
    `DB::table()->audit()`, eventos com nome livre (`reajuste`, `importado`)
    eram descartados em silêncio quando o tipo era a classe de um model.
    Agora são sempre gravados.

14. **Variável de ambiente renomeada:** `AUDITABLE_DEFAULT_created_by` →
    `AUDITABLE_DEFAULT_CREATED_BY`. O nome antigo ainda é lido.

15. **Tabela padrão.** Os valores de reserva espalhados pelo código usavam
    `audits`, mas a config dizia `audit_table`. Tudo usa agora
    `config('auditable.table')` (padrão `audit_table`). Nada muda para quem
    já tem a config publicada.

16. **Jobs em fila herdam a operação e o autor.** Uma job despachada durante
    uma operação grava no mesmo batch, e as auditorias feitas por jobs passam
    a ter o `created_by` de quem as despachou (antes ficava vazio ou com o
    `default_created_by`). Se o seu código dependia do comportamento antigo,
    desligue `propagate_batch`/`propagate_user` em `config('auditable.queue')`.

### Corrigido

- **A migration do pacote passa a ser registada sempre.** Estava dentro do
  bloco `runningInConsole()` e só era carregada depois de um `glob()` em
  `database/migrations` não encontrar uma cópia publicada. Resultado: quem
  dispara o `migrate` fora da consola (`Artisan::call('migrate')` num
  instalador, num webhook de deploy ou nos testes do projeto) nunca via a
  migration, e a tabela de auditoria não era criada. O
  `loadMigrationsFrom()` passou para fora do `runningInConsole()` e a
  verificação por `glob()` foi removida — é o Laravel que já descarta a cópia
  duplicada, porque indexa as migrations pelo nome do ficheiro.
- `withoutTenantScope()` não removia o filtro de tenant (o scope era uma classe anónima).
- `tenant.column` era ignorado ao gravar: a auditoria usava sempre `tenant_id`.
- O model de auditoria não era filtrado por tenant com o multitenancy por coluna ligado.
- `Audit::transaction()` abria a transação na conexão da auditoria, e não na dos dados, quando `auditable.connection` estava definido.
- O `resolveMap` consultava as tabelas na conexão da auditoria em vez da do model.
- O rastreio de `audit()` usava `spl_object_id()`, que o PHP reaproveita: `audit()` podia substituir a linha de outro registro.
- `AuditColumnJoiner` perdia as colunas do model, deixava os models "alterados" (`isDirty()`) e tornava ambíguas colunas como `id` e `name`. Agora também aceita `DB::table()`.
- Labels numéricos no `resolveMap` quebravam com `TypeError`; o cache só valia para `direct()` (agora também para `join()`).
- Uma subclasse do model de auditoria com `$table`/`$connection` próprios era ignorada.
- `restore()` falhava com `morphMap`.
- `useBatch()` não restaurava o batch anterior e não aceitava `null`.
- O `debug_info` de falhas em comandos e filas incluía uma requisição falsa (`GET /`, IP `127.0.0.1`).
- `auditable:publish-js` devolvia código de saída 2 quando o arquivo já existia (quebrava scripts de deploy).
- Publicar a migration duas vezes criava uma migration duplicada.
- `FakeConnection` (testes) não implementava a interface do Laravel 11, 12 e 13: dois arquivos de teste nem carregavam.
- Um teste dependia do relógio e falhava quando o segundo mudava a meio.

### Novo

- **Filas sem código nas jobs.** Jobs, listeners, notificações e e-mails em
  fila despachados durante uma operação continuam-na (mesmo `batch`) e gravam
  em nome de quem os despachou; opcionalmente, também herdam o tenant.
  Configurável em `config('auditable.queue')`. Seguro com o driver `sync`:
  nada fica "preso" à requisição depois de a job acabar.
- `Model::where(...)->auditedUpdate([...])` e `->auditedDelete()` — update e
  delete em massa com uma auditoria por registro: lotes travados e atômicos,
  valores calculados pelo banco (`DB::raw`), labels do `resolveMap`
  pré-carregados numa consulta por lote e auditorias gravadas em bloco.
- `Audit::filter($request->all())` — vários filtros de uma vez (usuário,
  evento, model, registro, operação, período, só falhas), com a receita de uma
  página de pesquisa no README.
- `Contracts\BulkAuditRepository` — interface opcional para repositórios
  personalizados gravarem em bloco.
- O cache de labels passou a ter um teto (10 000 entradas), para jobs longas
  não esgotarem a memória.
- `Audit::for($tipo, $id, $evento)` — auditar sem model (o mesmo que a macro `DB::table()->audit()`, sem a tabela sem uso).
- `Audit::withoutAuditing(fn () => ...)` — desligar a auditoria só num trecho.
- `Audit::useBatch($batch, fn () => ...)` — usar um batch específico, restaurando o anterior no fim.
- `Audit::resolveTenantUsing(...)` — resolver de tenant compatível com `config:cache`.
- `Audit::inBatch()`, `Audit::failures()`, `Audit::query()` etc. — a fachada repassa as consultas ao model de auditoria (um único `use`).
- `$model->operations()` / `Model::operationsFor($id)` — todas as operações de um registro (o histórico completo, agrupável por batch).
- `$audit->changeLines()` — as alterações em texto, prontas para uma view Blade.
- `$audit->isFailure()` e a relação `$audit->user`.
- `AuditWidget::full()` / `AuditWidget::simple()` — o JSON exato que o widget JS espera, sem N+1.
- `AuditOptions::logTimestamps()`.
- `config('auditable.tenant.strict')` — sem tenant identificado, não mostrar nada.
- `config('auditable.user_model')`.
- Migration com o tipo das chaves editável (`integer`, `uuid`, `ulid`, `string`) e um índice novo `(subject_type, event, subject_id)` para as listagens.

- **Config e migration opcionais.** O `config/auditable.php` não precisa de ser
  publicado: cada opção tem uma variável `AUDITABLE_*` no `.env` (tabela no
  README, em "Configurar sem publicar o config"). A migration também não: o
  pacote a carrega dele e `php artisan migrate` basta. `vendor:publish
  --tag=auditable-migrations` continua a existir para quem quer editá-la
  (ex.: ids UUID/ULID); o arquivo publicado mantém o nome do pacote
  (`2026_01_01_000000_create_audits_table.php`), e é por esse nome que o
  Laravel a identifica: a cópia do projeto substitui a do pacote e a tabela
  nunca é criada duas vezes. A migration ignora a tabela se ela já existir.
- `AUDITABLE_DEFAULT_created_by` (com `created_by` minúsculo) deixou de ser lida
  como alternativa; use `AUDITABLE_DEFAULT_CREATED_BY`.

### Para instalações existentes

Se a sua tabela de auditoria já existe, é recomendável acrescentar o índice
usado pelo `AuditColumnJoiner`:

```php
Schema::table(config('auditable.table', 'audit_table'), function (Blueprint $table) {
    $table->index(['subject_type', 'event', 'subject_id']);
});
```

### Ferramentas

- `orchestra/testbench` `^11` (Laravel 13) e PHPUnit `^12|^13` acrescentados.
- `composer test` e `phpunit.xml.dist`.
- Dependências `illuminate/console`, `illuminate/filesystem` e `illuminate/http` declaradas (já eram usadas).
