<?php

namespace Tests\Feature\Communication;

use App\Models\CommunicationCampaign;
use App\Models\InAppAlert;
use App\Models\Invoice;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AutomationChannelPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_automation_uses_email_only_when_in_app_channel_is_disabled(): void
    {
        Mail::fake();
        $recipient = User::factory()->create(['tipo_membro' => ['atleta']]);

        NotificationPreference::query()->create($this->preferences([
            'email_notificacoes' => true,
            'alertas_aplicacao' => false,
        ]));

        Invoice::query()->create([
            'user_id' => $recipient->id,
            'data_fatura' => now()->toDateString(),
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addWeek()->toDateString(),
            'mes' => now()->format('Y-m'),
            'valor_total' => 45,
            'oculta' => false,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        $campaign = CommunicationCampaign::query()->sole();

        $this->assertSame(['email'], $campaign->channels()->pluck('channel')->all());
        $this->assertSame(0, InAppAlert::query()->count());
    }

    public function test_invoice_automation_creates_no_campaign_when_all_channels_are_disabled(): void
    {
        Mail::fake();
        $recipient = User::factory()->create(['tipo_membro' => ['atleta']]);

        NotificationPreference::query()->create($this->preferences([
            'email_notificacoes' => false,
            'alertas_aplicacao' => false,
        ]));

        Invoice::query()->create([
            'user_id' => $recipient->id,
            'data_fatura' => now()->toDateString(),
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addWeek()->toDateString(),
            'mes' => now()->format('Y-m'),
            'valor_total' => 45,
            'oculta' => false,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        $this->assertSame(0, CommunicationCampaign::query()->count());
        $this->assertSame(0, InAppAlert::query()->count());
    }

    private function preferences(array $overrides = []): array
    {
        return array_merge([
            'email_notificacoes' => true,
            'alertas_aplicacao' => true,
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
        ], $overrides);
    }
}
