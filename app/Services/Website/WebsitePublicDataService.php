<?php

namespace App\Services\Website;

use App\Models\AthleteSportsData;
use App\Models\ConvocationGroup;
use App\Models\Event;
use App\Models\NewsItem;
use App\Models\Sponsor;
use App\Models\Training;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WebsitePublicDataService
{
    /** @return array<int, array<string, mixed>> */
    public function dataSources(): array
    {
        return [
            [
                'value' => 'news',
                'label' => 'Notícias publicadas',
                'description' => 'Notícias cuja data de publicação já foi atingida, ordenadas por destaque e data.',
                'emptyMessage' => 'Ainda não existem notícias publicadas.',
                'supportsImage' => true,
                'supportsLink' => true,
                'defaultLayout' => 'grid',
            ],
            [
                'value' => 'events',
                'label' => 'Próximos eventos públicos',
                'description' => 'Eventos futuros visíveis ao público, excluindo treinos, rascunhos e cancelados.',
                'emptyMessage' => 'Ainda não existem eventos públicos futuros.',
                'supportsImage' => false,
                'supportsLink' => true,
                'defaultLayout' => 'list',
            ],
            [
                'value' => 'convocations',
                'label' => 'Convocatórias públicas',
                'description' => 'Convocatórias de eventos públicos futuros. Nunca expõe nomes nem respostas individuais dos atletas.',
                'emptyMessage' => 'Ainda não existem convocatórias públicas futuras.',
                'supportsImage' => false,
                'supportsLink' => true,
                'defaultLayout' => 'list',
            ],
            [
                'value' => 'partners',
                'label' => 'Parceiros ativos',
                'description' => 'Parceiros ativos dentro do período contratual, sem contactos internos nem valores de patrocínio.',
                'emptyMessage' => 'Ainda não existem parceiros públicos ativos.',
                'supportsImage' => true,
                'supportsLink' => true,
                'defaultLayout' => 'grid',
            ],
            [
                'value' => 'statistics',
                'label' => 'Estatísticas do clube',
                'description' => 'Indicadores agregados calculados em tempo real a partir dos dados do ClubOS.',
                'emptyMessage' => 'Ainda não existem indicadores disponíveis.',
                'supportsImage' => false,
                'supportsLink' => false,
                'defaultLayout' => 'metrics',
            ],
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function dynamicData(): array
    {
        return [
            'news' => $this->news(30),
            'events' => $this->events(60),
            'partners' => $this->partners(60),
            'convocations' => $this->convocations(60),
            'statistics' => $this->statistics(),
        ];
    }

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

    /** @return array<int, array<string, mixed>> */
    public function partners(int $limit): array
    {
        return Sponsor::query()
            ->where('estado', 'ativo')
            ->whereDate('data_inicio', '<=', today())
            ->where(function ($query): void {
                $query->whereNull('data_fim')
                    ->orWhereDate('data_fim', '>=', today());
            })
            ->orderByRaw("CASE tipo WHEN 'principal' THEN 1 WHEN 'secundario' THEN 2 ELSE 3 END")
            ->orderBy('nome')
            ->limit(max(1, min($limit, 60)))
            ->get(['id', 'nome', 'descricao', 'logo', 'website', 'tipo'])
            ->map(fn (Sponsor $sponsor): array => [
                'id' => (string) $sponsor->id,
                'name' => $sponsor->nome,
                'description' => Str::limit(trim(strip_tags((string) $sponsor->descricao)), 180),
                'logo' => $this->publicImageUrl($sponsor->logo),
                'website' => $this->publicLinkUrl($sponsor->website),
                'type' => $sponsor->tipo,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function convocations(int $limit): array
    {
        return ConvocationGroup::query()
            ->with([
                'evento:id,titulo,descricao,data_inicio,hora_inicio,local,visibilidade,estado,tipo',
                'convocationAthletes:convocatoria_grupo_id,atleta_id',
            ])
            ->whereHas('evento', function ($query): void {
                $query->where('visibilidade', 'publico')
                    ->whereDate('data_inicio', '>=', today())
                    ->whereNotIn('estado', ['rascunho', 'cancelado'])
                    ->where('tipo', '!=', 'treino');
            })
            ->get(['id', 'evento_id', 'atletas_ids', 'hora_encontro', 'local_encontro', 'observacoes'])
            ->sortBy(fn (ConvocationGroup $group): string => sprintf(
                '%s %s',
                $group->evento?->data_inicio?->toDateString() ?? '9999-12-31',
                $group->hora_encontro ?? '23:59',
            ))
            ->take(max(1, min($limit, 60)))
            ->map(function (ConvocationGroup $group): array {
                $event = $group->evento;
                $athletes = max(
                    collect($group->atletas_ids)->filter()->unique()->count(),
                    $group->convocationAthletes->count(),
                );

                return [
                    'id' => (string) $group->id,
                    'title' => (string) $event?->titulo,
                    'description' => Str::limit(trim(strip_tags((string) ($group->observacoes ?: $event?->descricao))), 180),
                    'startDate' => $event?->data_inicio?->toDateString(),
                    'startTime' => $event?->hora_inicio ? substr((string) $event->hora_inicio, 0, 5) : null,
                    'place' => $event?->local,
                    'meetingTime' => $group->hora_encontro ? substr((string) $group->hora_encontro, 0, 5) : null,
                    'meetingPlace' => $group->local_encontro,
                    'athleteCount' => $athletes,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function statistics(): array
    {
        $activePartners = Sponsor::query()
            ->where('estado', 'ativo')
            ->whereDate('data_inicio', '<=', today())
            ->where(fn ($query) => $query->whereNull('data_fim')->orWhereDate('data_fim', '>=', today()))
            ->count();

        return [
            [
                'id' => 'active-athletes',
                'value' => AthleteSportsData::query()->where('ativo', true)->count(),
                'label' => 'atletas ativos',
                'description' => 'Atletas com estado desportivo ativo no ClubOS.',
            ],
            [
                'id' => 'upcoming-events',
                'value' => Event::query()
                    ->where('visibilidade', 'publico')
                    ->whereDate('data_inicio', '>=', today())
                    ->whereNotIn('estado', ['rascunho', 'cancelado'])
                    ->where('tipo', '!=', 'treino')
                    ->count(),
                'label' => 'eventos futuros',
                'description' => 'Eventos públicos confirmados no calendário.',
            ],
            [
                'id' => 'training-year',
                'value' => Training::query()->whereYear('data', now()->year)->count(),
                'label' => 'treinos este ano',
                'description' => 'Sessões de treino registadas no ano civil atual.',
            ],
            [
                'id' => 'published-news',
                'value' => NewsItem::query()->where('data_publicacao', '<=', now())->count(),
                'label' => 'notícias publicadas',
                'description' => 'Conteúdos já publicados no website.',
            ],
            [
                'id' => 'active-partners',
                'value' => $activePartners,
                'label' => 'parceiros ativos',
                'description' => 'Parceiros atualmente ligados ao clube.',
            ],
            [
                'id' => 'competition-years',
                'value' => max(1, now()->year - 2008),
                'label' => 'anos de competição',
                'description' => 'Benedita Sport Club Natação, em competição desde 2008.',
            ],
        ];
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

    private function publicLinkUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || preg_match('/\s/', $url) === 1) {
            return null;
        }

        return Str::startsWith($url, ['http://', 'https://']) ? $url : 'https://'.$url;
    }
}
