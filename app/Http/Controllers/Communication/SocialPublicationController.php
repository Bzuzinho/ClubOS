<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\StoreSocialPublicationRequest;
use App\Models\CommunicationSegment;
use App\Services\Communication\CommunicationCampaignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final class SocialPublicationController extends Controller
{
    public function __construct(private readonly CommunicationCampaignService $campaignService)
    {
    }

    public function store(StoreSocialPublicationRequest $request): RedirectResponse
    {
        try {
            $data = $request->validated();
            $campaign = DB::transaction(function () use ($data, $request) {
                $segment = CommunicationSegment::query()->firstOrCreate(
                    ['slug' => 'social-network-accounts'],
                    [
                        'name' => 'Contas de redes sociais',
                        'type' => 'system',
                        'description' => 'Segmento técnico do pipeline canónico para publicação em redes sociais.',
                        'rules_json' => ['source' => 'social_network_accounts'],
                        'is_active' => true,
                        'created_by' => $request->user()?->id,
                    ],
                );

                return $this->campaignService->createCampaign([
                    'title' => $data['title'],
                    'description' => $data['message'],
                    'segment_id' => $segment->id,
                    'status' => $data['submission_mode'] === 'schedule' ? 'agendada' : 'rascunho',
                    'scheduled_at' => $data['submission_mode'] === 'schedule' ? $data['scheduled_at'] : null,
                    'notes' => 'Publicação em redes sociais via Meta Graph API.',
                    'source_type' => 'social_publication',
                    'channels' => collect($data['providers'])->map(fn (string $provider): array => [
                        'channel' => $provider,
                        'subject' => $data['title'],
                        'message_body' => $data['message'],
                        'link_url' => $data['link_url'] ?? null,
                        'media_url' => $data['media_url'] ?? null,
                        'is_enabled' => true,
                    ])->all(),
                ], $request->user()?->id);
            });

            if ($data['submission_mode'] === 'send') {
                $this->campaignService->sendCampaign($campaign->load(['channels', 'segment']), $request->user()?->id, true);

                return back()->with('success', 'Publicação entregue à fila de comunicação.');
            }

            return back()->with('success', 'Publicação agendada no pipeline de comunicação.');
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }
}
