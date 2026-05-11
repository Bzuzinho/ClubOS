<?php

namespace Tests\Feature\Communication;

use App\Models\CommunicationCampaign;
use App\Models\CommunicationDelivery;
use App\Models\CommunicationSegment;
use App\Models\CommunicationTemplate;
use App\Models\Event;
use App\Models\EventConvocation;
use App\Models\InAppAlert;
use App\Models\Invoice;
use App\Models\LogisticsRequest;
use App\Models\Movement;
use App\Models\NotificationPreference;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Jobs\ProcessCommunicationCampaignJob;
use App\Services\Communication\CommunicationCampaignService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CommunicationFlowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        NotificationPreference::create($this->notificationPreferencePayload());
    }

    public function test_campaign_store_rejects_template_from_different_channel(): void
    {
        $user = User::factory()->create();
        $segment = CommunicationSegment::create([
            'name' => 'Segmento Manual',
            'type' => 'manual',
            'rules_json' => ['source' => 'manual', 'user_ids' => [$user->id]],
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $template = CommunicationTemplate::create([
            'name' => 'Template SMS',
            'channel' => 'sms',
            'category' => 'geral',
            'body' => 'Mensagem SMS',
            'status' => 'ativo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->from(route('comunicacao.index'))
            ->post(route('comunicacao.campaigns.store'), [
                'title' => 'Campanha invalida',
                'segment_id' => $segment->id,
                'channels' => [
                    [
                        'channel' => 'email',
                        'template_id' => $template->id,
                        'is_enabled' => true,
                    ],
                ],
            ])
            ->assertRedirect(route('comunicacao.index'))
            ->assertSessionHasErrors('channels.0.template_id');
    }

    public function test_campaign_store_with_schedule_mode_creates_scheduled_campaign(): void
    {
        $user = User::factory()->create();
        $segment = CommunicationSegment::create([
            'name' => 'Segmento Agendado',
            'type' => 'manual',
            'rules_json' => ['source' => 'manual', 'user_ids' => [$user->id]],
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $scheduledAt = now()->addHour()->format('Y-m-d H:i:s');

        $this->actingAs($user)
            ->from(route('comunicacao.index'))
            ->post(route('comunicacao.campaigns.store'), [
                'title' => 'Campanha agendada',
                'segment_id' => $segment->id,
                'submission_mode' => 'schedule',
                'scheduled_at' => $scheduledAt,
                'channels' => [
                    [
                        'channel' => 'interno',
                        'message_body' => 'Mensagem interna',
                        'is_enabled' => true,
                    ],
                ],
            ])
            ->assertRedirect(route('comunicacao.index'));

        $campaign = CommunicationCampaign::query()->latest('created_at')->first();

        $this->assertNotNull($campaign);
        $this->assertSame('agendada', $campaign->status);
        $this->assertNotNull($campaign->scheduled_at);
    }

    public function test_campaign_store_with_send_mode_starts_processing_flow(): void
    {
        Queue::fake();

        $author = User::factory()->create();
        $recipient = User::factory()->create([
            'tipo_membro' => ['atleta'],
        ]);

        $segment = CommunicationSegment::create([
            'name' => 'Segmento envio imediato',
            'type' => 'manual',
            'rules_json' => ['source' => 'manual', 'user_ids' => [$recipient->id]],
            'is_active' => true,
            'created_by' => $author->id,
        ]);

        $this->actingAs($author)
            ->from(route('comunicacao.index'))
            ->post(route('comunicacao.campaigns.store'), [
                'title' => 'Campanha enviada',
                'segment_id' => $segment->id,
                'submission_mode' => 'send',
                'channels' => [
                    [
                        'channel' => 'interno',
                        'message_body' => 'Mensagem pronta para envio',
                        'is_enabled' => true,
                    ],
                ],
            ])
            ->assertRedirect(route('comunicacao.index'));

        $campaign = CommunicationCampaign::query()->latest('created_at')->first();

        $this->assertNotNull($campaign);
        $this->assertSame('em_processamento', $campaign->fresh()->status);
        Queue::assertPushed(ProcessCommunicationCampaignJob::class);
    }

    public function test_individual_send_rejects_template_from_different_category(): void
    {
        $user = User::factory()->create();
        $recipient = User::factory()->create([
            'tipo_membro' => ['atleta'],
        ]);

        $template = CommunicationTemplate::create([
            'name' => 'Template Comportamento',
            'channel' => 'email',
            'category' => 'comportamento',
            'subject' => 'Assunto',
            'body' => 'Mensagem {{nome}}',
            'status' => 'ativo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->from(route('comunicacao.index'))
            ->post(route('comunicacao.campaigns.sendIndividual'), [
                'title' => 'Teste',
                'alert_category' => 'mensalidade',
                'alert_title' => 'Aviso',
                'alert_message' => 'Mensagem base',
                'alert_type' => 'info',
                'recipient_user_ids' => [$recipient->id],
                'channels' => [
                    [
                        'channel' => 'email',
                        'template_id' => $template->id,
                        'is_enabled' => true,
                    ],
                ],
            ])
            ->assertRedirect(route('comunicacao.index'))
            ->assertSessionHasErrors('channels.0.template_id');
    }

    public function test_send_individual_uses_default_title_when_blank(): void
    {
        Mail::fake();
        Http::fake();

        $author = User::factory()->create();
        $recipient = User::factory()->create([
            'tipo_membro' => ['atleta'],
        ]);

        $campaign = app(CommunicationCampaignService::class)->sendIndividualCommunication([
            'title' => '   ',
            'alert_category' => 'mensalidade',
            'alert_title' => 'Alerta',
            'alert_message' => 'Mensagem individual',
            'alert_type' => 'info',
            'recipient_user_ids' => [$recipient->id],
            'channels' => [
                [
                    'channel' => 'alert_app',
                    'is_enabled' => true,
                    'message_body' => 'Mensagem individual',
                ],
            ],
        ], $author->id);

        $this->assertStringStartsWith('Alerta Individual - Mensalidade', $campaign->title);
        $this->assertSame('enviada', $campaign->fresh()->status);
    }

    public function test_direct_campaign_update_can_refresh_manual_segment_recipients(): void
    {
        $author = User::factory()->create();
        $recipientA = User::factory()->create(['tipo_membro' => ['atleta']]);
        $recipientB = User::factory()->create(['tipo_membro' => ['atleta']]);

        $campaign = app(CommunicationCampaignService::class)->sendIndividualCommunication([
            'title' => 'Alerta de teste',
            'alert_category' => 'geral',
            'alert_title' => 'Titulo',
            'alert_message' => 'Mensagem original',
            'alert_type' => 'info',
            'scheduled_at' => now()->addHour()->format('Y-m-d H:i:s'),
            'recipient_user_ids' => [$recipientA->id],
            'channels' => [
                [
                    'channel' => 'alert_app',
                    'is_enabled' => true,
                    'message_body' => 'Mensagem original',
                ],
            ],
        ], $author->id);

        $this->actingAs($author)
            ->from(route('comunicacao.index'))
            ->put(route('comunicacao.campaigns.update', $campaign->id), [
                'title' => 'Alerta editado',
                'description' => 'Mensagem editada',
                'segment_id' => $campaign->segment_id,
                'submission_mode' => 'schedule',
                'alert_category' => 'geral',
                'alert_title' => 'Titulo editado',
                'alert_message' => 'Mensagem editada',
                'alert_type' => 'info',
                'scheduled_at' => now()->addHours(2)->format('Y-m-d H:i:s'),
                'notes' => 'Envio individual | categoria: geral | modo: agendado',
                'recipient_user_ids' => [$recipientB->id],
                'channels' => [
                    [
                        'channel' => 'alert_app',
                        'is_enabled' => true,
                        'message_body' => 'Mensagem editada',
                    ],
                ],
            ])
            ->assertRedirect(route('comunicacao.index'));

        $campaign->refresh();

        $this->assertSame('Alerta editado', $campaign->title);
        $this->assertSame([$recipientB->id], $campaign->segment?->fresh()->rules_json['user_ids'] ?? []);
        $this->assertSame('agendada', $campaign->status);
    }

    public function test_comunicacao_index_loads_without_age_group_id_column_on_users(): void
    {
        $user = User::factory()->create([
            'estado' => 'ativo',
            'escalao' => [],
        ]);

        $this->actingAs($user)
            ->get(route('comunicacao.index'))
            ->assertOk();
    }

    public function test_campaign_creates_single_in_app_alert_batch_for_multiple_channels(): void
    {
        Mail::fake();

        $author = User::factory()->create();
        $recipient = User::factory()->create([
            'tipo_membro' => ['atleta'],
        ]);

        $segment = CommunicationSegment::create([
            'name' => 'Segmento Multi Canal',
            'type' => 'manual',
            'rules_json' => ['source' => 'manual', 'user_ids' => [$recipient->id]],
            'is_active' => true,
            'created_by' => $author->id,
        ]);

        $campaign = CommunicationCampaign::create([
            'codigo' => 'CMP-TEST-001',
            'title' => 'Campanha com alerta unico',
            'segment_id' => $segment->id,
            'author_id' => $author->id,
            'status' => 'rascunho',
            'create_in_app_alert' => true,
            'alert_title' => 'Alerta unico',
            'alert_message' => 'Mensagem para app',
            'alert_type' => 'info',
        ]);

        $campaign->channels()->createMany([
            [
                'channel' => 'email',
                'subject' => 'Assunto',
                'message_body' => 'Mensagem email',
                'is_enabled' => true,
            ],
            [
                'channel' => 'interno',
                'message_body' => 'Mensagem interna',
                'is_enabled' => true,
            ],
        ]);

        app(CommunicationCampaignService::class)->sendCampaign($campaign->load(['channels', 'segment']), $author->id, false);

        $this->assertSame(2, $campaign->fresh()->deliveries()->count());
        $this->assertSame(1, InAppAlert::query()->where('campaign_id', $campaign->id)->count());
    }

    public function test_invoice_creation_triggers_automatic_communication(): void
    {
        Mail::fake();

        $recipient = User::factory()->create([
            'tipo_membro' => ['atleta'],
        ]);

        Invoice::create([
            'user_id' => $recipient->id,
            'data_fatura' => now()->toDateString(),
            'mes' => 'Abril',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addWeek()->toDateString(),
            'valor_total' => 45.50,
            'oculta' => false,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        $campaign = CommunicationCampaign::query()->latest('created_at')->first();

        $this->assertNotNull($campaign);
        $this->assertSame('Nova fatura disponível', $campaign->alert_title);
        $this->assertStringContainsString('invoice:', $campaign->notes ?? '');
        $this->assertSame('enviada', $campaign->status);
        $this->assertSame([$recipient->id], $campaign->segment?->rules_json['user_ids'] ?? []);
    }

    public function test_future_invoice_creation_defers_automatic_communication_until_visible(): void
    {
        Mail::fake();

        Carbon::setTestNow('2025-04-10 10:00:00');

        try {
            $recipient = User::factory()->create([
                'tipo_membro' => ['atleta'],
            ]);

            $invoice = Invoice::create([
                'user_id' => $recipient->id,
                'data_fatura' => '2025-05-01',
                'mes' => 'Maio',
                'data_emissao' => now()->toDateString(),
                'data_vencimento' => now()->addMonth()->toDateString(),
                'valor_total' => 45.50,
                'oculta' => true,
                'estado_pagamento' => 'pendente',
                'tipo' => 'mensalidade',
            ]);

            $this->assertSame(0, CommunicationCampaign::query()->count());

            Carbon::setTestNow('2025-05-01 09:00:00');

            Artisan::call('comunicacao:libertar-alertas-faturas');

            $campaign = CommunicationCampaign::query()->latest('created_at')->first();

            $this->assertNotNull($campaign);
            $this->assertSame('Nova fatura disponível', $campaign->alert_title);
            $this->assertStringContainsString('origem: invoice:' . $invoice->id, $campaign->notes ?? '');
            $this->assertSame([$recipient->id], $campaign->segment?->rules_json['user_ids'] ?? []);

            Artisan::call('comunicacao:libertar-alertas-faturas');

            $this->assertSame(1, CommunicationCampaign::query()->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_invoice_automation_prefers_dedicated_templates_when_available(): void
    {
        Mail::fake();

        $recipient = User::factory()->create([
            'tipo_membro' => ['atleta'],
        ]);

        $emailTemplate = CommunicationTemplate::create([
            'name' => 'Automação Financeiro - Fatura Email',
            'channel' => 'email',
            'category' => 'mensalidade',
            'subject' => '{{titulo_comunicacao}}',
            'body' => '{{mensagem_alerta}}',
            'status' => 'ativo',
        ]);

        $appTemplate = CommunicationTemplate::create([
            'name' => 'Automação Financeiro - Fatura App',
            'channel' => 'alert_app',
            'category' => 'mensalidade',
            'body' => '{{mensagem_alerta}}',
            'status' => 'ativo',
        ]);

        Invoice::create([
            'user_id' => $recipient->id,
            'data_fatura' => now()->toDateString(),
            'mes' => 'Abril',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addWeek()->toDateString(),
            'valor_total' => 45.50,
            'oculta' => false,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        $campaign = CommunicationCampaign::query()->latest('created_at')->first();

        $this->assertNotNull($campaign);
        $this->assertSame($emailTemplate->id, $campaign->channels()->where('channel', 'email')->value('template_id'));
        $this->assertSame($appTemplate->id, $campaign->channels()->where('channel', 'alert_app')->value('template_id'));
    }

    public function test_movement_without_user_does_not_trigger_automatic_communication(): void
    {
        Mail::fake();

        Movement::create([
            'classificacao' => 'receita',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'valor_total' => 99.99,
            'estado_pagamento' => 'pendente',
            'tipo' => 'manual',
            'nome_manual' => 'Receita avulsa',
        ]);

        $this->assertSame(0, CommunicationCampaign::query()->count());
    }

    public function test_finance_automation_can_be_disabled_in_notification_preferences(): void
    {
        Mail::fake();

        NotificationPreference::query()->first()->update([
            'automacoes_financeiro' => false,
        ]);

        $recipient = User::factory()->create([
            'tipo_membro' => ['atleta'],
        ]);

        Invoice::create([
            'user_id' => $recipient->id,
            'data_fatura' => now()->toDateString(),
            'mes' => 'Abril',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addWeek()->toDateString(),
            'valor_total' => 45.50,
            'oculta' => false,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        $this->assertSame(0, CommunicationCampaign::query()->count());
        $this->assertSame(0, CommunicationDelivery::query()->count());
        $this->assertSame(0, InAppAlert::query()->count());
        Mail::assertNothingSent();
    }

    public function test_invoice_automation_does_not_create_campaign_when_specific_switch_is_disabled(): void
    {
        Mail::fake();

        NotificationPreference::query()->first()->update([
            'automacoes_faturas_financeiras' => false,
        ]);

        $recipient = User::factory()->create([
            'tipo_membro' => ['atleta'],
        ]);

        Invoice::create([
            'user_id' => $recipient->id,
            'data_fatura' => now()->toDateString(),
            'mes' => 'Abril',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addWeek()->toDateString(),
            'valor_total' => 45.50,
            'oculta' => false,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        $this->assertSame(0, CommunicationCampaign::query()->count());
        $this->assertSame(0, CommunicationDelivery::query()->count());
        $this->assertSame(0, InAppAlert::query()->count());
        Mail::assertNothingSent();
    }

    public function test_release_visible_invoice_communications_returns_zero_when_invoice_automation_is_disabled(): void
    {
        Mail::fake();

        NotificationPreference::query()->first()->update([
            'automacoes_faturas_financeiras' => false,
        ]);

        $recipient = User::factory()->create([
            'tipo_membro' => ['atleta'],
        ]);

        Invoice::create([
            'user_id' => $recipient->id,
            'data_fatura' => now()->addDay()->toDateString(),
            'mes' => 'Maio',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addMonth()->toDateString(),
            'valor_total' => 45.50,
            'oculta' => true,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        $exitCode = Artisan::call('comunicacao:libertar-alertas-faturas');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Comunicacoes de faturas libertadas: 0', Artisan::output());
        $this->assertSame(0, CommunicationCampaign::query()->count());
        $this->assertSame(0, CommunicationDelivery::query()->count());
        $this->assertSame(0, InAppAlert::query()->count());
        Mail::assertNothingSent();
    }

    public function test_disabling_payment_alerts_blocks_invoice_and_movement_campaigns(): void
    {
        Mail::fake();

        NotificationPreference::query()->first()->update([
            'alertas_pagamento' => false,
        ]);

        $recipient = User::factory()->create([
            'tipo_membro' => ['atleta'],
        ]);

        Invoice::create([
            'user_id' => $recipient->id,
            'data_fatura' => now()->toDateString(),
            'mes' => 'Abril',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addWeek()->toDateString(),
            'valor_total' => 45.50,
            'oculta' => false,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        Movement::create([
            'user_id' => $recipient->id,
            'classificacao' => 'receita',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'valor_total' => 99.99,
            'estado_pagamento' => 'pendente',
            'tipo' => 'manual',
            'nome_manual' => 'Receita avulsa',
        ]);

        $this->assertSame(0, CommunicationCampaign::query()->count());
        $this->assertSame(0, CommunicationDelivery::query()->count());
        $this->assertSame(0, InAppAlert::query()->count());
        Mail::assertNothingSent();
    }

    public function test_disabling_finance_automation_blocks_all_financial_automation_channels(): void
    {
        Mail::fake();

        NotificationPreference::query()->first()->update([
            'automacoes_financeiro' => false,
        ]);

        $recipient = User::factory()->create([
            'tipo_membro' => ['atleta'],
        ]);

        Invoice::create([
            'user_id' => $recipient->id,
            'data_fatura' => now()->toDateString(),
            'mes' => 'Abril',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addWeek()->toDateString(),
            'valor_total' => 45.50,
            'oculta' => false,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        Movement::create([
            'user_id' => $recipient->id,
            'classificacao' => 'receita',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'valor_total' => 99.99,
            'estado_pagamento' => 'pendente',
            'tipo' => 'manual',
            'nome_manual' => 'Receita avulsa',
        ]);

        $this->assertSame(0, CommunicationCampaign::query()->count());
        $this->assertSame(0, CommunicationDelivery::query()->count());
        $this->assertSame(0, InAppAlert::query()->count());
        Mail::assertNothingSent();
    }

    public function test_disabling_email_notifications_keeps_financial_app_alert_automation_active(): void
    {
        Mail::fake();

        NotificationPreference::query()->first()->update([
            'email_notificacoes' => false,
        ]);

        $recipient = User::factory()->create([
            'tipo_membro' => ['atleta'],
        ]);

        Invoice::create([
            'user_id' => $recipient->id,
            'data_fatura' => now()->toDateString(),
            'mes' => 'Abril',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addWeek()->toDateString(),
            'valor_total' => 45.50,
            'oculta' => false,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        $campaign = CommunicationCampaign::query()->latest('created_at')->first();

        $this->assertNotNull($campaign);
        $this->assertSame(['alert_app'], $campaign->channels()->pluck('channel')->all());
        $this->assertSame(1, CommunicationDelivery::query()->count());
        $this->assertSame(1, InAppAlert::query()->count());
        Mail::assertNothingSent();
    }

    public function test_event_convocation_creation_triggers_automatic_communication_with_event_context(): void
    {
        Mail::fake();

        $author = User::factory()->create();
        $recipient = User::factory()->create([
            'tipo_membro' => ['atleta'],
        ]);

        $event = Event::create([
            'titulo' => 'Torneio Regional',
            'descricao' => 'Competição regional',
            'data_inicio' => now()->addDays(10)->toDateString(),
            'hora_inicio' => '09:00:00',
            'data_fim' => now()->addDays(10)->toDateString(),
            'hora_fim' => '18:00:00',
            'local' => 'Piscina Municipal',
            'tipo' => 'competicao',
            'visibilidade' => 'publico',
            'estado' => 'publicado',
            'criado_por' => $author->id,
            'recorrente' => false,
        ]);

        EventConvocation::create([
            'evento_id' => $event->id,
            'user_id' => $recipient->id,
            'data_convocatoria' => now()->toDateString(),
            'estado_confirmacao' => 'pendente',
            'transporte_clube' => false,
        ]);

        $campaign = CommunicationCampaign::query()->latest('created_at')->first();

        $this->assertNotNull($campaign);
        $this->assertSame('Nova convocatória', $campaign->alert_title);
        $this->assertStringContainsString('event_convocation:', $campaign->notes ?? '');
        $this->assertSame($event->id, $campaign->segment?->rules_json['event_id'] ?? null);
        $this->assertSame([$recipient->id], $campaign->segment?->rules_json['user_ids'] ?? []);
    }

    public function test_logistics_request_creation_triggers_automatic_communication(): void
    {
        Mail::fake();

        $recipient = User::factory()->create([
            'tipo_membro' => ['atleta'],
        ]);

        LogisticsRequest::create([
            'requester_user_id' => $recipient->id,
            'requester_name_snapshot' => $recipient->nome_completo ?? $recipient->name,
            'requester_area' => 'Desportivo',
            'requester_type' => 'atleta',
            'status' => 'pending',
            'total_amount' => 12.50,
        ]);

        $campaign = CommunicationCampaign::query()->latest('created_at')->first();

        $this->assertNotNull($campaign);
        $this->assertSame('Requisição logística criada', $campaign->alert_title);
        $this->assertStringContainsString('logistics_request_created:', $campaign->notes ?? '');
        $this->assertSame([$recipient->id], $campaign->segment?->rules_json['user_ids'] ?? []);
    }

    public function test_logistics_request_status_change_triggers_automatic_communication(): void
    {
        Mail::fake();

        $recipient = User::factory()->create([
            'tipo_membro' => ['atleta'],
        ]);

        $request = LogisticsRequest::create([
            'requester_user_id' => $recipient->id,
            'requester_name_snapshot' => $recipient->nome_completo ?? $recipient->name,
            'requester_area' => 'Desportivo',
            'requester_type' => 'atleta',
            'status' => 'pending',
            'total_amount' => 20.00,
        ]);

        CommunicationCampaign::query()->delete();

        $request->update([
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $campaign = CommunicationCampaign::query()->latest('created_at')->first();

        $this->assertNotNull($campaign);
        $this->assertSame('Requisição aprovada', $campaign->alert_title);
        $this->assertStringContainsString('logistics_request_status:', $campaign->notes ?? '');
        $this->assertStringContainsString(':approved', $campaign->notes ?? '');
    }

    public function test_supplier_purchase_triggers_operational_internal_alert(): void
    {
        Mail::fake();

        $operator = User::factory()->create([
            'perfil' => 'admin',
            'tipo_membro' => ['gestor'],
        ]);

        $purchase = SupplierPurchase::create([
            'supplier_name_snapshot' => 'Fornecedor Azul',
            'invoice_reference' => 'FAT-2026-001',
            'invoice_date' => now()->toDateString(),
            'total_amount' => 250.00,
            'created_by' => $operator->id,
        ]);

        $campaign = CommunicationCampaign::query()->latest('created_at')->first();

        $this->assertNotNull($campaign);
        $this->assertSame('Nova compra de fornecedor', $campaign->alert_title);
        $this->assertStringContainsString('supplier_purchase:', $campaign->notes ?? '');
        $this->assertSame([$operator->id], $campaign->segment?->rules_json['user_ids'] ?? []);
        $this->assertSame(['alert_app'], $campaign->channels()->pluck('channel')->all());
        $this->assertSame('enviada', $campaign->status);
        $this->assertSame(1, InAppAlert::query()->where('campaign_id', $campaign->id)->count());
        $this->assertSame($purchase->id, str_replace('origem: supplier_purchase:', '', collect(explode(' | ', $campaign->notes ?? ''))->last()));
    }

    /**
     * @return array<string, bool>
     */
    private function notificationPreferencePayload(array $overrides = []): array
    {
        return array_merge([
            'email_notificacoes' => true,
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