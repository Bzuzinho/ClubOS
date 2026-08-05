<?php

namespace App\Services\Website;

use App\Models\User;
use App\Models\WebsiteMedia;
use App\Models\WebsitePage;
use App\Models\WebsitePageVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebsiteMediaService
{
    public function store(UploadedFile $image, string $altText, User $actor): WebsiteMedia
    {
        $extension = strtolower($image->extension() ?: 'jpg');
        $path = $image->storeAs(
            'website/media/'.now()->format('Y/m'),
            Str::uuid().'.'.$extension,
            'public'
        );

        [$width, $height] = $this->dimensions($image);

        try {
            return WebsiteMedia::query()->create([
                'disk' => 'public',
                'path' => $path,
                'original_name' => $image->getClientOriginalName(),
                'mime_type' => $image->getMimeType() ?: 'application/octet-stream',
                'size' => $image->getSize(),
                'width' => $width,
                'height' => $height,
                'alt_text' => trim($altText),
                'uploaded_by' => $actor->id,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }
    }

    public function updateAltText(WebsiteMedia $media, string $altText): WebsiteMedia
    {
        $media->update(['alt_text' => trim($altText)]);

        return $media->fresh();
    }

    public function delete(WebsiteMedia $media): void
    {
        if ($this->isUsed($media)) {
            throw ValidationException::withMessages([
                'media' => 'A imagem está a ser usada numa página ou versão publicada e não pode ser eliminada.',
            ]);
        }

        Storage::disk($media->disk)->delete($media->path);
        $media->delete();
    }

    public function isUsed(WebsiteMedia $media): bool
    {
        return $this->usageMap(collect([$media]))->get($media->id, false);
    }

    /** @param Collection<int, WebsiteMedia> $media */
    public function usageMap(Collection $media): Collection
    {
        $pages = WebsitePage::query()->with('blocks')->get();
        $versions = WebsitePageVersion::query()->pluck('snapshot')->all();

        return $media->mapWithKeys(function (WebsiteMedia $item) use ($pages, $versions): array {
            $used = $pages->contains(function (WebsitePage $page) use ($item): bool {
                $draft = [
                    'design_settings' => $page->design_settings,
                    'blocks' => $page->blocks->map(fn ($block): array => [
                        'content' => $block->content,
                        'style' => $block->style,
                        'settings' => $block->settings,
                    ])->all(),
                ];

                return $this->containsValue($draft, $item->url)
                    || $this->containsValue($draft, $item->path)
                    || $this->containsValue($page->published_snapshot, $item->url)
                    || $this->containsValue($page->published_snapshot, $item->path)
                    || $this->containsValue($page->scheduled_snapshot, $item->url)
                    || $this->containsValue($page->scheduled_snapshot, $item->path);
            });

            $used = $used
                || $this->containsValue($versions, $item->url)
                || $this->containsValue($versions, $item->path);

            return [$item->id => $used];
        });
    }

    /** @return array{0: int|null, 1: int|null} */
    private function dimensions(UploadedFile $image): array
    {
        $dimensions = @getimagesize($image->getRealPath());

        return $dimensions === false
            ? [null, null]
            : [(int) $dimensions[0], (int) $dimensions[1]];
    }

    private function containsValue(mixed $haystack, string $needle): bool
    {
        if (is_string($haystack)) {
            return $haystack === $needle;
        }

        if (! is_array($haystack)) {
            return false;
        }

        foreach ($haystack as $value) {
            if ($this->containsValue($value, $needle)) {
                return true;
            }
        }

        return false;
    }
}
