<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Tabela de auditoria do laravel-auditable
|--------------------------------------------------------------------------
| ANTES de rodar `php artisan migrate`, confira os três tipos abaixo.
|
| O padrão ('integer') serve para ids numéricos (o normal no Laravel).
| Se os seus models usam HasUuids/HasUlids, ou os usuários/tenants têm
| ids de texto, troque para 'uuid', 'ulid' ou 'string'.
*/
return new class extends Migration
{
    /** Tipo do id dos registros auditados (os seus models). */
    private string $subjectKeyType = 'integer';

    /** Tipo do id dos usuários (coluna created_by). */
    private string $userKeyType = 'integer';

    /** Tipo do id do tenant (só usado com config('auditable.tenant.enabled')). */
    private string $tenantKeyType = 'integer';

    public function up(): void
    {
        $tableName = config('auditable.table', 'audit_table');
        $schema = Schema::connection(config('auditable.connection'));

        // Já existe (ex.: migration publicada e apagada depois, ou tabela criada
        // à mão): não faz nada, para o migrate nunca falhar por isso.
        if ($schema->hasTable($tableName)) {
            return;
        }

        $schema->create($tableName, function (Blueprint $table) {
            $table->id();

            // Agrupa as auditorias de uma mesma operação (Audit::transaction()).
            $table->ulid('batch')->nullable()->index();

            // O registro auditado: classe do model (ou nome livre) + id.
            $table->string('subject_type');
            $this->keyColumn($table, 'subject_id', $this->subjectKeyType)->nullable();

            // created, updated, deleted, restored ou o nome de uma ação sua.
            $table->string('event', 100);

            // O que mudou (legível) e detalhes técnicos (falhas / retrato de restauro).
            $table->json('changes')->nullable();
            $table->json('debug_info')->nullable();

            // Quem fez. Vazio = ação do sistema.
            $this->keyColumn($table, 'created_by', $this->userKeyType)->nullable()->index();

            if (config('auditable.tenant.enabled', false)) {
                $this->keyColumn($table, config('auditable.tenant.column', 'tenant_id'), $this->tenantKeyType)
                    ->nullable()
                    ->index();
            }

            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['subject_type', 'event', 'subject_id']);
            $table->index('event');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection(config('auditable.connection'))
            ->dropIfExists(config('auditable.table', 'audit_table'));
    }

    private function keyColumn(Blueprint $table, string $name, string $type): \Illuminate\Database\Schema\ColumnDefinition
    {
        return match ($type) {
            'uuid' => $table->uuid($name),
            'ulid' => $table->ulid($name),
            'string' => $table->string($name, 64),
            default => $table->unsignedBigInteger($name),
        };
    }
};
