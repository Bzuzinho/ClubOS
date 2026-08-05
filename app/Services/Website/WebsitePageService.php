<?php

namespace App\Services\Website;

use App\Models\User;
use App\Models\WebsitePage;
use App\Models\WebsitePageVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebsitePageService
{
    public function __construct(
        private readonly WebsitePageTemplateService $templates,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): WebsitePage
    {
        return DB::transaction(function () use ($data, $actor): WebsitePage {
            $page = WebsitePage::query()->create([
                ...Arr::only($data, ['slug', 'title', 'navigation_label', 'show_in_navigation', 'sort_order', 'meta_title', 'meta_description']),
                'design_settings' => $this->normalizeDesignSettings($data['design_settings'] ?? []),
                'status' => 'draft',
                'is_system' => false,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->replaceBlocks($page, $this->templates->starterBlocks($page->slug));
            $this->recordVersion($page, 'created', $actor);

            return $page->fresh(['blocks', 'versions.creator']);
        });
    }

    public function importStarterBlocks(WebsitePage $page, User $actor): WebsitePage
    {
        return DB::transaction(function () use ($page, $actor): WebsitePage {
            $locked = WebsitePage::query()->lockForUpdate()->findOrFail($page->id);
            if ($locked->blocks()->exists()) {
                throw ValidationException::withMessages([
                    'blocks' => 'A página já tem conteúdo no editor.',
                ]);
            }

            $this->replaceBlocks($locked, $this->templates->starterBlocks($locked->slug));
            $locked->forceFill([
                'status' => $locked->published_snapshot ? $locked->status : 'draft',
                'updated_by' => $actor->id,
            ])->save();
            $this->recordVersion($locked, 'imported', $actor);

            return $locked->fresh(['blocks', 'versions.creator']);
        });
    }

    /** @param array<string, mixed> $data */
    public function save(WebsitePage $page, array $data, User $actor): WebsitePage
    {
        return DB::transaction(function () use ($page, $data, $actor): WebsitePage {
            $locked = WebsitePage::query()->lockForUpdate()->findOrFail($page->id);
            $operation = (string) $data['operation'];

            if (! $locked->is_system && $locked->published_snapshot && $locked->slug !== $data['slug']) {
                throw ValidationException::withMessages([
                    'slug' => 'O endereço de uma página já publicada fica bloqueado para não quebrar ligações existentes.',
                ]);
            }

            $attributes = Arr::only($data, [
                'title', 'navigation_label', 'show_in_navigation', 'sort_order', 'meta_title', 'meta_description',
            ]);
            $attributes['design_settings'] = $this->normalizeDesignSettings($data['design_settings'] ?? []);
            if (! $locked->is_system) {
                $attributes['slug'] = $data['slug'];
            }

            $locked->forceFill([
                ...$attributes,
                'updated_by' => $actor->id,
            ])->save();

            $this->replaceBlocks($locked, $data['blocks']);
            $snapshot = $this->draftSnapshot($locked->fresh('blocks'));

            if ($operation === 'publish') {
                $locked->forceFill([
                    'status' => 'published',
                    'published_snapshot' => $snapshot,
                    'published_at' => now(),
                    'scheduled_snapshot' => null,
                    'scheduled_for' => null,
                ])->save();
            } elseif ($operation === 'schedule') {
                $locked->forceFill([
                    'status' => 'scheduled',
                    'scheduled_snapshot' => $snapshot,
                    'scheduled_for' => $data['scheduled_for'],
                ])->save();
            } elseif ($operation === 'hide') {
                $locked->forceFill(['status' => 'hidden'])->save();
            } elseif (! $locked->published_snapshot) {
                $locked->forceFill(['status' => 'draft'])->save();
            }

            $this->recordVersion($locked, $operation, $actor, $snapshot);

            return $locked->fresh(['blocks', 'versions.creator']);
        });
    }

    public function restoreVersion(WebsitePage $page, WebsitePageVersion $version, User $actor): WebsitePage
    {
        abort_unless($version->website_page_id === $page->id, 404);

        return DB::transaction(function () use ($page, $version, $actor): WebsitePage {
            $locked = WebsitePage::query()->lockForUpdate()->findOrFail($page->id);
            $snapshot = $version->snapshot;
            $metadata = Arr::only($snapshot, ['title', 'navigation_label', 'show_in_navigation', 'sort_order', 'meta_title', 'meta_description', 'design_settings']);

            if (! $locked->is_system && ! $locked->published_snapshot && isset($snapshot['slug'])) {
                $metadata['slug'] = $snapshot['slug'];
            }

            $locked->forceFill([
                ...$metadata,
                'status' => $locked->published_snapshot ? $locked->status : 'draft',
                'updated_by' => $actor->id,
            ])->save();
            $this->replaceBlocks($locked, $snapshot['blocks'] ?? []);
            $this->recordVersion($locked, 'restored', $actor);

            return $locked->fresh(['blocks', 'versions.creator']);
        });
    }

    public function delete(WebsitePage $page): void
    {
        if ($page->is_system) {
            throw ValidationException::withMessages([
                'page' => 'As páginas essenciais podem ser ocultadas, mas não eliminadas.',
            ]);
        }

        $page->delete();
    }

    /** @return array<string, mixed> */
    public function draftSnapshot(WebsitePage $page): array
    {
        $page->loadMissing('blocks');

        return [
            'slug' => $page->slug,
            'title' => $page->title,
            'navigation_label' => $page->navigation_label,
            'show_in_navigation' => (bool) $page->show_in_navigation,
            'sort_order' => (int) $page->sort_order,
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'design_settings' => $this->normalizeDesignSettings($page->design_settings ?? []),
            'blocks' => $page->blocks->map(fn ($block): array => [
                'id' => $block->id,
                'block_key' => $block->block_key,
                'type' => $block->type,
                'sort_order' => (int) $block->sort_order,
                'is_visible' => (bool) $block->is_visible,
                'content' => $block->content,
                'style' => $this->normalizeBlockStyle($block->style ?? [], $block->type),
                'settings' => $this->normalizeBlockSettings($block->settings ?? []),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function publicSnapshot(WebsitePage $page): ?array
    {
        if ($page->status === 'hidden') {
            return null;
        }

        if ($page->scheduled_snapshot && $page->scheduled_for?->isPast()) {
            return $page->scheduled_snapshot;
        }

        return $page->published_snapshot;
    }

    public function shouldRenderLegacy(WebsitePage $page): bool
    {
        return $page->is_system && $page->status !== 'hidden' && $this->publicSnapshot($page) === null;
    }

    /** @return array<int, array{label: string, href: string}> */
    public function navigation(): array
    {
        /** @var Collection<int, WebsitePage> $pages */
        $pages = WebsitePage::query()
            ->where('status', '!=', 'hidden')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        return $pages
            ->map(function (WebsitePage $page): ?array {
                $snapshot = $this->publicSnapshot($page);
                if ($snapshot === null) {
                    return $this->shouldRenderLegacy($page) && $page->show_in_navigation
                        ? ['label' => $page->navigation_label ?: $page->title, 'href' => $page->publicPath(), 'sort_order' => $page->sort_order]
                        : null;
                }

                if (! ($snapshot['show_in_navigation'] ?? false)) {
                    return null;
                }

                $slug = (string) ($snapshot['slug'] ?? $page->slug);

                return [
                    'label' => (string) (($snapshot['navigation_label'] ?? null) ?: ($snapshot['title'] ?? $page->title)),
                    'href' => $slug === 'home' ? '/' : '/'.$slug,
                    'sort_order' => (int) ($snapshot['sort_order'] ?? $page->sort_order),
                ];
            })
            ->filter()
            ->sortBy('sort_order')
            ->map(fn (array $item): array => Arr::except($item, ['sort_order']))
            ->values()
            ->all();
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function replaceBlocks(WebsitePage $page, array $blocks): void
    {
        $ids = collect($blocks)->pluck('id')->filter()->unique()->all();
        $page->blocks()->when($ids !== [], fn ($query) => $query->whereNotIn('id', $ids))->delete();
        if ($ids === []) {
            $page->blocks()->delete();
        }

        foreach (array_values($blocks) as $index => $block) {
            $id = isset($block['id']) && $page->blocks()->whereKey($block['id'])->exists()
                ? $block['id']
                : (string) Str::uuid();

            $page->blocks()->updateOrCreate(
                ['id' => $id],
                [
                    'block_key' => $block['block_key'] ?: 'block-'.$id,
                    'type' => $block['type'],
                    'sort_order' => $index,
                    'is_visible' => (bool) ($block['is_visible'] ?? true),
                    'content' => $this->normalizeContent($block['content'] ?? []),
                    'style' => $this->normalizeBlockStyle($block['style'] ?? [], $block['type'] ?? null),
                    'settings' => $this->normalizeBlockSettings($block['settings'] ?? []),
                ]
            );
        }
    }

    /** @param array<string, mixed> $settings */
    private function normalizeDesignSettings(array $settings): array
    {
        return [
            'background_color' => $settings['background_color'] ?? '#ffffff',
            'text_color' => $settings['text_color'] ?? '#102c44',
            'heading_color' => $settings['heading_color'] ?? '#062b54',
            'accent_color' => $settings['accent_color'] ?? '#f2e613',
            'heading_font' => $settings['heading_font'] ?? 'inter',
            'body_font' => $settings['body_font'] ?? 'inter',
            'base_font_size' => (int) ($settings['base_font_size'] ?? 16),
            'content_width' => $settings['content_width'] ?? 'standard',
        ];
    }

    /** @param array<string, mixed> $style */
    private function normalizeBlockStyle(array $style, ?string $type = null): array
    {
        [$paddingTop, $paddingBottom] = match ($type) {
            'hero' => [18, 0],
            'rich_text', 'cards', 'news_feed', 'events_feed' => [68, 72],
            'stats' => [0, 0],
            'cta' => [66, 78],
            'contact_form' => [75, 90],
            'registration_form' => [70, 92],
            default => [64, 64],
        };

        return [
            'background_color' => $style['background_color'] ?? null,
            'text_color' => $style['text_color'] ?? null,
            'heading_color' => $style['heading_color'] ?? null,
            'accent_color' => $style['accent_color'] ?? null,
            'padding_top' => (int) ($style['padding_top'] ?? $paddingTop),
            'padding_bottom' => (int) ($style['padding_bottom'] ?? $paddingBottom),
            'content_width' => $style['content_width'] ?? 'page',
            'text_align' => $style['text_align'] ?? 'left',
            'heading_size' => (int) ($style['heading_size'] ?? 32),
            'body_size' => (int) ($style['body_size'] ?? 14),
            'border_radius' => (int) ($style['border_radius'] ?? 0),
            'shadow' => $style['shadow'] ?? 'none',
            'card_background' => $style['card_background'] ?? null,
            'card_border_color' => $style['card_border_color'] ?? null,
            'card_radius' => (int) ($style['card_radius'] ?? 15),
            'card_shadow' => $style['card_shadow'] ?? 'soft',
            'card_gap' => (int) ($style['card_gap'] ?? 14),
        ];
    }

    /** @param array<string, mixed> $settings */
    private function normalizeBlockSettings(array $settings): array
    {
        return [
            'anchor_id' => $settings['anchor_id'] ?? null,
            'animation' => $settings['animation'] ?? 'none',
            'animation_delay' => (int) ($settings['animation_delay'] ?? 0),
            'hide_mobile' => (bool) ($settings['hide_mobile'] ?? false),
            'hide_desktop' => (bool) ($settings['hide_desktop'] ?? false),
            'open_links_new_tab' => (bool) ($settings['open_links_new_tab'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeContent(array $content): array
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (is_array($value)) {
                return collect($value)->take(50)->map(fn (mixed $item): mixed => $normalize($item))->all();
            }

            if (is_string($value)) {
                return Str::limit(trim(str_replace("\0", '', $value)), 10_000, '');
            }

            return is_bool($value) || is_numeric($value) || $value === null ? $value : null;
        };

        return $normalize($content);
    }

    /** @param array<string, mixed>|null $snapshot */
    private function recordVersion(WebsitePage $page, string $action, User $actor, ?array $snapshot = null): void
    {
        $snapshot ??= $this->draftSnapshot($page->fresh('blocks'));
        $latest = $page->versions()->orderByDesc('version')->first();
        if ($action === 'autosave' && $latest && $latest->snapshot === $snapshot) {
            return;
        }

        $nextVersion = ((int) $page->versions()->max('version')) + 1;
        $page->versions()->create([
            'version' => $nextVersion,
            'action' => $action,
            'snapshot' => $snapshot,
            'created_by' => $actor->id,
        ]);
    }
}
