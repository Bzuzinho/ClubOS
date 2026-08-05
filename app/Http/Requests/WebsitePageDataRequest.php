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
        'logistica', 'loja', 'patrocinios', 'website', 'website-redes', 'storage', 'build', 'icons', 'manifest.webmanifest',
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
            'design_settings' => ['required', 'array'],
            'design_settings.background_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'design_settings.text_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'design_settings.heading_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'design_settings.accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'design_settings.heading_font' => ['required', Rule::in(['inter', 'poppins', 'montserrat', 'georgia', 'system'])],
            'design_settings.body_font' => ['required', Rule::in(['inter', 'poppins', 'montserrat', 'georgia', 'system'])],
            'design_settings.base_font_size' => ['required', 'integer', 'min:12', 'max:22'],
            'design_settings.content_width' => ['required', Rule::in(['compact', 'standard', 'wide'])],
            'operation' => ['required', Rule::in(['autosave', 'save_draft', 'publish', 'schedule', 'hide'])],
            'scheduled_for' => ['nullable', 'date', 'after:now', 'required_if:operation,schedule'],
            'blocks' => ['present', 'array', 'max:40'],
            'blocks.*.id' => ['nullable', 'uuid', 'distinct'],
            'blocks.*.block_key' => ['required', 'string', 'max:100'],
            'blocks.*.type' => ['required', Rule::in(WebsitePageBlock::TYPES)],
            'blocks.*.is_visible' => ['required', 'boolean'],
            'blocks.*.content' => ['required', 'array'],
            'blocks.*.style' => ['required', 'array'],
            'blocks.*.style.background_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'blocks.*.style.text_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'blocks.*.style.heading_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'blocks.*.style.accent_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'blocks.*.style.padding_top' => ['required', 'integer', 'min:0', 'max:180'],
            'blocks.*.style.padding_bottom' => ['required', 'integer', 'min:0', 'max:180'],
            'blocks.*.style.content_width' => ['required', Rule::in(['page', 'compact', 'wide', 'full'])],
            'blocks.*.style.text_align' => ['required', Rule::in(['left', 'center', 'right'])],
            'blocks.*.style.heading_size' => ['required', 'integer', 'min:18', 'max:88'],
            'blocks.*.style.body_size' => ['required', 'integer', 'min:10', 'max:28'],
            'blocks.*.style.border_radius' => ['required', 'integer', 'min:0', 'max:48'],
            'blocks.*.style.shadow' => ['required', Rule::in(['none', 'soft', 'medium', 'strong'])],
            'blocks.*.style.card_background' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'blocks.*.style.card_border_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'blocks.*.style.card_radius' => ['required', 'integer', 'min:0', 'max:48'],
            'blocks.*.style.card_shadow' => ['required', Rule::in(['none', 'soft', 'medium', 'strong'])],
            'blocks.*.style.card_gap' => ['required', 'integer', 'min:0', 'max:64'],
            'blocks.*.settings' => ['required', 'array'],
            'blocks.*.settings.anchor_id' => ['nullable', 'string', 'max:80', 'regex:/^[a-z][a-z0-9-]*$/'],
            'blocks.*.settings.animation' => ['required', Rule::in(['none', 'fade', 'slide-up', 'zoom'])],
            'blocks.*.settings.animation_delay' => ['required', 'integer', 'min:0', 'max:2000'],
            'blocks.*.settings.hide_mobile' => ['required', 'boolean'],
            'blocks.*.settings.hide_desktop' => ['required', 'boolean'],
            'blocks.*.settings.open_links_new_tab' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $encoded = json_encode([
                'design_settings' => $this->input('design_settings', []),
                'blocks' => $this->input('blocks', []),
            ]);
            if ($encoded === false || strlen($encoded) > 240_000) {
                $validator->errors()->add('blocks', 'O conteúdo da página excede o limite permitido.');
            }

            $keys = collect($this->input('blocks', []))->pluck('block_key')->filter();
            if ($keys->count() !== $keys->unique()->count()) {
                $validator->errors()->add('blocks', 'Cada bloco tem de ter uma chave única.');
            }

            $anchors = collect($this->input('blocks', []))->pluck('settings.anchor_id')->filter();
            if ($anchors->count() !== $anchors->unique()->count()) {
                $validator->errors()->add('blocks', 'Cada âncora só pode ser usada uma vez na página.');
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
