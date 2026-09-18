# Changelog

## [Não lançado] — correções gerais e documentação nova

Esta versão corrige bugs encontrados numa revisão completa do pacote (todos
cobertos agora por testes), simplifica a API e traz um README reescrito para
iniciantes. A suíte de testes passou de 37 para 93 testes e foi executada no
Laravel 11.4, 11.x, 12.x e 13.x.

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

### Corrigido

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

- `Audit::for($tipo, $id, $evento)` — auditar sem model (o mesmo que a macro `DB::table()->audit()`, sem a tabela sem uso).
- `Audit::withoutAuditing(fn () => ...)` — desligar a auditoria só num trecho.
- `Audit::useBatch($batch, fn () => ...)` — continuar uma operação numa job, restaurando o batch anterior no fim.
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
