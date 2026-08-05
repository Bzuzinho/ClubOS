<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWebsiteMediaRequest;
use App\Models\WebsiteMedia;
use App\Services\Website\WebsiteMediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebsiteMediaController extends Controller
{
    public function __construct(
        private readonly WebsiteMediaService $media,
    ) {
    }

    public function store(StoreWebsiteMediaRequest $request): RedirectResponse
    {
        $this->media->store(
            $request->file('image'),
            $request->validated('alt_text'),
            $request->user()
        );

        return back()->with('success', 'Imagem adicionada à biblioteca.');
    }

    public function update(Request $request, WebsiteMedia $media): RedirectResponse
    {
        $validated = $request->validate(['alt_text' => ['required', 'string', 'max:220']]);
        $this->media->updateAltText($media, $validated['alt_text']);

        return back()->with('success', 'Texto alternativo atualizado.');
    }

    public function destroy(WebsiteMedia $media): RedirectResponse
    {
        $this->media->delete($media);

        return back()->with('success', 'Imagem eliminada da biblioteca.');
    }
}
