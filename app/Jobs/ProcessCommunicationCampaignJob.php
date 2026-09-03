<?php

namespace App\Jobs;

use App\Models\CommunicationCampaign;
use App\Services\Communication\CommunicationCampaignService;
use App\Services\Communication\CommunicationDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCommunicationCampaignJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 600;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly string $campaignId,
        public readonly ?string $executedBy = null
    ) {
    }

    public function uniqueId(): string
    {
        return $this->campaignId;
    }

    public function handle(CommunicationDeliveryService $deliveryService, CommunicationCampaignService $campaignService): void
    {
        $campaign = CommunicationCampaign::with(['channels', 'segment', 'deliveries'])->find($this->campaignId);

        if (!$campaign) {
            return;
        }

        foreach ($campaign->channels->where('is_enabled', true) as $channel) {
            $deliveryService->createAndExecuteDelivery($campaign, $channel, $this->executedBy);
        }

        $campaignService->consolidateStatus($campaign->refresh());
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessCommunicationCampaignJob failed', [
            'campaign_id' => $this->campaignId,
            'error' => $exception->getMessage(),
        ]);

        CommunicationCampaign::query()
            ->where('id', $this->campaignId)
            ->whereNotIn('status', ['enviada', 'cancelada'])
            ->update([
                'status' => 'falhada',
                'notes' => 'Falha no processamento: ' . mb_substr($exception->getMessage(), 0, 1900),
            ]);
    }
}
