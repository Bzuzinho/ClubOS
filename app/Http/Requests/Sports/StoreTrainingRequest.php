<?php

namespace App\Http\Requests\Sports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTrainingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'numero_treino' => ['nullable', 'string', 'max:255'],
            'data' => ['nullable', 'date'],
            'hora_inicio' => ['nullable', 'date_format:H:i'],
            'hora_fim' => ['nullable', 'date_format:H:i', 'after:hora_inicio'],
            'local' => ['nullable', 'string', 'max:255'],
            'sports_venue_id' => ['nullable', 'uuid', 'exists:sports_venues,id'],
            'epoca_id' => ['nullable', 'uuid', 'exists:seasons,id'],
            'macrocycle_id' => ['nullable', 'uuid', 'exists:macrocycles,id'],
            'mesociclo_id' => ['nullable', 'uuid', 'exists:mesocycles,id'],
            'microciclo_id' => ['nullable', 'uuid', 'exists:microcycles,id'],
            'tipo_treino' => [
                'nullable',
                'string',
                'max:100',
                Rule::exists('training_type_configs', 'nome')->where(fn ($query) => $query->where('ativo', true)),
            ],
            'volume_planeado_m' => ['nullable', 'integer', 'min:0'],
            'descricao_treino' => ['nullable', 'string'],
            'notas_gerais' => ['nullable', 'string'],
            'responsavel_id' => ['nullable', 'uuid', 'exists:users,id'],
            'training_plan_version_id' => ['nullable', 'uuid', 'exists:training_plan_versions,id'],
            'session_status' => ['nullable', Rule::in(['draft', 'published'])],
            'instrucao' => ['nullable', 'string'],
            'escaloes' => ['nullable', 'array'],
            'escaloes.*' => ['uuid', 'exists:age_groups,id'],
            'series_linhas' => ['nullable', 'array'],
            'series_linhas.*.repeticoes' => ['nullable', 'integer', 'min:0'],
            'series_linhas.*.exercicio' => ['nullable', 'string', 'max:255'],
            'series_linhas.*.metros' => ['nullable', 'integer', 'min:0'],
            'series_linhas.*.zona' => [
                'nullable',
                'string',
                Rule::exists('training_zone_configs', 'codigo')->where(fn ($query) => $query->where('ativo', true)),
            ],
            'training_groups' => ['nullable', 'array'],
            'training_groups.*.training_group_id' => ['required', 'uuid', 'exists:training_groups,id'],
            'training_groups.*.training_plan_version_id' => ['nullable', 'uuid', 'exists:training_plan_versions,id'],
            'training_groups.*.instruction' => ['nullable', 'string'],
            'training_groups.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'training_groups.*.lanes' => ['nullable', 'array'],
            'training_groups.*.lanes.*.lane_id' => ['required', 'uuid', 'exists:sports_venue_lanes,id'],
            'training_groups.*.lanes.*.planned_capacity' => ['nullable', 'integer', 'min:1', 'max:999'],
        ];
    }
}
