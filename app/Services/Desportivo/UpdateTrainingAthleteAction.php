<?php

namespace App\Services\Desportivo;

use App\Models\TrainingAthlete;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateTrainingAthleteAction
{
    public function __construct() {}

    public function execute(TrainingAthlete $trainingAthlete, array $data, ?User $atualizadoPor = null): TrainingAthlete
    {
        $this->validate($data);
        $updateData = [];

        if (isset($data['presente'])) {
            $updateData['presente'] = (bool) $data['presente'];
        }

        if (isset($data['estado'])) {
            $updateData['estado'] = $data['estado'];
            if (in_array($data['estado'], ['presente', 'limitado', 'atrasado'], true)) {
                $updateData['presente'] = true;
            } elseif (in_array($data['estado'], ['ausente', 'justificado', 'lesionado', 'doente', 'dispensado'], true)) {
                $updateData['presente'] = false;
            }
        }

        if (isset($data['volume_real_m'])) $updateData['volume_real_m'] = $data['volume_real_m'];
        if (isset($data['rpe'])) $updateData['rpe'] = $data['rpe'];
        if (isset($data['observacoes_tecnicas'])) $updateData['observacoes_tecnicas'] = $data['observacoes_tecnicas'];

        if ($atualizadoPor) {
            $updateData['atualizado_por'] = $atualizadoPor->id;
            $updateData['atualizado_por_utilizador_em'] = now();
        }

        $trainingAthlete->update($updateData);

        Log::info('TrainingAthlete updated', [
            'training_athlete_id' => $trainingAthlete->id,
            'training_id' => $trainingAthlete->treino_id,
            'user_id' => $trainingAthlete->user_id,
            'estado' => $trainingAthlete->estado,
            'presente' => $trainingAthlete->presente,
            'updated_by' => $atualizadoPor?->id,
        ]);

        return $trainingAthlete->fresh();
    }

    private function validate(array $data): void
    {
        $rules = [
            'presente' => 'nullable|boolean',
            'estado' => 'nullable|string|max:30|in:presente,ausente,justificado,lesionado,limitado,doente,dispensado,atrasado',
            'volume_real_m' => 'nullable|integer|min:0|max:50000',
            'rpe' => 'nullable|integer|min:1|max:10',
            'observacoes_tecnicas' => 'nullable|string|max:5000',
        ];

        $validator = validator($data, $rules);
        if ($validator->fails()) throw new ValidationException($validator);
    }

    public function markMultiplePresent(array $trainingAthleteIds, ?User $atualizadoPor = null): int
    {
        $updated = TrainingAthlete::whereIn('id', $trainingAthleteIds)->update([
            'presente' => true,
            'estado' => 'presente',
            'atualizado_por' => $atualizadoPor?->id,
            'atualizado_por_utilizador_em' => now(),
        ]);
        Log::info('Multiple athletes marked as present', ['count' => $updated, 'updated_by' => $atualizadoPor?->id]);
        return $updated;
    }

    public function clearAllPresences(string $treinoId, ?User $atualizadoPor = null): int
    {
        $updated = TrainingAthlete::where('treino_id', $treinoId)->update([
            'presente' => false,
            'estado' => 'ausente',
            'volume_real_m' => null,
            'rpe' => null,
            'atualizado_por' => $atualizadoPor?->id,
            'atualizado_por_utilizador_em' => now(),
        ]);
        Log::info('All presences cleared for training', ['training_id' => $treinoId, 'count' => $updated, 'updated_by' => $atualizadoPor?->id]);
        return $updated;
    }
}
