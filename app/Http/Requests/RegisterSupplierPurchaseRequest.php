<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterSupplierPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'invoice_reference' => ['required', 'string', 'max:255'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'centro_custo_id' => ['nullable', 'exists:cost_centers,id'],
            'metodo_pagamento' => ['nullable', 'string', 'max:50'],
            'document_type' => ['nullable', 'in:invoice,receipt,invoice_receipt,payment_proof,other'],
            'vat_amount' => ['nullable', 'numeric'],
            'attachment' => ['nullable', 'file'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.article_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }
}
