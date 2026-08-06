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
        $recipient = $this->preparePreferences([
            'email_notificacoes' => true,
            'alertas_aplicacao' => false,
        ]);

        $this->createInvoice($recipient);

        $campaign = CommunicationCampaign::query()->sole();

        $this->assertSame(['email'], $campaign->channels()->pluck('channel')->all());
        $this->assertSame(0, InAppAlert::query()->count());
    }

    public function test_invoice_automation_uses_in_app_only_when_email_channel_is_disabled(): void
    {
        $recipient = $this->preparePreferences([
            'email_notificacoes' => false,
            'alertas_aplicacao' => true,
        ]);

        $this->createInvoice($recipient);

        $campaign = CommunicationCampaign::query()->sole();

        $this->assertSame(['alert_app'], $campaign->channels()->pluck('channel')->all());
        $this->assertSame(1, InAppAlert::query()->where('user_id', $recipient->id)->count());
    }

    public function test_invoice_automation_uses_both_channels_when_both_are_enabled(): void
    {
        $recipient = $this->preparePreferences([
            'email_notificacoes' => true,
            'alertas_aplicacao' => true,
        ]);

        $this->createInvoice($recipient);

        $campaign = CommunicationCampaign::query()->sole();

        $this->assertEqualsCanonicalizing(
            ['email', 'alert_app'],
            $campaign->channels()->pluck('channel')->all(),
        );
        $this->assertSame(1, InAppAlert::query()->where('user_id', $recipient->id)->count());
    }

    public function test_invoice_automation_creates_no_campaign_when_all_channels_are_disabled(): void
    {
        $recipient = $this->preparePreferences([
            'email_notificacoes' => false,
            'alertas_aplicacao' => false,
        ]);

        $this->createInvoice($recipient);

        $this->assertSame(0, CommunicationCampaign::query()->count());
        $this->assertSame(0, InAppAlert::query()->count());
    }

    public function test_payment_category_switch_blocks_financial_invoice_automation(): void
    {
        $recipient = $this->preparePreferences([
            'alertas_pagamento' => false,
        ]);

        $this->createInvoice($recipient);

        $this->assertSame(0, CommunicationCampaign::query()->count());
        $this->assertSame(0, InAppAlert::query()->count());
    }

    public function test_financial_module_switch_blocks_financial_invoice_automation(): void
    {
        $recipient = $this->preparePreferences([
            'automacoes_financeiro' => false,
        ]);

        $this->createInvoice($recipient);

        $this->assertSame(0, CommunicationCampaign::query()->count());
        $this->assertSame(0, InAppAlert::query()->count());
    }

    public function test_specific_invoice_switch_blocks_only_invoice_automation(): void
    {
        $recipient = $this->preparePreferences([
            'automacoes_faturas_financeiras' => false,
        ]);

        $this->createInvoice($recipient);

        $this->assertSame(0, CommunicationCampaign::query()->count());
        $this->assertSame(0, InAppAlert::query()->count());
    }

    private function preparePreferences(array $overrides): User
    {
        Mail::fake();

        $recipient = User::factory()->create(['tipo_membro' => ['atleta']]);

        NotificationPreference::query()->create($this->preferences($overrides));

        return $recipient;
    }

    private function createInvoice(User $recipient): Invoice
    {
        return Invoice::query()->create([
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
