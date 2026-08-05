<?php

namespace App\Http\Requests;

use App\Models\WebsitePage;
use App\Models\WebsitePageBlock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class WebsitePageDataRequest extends FormRequest
{
    public const RESERVED_SLUGS = [
        'api', 'admin', 'dashboard', 'login', 'logout', 'register', 'password', 'email', 'profile',
        'portal', 'membros', 'financeiro', 'eventos', 'desportivo', 'comunicacao', 'configuracoes',
        'logistica', 'loja', 'patrocinios', 'website-redes', 'storage', 'build', 'icons', 'manifest.webmanifest',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'slug' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(self::RESERVED_SLUGS),
                Rule::unique('website_pages', 'slug')->ignore($this->route('page')),
            ],
            'navigation_label' => ['nullable', 'string', 'max:80'],
            'show_in_navigation' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'meta_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'operation' => ['required', Rule::in(['save_draft', 'publish', 'schedule', 'hide'])],
            'scheduled_for' => ['nullable', 'date', 'after:now', 'required_if:operation,schedule'],
            'blocks' => ['present', 'array', 'max:40'],
            'blocks.*.id' => ['nullable', 'uuid', 'distinct'],
            'blocks.*.block_key' => ['required', 'string', 'max:100'],
            'blocks.*.type' => ['required', Rule::in(WebsitePageBlock::TYPES)],
            'blocks.*.is_visible' => ['required', 'boolean'],
            'blocks.*.content' => ['required', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $encoded = json_encode($this->input('blocks', []));
            if ($encoded === false || strlen($encoded) > 180_000) {
                $validator->errors()->add('blocks', 'O conteúdo da página excede o limite permitido.');
            }

            $keys = collect($this->input('blocks', []))->pluck('block_key')->filter();
            if ($keys->count() !== $keys->unique()->count()) {
                $validator->errors()->add('blocks', 'Cada bloco tem de ter uma chave única.');
            }

            $operation = $this->input('operation');
            $visibleBlocks = collect($this->input('blocks', []))->where('is_visible', true);
            if (in_array($operation, ['publish', 'schedule'], true) && $visibleBlocks->isEmpty()) {
                $validator->errors()->add('blocks', 'Adiciona pelo menos um bloco visível antes de publicar.');
            }

            $page = $this->route('page');
            $slug = $page instanceof WebsitePage ? $page->slug : null;
            $requiredType = match ($slug) {
                'junta-te' => 'contact_form',
                'inscricao' => 'registration_form',
                default => null,
            };
            if ($requiredType && in_array($operation, ['publish', 'schedule'], true) && ! $visibleBlocks->contains('type', $requiredType)) {
                $validator->errors()->add('blocks', 'Esta página tem de manter o respetivo formulário público visível.');
            }
        });
    }
}
