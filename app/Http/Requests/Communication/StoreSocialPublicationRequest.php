<?php

declare(strict_types=1);

namespace App\Http\Requests\Communication;

use App\Models\SocialNetworkAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSocialPublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:63206'],
            'providers' => ['required', 'array', 'min:1'],
            'providers.*' => ['required', 'distinct', Rule::in(['facebook', 'instagram'])],
            'link_url' => ['nullable', 'url:http,https', 'max:2048'],
            'media_url' => ['nullable', 'url:http,https', 'max:2048'],
            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
            'submission_mode' => ['required', Rule::in(['send', 'schedule'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $providers = is_array($this->input('providers')) ? $this->input('providers') : [];

            if (in_array('instagram', $providers, true) && ! $this->filled('media_url')) {
                $validator->errors()->add('media_url', 'Instagram exige uma URL pública de imagem.');
            }
            if (in_array('instagram', $providers, true) && $this->filled('media_url') && ! str_starts_with((string) $this->input('media_url'), 'https://')) {
                $validator->errors()->add('media_url', 'A imagem do Instagram tem de estar disponível por HTTPS.');
            }
            if ($this->input('submission_mode') === 'schedule' && ! $this->filled('scheduled_at')) {
                $validator->errors()->add('scheduled_at', 'Indique a data e hora da publicação.');
            }

            $configured = SocialNetworkAccount::query()
                ->whereIn('provider', $providers)
                ->get()
                ->filter->isPublishReady()
                ->pluck('provider');
            foreach ($providers as $provider) {
                if (! $configured->contains($provider)) {
                    $validator->errors()->add('providers', ucfirst((string) $provider).' ainda não tem uma conta ativa com credenciais nas Definições.');
                }
            }
        });
    }
}
