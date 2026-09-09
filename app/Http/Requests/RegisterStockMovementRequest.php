<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'article_id' => ['required', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'uuid', 'exists:product_variants,id'],
            'movement_type' => ['required', 'in:entry,exit,reservation,cancel_reservation,deliver_reservation,return'],
            'quantity' => ['required', 'integer', 'not_in:0'],
            'reference_type' => ['nullable', 'string', 'max:40'],
            'reference_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
