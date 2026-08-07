<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'data_emissao' => ['required', 'date'],
            'data_vencimento' => ['required', 'date', 'after_or_equal:data_emissao'],
            'data_fatura' => ['nullable', 'date'],
            'mes' => ['nullable', 'string', 'max:20'],
            'tipo' => ['required', 'exists:invoice_types,codigo'],
            'estado_pagamento' => ['nullable', 'in:pendente,pago,vencido,parcial,cancelado'],
            'valor_total' => ['required', 'numeric', 'min:0'],
            'oculta' => ['nullable', 'boolean'],
            'centro_custo_id' => ['nullable', 'exists:cost_centers,id'],
            'referencia_pagamento' => ['nullable', 'string', 'max:255'],
            'origem_tipo' => ['nullable', 'in:evento,stock,patrocinio,manual,monthly_fee'],
            'origem_id' => ['nullable', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.descricao' => ['required', 'string', 'max:255'],
            'items.*.quantidade' => ['required', 'integer', 'min:1'],
            'items.*.valor_unitario' => ['required', 'numeric', 'min:0'],
            'items.*.imposto_percentual' => ['nullable', 'numeric', 'min:0'],
            'items.*.total_linha' => ['required', 'numeric', 'min:0'],
            'items.*.produto_id' => ['nullable', 'exists:products,id'],
            'items.*.centro_custo_id' => ['nullable', 'exists:cost_centers,id'],
        ];
    }
}
