<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'data_inicio' => ['required', 'date'],
            'hora_inicio' => ['nullable', 'date_format:H:i'],
            'data_fim' => ['nullable', 'date', 'after_or_equal:data_inicio'],
            'hora_fim' => ['nullable', 'date_format:H:i'],
            'local' => ['nullable', 'string', 'max:255'],
            'local_detalhes' => ['nullable', 'string'],
            'tipo' => ['required', 'string', 'max:50'],
            'tipo_config_id' => ['nullable', 'exists:event_type_configs,id'],
            'tipo_piscina' => ['nullable', 'in:piscina_25m,piscina_50m,aguas_abertas'],
            'visibilidade' => ['nullable', 'in:publico,privado,restrito'],
            'escaloes_elegiveis' => ['nullable', 'array'],
            'escaloes_elegiveis.*' => ['uuid', 'exists:age_groups,id'],
            'transporte_necessario' => ['nullable', 'boolean'],
            'transporte_detalhes' => ['nullable', 'string'],
            'hora_partida' => ['nullable', 'date_format:H:i'],
            'local_partida' => ['nullable', 'string', 'max:255'],
            'taxa_inscricao' => ['nullable', 'numeric', 'min:0'],
            'custo_inscricao_por_prova' => ['nullable', 'numeric', 'min:0'],
            'custo_inscricao_por_salto' => ['nullable', 'numeric', 'min:0'],
            'custo_inscricao_estafeta' => ['nullable', 'numeric', 'min:0'],
            'centro_custo_id' => ['nullable', 'exists:cost_centers,id'],
            'observacoes' => ['nullable', 'string'],
            'estado' => ['nullable', 'in:rascunho,agendado,em_curso,concluido,cancelado'],
            'criado_por' => ['nullable', 'exists:users,id'],
            'recorrente' => ['nullable', 'boolean'],
            'recorrencia_data_inicio' => ['exclude_unless:recorrente,true', 'required', 'date', 'after_or_equal:data_inicio'],
            'recorrencia_data_fim' => ['exclude_unless:recorrente,true', 'required', 'date', 'after_or_equal:recorrencia_data_inicio'],
            'recorrencia_dias_semana' => ['exclude_unless:recorrente,true', 'required', 'array', 'min:1'],
            'recorrencia_dias_semana.*' => ['string', 'distinct', 'in:0,1,2,3,4,5,6'],
            'evento_pai_id' => ['nullable', 'exists:events,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'titulo.required' => 'Preencha o título do evento.',
            'titulo.max' => 'O título não pode ter mais de 255 caracteres.',
            'data_inicio.required' => 'Preencha a data de início do evento.',
            'data_inicio.date' => 'A data de início não é válida.',
            'data_fim.date' => 'A data de fim não é válida.',
            'data_fim.after_or_equal' => 'A data de fim não pode ser anterior à data de início.',
            'hora_inicio.date_format' => 'A hora de início não é válida.',
            'hora_fim.date_format' => 'A hora de fim não é válida.',
            'tipo.required' => 'Selecione o tipo de evento.',
            'escaloes_elegiveis.*.exists' => 'Um dos escalões selecionados deixou de estar disponível.',
            'centro_custo_id.exists' => 'O centro de custo selecionado deixou de estar disponível.',
            'recorrencia_data_inicio.required' => 'Preencha a data de início da recorrência.',
            'recorrencia_data_inicio.after_or_equal' => 'A recorrência não pode começar antes do evento.',
            'recorrencia_data_fim.required' => 'Preencha a data de fim da recorrência.',
            'recorrencia_data_fim.after_or_equal' => 'A recorrência não pode terminar antes de começar.',
            'recorrencia_dias_semana.required' => 'Selecione pelo menos um dia da semana.',
            'recorrencia_dias_semana.min' => 'Selecione pelo menos um dia da semana.',
        ];
    }
}
