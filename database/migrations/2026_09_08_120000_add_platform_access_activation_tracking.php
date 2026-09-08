<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_access_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('dados_configuracao', function (Blueprint $table): void {
            $table->timestamp('platform_access_activated_at')
                ->nullable()
                ->after('platform_access_granted_at')
                ->index();
        });

        $previouslyActivatedUserIds = DB::table('users')
            ->where(function ($query): void {
                $query->whereNotNull('email_verified_at')
                    ->orWhereNotNull('remember_token');
            })
            ->pluck('id');

        if ($previouslyActivatedUserIds->isNotEmpty()) {
            DB::table('dados_configuracao')
                ->where('platform_access_enabled', true)
                ->whereIn('user_id', $previouslyActivatedUserIds)
                ->update([
                    'platform_access_activated_at' => DB::raw('COALESCE(platform_access_granted_at, CURRENT_TIMESTAMP)'),
                ]);
        }

        DB::table('dados_configuracao')
            ->where('platform_access_enabled', true)
            ->whereNull('platform_access_activated_at')
            ->whereNotNull('ultimo_envio_acessos_at')
            ->update(['platform_access_enabled' => false]);
    }

    public function down(): void
    {
        Schema::table('dados_configuracao', function (Blueprint $table): void {
            $table->dropColumn('platform_access_activated_at');
        });

        Schema::dropIfExists('member_access_tokens');
    }
};
