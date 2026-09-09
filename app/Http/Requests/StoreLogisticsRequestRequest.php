<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLogisticsRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'requester_user_id' => ['nullable', 'exists:users,id'],
            'requester_name_snapshot' => ['required', 'string', 'max:255'],
            'requester_area' => ['nullable', 'string', 'max:100'],
            'requester_type' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:draft,pending'],
            'allow_overdraw' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.article_id' => ['required', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
