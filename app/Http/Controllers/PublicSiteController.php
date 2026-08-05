<?php

namespace App\Http\Controllers;

use App\Models\WebsitePage;
use App\Services\Website\WebsitePageService;
use App\Services\Website\WebsitePublicDataService;
use Illuminate\Http\Request;
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

    public function __construct(
        private readonly WebsitePageService $pages,
        private readonly WebsitePublicDataService $publicData,
    ) {
    }

    public function home(): Response
    {
        if ($managed = WebsitePage::query()->where('slug', 'home')->first()) {
            if ($managed->status === 'hidden') {
                abort(404);
            }

            if ($snapshot = $this->pages->publicSnapshot($managed)) {
                return $this->managedResponse($snapshot);
            }
        }

        return Inertia::render('PublicSite/Home', [
            'news' => $this->publicData->news(3),
            'events' => $this->publicData->events(3),
            'publicNavigation' => $this->pages->navigation(),
        ]);
    }

    public function show(string $page): Response
    {
        abort_unless(isset(self::PAGES[$page]), 404);

        if ($managed = WebsitePage::query()->where('slug', $page)->first()) {
            if ($managed->status === 'hidden') {
                abort(404);
            }

            if ($snapshot = $this->pages->publicSnapshot($managed)) {
                return $this->managedResponse($snapshot);
            }
        }

        $props = match ($page) {
            'noticias' => ['news' => $this->publicData->news(12)],
            'calendario' => ['events' => $this->publicData->events(30)],
            default => [],
        };

        return Inertia::render('PublicSite/'.self::PAGES[$page], [
            ...$props,
            'publicNavigation' => $this->pages->navigation(),
        ]);
    }

    public function custom(Request $request): Response
    {
        $page = trim($request->path(), '/');

        abort_unless(
            in_array(strtoupper($request->method()), ['GET', 'HEAD'], true)
                && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $page) === 1,
            404,
        );

        $managed = WebsitePage::query()->where('slug', $page)->firstOrFail();
        $snapshot = $this->pages->publicSnapshot($managed);
        abort_unless($snapshot !== null, 404);

        return $this->managedResponse($snapshot);
    }

    /** @param array<string, mixed> $snapshot */
    private function managedResponse(array $snapshot): Response
    {
        return Inertia::render('PublicSite/ManagedPage', [
            'page' => $snapshot,
            'news' => $this->publicData->news(30),
            'events' => $this->publicData->events(60),
            'publicNavigation' => $this->pages->navigation(),
            'preview' => false,
        ]);
    }
}
