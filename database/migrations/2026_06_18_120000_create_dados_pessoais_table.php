<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('dados_pessoais', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('nome_completo')->nullable();
            $table->date('data_nascimento')->nullable();
            $table->string('sexo', 20)->nullable();
            $table->string('nif')->nullable()->index();
            $table->string('documento_identificacao')->nullable();
            $table->string('tipo_documento')->nullable();
            $table->date('validade_documento')->nullable();
            $table->string('nacionalidade')->nullable();
            $table->string('naturalidade')->nullable();
            $table->text('morada')->nullable();
            $table->string('codigo_postal')->nullable();
            $table->string('localidade')->nullable();
            $table->string('distrito')->nullable();
            $table->string('concelho')->nullable();
            $table->string('contacto')->nullable();
            $table->string('contacto_alternativo')->nullable();
            $table->string('email_secundario')->nullable();
            $table->string('tipo_utilizador')->nullable()->index();
            $table->text('observacoes')->nullable();
            $table->timestamp('migrated_from_users_at')->nullable();
            $table->string('migration_source_hash')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dados_pessoais');
    }
};
