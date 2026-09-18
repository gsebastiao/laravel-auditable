<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Usa a MESMA migration que o pacote publica (sem cópia para manter em
// sincronia) e cria as tabelas das fixtures dos testes.
return new class extends Migration
{
    public function up(): void
    {
        (require __DIR__.'/../../../database/migrations/create_audits_table.php.stub')->up();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('status', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
        });

        Schema::create('produtos', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->unsignedInteger('preco')->default(0);
            $table->unsignedBigInteger('status_id')->nullable();
            $table->timestamps();
        });

        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->text('meta')->nullable();
            $table->date('nascimento')->nullable();
            $table->string('situacao')->nullable();
            $table->text('segredo')->nullable();
            $table->timestamps();
        });

        Schema::create('notas', function (Blueprint $table) {
            $table->id();
            $table->string('texto');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('documentos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('titulo')->default('');
        });
    }

    public function down(): void
    {
        foreach (['documentos', 'notas', 'clientes', 'produtos', 'status', 'users', config('auditable.table', 'audit_table')] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
