<?php

declare(strict_types=1);

namespace App\Http\Requests\Communication;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSocialNetworkAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['sometimes', Rule::in(['facebook', 'instagram'])],
            'display_name' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'external_account_id' => ['required', 'string', 'max:255'],
            'graph_api_version' => ['required', 'regex:/^v\d{1,2}\.\d$/', 'max:20'],
            'app_id' => ['nullable', 'string', 'max:255'],
            'app_secret' => ['nullable', 'string', 'max:4096'],
            'access_token' => ['nullable', 'string', 'max:8192'],
            'webhook_verify_token' => ['nullable', 'string', 'max:4096'],
            'is_enabled' => ['required', 'boolean'],
            'clear_app_secret' => ['nullable', 'boolean'],
            'clear_access_token' => ['nullable', 'boolean'],
            'clear_webhook_verify_token' => ['nullable', 'boolean'],
        ];
    }
}
