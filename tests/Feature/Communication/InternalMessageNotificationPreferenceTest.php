<?php

namespace Tests\Feature\Communication;

use App\Models\InAppAlert;
use App\Models\InternalMessageRecipient;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Communication\InternalCommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalMessageNotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_message_is_delivered_without_alert_when_global_channel_is_disabled(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        NotificationPreference::query()->create($this->preferences(false));

        $message = app(InternalCommunicationService::class)->send($sender, [
            'recipient_ids' => [$recipient->id],
            'subject' => 'Treino alterado',
            'message' => 'O treino de amanhã mudou de horário.',
            'type' => 'info',
        ]);

        $entry = InternalMessageRecipient::query()
            ->where('internal_message_id', $message->id)
            ->where('recipient_id', $recipient->id)
            ->sole();

        $this->assertNull($entry->in_app_alert_id);
        $this->assertFalse((bool) $entry->is_read);
        $this->assertSame(0, InAppAlert::query()->count());
    }

    public function test_internal_message_creates_linked_alert_when_global_channel_is_enabled(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        NotificationPreference::query()->create($this->preferences(true));

        $message = app(InternalCommunicationService::class)->send($sender, [
            'recipient_ids' => [$recipient->id],
            'subject' => 'Nova comunicação',
            'message' => 'Consulta esta comunicação na tua área reservada.',
            'type' => 'warning',
        ]);

        $entry = InternalMessageRecipient::query()
            ->where('internal_message_id', $message->id)
            ->where('recipient_id', $recipient->id)
            ->sole();

        $this->assertNotNull($entry->in_app_alert_id);
        $this->assertDatabaseHas('in_app_alerts', [
            'id' => $entry->in_app_alert_id,
            'user_id' => $recipient->id,
            'title' => 'Nova comunicação',
            'type' => 'warning',
            'is_read' => false,
        ]);
    }

    public function test_marking_message_unread_does_not_recreate_alert_when_channel_is_disabled(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        NotificationPreference::query()->create($this->preferences(false));

        $message = app(InternalCommunicationService::class)->send($sender, [
            'recipient_ids' => [$recipient->id],
            'subject' => 'Sem alerta',
            'message' => 'A mensagem deve continuar acessível.',
            'type' => 'info',
        ]);

        $entry = InternalMessageRecipient::query()
            ->where('internal_message_id', $message->id)
            ->where('recipient_id', $recipient->id)
            ->sole();

        $entry->forceFill(['is_read' => true, 'read_at' => now()])->save();

        app(InternalCommunicationService::class)->markAsUnread($entry->fresh());

        $entry->refresh();
        $this->assertFalse((bool) $entry->is_read);
        $this->assertNull($entry->read_at);
        $this->assertNull($entry->in_app_alert_id);
        $this->assertSame(0, InAppAlert::query()->count());
    }

    /** @return array<string, bool> */
    private function preferences(bool $inAppEnabled): array
    {
        return [
            'email_notificacoes' => true,
            'alertas_aplicacao' => $inAppEnabled,
            'alertas_pagamento' => true,
            'alertas_atividade' => true,
            'automacoes_financeiro' => true,
            'automacoes_eventos' => true,
            'automacoes_logistica' => true,
            'automacoes_faturas_financeiras' => true,
            'automacoes_movimentos_financeiros' => true,
            'automacoes_convocatorias_eventos' => true,
            'automacoes_requisicoes_logistica' => true,
            'automacoes_alertas_operacionais' => true,
        ];
    }
}
