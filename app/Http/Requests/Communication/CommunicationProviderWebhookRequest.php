<?php

declare(strict_types=1);

namespace App\Http\Requests\Communication;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CommunicationProviderWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:255'],
            'message_id' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(['delivered', 'failed', 'read'])],
            'occurred_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
