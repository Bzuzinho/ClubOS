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
            'design_settings.background_image' => ['nullable', 'string', 'max:2048'],
            'design_settings.background_position' => ['required', 'string', 'max:100'],
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
            'blocks.*.style.background_image' => ['nullable', 'string', 'max:2048'],
            'blocks.*.style.background_position' => ['required', 'string', 'max:100'],
            'blocks.*.style.heading_font' => ['required', Rule::in(['inherit', 'inter', 'poppins', 'montserrat', 'georgia', 'system'])],
            'blocks.*.style.body_font' => ['required', Rule::in(['inherit', 'inter', 'poppins', 'montserrat', 'georgia', 'system'])],
            'blocks.*.style.heading_weight' => ['required', 'integer', Rule::in([300, 400, 500, 600, 700, 800])],
            'blocks.*.style.body_weight' => ['required', 'integer', Rule::in([300, 400, 500, 600, 700, 800])],
            'blocks.*.style.line_height' => ['required', 'numeric', 'min:1', 'max:2.4'],
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
            $blocks = $this->input('blocks', []);
            $blocks = is_array($blocks) ? $blocks : [];
            $encoded = json_encode([
                'design_settings' => $this->input('design_settings', []),
                'blocks' => $blocks,
            ]);
            if ($encoded === false || strlen($encoded) > 240_000) {
                $validator->errors()->add('blocks', 'O conteúdo da página excede o limite permitido.');
            }

            $keys = collect($blocks)->pluck('block_key')->filter();
            if ($keys->count() !== $keys->unique()->count()) {
                $validator->errors()->add('blocks', 'Cada bloco tem de ter uma chave única.');
            }

            $anchors = collect($blocks)->pluck('settings.anchor_id')->filter();
            if ($anchors->count() !== $anchors->unique()->count()) {
                $validator->errors()->add('blocks', 'Cada âncora só pode ser usada uma vez na página.');
            }

            $elementIds = [];
            $elementCount = 0;
            foreach ($blocks as $blockIndex => $block) {
                if (! is_array($block)) {
                    continue;
                }

                $blockContent = is_array($block['content'] ?? null) ? $block['content'] : [];
                if (array_key_exists('elements', $blockContent)) {
                    $this->validateElementTree(
                        $validator,
                        $blockContent['elements'],
                        "blocks.$blockIndex.content.elements",
                        0,
                        $elementIds,
                        $elementCount,
                    );
                }

                if (in_array($block['type'] ?? null, ['news_feed', 'events_feed'], true)) {
                    $source = is_array($block['content'] ?? null) ? ($block['content']['source'] ?? null) : null;
                    if (! in_array($source, ['news', 'events', 'partners'], true)) {
                        $validator->errors()->add("blocks.$blockIndex.content.source", 'Escolhe uma origem de dados válida.');
                    }
                }

                if (($block['type'] ?? null) !== 'section') {
                    continue;
                }

                $content = is_array($block['content'] ?? null) ? $block['content'] : [];
                $items = $content['items'] ?? [];
                if (! is_array($items) || count($items) > 30) {
                    $validator->errors()->add("blocks.$blockIndex.content.items", 'Cada secção pode ter até 30 elementos.');
                    continue;
                }

                foreach (['columns_desktop' => [1, 6], 'columns_tablet' => [1, 4], 'columns_mobile' => [1, 2], 'gap' => [0, 80]] as $field => [$minimum, $maximum]) {
                    $value = $content[$field] ?? null;
                    if (! is_numeric($value) || (int) $value < $minimum || (int) $value > $maximum) {
                        $validator->errors()->add("blocks.$blockIndex.content.$field", 'A organização da secção contém um valor inválido.');
                    }
                }

                if (! in_array($content['align_items'] ?? null, ['stretch', 'start', 'center', 'end'], true)) {
                    $validator->errors()->add("blocks.$blockIndex.content.align_items", 'Escolhe um alinhamento válido para a secção.');
                }

                $itemIds = [];
                foreach ($items as $itemIndex => $item) {
                    $path = "blocks.$blockIndex.content.items.$itemIndex";
                    if (! is_array($item)) {
                        $validator->errors()->add($path, 'O elemento da secção é inválido.');
                        continue;
                    }

                    $itemId = $item['id'] ?? null;
                    if (! is_string($itemId) || preg_match('/\Aelement-[a-zA-Z0-9-]+\z/', $itemId) !== 1 || in_array($itemId, $itemIds, true)) {
                        $validator->errors()->add("$path.id", 'Cada elemento da secção tem de ter um identificador único.');
                    } else {
                        $itemIds[] = $itemId;
                    }

                    $itemType = $item['type'] ?? null;
                    if (! in_array($itemType, ['subsection', 'card', 'text', 'image', 'button', 'data_collection'], true)) {
                        $validator->errors()->add("$path.type", 'Escolhe um tipo de elemento válido.');
                    }

                    if (! is_array($item['content'] ?? null) || ! is_array($item['style'] ?? null) || ! is_array($item['settings'] ?? null)) {
                        $validator->errors()->add($path, 'O conteúdo, estilo e comportamento do elemento são obrigatórios.');
                        continue;
                    }

                    if ($itemType === 'data_collection' && ! in_array($item['content']['source'] ?? null, ['news', 'events', 'partners'], true)) {
                        $validator->errors()->add("$path.content.source", 'Escolhe uma origem de dados válida.');
                    }
                }
            }

            $operation = $this->input('operation');
            $visibleBlocks = collect($blocks)->where('is_visible', true);
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
            if ($requiredType && in_array($operation, ['publish', 'schedule'], true)) {
                $hasRequiredForm = $visibleBlocks->contains(function (mixed $block) use ($requiredType): bool {
                    if (! is_array($block) || ($block['type'] ?? null) !== $requiredType) {
                        return false;
                    }

                    $content = is_array($block['content'] ?? null) ? $block['content'] : [];

                    return ! array_key_exists('elements', $content)
                        || $this->elementTreeContainsVisibleType($content['elements'], $requiredType);
                });

                if (! $hasRequiredForm) {
                    $validator->errors()->add('blocks', 'Esta página tem de manter o respetivo formulário público visível.');
                }
            }
        });
    }

    /** @param array<int, string> $ids */
    private function validateElementTree(Validator $validator, mixed $items, string $path, int $depth, array &$ids, int &$count): void
    {
        if (! is_array($items)) {
            $validator->errors()->add($path, 'A estrutura de elementos é inválida.');

            return;
        }

        if ($depth > 4) {
            $validator->errors()->add($path, 'A estrutura só pode ter cinco níveis.');

            return;
        }

        if (count($items) > 30) {
            $validator->errors()->add($path, 'Cada contentor pode ter até 30 elementos.');
        }

        $types = [
            'container', 'subsection', 'card', 'text', 'eyebrow', 'heading', 'paragraph', 'rich_text',
            'image', 'button', 'link', 'divider', 'spacer', 'data_collection', 'contact_form', 'registration_form',
        ];

        foreach (array_slice($items, 0, 30) as $index => $item) {
            $itemPath = "$path.$index";
            if (! is_array($item)) {
                $validator->errors()->add($itemPath, 'O elemento é inválido.');
                continue;
            }

            $count++;
            if ($count > 120) {
                $validator->errors()->add($path, 'A página pode ter até 120 elementos editáveis.');
                return;
            }

            $id = $item['id'] ?? null;
            if (! is_string($id) || preg_match('/\Aelement-[a-zA-Z0-9-]+\z/', $id) !== 1 || in_array($id, $ids, true)) {
                $validator->errors()->add("$itemPath.id", 'Cada elemento tem de ter um identificador único.');
            } else {
                $ids[] = $id;
            }

            $type = $item['type'] ?? null;
            if (! in_array($type, $types, true)) {
                $validator->errors()->add("$itemPath.type", 'Escolhe um tipo de elemento válido.');
            }

            foreach (['content', 'style', 'settings'] as $field) {
                if (! is_array($item[$field] ?? null)) {
                    $validator->errors()->add("$itemPath.$field", 'O conteúdo, estilo e comportamento do elemento são obrigatórios.');
                }
            }

            $itemContent = is_array($item['content'] ?? null) ? $item['content'] : [];
            if ($type === 'data_collection' && ! in_array($itemContent['source'] ?? null, ['news', 'events', 'partners'], true)) {
                $validator->errors()->add("$itemPath.content.source", 'Escolhe uma origem de dados válida.');
            }

            $children = $item['children'] ?? [];
            if (! is_array($children)) {
                $validator->errors()->add("$itemPath.children", 'Os elementos internos são inválidos.');
                continue;
            }

            if ($children !== []) {
                if (! in_array($type, ['container', 'subsection', 'card'], true)) {
                    $validator->errors()->add("$itemPath.children", 'Apenas contentores, subsecções e cards podem receber elementos internos.');
                    continue;
                }

                $this->validateElementTree($validator, $children, "$itemPath.children", $depth + 1, $ids, $count);
            }
        }
    }

    private function elementTreeContainsVisibleType(mixed $items, string $type): bool
    {
        if (! is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (! is_array($item) || ($item['is_visible'] ?? true) === false) {
                continue;
            }
            if (($item['type'] ?? null) === $type || $this->elementTreeContainsVisibleType($item['children'] ?? [], $type)) {
                return true;
            }
        }

        return false;
    }
}
