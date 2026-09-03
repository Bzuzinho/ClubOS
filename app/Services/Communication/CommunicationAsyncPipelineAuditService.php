<?php

declare(strict_types=1);

namespace App\Services\Communication;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CommunicationAsyncPipelineAuditService
{
    private const VERSION = 'h6c-communication-provider-lifecycle-audit-v3';

    /** @var list<string> */
    private const AUTOMATION_SOURCE_TYPES = [
        'invoice',
        'movement',
        'event_convocation',
        'logistics_request_created',
        'logistics_request_status',
        'supplier_purchase',
        'sports_intent',
    ];

    /** @return array<string,mixed> */
    public function audit(): array
    {
        $schema = $this->schemaDetected();
        $schemaReady = ! in_array(false, $schema['required_tables'], true)
            && ! in_array(false, $schema['campaign_fields'], true)
            && ! in_array(false, $schema['delivery_fields'], true)
            && ! in_array(false, $schema['recipient_fields'], true)
            && ! in_array(false, $schema['provider_event_fields'], true);

        $summary = [
            'schema_ready' => $schemaReady,
            'campaign_count' => $this->count('communication_campaigns'),
            'managed_campaign_count' => $this->whereNotNullCount('communication_campaigns', 'idempotency_key'),
            'legacy_campaign_count' => $this->whereNullCount('communication_campaigns', 'idempotency_key'),
            'scheduled_due_count' => $this->scheduledDueCount(true),
            'legacy_scheduled_due_count' => $this->scheduledDueCount(false),
            'automation_campaign_count' => $this->automationCampaignCount(),
            'automation_processing_count' => $this->automationProcessingCount(),
            'automation_without_dispatch_request_count' => $this->automationWithoutDispatchRequestCount(),
            'outbox_recovery_due_count' => $this->outboxRecoveryDueCount(),
            'delivery_count' => $this->count('communication_deliveries'),
            'managed_delivery_count' => $this->whereNotNullCount('communication_deliveries', 'idempotency_key'),
            'legacy_delivery_count' => $this->whereNullCount('communication_deliveries', 'idempotency_key'),
            'recipient_count' => $this->count('communication_delivery_recipients'),
            'managed_recipient_count' => $this->whereNotNullCount('communication_delivery_recipients', 'idempotency_key'),
            'legacy_recipient_count' => $this->whereNullCount('communication_delivery_recipients', 'idempotency_key'),
            'attempt_count' => $this->count('communication_delivery_attempts'),
            'failed_attempt_count' => $this->whereCount('communication_delivery_attempts', 'status', 'failed'),
            'retry_due_count' => $this->retryDueCount(),
            'retry_waiting_count' => $this->retryWaitingCount(),
            'exhausted_recipient_count' => $this->exhaustedRecipientCount(),
            'stale_processing_count' => $this->staleProcessingCount(),
            'managed_success_missing_provider_count' => $this->managedSuccessMissingProviderCount(),
            'managed_success_missing_provider_message_id_count' => $this->managedSuccessMissingProviderMessageIdCount(),
            'provider_event_count' => $this->count('communication_provider_events'),
            'provider_event_applied_count' => $this->whereCount('communication_provider_events', 'status', 'applied'),
            'provider_event_ignored_count' => $this->whereCount('communication_provider_events', 'status', 'ignored'),
            'provider_event_unmatched_count' => $this->whereCount('communication_provider_events', 'status', 'unmatched'),
        ];

        $summary['critical_count'] = $schemaReady ? 0 : 1;
        $summary['warning_count'] = $summary['exhausted_recipient_count']
            + $summary['stale_processing_count']
            + $summary['legacy_scheduled_due_count']
            + $summary['outbox_recovery_due_count']
            + $summary['managed_success_missing_provider_count']
            + $summary['managed_success_missing_provider_message_id_count']
            + $summary['provider_event_unmatched_count'];
        $summary['actionable_count'] = $summary['exhausted_recipient_count']
            + $summary['stale_processing_count']
            + $summary['legacy_scheduled_due_count']
            + $summary['outbox_recovery_due_count']
            + $summary['provider_event_unmatched_count'];

        return [
            'version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'read_only' => true,
            'schema_detected' => $schema,
            'summary' => $summary,
            'interpretation' => [
                'campaigns_are_the_canonical_outbox' => true,
                'deliveries_snapshot_channel_execution' => true,
                'recipients_have_persistent_idempotency' => true,
                'attempts_are_append_only' => true,
                'provider_references_are_preserved_when_available' => true,
                'scheduled_and_retry_dispatch_share_one_scheduler' => true,
                'legacy_rows_are_measured_without_backfill' => true,
                'legacy_scheduled_campaigns_are_never_auto_dispatched' => true,
                'automatic_sources_dispatch_via_persistent_outbox' => true,
                'stale_automatic_outbox_campaigns_are_recoverable' => true,
                'external_channels_use_explicit_adapters' => true,
                'provider_callbacks_require_hmac_and_fresh_timestamp' => true,
                'provider_events_are_idempotent_and_payload_minimized' => true,
                'provider_status_transitions_never_downgrade_delivered_or_read' => true,
                'future_social_network_providers_must_reuse_this_pipeline' => true,
                'no_data_changed' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function schemaDetected(): array
    {
        return [
            'required_tables' => collect([
                'communication_campaigns',
                'communication_deliveries',
                'communication_delivery_recipients',
                'communication_delivery_attempts',
                'communication_provider_events',
            ])->mapWithKeys(static fn (string $table): array => [$table => Schema::hasTable($table)])->all(),
            'campaign_fields' => collect(['source_type', 'source_id', 'idempotency_key', 'dispatch_requested_at'])
                ->mapWithKeys(static fn (string $field): array => [$field => Schema::hasColumn('communication_campaigns', $field)])->all(),
            'delivery_fields' => collect(['idempotency_key', 'completed_at'])
                ->mapWithKeys(static fn (string $field): array => [$field => Schema::hasColumn('communication_deliveries', $field)])->all(),
            'recipient_fields' => collect([
                'idempotency_key',
                'attempt_count',
                'max_attempts',
                'provider',
                'provider_message_id',
                'processing_at',
                'last_attempt_at',
                'next_attempt_at',
            ])->mapWithKeys(static fn (string $field): array => [$field => Schema::hasColumn('communication_delivery_recipients', $field)])->all(),
            'provider_event_fields' => collect([
                'provider',
                'external_event_id',
                'provider_message_id',
                'event_type',
                'occurred_at',
                'received_at',
                'payload_hash',
                'status',
                'recipient_id',
                'processed_at',
            ])->mapWithKeys(static fn (string $field): array => [$field => Schema::hasColumn('communication_provider_events', $field)])->all(),
            'webhook_secret_configured' => collect(['email', 'sms', 'push'])
                ->mapWithKeys(static fn (string $provider): array => [
                    $provider => filled(config("services.communication_webhooks.secrets.{$provider}")),
                ])->all(),
            'redis_queue_connection_defined' => is_array(config('queue.connections.redis')),
            'default_queue_connection' => (string) config('queue.default'),
        ];
    }

    private function count(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }

    private function whereNullCount(string $table, string $column): int
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column)
            ? DB::table($table)->whereNull($column)->count()
            : 0;
    }

    private function whereNotNullCount(string $table, string $column): int
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column)
            ? DB::table($table)->whereNotNull($column)->count()
            : 0;
    }

    private function whereCount(string $table, string $column, string $value): int
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column)
            ? DB::table($table)->where($column, $value)->count()
            : 0;
    }

    private function scheduledDueCount(bool $managed): int
    {
        if (
            ! Schema::hasTable('communication_campaigns')
            || ! Schema::hasColumn('communication_campaigns', 'idempotency_key')
        ) {
            return 0;
        }

        return DB::table('communication_campaigns')
            ->where('status', 'agendada')
            ->when(
                $managed,
                static fn ($query) => $query->whereNotNull('idempotency_key'),
                static fn ($query) => $query->whereNull('idempotency_key'),
            )
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->count();
    }

    private function retryDueCount(): int
    {
        if (! $this->recipientRetrySchemaReady()) {
            return 0;
        }

        return DB::table('communication_delivery_recipients')
            ->where('status', 'failed')
            ->whereColumn('attempt_count', '<', 'max_attempts')
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->count();
    }

    private function automationCampaignCount(): int
    {
        return $this->automationCampaignQueryReady()
            ? DB::table('communication_campaigns')->whereIn('source_type', self::AUTOMATION_SOURCE_TYPES)->count()
            : 0;
    }

    private function automationProcessingCount(): int
    {
        return $this->automationCampaignQueryReady()
            ? DB::table('communication_campaigns')
                ->whereIn('source_type', self::AUTOMATION_SOURCE_TYPES)
                ->where('status', 'em_processamento')
                ->count()
            : 0;
    }

    private function automationWithoutDispatchRequestCount(): int
    {
        return $this->automationCampaignQueryReady()
            ? DB::table('communication_campaigns')
                ->whereIn('source_type', self::AUTOMATION_SOURCE_TYPES)
                ->whereNull('dispatch_requested_at')
                ->count()
            : 0;
    }

    private function outboxRecoveryDueCount(): int
    {
        if (! $this->automationCampaignQueryReady() || ! Schema::hasTable('communication_deliveries')) {
            return 0;
        }

        return DB::table('communication_campaigns')
            ->whereIn('source_type', self::AUTOMATION_SOURCE_TYPES)
            ->whereNotNull('idempotency_key')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('communication_deliveries')
                    ->whereColumn('communication_deliveries.campaign_id', 'communication_campaigns.id');
            })
            ->where(function ($query): void {
                $query->where(function ($pending): void {
                    $pending->where('status', 'rascunho')
                        ->whereNull('dispatch_requested_at');
                })->orWhere(function ($stale): void {
                    $stale->where('status', 'em_processamento')
                        ->whereNotNull('dispatch_requested_at')
                        ->where('dispatch_requested_at', '<=', now()->subMinutes(10));
                });
            })
            ->count();
    }

    private function retryWaitingCount(): int
    {
        if (! $this->recipientRetrySchemaReady()) {
            return 0;
        }

        return DB::table('communication_delivery_recipients')
            ->where('status', 'failed')
            ->whereColumn('attempt_count', '<', 'max_attempts')
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '>', now())
            ->count();
    }

    private function exhaustedRecipientCount(): int
    {
        if (! $this->recipientRetrySchemaReady()) {
            return 0;
        }

        return DB::table('communication_delivery_recipients')
            ->where('status', 'failed')
            ->whereColumn('attempt_count', '>=', 'max_attempts')
            ->count();
    }

    private function staleProcessingCount(): int
    {
        if (! $this->recipientRetrySchemaReady()) {
            return 0;
        }

        return DB::table('communication_delivery_recipients')
            ->whereNotNull('processing_at')
            ->where('processing_at', '<=', now()->subMinutes(10))
            ->count();
    }

    private function managedSuccessMissingProviderCount(): int
    {
        if (! $this->recipientRetrySchemaReady()) {
            return 0;
        }

        return DB::table('communication_delivery_recipients')
            ->whereNotNull('idempotency_key')
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->whereNull('provider')
            ->count();
    }

    private function managedSuccessMissingProviderMessageIdCount(): int
    {
        if (! $this->recipientRetrySchemaReady()) {
            return 0;
        }

        return DB::table('communication_delivery_recipients')
            ->whereNotNull('idempotency_key')
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->whereNull('provider_message_id')
            ->count();
    }

    private function recipientRetrySchemaReady(): bool
    {
        return Schema::hasTable('communication_delivery_recipients')
            && Schema::hasColumn('communication_delivery_recipients', 'idempotency_key')
            && Schema::hasColumn('communication_delivery_recipients', 'attempt_count')
            && Schema::hasColumn('communication_delivery_recipients', 'max_attempts')
            && Schema::hasColumn('communication_delivery_recipients', 'provider')
            && Schema::hasColumn('communication_delivery_recipients', 'provider_message_id')
            && Schema::hasColumn('communication_delivery_recipients', 'processing_at')
            && Schema::hasColumn('communication_delivery_recipients', 'next_attempt_at');
    }

    private function automationCampaignQueryReady(): bool
    {
        return Schema::hasTable('communication_campaigns')
            && Schema::hasColumn('communication_campaigns', 'source_type')
            && Schema::hasColumn('communication_campaigns', 'idempotency_key')
            && Schema::hasColumn('communication_campaigns', 'dispatch_requested_at');
    }
}
