<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('seasons') || ! Schema::hasColumn('seasons', 'status')) {
            return;
        }

        if (Schema::hasColumn('seasons', 'estado')) {
            DB::table('seasons')->where('estado', 'Planeada')->update(['status' => 'planned']);
            DB::table('seasons')->where('estado', 'Em curso')->update(['status' => 'active']);
            DB::table('seasons')->whereIn('estado', ['Concluída', 'Arquivada'])->update(['status' => 'closed']);
        }

        if (Schema::hasColumn('seasons', 'ativa')) {
            DB::table('seasons')->where('ativa', true)->update(['status' => 'active']);
        }
    }

    public function down(): void
    {
        // Expand-first migration: legacy state is not rewritten backwards.
    }
};
