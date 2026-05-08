<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Dados pessoais
            'nome_completo' => ['required', 'string', 'max:255'],
            'email_utilizador' => [
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email_utilizador')->ignore($this->member),
                Rule::unique('users', 'email')->ignore($this->member),
            ],
            'password' => ['nullable', 'string', 'min:8'],
            'numero_socio' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('users', 'numero_socio')->ignore($this->member),
            ],
            'nif' => ['nullable', 'string', 'max:50'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'data_nascimento' => ['nullable', 'date'],
            'data_inscricao' => ['nullable', 'date'],
            'sexo' => ['required', 'in:masculino,feminino'],
            'menor' => ['boolean'],

            // Morada
            'morada' => ['nullable', 'string'],
            'codigo_postal' => ['nullable', 'string', 'max:10'],
            'localidade' => ['nullable', 'string', 'max:255'],
            'nacionalidade' => ['nullable', 'string', 'max:255'],
            'estado_civil' => ['nullable', 'string'],

            // Tipo e estado
            'tipo_membro' => ['nullable', 'array'],
            'tipo_membro.*' => ['string'],
            'estado' => ['required', 'in:ativo,inativo,suspenso'],
            'perfil' => ['nullable', 'string'],

            // Dados desportivos
            'escalao' => ['nullable', 'array'],
            'escalao.*' => ['exists:age_groups,id'],
            'escalao_id' => ['nullable', 'exists:age_groups,id'],
            'ativo_desportivo' => ['boolean'],
            'num_federacao' => ['nullable', 'string'],
            'data_atestado_medico' => ['nullable', 'date'],
            'informacoes_medicas' => ['nullable', 'string'],

            // Encarregados de educação
            'encarregado_educacao' => ['nullable', 'array'],
            'encarregado_educacao.*' => ['exists:users,id'],
            'educandos' => ['nullable', 'array'],
            'educandos.*' => ['exists:users,id'],
            'sync_encarregado_educacao' => ['sometimes', 'boolean'],
            'sync_educandos' => ['sometimes', 'boolean'],

            // RGPD e documentos
            'rgpd' => ['boolean'],
            'consentimento' => ['boolean'],
            'afiliacao' => ['boolean'],
            'declaracao_de_transporte' => ['boolean'],

            // Ficheiros (base64)
            'foto_perfil' => ['nullable', 'string'],
            'cartao_federacao' => ['nullable', 'string'],
            'arquivo_rgpd' => ['nullable', 'string'],
            'arquivo_consentimento' => ['nullable', 'string'],
            'arquivo_afiliacao' => ['nullable', 'string'],
            'declaracao_transporte' => ['nullable', 'string'],

            // Outros
            'notas' => ['nullable', 'string'],
            'ocupacao' => ['nullable', 'string'],
            'empresa' => ['nullable', 'string'],
            'escola' => ['nullable', 'string'],
            'email_secundario' => ['nullable', 'email'],
            'cc' => ['nullable', 'string'],
            'numero_irmaos' => ['nullable', 'integer'],
            'tipo_mensalidade' => ['nullable', 'exists:monthly_fees,id'],
            'discount_type' => ['nullable', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'centro_custo' => ['nullable', 'array'],
            'centro_custo.*' => ['exists:cost_centers,id'],
            'conta_corrente_manual' => ['nullable', 'numeric'],
        ];
    }

    public function messages(): array
    {
        return [
            'nome_completo.required' => 'O nome completo é obrigatório.',
            'email_utilizador.email' => 'O email deve ser válido.',
            'email_utilizador.unique' => 'Este email já está em uso.',
            'sexo.required' => 'O sexo é obrigatório.',
            'estado.required' => 'O estado é obrigatório.',
        ];
    }
}