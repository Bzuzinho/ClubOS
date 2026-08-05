<?php

namespace App\Services\Website;

use App\Models\Event;
use App\Models\NewsItem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WebsitePublicDataService
{
    /** @return array<int, array<string, mixed>> */
    public function news(int $limit): array
    {
        return NewsItem::query()
            ->where('data_publicacao', '<=', now())
            ->orderByDesc('destaque')
            ->orderByDesc('data_publicacao')
            ->limit(max(1, min($limit, 30)))
            ->get()
            ->map(fn (NewsItem $item): array => [
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
    public function events(int $limit): array
    {
        return Event::query()
            ->where('visibilidade', 'publico')
            ->whereDate('data_inicio', '>=', today())
            ->whereNotIn('estado', ['rascunho', 'cancelado'])
            ->where('tipo', '!=', 'treino')
            ->orderBy('data_inicio')
            ->orderBy('hora_inicio')
            ->limit(max(1, min($limit, 60)))
            ->get(['id', 'titulo', 'descricao', 'data_inicio', 'data_fim', 'hora_inicio', 'local', 'tipo', 'estado'])
            ->map(fn (Event $event): array => [
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
