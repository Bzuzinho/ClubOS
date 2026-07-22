<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dados_configuracao', function (Blueprint $table): void {
            $table->boolean('platform_access_enabled')->default(false)->after('ultimo_envio_acessos_at');
            $table->timestamp('platform_access_granted_at')->nullable()->after('platform_access_enabled');
            $table->foreignUuid('platform_access_granted_by')->nullable()->after('platform_access_granted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('platform_access_revoked_at')->nullable()->after('platform_access_granted_by');
            $table->foreignUuid('platform_access_revoked_by')->nullable()->after('platform_access_revoked_at')->constrained('users')->nullOnDelete();
            $table->text('platform_access_notes')->nullable()->after('platform_access_revoked_by');
        });
    }

    public function down(): void
    {
        Schema::table('dados_configuracao', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('platform_access_granted_by');
            $table->dropConstrainedForeignId('platform_access_revoked_by');
            $table->dropColumn([
                'platform_access_enabled',
                'platform_access_granted_at',
                'platform_access_revoked_at',
                'platform_access_notes',
            ]);
        });
    }
};
