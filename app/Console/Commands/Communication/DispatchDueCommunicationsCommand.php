<?php

declare(strict_types=1);

namespace App\Console\Commands\Communication;

use App\Jobs\ProcessCommunicationCampaignJob;
use App\Models\CommunicationCampaign;
use App\Models\CommunicationDeliveryRecipient;
use App\Services\Communication\CommunicationCampaignService;
use Illuminate\Console\Command;

final class DispatchDueCommunicationsCommand extends Command
{
    protected $signature = 'communication:dispatch-due {--limit=100 : Máximo de campanhas por ciclo}';

    protected $description = 'Enfileira campanhas agendadas e retentativas de comunicação vencidas';

    public function __construct(private readonly CommunicationCampaignService $campaignService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $scheduledCount = 0;
        $retryCount = 0;

        CommunicationCampaign::query()
            ->where('status', 'agendada')
            ->whereNotNull('idempotency_key')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get()
            ->each(function (CommunicationCampaign $campaign) use (&$scheduledCount): void {
                $before = $campaign->status;
                $this->campaignService->sendCampaign($campaign->load(['channels', 'segment']), null, true);

                if ($before === 'agendada' && $campaign->fresh()->status === 'em_processamento') {
                    $scheduledCount++;
                }
            });

        $remaining = max(0, $limit - $scheduledCount);
        if ($remaining > 0) {
            $retryCampaignIds = CommunicationDeliveryRecipient::query()
                ->join('communication_deliveries', 'communication_deliveries.id', '=', 'communication_delivery_recipients.delivery_id')
                ->join('communication_campaigns', 'communication_campaigns.id', '=', 'communication_deliveries.campaign_id')
                ->whereNotIn('communication_campaigns.status', ['enviada', 'cancelada'])
                ->where(function ($lease): void {
                    $lease->whereNull('communication_campaigns.dispatch_requested_at')
                        ->orWhere('communication_campaigns.dispatch_requested_at', '<=', now()->subSeconds(30));
                })
                ->whereColumn('communication_delivery_recipients.attempt_count', '<', 'communication_delivery_recipients.max_attempts')
                ->where(function ($query): void {
                    $query->where(function ($due): void {
                        $due->where('communication_delivery_recipients.status', 'failed')
                            ->whereNotNull('communication_delivery_recipients.next_attempt_at')
                            ->where('communication_delivery_recipients.next_attempt_at', '<=', now());
                    })->orWhere(function ($stale): void {
                        $stale->whereNotNull('communication_delivery_recipients.processing_at')
                            ->where('communication_delivery_recipients.processing_at', '<=', now()->subMinutes(10));
                    });
                })
                ->distinct()
                ->limit($remaining)
                ->pluck('communication_deliveries.campaign_id');

            foreach ($retryCampaignIds as $campaignId) {
                $campaign = CommunicationCampaign::query()->find($campaignId);
                if (! $campaign || in_array($campaign->status, ['enviada', 'cancelada'], true)) {
                    continue;
                }

                $campaign->update([
                    'status' => 'em_processamento',
                    'dispatch_requested_at' => now(),
                ]);

                ProcessCommunicationCampaignJob::dispatch((string) $campaign->id)->afterCommit();
                $retryCount++;
            }
        }

        $this->info(sprintf(
            'Comunicações enfileiradas: %d agendadas, %d retentativas.',
            $scheduledCount,
            $retryCount,
        ));

        return self::SUCCESS;
    }
}
