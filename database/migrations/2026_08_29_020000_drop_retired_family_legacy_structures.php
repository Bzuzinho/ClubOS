<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::dropIfExists('user_relationships');

        $columns = array_values(array_filter(
            ['encarregado_educacao', 'educandos'],
            static fn (string $column): bool => Schema::hasColumn('users', $column),
        ));

        if ($columns !== []) {
            Schema::table('users', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    public function down(): void
    {
        $missingGuardianMirror = ! Schema::hasColumn('users', 'encarregado_educacao');
        $missingDependentMirror = ! Schema::hasColumn('users', 'educandos');

        if ($missingGuardianMirror || $missingDependentMirror) {
            Schema::table('users', function (Blueprint $table) use ($missingGuardianMirror, $missingDependentMirror): void {
                if ($missingGuardianMirror) {
                    $table->json('encarregado_educacao')->nullable();
                }

                if ($missingDependentMirror) {
                    $table->json('educandos')->nullable();
                }
            });
        }

        if (! Schema::hasTable('user_relationships')) {
            Schema::create('user_relationships', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('related_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('type', 50);
                $table->timestamps();
                $table->unique(['user_id', 'related_user_id', 'type']);
                $table->index('user_id');
                $table->index('related_user_id');
            });
        }
    }
};
