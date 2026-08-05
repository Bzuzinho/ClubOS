<?php

namespace App\Services\Communication;

use App\Models\CommunicationCampaign;
use App\Models\CommunicationTemplate;
use App\Models\EventConvocation;
use App\Models\Invoice;
use App\Models\LogisticsRequest;
use App\Models\Movement;
use App\Models\NotificationPreference;
use App\Models\SupplierPurchase;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CommunicationAutomationService
{
    public function __construct(private readonly CommunicationCampaignService $campaignService)
    {
    }

    public function triggerInvoiceIssued(Invoice $invoice): void
    {
        if (!$this->canRun() || !$invoice->user_id || !$this->canSendFinancialInvoiceAutomation()) {
            return;
        }

        if (!$this->invoiceCommunicationShouldBeVisible($invoice) || $this->invoiceCommunicationAlreadyDispatched($invoice)) {
            return;
        }

        $invoice->loadMissing('user');

        $isMonthlyFee = $invoice->tipo === 'mensalidade';
        $period = $invoice->mes ?: optional($invoice->data_fatura)->format('Y-m');
        $subject = $isMonthlyFee
            ? sprintf('Mensalidade de %s disponível', $period ?: 'período atual')
            : sprintf('Nova fatura disponível - %s', $period ?: $invoice->tipo);
        $message = $isMonthlyFee
            ? sprintf(
                'A mensalidade de %s, no valor de %s, já está disponível para pagamento. Data de vencimento: %s.',
                $period ?: 'período atual',
                number_format((float) $invoice->valor_total, 2, ',', '.'),
                optional($invoice->data_vencimento)->format('Y-m-d') ?: 'data por definir'
            )
            : sprintf(
                'Foi disponibilizada uma nova fatura no valor de %s com vencimento em %s.',
                number_format((float) $invoice->valor_total, 2, ',', '.'),
                optional($invoice->data_vencimento)->format('Y-m-d') ?: 'data por definir'
            );

        $this->dispatch([
            'title' => $subject,
            'alert_category' => 'mensalidade',
            'alert_title' => $isMonthlyFee ? 'Mensalidade disponível' : 'Nova fatura disponível',
            'alert_message' => $message,
            'alert_type' => $invoice->estado_pagamento === 'vencido' ? 'warning' : 'info',
            'recipient_user_ids' => [$invoice->user_id],
            'channels' => $this->buildChannels('mensalidade', $subject, $message, [
                'email' => ['Automação Financeiro - Fatura Email'],
                'alert_app' => ['Automação Financeiro - Fatura App'],
            ]),
        ], 'invoice', $invoice->id);
    }

    public function releaseVisibleInvoiceCommunications(): int
    {
        if (!$this->canRun() || !$this->canSendFinancialInvoiceAutomation()) {
            return 0;
        }

        $released = 0;

        Invoice::query()
            ->whereNotNull('user_id')
            ->where('oculta', false)
            ->where('estado_pagamento', '!=', 'cancelado')
            ->orderBy('data_fatura')
            ->chunkById(100, function (Collection $invoices) use (&$released) {
                foreach ($invoices as $invoice) {
                    if (!$this->invoiceCommunicationShouldBeVisible($invoice) || $this->invoiceCommunicationAlreadyDispatched($invoice)) {
                        continue;
                    }

                    if ($this->triggerInvoiceAndReportDispatch($invoice)) {
                        $released++;
                    }
                }
            }, 'id');

        return $released;
    }

    private function triggerInvoiceAndReportDispatch(Invoice $invoice): bool
    {
        $before = $this->invoiceCommunicationAlreadyDispatched($invoice);
        $this->triggerInvoiceIssued($invoice);

        return !$before && $this->invoiceCommunicationAlreadyDispatched($invoice);
    }

    public function triggerMovementIssued(Movement $movement): void
    {
        if (!$this->canRun() || !$movement->user_id || !$this->canSendFinancialMovementAutomation()) {
            return;
        }

        $movement->loadMissing('user');

        $movementLabel = $movement->classificacao === 'despesa' ? 'despesa' : 'movimento';
        $subject = sprintf('Novo %s financeiro registado', $movementLabel);
        $message = sprintf(
            'Foi registado um novo %s no valor de %s com vencimento em %s.',
            $movementLabel,
            number_format((float) $movement->valor_total, 2, ',', '.'),
            optional($movement->data_vencimento)->format('Y-m-d') ?: 'data por definir'
        );

        $this->dispatch([
            'title' => $subject,
            'alert_category' => 'geral',
            'alert_title' => 'Novo movimento financeiro',
            'alert_message' => $message,
            'alert_type' => $movement->classificacao === 'despesa' ? 'warning' : 'info',
            'recipient_user_ids' => [$movement->user_id],
            'channels' => $this->buildChannels('geral', $subject, $message, [
                'email' => ['Automação Financeiro - Movimento Email'],
                'alert_app' => ['Automação Financeiro - Movimento App'],
            ]),
        ], 'movement', $movement->id);
    }

    public function triggerEventConvocationCreated(EventConvocation $convocation): void
    {
        if (!$this->canRun() || !$convocation->user_id || !$this->canSendEventConvocationAutomation()) {
            return;
        }

        $convocation->loadMissing('event', 'user');

        if (!$convocation->event) {
            return;
        }

        $subject = sprintf('Nova convocatória - %s', $convocation->event->titulo);
        $message = sprintf(
            'Foi criada uma convocatória para o evento %s em %s%s.',
            $convocation->event->titulo,
            optional($convocation->event->data_inicio)->format('Y-m-d') ?: 'data por definir',
            $convocation->event->local ? ' no local ' . $convocation->event->local : ''
        );

        $this->dispatch([
            'title' => $subject,
            'alert_category' => 'geral',
            'alert_title' => 'Nova convocatória',
            'alert_message' => $message,
            'alert_type' => 'info',
            'recipient_user_ids' => [$convocation->user_id],
            'context_event_id' => $convocation->evento_id,
            'channels' => $this->buildChannels('geral', $subject, $message, [
                'email' => ['Automação Eventos - Convocatória Email'],
                'alert_app' => ['Automação Eventos - Convocatória App'],
            ]),
        ], 'event_convocation', $convocation->id);
    }

    public function triggerLogisticsRequestCreated(LogisticsRequest $request): void
    {
        if (!$this->canRun() || !$request->requester_user_id || !$this->canSendLogisticsRequestAutomation()) {
            return;
        }

        $request->loadMissing('requester');

        $subject = 'Nova requisição logística registada';
        $message = sprintf(
            'A tua requisição logística foi registada com o estado %s e valor total de %s.',
            $request->status,
            number_format((float) $request->total_amount, 2, ',', '.')
        );

        $this->dispatch([
            'title' => $subject,
            'alert_category' => 'geral',
            'alert_title' => 'Requisição logística criada',
            'alert_message' => $message,
            'alert_type' => 'info',
            'recipient_user_ids' => [$request->requester_user_id],
            'channels' => $this->buildChannels('geral', $subject, $message, [
                'email' => ['Automação Logística - Requisição Email'],
                'alert_app' => ['Automação Logística - Requisição App'],
            ]),
        ], 'logistics_request_created', $request->id);
    }

    public function triggerLogisticsRequestStatusChanged(LogisticsRequest $request, string $fromStatus, string $toStatus): void
    {
        if (!$this->canRun() || !$request->requester_user_id || !$this->canSendLogisticsRequestAutomation()) {
            return;
        }

        if (!in_array($toStatus, ['approved', 'invoiced', 'delivered'], true)) {
            return;
        }

        $request->loadMissing('requester');

        $statusLabels = [
            'approved' => 'aprovada',
            'invoiced' => 'faturada',
            'delivered' => 'entregue',
        ];

        $statusLabel = $statusLabels[$toStatus] ?? $toStatus;
        $subject = sprintf('Requisição logística %s', $statusLabel);
        $message = sprintf('A tua requisição logística mudou de %s para %s.', $fromStatus, $toStatus);

        $this->dispatch([
            'title' => $subject,
            'alert_category' => 'geral',
            'alert_title' => sprintf('Requisição %s', $statusLabel),
            'alert_message' => $message,
            'alert_type' => $toStatus === 'approved' ? 'success' : 'info',
            'recipient_user_ids' => [$request->requester_user_id],
            'channels' => $this->buildChannels('geral', $subject, $message, [
                'email' => ['Automação Logística - Requisição Email'],
                'alert_app' => ['Automação Logística - Requisição App'],
            ]),
        ], 'logistics_request_status', $request->id . ':' . $toStatus);
    }

    public function triggerSupplierPurchaseCreated(SupplierPurchase $purchase): void
    {
        if (!$this->canRun() || !$this->canSendOperationalLogisticsAutomation()) {
            return;
        }

        $recipientIds = $this->resolveOperationalRecipientIds($purchase);
        if ($recipientIds === []) {
            return;
        }

        $purchase->loadMissing('supplier', 'creator');

        $subject = sprintf('Compra de fornecedor registada - %s', $purchase->supplier_name_snapshot);
        $message = sprintf(
            'Foi registada uma compra de fornecedor no valor de %s com referência %s.',
            number_format((float) $purchase->total_amount, 2, ',', '.'),
            $purchase->invoice_reference
        );

        $this->dispatch([
            'title' => $subject,
            'alert_category' => 'geral',
            'alert_title' => 'Nova compra de fornecedor',
            'alert_message' => $message,
            'alert_type' => 'warning',
            'recipient_user_ids' => $recipientIds,
            'channels' => $this->buildChannels(
                'geral',
                $subject,
                $message,
                ['alert_app' => ['Automação Logística - Compra Fornecedor App']],
                ['alert_app']
            ),
        ], 'supplier_purchase', $purchase->id);
    }

    private function buildChannels(string $category, string $subject, string $message, array $preferredTemplates = [], array $allowedChannels = ['email', 'alert_app']): array
    {
        $channels = collect();

        if (in_array('email', $allowedChannels, true) && $this->automationEnabled('email_notificacoes')) {
            $channels->push([
                'channel' => 'email',
                'is_enabled' => true,
                'template_id' => $this->resolveTemplateId($category, 'email', $preferredTemplates['email'] ?? []),
                'subject' => $subject,
                'message_body' => $message,
            ]);
        }

        if (in_array('alert_app', $allowedChannels, true)) {
            $channels->push([
                'channel' => 'alert_app',
                'is_enabled' => true,
                'template_id' => $this->resolveTemplateId($category, 'alert_app', $preferredTemplates['alert_app'] ?? [])
                    ?? $this->resolveTemplateId('geral', 'alert_app', $preferredTemplates['alert_app'] ?? []),
                'subject' => $subject,
                'message_body' => $message,
            ]);
        }

        return $channels->values()->all();
    }

    private function resolveTemplateId(string $category, string $channel, array $preferredNames = []): ?string
    {
        if (!Schema::hasTable('communication_templates')) {
            return null;
        }

        if ($preferredNames !== []) {
            $preferred = CommunicationTemplate::query()
                ->where('status', 'ativo')
                ->where('channel', $channel)
                ->whereIn('name', $preferredNames)
                ->orderByRaw('case ' . collect($preferredNames)->values()->map(fn (string $name, int $index) => "when name = '" . str_replace("'", "''", $name) . "' then {$index}")->implode(' ') . ' else 999 end')
                ->value('id');

            if ($preferred) {
                return $preferred;
            }
        }

        return CommunicationTemplate::query()
            ->where('status', 'ativo')
            ->where('channel', $channel)
            ->where(function ($query) use ($category) {
                $query->where('category', $category)->orWhere('category', 'geral');
            })
            ->orderByRaw("case when category = ? then 0 else 1 end", [$category])
            ->value('id');
    }

    private function dispatch(array $payload, string $originType, string $originId): void
    {
        if (empty($payload['channels'])) {
            Log::info('Communication automation skipped because all configured channels are disabled.', [
                'origin_type' => $originType,
                'origin_id' => $originId,
            ]);

            return;
        }

        try {
            $campaign = $this->campaignService->sendIndividualCommunication($payload);

            $campaign->update([
                'notes' => trim(($campaign->notes ? $campaign->notes . ' | ' : '') . sprintf('origem: %s:%s', $originType, $originId)),
            ]);
        } catch (\Throwable $exception) {
            Log::error('CommunicationAutomationService dispatch failed', [
                'origin_type' => $originType,
                'origin_id' => $originId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function invoiceCommunicationShouldBeVisible(Invoice $invoice): bool
    {
        if ((bool) $invoice->oculta) {
            return false;
        }

        if ($invoice->estado_pagamento === 'cancelado') {
            return false;
        }

        if (!$invoice->data_fatura) {
            return true;
        }

        return $invoice->data_fatura->copy()->startOfDay()->lte(now()->startOfDay());
    }

    private function invoiceCommunicationAlreadyDispatched(Invoice $invoice): bool
    {
        return CommunicationCampaign::query()
            ->where('notes', 'like', sprintf('%%origem: invoice:%s%%', $invoice->id))
            ->exists();
    }

    private function canRun(): bool
    {
        return Schema::hasTable('communication_campaigns')
            && Schema::hasTable('communication_segments')
            && Schema::hasTable('communication_templates');
    }

    public function canSendFinancialInvoiceAutomation(): bool
    {
        return $this->automationEnabled('alertas_pagamento')
            && $this->automationEnabled('automacoes_financeiro')
            && $this->automationEnabled('automacoes_faturas_financeiras');
    }

    public function canSendFinancialMovementAutomation(): bool
    {
        return $this->automationEnabled('alertas_pagamento')
            && $this->automationEnabled('automacoes_financeiro')
            && $this->automationEnabled('automacoes_movimentos_financeiros');
    }

    public function canSendEventConvocationAutomation(): bool
    {
        return $this->automationEnabled('alertas_atividade')
            && $this->automationEnabled('automacoes_eventos')
            && $this->automationEnabled('automacoes_convocatorias_eventos');
    }

    public function canSendLogisticsRequestAutomation(): bool
    {
        return $this->automationEnabled('automacoes_logistica')
            && $this->automationEnabled('automacoes_requisicoes_logistica');
    }

    public function canSendOperationalLogisticsAutomation(): bool
    {
        return $this->automationEnabled('automacoes_logistica')
            && $this->automationEnabled('automacoes_alertas_operacionais');
    }

    private function automationEnabled(string $field): bool
    {
        if (!Schema::hasTable('notification_preferences')) {
            return false;
        }

        $prefs = NotificationPreference::query()->first();

        if (!$prefs) {
            return false;
        }

        $attributes = $prefs->getAttributes();

        if (!array_key_exists($field, $attributes) || $attributes[$field] === null) {
            return false;
        }

        return filter_var($attributes[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    private function resolveOperationalRecipientIds(SupplierPurchase $purchase): array
    {
        $query = User::query()
            ->where(function (Builder $builder) {
                $builder->where('estado', 'ativo')->orWhereNull('estado');
            })
            ->where(function (Builder $builder) {
                $builder->whereIn('perfil', ['admin', 'administrador', 'gestor', 'logistica'])
                    ->orWhereJsonContains('tipo_membro', 'admin')
                    ->orWhereJsonContains('tipo_membro', 'gestor')
                    ->orWhereJsonContains('tipo_membro', 'logistica');
            });

        $recipientIds = $query->pluck('id');

        if ($recipientIds->isEmpty() && $purchase->created_by) {
            return [$purchase->created_by];
        }

        return $recipientIds->unique()->values()->all();
    }
}
