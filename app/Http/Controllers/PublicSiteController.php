<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\NewsItem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PublicSiteController extends Controller
{
    private const PAGES = [
        'clube' => 'Clube',
        'competicao' => 'Competicao',
        'treinos' => 'Treinos',
        'noticias' => 'Noticias',
        'calendario' => 'Calendario',
        'parceiros' => 'Parceiros',
        'contactos' => 'Contactos',
        'junta-te' => 'JuntaTe',
        'inscricao' => 'Inscricao',
        'privacidade' => 'Privacidade',
    ];

    public function home(): Response
    {
        return Inertia::render('PublicSite/Home', [
            'news' => $this->news(3),
            'events' => $this->events(3),
        ]);
    }

    public function show(string $page): Response
    {
        abort_unless(isset(self::PAGES[$page]), 404);

        $props = match ($page) {
            'noticias' => ['news' => $this->news(12)],
            'calendario' => ['events' => $this->events(30)],
            default => [],
        };

        return Inertia::render('PublicSite/'.self::PAGES[$page], $props);
    }

    /** @return array<int, array<string, mixed>> */
    private function news(int $limit): array
    {
        return NewsItem::query()
            ->where('data_publicacao', '<=', now())
            ->orderByDesc('destaque')
            ->orderByDesc('data_publicacao')
            ->limit($limit)
            ->get()
            ->map(fn (NewsItem $item) => [
                'id' => (string) $item->id,
                'title' => $item->titulo,
                'excerpt' => Str::limit(trim(strip_tags((string) $item->conteudo)), 190),
                'image' => $this->publicImageUrl($item->imagem),
                'featured' => (bool) $item->destaque,
                'publishedAt' => $item->data_publicacao?->toIso8601String(),
                'category' => collect($item->categorias)->filter()->first() ?: 'Clube',
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function events(int $limit): array
    {
        return Event::query()
            ->where('visibilidade', 'publico')
            ->whereDate('data_inicio', '>=', today())
            ->whereNotIn('estado', ['rascunho', 'cancelado'])
            ->where('tipo', '!=', 'treino')
            ->orderBy('data_inicio')
            ->orderBy('hora_inicio')
            ->limit($limit)
            ->get(['id', 'titulo', 'descricao', 'data_inicio', 'data_fim', 'hora_inicio', 'local', 'tipo', 'estado'])
            ->map(fn (Event $event) => [
                'id' => (string) $event->id,
                'title' => $event->titulo,
                'description' => Str::limit(trim(strip_tags((string) $event->descricao)), 180),
                'startDate' => $event->data_inicio?->toDateString(),
                'endDate' => $event->data_fim?->toDateString(),
                'startTime' => $event->hora_inicio ? substr((string) $event->hora_inicio, 0, 5) : null,
                'place' => $event->local,
                'type' => $event->tipo,
            ])
            ->all();
    }

    private function publicImageUrl(?string $image): ?string
    {
        $image = trim((string) $image);

        if ($image === '') {
            return null;
        }

        if (Str::startsWith($image, ['http://', 'https://', '/'])) {
            return $image;
        }

        return Storage::url($image);
    }
}
