<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->json('publicos_alvo')->nullable()->after('visibilidade');
        });

        $hasCompetitionProjections = Schema::hasTable('competition_event_projections');

        DB::table('events')
            ->select(['id', 'tipo', 'visibilidade'])
            ->orderBy('id')
            ->chunk(200, function ($events) use ($hasCompetitionProjections): void {
                foreach ($events as $event) {
                    $hasAgeGroups = DB::table('event_age_group')
                        ->where('event_id', $event->id)
                        ->exists();
                    $isCompetitionProjection = $hasCompetitionProjections
                        && DB::table('competition_event_projections')
                            ->where('event_id', $event->id)
                            ->where('status', 'linked')
                            ->exists();
                    $isSportsEvent = in_array($event->tipo, ['treino', 'prova', 'competicao', 'estagio'], true);

                    $audiences = $hasAgeGroups || $isCompetitionProjection || $isSportsEvent
                        ? ['atletas']
                        : ($event->visibilidade === 'publico' ? ['todos'] : []);

                    DB::table('events')
                        ->where('id', $event->id)
                        ->update(['publicos_alvo' => json_encode($audiences)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('publicos_alvo');
        });
    }
};
