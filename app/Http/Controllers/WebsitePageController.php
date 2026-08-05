<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWebsitePageRequest;
use App\Http\Requests\WebsitePageDataRequest;
use App\Models\WebsiteMedia;
use App\Models\WebsitePage;
use App\Models\WebsitePageVersion;
use App\Services\Website\WebsiteMediaService;
use App\Services\Website\WebsitePageService;
use App\Services\Website\WebsitePublicDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WebsitePageController extends Controller
{
    public function __construct(
        private readonly WebsitePageService $pages,
        private readonly WebsitePublicDataService $publicData,
        private readonly WebsiteMediaService $mediaService,
    ) {
    }

    public function index(): Response
    {
        $pages = WebsitePage::query()
            ->withCount('blocks')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->map(fn (WebsitePage $page): array => $this->summaryPayload($page));

        return Inertia::render('WebsiteRedes/Pages/Index', [
            'pages' => $pages,
            'summary' => [
                'total' => $pages->count(),
                'published' => $pages->whereIn('status', ['published', 'scheduled'])->count(),
                'draft' => $pages->whereIn('status', ['legacy', 'draft'])->count(),
                'hidden' => $pages->where('status', 'hidden')->count(),
            ],
        ]);
    }

    public function store(StoreWebsitePageRequest $request): RedirectResponse
    {
        $page = $this->pages->create($request->validated(), $request->user());

        return redirect()->route('website-redes.pages.edit', $page)
            ->with('success', 'Página criada em rascunho.');
    }

    public function edit(WebsitePage $page): Response
    {
        $page->load(['blocks', 'versions.creator']);
        $media = WebsiteMedia::query()->latest()->limit(100)->get();
        $usage = $this->mediaService->usageMap($media);

        return Inertia::render('WebsiteRedes/Pages/Edit', [
            'page' => $this->editorPayload($page),
            'media' => $media->map(fn (WebsiteMedia $item): array => $this->mediaPayload($item, $usage->get($item->id, false))),
            'blockTypes' => [
                ['value' => 'hero', 'label' => 'Hero'],
                ['value' => 'rich_text', 'label' => 'Texto'],
                ['value' => 'cards', 'label' => 'Cards'],
                ['value' => 'image_text', 'label' => 'Imagem + texto'],
                ['value' => 'stats', 'label' => 'Indicadores'],
                ['value' => 'cta', 'label' => 'Chamada para ação'],
                ['value' => 'news_feed', 'label' => 'Notícias automáticas'],
                ['value' => 'events_feed', 'label' => 'Eventos automáticos'],
                ['value' => 'contact_form', 'label' => 'Formulário de contacto'],
                ['value' => 'registration_form', 'label' => 'Formulário de inscrição'],
            ],
        ]);
    }

    public function import(WebsitePage $page, Request $request): RedirectResponse
    {
        $this->pages->importStarterBlocks($page, $request->user());

        return back()->with('success', 'Conteúdo atual importado para o editor. O website público ainda não foi alterado.');
    }

    public function update(WebsitePageDataRequest $request, WebsitePage $page): RedirectResponse
    {
        $this->pages->save($page, $request->validated(), $request->user());

        $message = match ($request->validated('operation')) {
            'publish' => 'Página publicada.',
            'schedule' => 'Publicação agendada.',
            'hide' => 'Página ocultada do website.',
            default => 'Rascunho guardado.',
        };

        return back()->with('success', $message);
    }

    public function preview(WebsitePage $page): Response
    {
        $snapshot = $this->pages->draftSnapshot($page->load('blocks'));

        return Inertia::render('PublicSite/ManagedPage', [
            'page' => $snapshot,
            'news' => $this->publicData->news(30),
            'events' => $this->publicData->events(60),
            'publicNavigation' => $this->pages->navigation(),
            'preview' => true,
        ]);
    }

    public function restore(WebsitePage $page, WebsitePageVersion $version, Request $request): RedirectResponse
    {
        $this->pages->restoreVersion($page, $version, $request->user());

        return back()->with('success', 'Versão recuperada para o rascunho. Publica quando estiver validada.');
    }

    public function destroy(WebsitePage $page): RedirectResponse
    {
        $this->pages->delete($page);

        return redirect()->route('website-redes.pages.index')->with('success', 'Página eliminada.');
    }

    /** @return array<string, mixed> */
    private function summaryPayload(WebsitePage $page): array
    {
        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'title' => $page->title,
            'navigation_label' => $page->navigation_label,
            'status' => $page->status,
            'is_system' => (bool) $page->is_system,
            'show_in_navigation' => (bool) $page->show_in_navigation,
            'sort_order' => (int) $page->sort_order,
            'blocks_count' => (int) $page->blocks_count,
            'published_at' => $page->published_at?->toISOString(),
            'scheduled_for' => $page->scheduled_for?->toISOString(),
            'public_url' => $page->publicPath(),
            'edit_url' => route('website-redes.pages.edit', $page),
        ];
    }

    /** @return array<string, mixed> */
    private function editorPayload(WebsitePage $page): array
    {
        return [
            ...$this->summaryPayload($page->loadCount('blocks')),
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'has_published_version' => $page->published_snapshot !== null,
            'blocks' => $page->blocks->map(fn ($block): array => [
                'id' => $block->id,
                'block_key' => $block->block_key,
                'type' => $block->type,
                'is_visible' => (bool) $block->is_visible,
                'content' => $block->content,
            ])->values(),
            'versions' => $page->versions->take(20)->map(fn (WebsitePageVersion $version): array => [
                'id' => $version->id,
                'version' => $version->version,
                'action' => $version->action,
                'created_at' => $version->created_at?->toISOString(),
                'created_by' => $version->creator?->name,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function mediaPayload(WebsiteMedia $media, bool $inUse): array
    {
        return [
            'id' => $media->id,
            'url' => $media->url,
            'alt_text' => $media->alt_text,
            'original_name' => $media->original_name,
            'width' => $media->width,
            'height' => $media->height,
            'size' => $media->size,
            'in_use' => $inUse,
        ];
    }
}
