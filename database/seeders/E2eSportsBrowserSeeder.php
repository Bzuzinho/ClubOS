<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\{SportsLiveMonitoring, SportsLiveMonitoringAthlete, SportsLiveMeasurement, SportsLiveMeasurementAthlete, TrainingMetric, AgeGroup, Macrocycle, Mesocycle, Microcycle, Season, SportsModality, Training, TrainingAthlete, TrainingSeries, User};
use Illuminate\Database\Seeder;
use RuntimeException;

final class E2eSportsBrowserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Sports browser fixtures require testing.');
        }

        $club = (string) config('sports.club_id', 'bscn');
        $modality = SportsModality::query()->where('club_id', $club)->where('code', 'swimming')->firstOrFail();
        $season = Season::query()->updateOrCreate(['club_id' => $club, 'nome' => 'E2E Época'], [
            'sports_modality_id' => $modality->id, 'ano_temporada' => 'E2E',
            'data_inicio' => now()->startOfYear()->toDateString(), 'data_fim' => now()->endOfYear()->toDateString(),
            'tipo' => 'Principal', 'estado' => 'Em curso', 'status' => 'active',
        ]);
        $dates = ['data_inicio' => now()->startOfMonth()->toDateString(), 'data_fim' => now()->endOfMonth()->toDateString(), 'active' => true];
        $macro = Macrocycle::query()->updateOrCreate(['epoca_id' => $season->id, 'nome' => 'E2E Preparação'], $dates + ['club_id' => $club, 'tipo' => 'Preparação geral']);
        $meso = Mesocycle::query()->updateOrCreate(['macrociclo_id' => $macro->id, 'nome' => 'E2E Base'], $dates + ['club_id' => $club, 'foco' => 'Técnica']);
        $micro = Microcycle::query()->updateOrCreate(['mesociclo_id' => $meso->id, 'semana' => 'E2E Semana'], $dates + ['club_id' => $club, 'volume_previsto' => 400]);
        $athlete = User::query()->firstOrCreate(['email' => 'e2e.sports@clubos.test'], [
            'name' => 'Atleta E2E Desportivo', 'nome_completo' => 'Atleta E2E Desportivo',
            'password' => bcrypt('No-browser-login-2026!'), 'perfil' => 'atleta',
            'estado' => 'ativo', 'tipo_membro' => ['atleta'], 'ativo_desportivo' => true,
            'data_nascimento' => '1990-01-01', 'menor' => false, 'rgpd' => true, 'consentimento' => true,
            'afiliacao' => false, 'declaracao_de_transporte' => false,
        ]);
        $ageGroup = AgeGroup::query()->updateOrCreate(['club_id' => $club, 'code' => 'e2e-masters'], [
            'nome' => 'E2E Masters', 'ativo' => true,
        ]);
        $currentAgeGroup = AgeGroup::query()->updateOrCreate(['club_id' => $club, 'code' => 'e2e-current'], [
            'nome' => 'E2E Escalão atual', 'ativo' => true,
        ]);
        $athlete->update(['escalao' => [$currentAgeGroup->id]]);
        $training = Training::query()->updateOrCreate(['numero_treino' => '#E2E-DESPORTIVO'], [
            'club_id' => $club, 'data' => now()->toDateString(), 'hora_inicio' => '18:00', 'hora_fim' => '19:30',
            'descricao_treino' => 'E2E Descrição completa do treino para consultar na ficha do atleta em qualquer ecrã.',
            'tipo_treino' => 'Técnico', 'session_status' => 'published', 'epoca_id' => $season->id,
            'macrocycle_id' => $macro->id, 'mesociclo_id' => $meso->id, 'microciclo_id' => $micro->id,
        ]);
        $training->syncAgeGroupsWithPivot([$ageGroup->id]);
        $participation = TrainingAthlete::query()->updateOrCreate(['treino_id' => $training->id, 'user_id' => $athlete->id], [
            'presente' => true, 'estado' => 'presente', 'registado_em' => now(),
        ]);
        $series = TrainingSeries::query()->updateOrCreate(['treino_id' => $training->id, 'ordem' => 1], [
            'descricao_texto' => 'E2E Livre técnico', 'distancia_total_m' => 400, 'estilo' => 'Livre',
            'repeticoes' => 8, 'distancia_m' => 50, 'block_name' => 'Principal', 'block_order' => 1,
            'block_rounds' => 1, 'timing_mode' => 'each_rep',
        ]);
        TrainingMetric::query()->updateOrCreate(['treino_id'=>$training->id, 'user_id'=>$athlete->id, 'metrica'=>'technical_note'], [
            'ordem'=>1, 'valor'=>'E2E Melhorar a viragem', 'observacao'=>'E2E Melhorar a viragem',
        ]);
        $monitor = SportsLiveMonitoring::query()->updateOrCreate(['training_id'=>$training->id, 'type'=>'planned'], [
            'club_id'=>$club, 'training_series_id'=>$series->id, 'state'=>'completed', 'completed_at'=>now(),
        ]);
        $monitorAthlete = SportsLiveMonitoringAthlete::query()->updateOrCreate(['monitoring_id'=>$monitor->id, 'user_id'=>$athlete->id], [
            'training_athlete_id'=>$participation->id, 'active'=>false,
        ]);
        $measurement = SportsLiveMeasurement::query()->updateOrCreate(['client_measurement_id'=>'e2e-history'], [
            'monitoring_id'=>$monitor->id, 'training_id'=>$training->id, 'training_series_id'=>$series->id,
            'state'=>'completed', 'started_at'=>now()->subMinute(), 'ended_at'=>now(), 'repetition_number'=>1,
        ]);
        SportsLiveMeasurementAthlete::query()->updateOrCreate(['measurement_id'=>$measurement->id, 'user_id'=>$athlete->id], [
            'monitoring_athlete_id'=>$monitorAthlete->id, 'training_athlete_id'=>$participation->id,
            'state'=>'stopped', 'duration_ms'=>32540, 'stopped_at'=>now(),
        ]);
    }
}
