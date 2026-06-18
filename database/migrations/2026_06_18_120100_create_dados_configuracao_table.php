<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('dados_configuracao', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('consentimento_rgpd')->nullable();
            $table->dateTime('consentimento_rgpd_data')->nullable();
            $table->boolean('consentimento_imagem')->nullable();
            $table->dateTime('consentimento_imagem_data')->nullable();
            $table->boolean('declaracao_transporte')->nullable();
            $table->dateTime('declaracao_transporte_data')->nullable();
            $table->string('declaracao_transporte_ficheiro')->nullable();
            $table->boolean('afiliacao_federativa')->nullable();
            $table->string('afiliacao_numero')->nullable();
            $table->date('afiliacao_data')->nullable();
            $table->string('afiliacao_ficheiro')->nullable();
            $table->string('ficha_inscricao_ficheiro')->nullable();
            $table->string('documento_identificacao_ficheiro')->nullable();
            $table->string('certificado_medico_ficheiro')->nullable();
            $table->string('autorizacao_parental_ficheiro')->nullable();
            $table->boolean('termos_aceites')->nullable();
            $table->dateTime('termos_aceites_data')->nullable();
            $table->boolean('receber_comunicacoes')->nullable();
            $table->boolean('acesso_portal_ativo')->nullable();
            $table->timestamp('ultimo_envio_acessos_at')->nullable();
            $table->json('configuracao_extra')->nullable();
            $table->timestamp('migrated_from_users_at')->nullable();
            $table->string('migration_source_hash')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dados_configuracao');
    }
};
