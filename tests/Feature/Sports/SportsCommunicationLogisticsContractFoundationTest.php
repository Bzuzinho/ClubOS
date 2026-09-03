<?php

namespace Tests\Feature\Sports;

use App\Contracts\Logistica\SportsLogisticsGateway;
use App\Contracts\Logistica\SportsLogisticsRequest;
use App\Jobs\ProcessCommunicationCampaignJob;
use App\Models\CommunicationCampaign;
use App\Models\CommunicationDelivery;
use App\Models\ConvocationGroup;
use App\Models\Event;
use App\Models\EventConvocation;
use App\Models\LogisticsRequest;
use App\Models\NotificationPreference;
use App\Models\Product;
use App\Models\SportsCommunicationIntent;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Communication\CommunicationCampaignService;
use App\Services\Desportivo\SportsConvocationPublicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SportsCommunicationLogisticsContractFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        NotificationPreference::query()->create([
            'email_notificacoes' => true,
            'alertas_aplicacao' => true,
            'alertas_atividade' => true,
            'automacoes_eventos' => true,
            'automacoes_convocatorias_eventos' => true,
            'automacoes_logistica' => true,
            'automacoes_requisicoes_logistica' => true,
        ]);
    }

    public function test_group_managed_event_convocation_creation_does_not_send_before_explicit_publication(): void
    {
        Mail::fake();

        [$actor, $athlete, $event, $group] = $this->convocationFixture();

        EventConvocation::query()->create([
            'evento_id' => $event->id,
            'user_id' => $athlete->id,
            'data_convocatoria' => now()->toDateString(),
            'estado_confirmacao' => 'pendente',
            'transporte_clube' => false,
        ]);

        $this->assertSame(0, CommunicationCampaign::query()->count());
        $this->assertSame('draft', $group->fresh()->publication_status);
        Mail::assertNothingSent();
    }

    public function test_explicit_publication_is_idempotent_for_same_version(): void
    {
        Mail::fake();
        Queue::fake();

        [$actor, $athlete, $event, $group] = $this->convocationFixture();
        EventConvocation::query()->create([
            'evento_id' => $event->id,
            'user_id' => $athlete->id,
            'data_convocatoria' => now()->toDateString(),
            'estado_confirmacao' => 'pendente',
        ]);

        $service = app(SportsConvocationPublicationService::class);
        $first = $service->publish($group, $actor);
        $second = $service->publish($group->fresh(), $actor);

        $this->assertSame('published', $first['group']->publication_status);
        $this->assertSame(1, (int) $first['group']->publication_version);
        $this->assertSame('dispatched', $first['communication']->status);
        $this->assertSame($first['communication']->intentId, $second['communication']->intentId);
        $this->assertSame(1, SportsCommunicationIntent::query()->count());
        $this->assertSame(1, CommunicationCampaign::query()->count());
        $this->assertSame('em_processamento', CommunicationCampaign::query()->value('status'));
        $this->assertSame(0, CommunicationDelivery::query()->count());
        Queue::assertPushed(ProcessCommunicationCampaignJob::class, 1);
        $this->assertStringContainsString(
            'origem: sports_intent:',
            (string) CommunicationCampaign::query()->value('notes'),
        );
    }

    public function test_relevant_edit_after_publication_returns_group_to_draft_and_new_version_can_publish(): void
    {
        Mail::fake();

        [$actor, $athlete, $event, $group] = $this->convocationFixture();
        EventConvocation::query()->create([
            'evento_id' => $event->id,
            'user_id' => $athlete->id,
            'data_convocatoria' => now()->toDateString(),
            'estado_confirmacao' => 'pendente',
        ]);

        $service = app(SportsConvocationPublicationService::class);
        $service->publish($group, $actor);

        $group->fresh()->update(['local_encontro' => 'Entrada principal']);
        $draft = $group->fresh();

        $this->assertSame('draft', $draft->publication_status);
        $this->assertSame(2, (int) $draft->publication_version);

        $result = $service->publish($draft, $actor);

        $this->assertSame('published', $result['group']->publication_status);
        $this->assertSame(2, (int) $result['group']->publication_version);
        $this->assertSame(2, SportsCommunicationIntent::query()->count());
        $this->assertSame(2, CommunicationCampaign::query()->count());
    }

    public function test_disabled_communication_keeps_convocation_published_and_records_skipped_intent(): void
    {
        Mail::fake();
        NotificationPreference::query()->firstOrFail()->update(['automacoes_eventos' => false]);

        [$actor, $athlete, $event, $group] = $this->convocationFixture();
        EventConvocation::query()->create([
            'evento_id' => $event->id,
            'user_id' => $athlete->id,
            'data_convocatoria' => now()->toDateString(),
            'estado_confirmacao' => 'pendente',
        ]);

        $result = app(SportsConvocationPublicationService::class)->publish($group, $actor);

        $this->assertSame('published', $result['group']->publication_status);
        $this->assertSame('skipped', $result['communication']->status);
        $this->assertSame(0, CommunicationCampaign::query()->count());
        $this->assertDatabaseHas('sports_communication_intents', [
            'id' => $result['communication']->intentId,
            'status' => 'skipped',
        ]);
    }

    public function test_skipped_publication_can_dispatch_later_when_communication_is_reenabled_without_new_version(): void
    {
        Mail::fake();
        NotificationPreference::query()->firstOrFail()->update(['automacoes_eventos' => false]);

        [$actor, $athlete, $event, $group] = $this->convocationFixture();
        EventConvocation::query()->create([
            'evento_id' => $event->id,
            'user_id' => $athlete->id,
            'data_convocatoria' => now()->toDateString(),
            'estado_confirmacao' => 'pendente',
        ]);

        $service = app(SportsConvocationPublicationService::class);
        $skipped = $service->publish($group, $actor);
        $this->assertSame('skipped', $skipped['communication']->status);
        $this->assertSame(1, (int) $skipped['group']->publication_version);

        NotificationPreference::query()->firstOrFail()->update(['automacoes_eventos' => true]);
        $dispatched = $service->publish($group->fresh(), $actor);

        $this->assertSame('dispatched', $dispatched['communication']->status);
        $this->assertSame($skipped['communication']->intentId, $dispatched['communication']->intentId);
        $this->assertSame(1, SportsCommunicationIntent::query()->count());
        $this->assertSame(1, CommunicationCampaign::query()->count());
        $this->assertSame(1, (int) $dispatched['group']->publication_version);
    }

    public function test_delivery_failure_does_not_rollback_published_convocation(): void
    {
        Mail::fake();

        [$actor, $athlete, $event, $group] = $this->convocationFixture();
        EventConvocation::query()->create([
            'evento_id' => $event->id,
            'user_id' => $athlete->id,
            'data_convocatoria' => now()->toDateString(),
            'estado_confirmacao' => 'pendente',
        ]);

        $campaignService = new class extends CommunicationCampaignService {
            public function __construct()
            {
            }

            public function queueIndividualCommunication(array $payload, ?string $authorId = null): CommunicationCampaign
            {
                throw new \RuntimeException('delivery unavailable');
            }
        };
        $this->app->instance(CommunicationCampaignService::class, $campaignService);

        $result = app(SportsConvocationPublicationService::class)->publish($group, $actor);

        $this->assertSame('published', $result['group']->publication_status);
        $this->assertSame('failed', $result['communication']->status);
        $this->assertSame(0, CommunicationCampaign::query()->count());
        $this->assertDatabaseHas('sports_communication_intents', [
            'id' => $result['communication']->intentId,
            'status' => 'failed',
        ]);
    }

    public function test_sports_logistics_availability_is_read_only(): void
    {
        NotificationPreference::query()->firstOrFail()->update(['automacoes_logistica' => false]);
        $product = $this->requestableProduct(stock: 5);

        $rows = app(SportsLogisticsGateway::class)->inspectAvailability([$product->id]);

        $this->assertCount(1, $rows);
        $this->assertSame((string) $product->id, $rows[0]['article_id']);
        $this->assertSame(5, $rows[0]['available_quantity']);
        $this->assertTrue($rows[0]['is_available']);
        $this->assertSame(0, LogisticsRequest::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(5, (int) $product->fresh()->stock);
    }

    public function test_sports_logistics_request_is_idempotent_and_does_not_move_stock_on_request(): void
    {
        NotificationPreference::query()->firstOrFail()->update(['automacoes_logistica' => false]);
        $actor = User::factory()->create();
        $product = $this->requestableProduct(stock: 8);

        $request = new SportsLogisticsRequest(
            sourceType: 'training_material_need',
            sourceId: 'training-123',
            sourceVersion: 1,
            requesterUserId: $actor->id,
            requesterNameSnapshot: $actor->nome_completo ?? $actor->name ?? 'Treinador',
            requesterType: 'treinador',
            items: [[
                'article_id' => $product->id,
                'quantity' => 2,
            ]],
            notes: 'Material para treino',
            actorId: $actor->id,
        );

        $gateway = app(SportsLogisticsGateway::class);
        $first = $gateway->requestClubEquipment($request);
        $second = $gateway->requestClubEquipment($request);

        $this->assertFalse($first->reused);
        $this->assertTrue($second->reused);
        $this->assertSame($first->requestId, $second->requestId);
        $this->assertSame(1, LogisticsRequest::query()->count());
        $this->assertDatabaseHas('logistics_requests', [
            'id' => $first->requestId,
            'source_type' => 'training_material_need',
            'source_id' => 'training-123',
            'idempotency_key' => $request->idempotencyKey(),
        ]);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(8, (int) $product->fresh()->stock);
    }

    /** @return array{0:User,1:User,2:Event,3:ConvocationGroup} */
    private function convocationFixture(): array
    {
        $actor = User::factory()->create();
        $athlete = User::factory()->create(['tipo_membro' => ['atleta']]);
        $event = Event::query()->create([
            'titulo' => 'Meeting F6',
            'descricao' => 'Teste F6',
            'data_inicio' => now()->addWeek()->toDateString(),
            'data_fim' => now()->addWeek()->toDateString(),
            'local' => 'Piscina Municipal',
            'tipo' => 'competicao',
            'visibilidade' => 'publico',
            'estado' => 'agendado',
            'criado_por' => $actor->id,
            'recorrente' => false,
        ]);
        $group = ConvocationGroup::query()->create([
            'evento_id' => $event->id,
            'data_criacao' => now(),
            'criado_por' => $actor->id,
            'atletas_ids' => [$athlete->id],
            'hora_encontro' => '08:00',
            'local_encontro' => 'Entrada',
            'observacoes' => 'Levar equipamento',
            'tipo_custo' => 'por_salto',
        ]);

        return [$actor, $athlete, $event, $group];
    }

    private function requestableProduct(int $stock): Product
    {
        return Product::query()->create([
            'codigo' => 'F6-'.uniqid(),
            'nome' => 'Material F6',
            'preco' => 5,
            'preco_venda' => 5,
            'stock' => $stock,
            'stock_reservado' => 0,
            'stock_minimo' => 0,
            'ativo' => true,
            'visible_in_store' => false,
            'allow_sale' => false,
            'allow_request' => true,
            'allow_loan' => true,
            'track_stock' => true,
        ]);
    }
}
