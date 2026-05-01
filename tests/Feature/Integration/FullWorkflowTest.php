<?php

namespace Tests\Feature\Integration;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Event;
use App\Models\EventType;
use App\Models\EventConvocation;
use App\Models\EventAttendance;
use App\Models\MonthlyFee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Movement;
use App\Models\MovementItem;
use App\Models\CostCenter;
use Carbon\Carbon;

class FullWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Seed basic data
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    /**
     * Test complete member workflow from creation to payment
     */
    public function test_complete_member_workflow(): void
    {
        // 1. Create admin user
        $admin = User::where('email', 'admin@test.com')->first();
        $this->assertNotNull($admin);

        // 2. Create a new member directly (not via HTTP)
        $member = User::create([
            'name' => 'Test Athlete',
            'numero_socio' => '999',
            'nome_completo' => 'Test Athlete',
            'email' => 'athlete@test.com',
            'password' => bcrypt('password123'),
            'perfil' => 'atleta',
            'estado' => 'ativo',
            'data_nascimento' => '2005-05-15',
            'sexo' => 'masculino',
            'tipo_membro' => ['Atleta'],
            'menor' => true,
            'rgpd' => true,
            'consentimento' => true,
            'afiliacao' => true,
            'declaracao_de_transporte' => true,
            'ativo_desportivo' => true,
        ]);
        $this->assertNotNull($member);
        $this->assertEquals('Test Athlete', $member->nome_completo);
        $this->assertEquals('atleta', $member->perfil);

        // 3. Create an event
        $eventType = EventType::where('nome', 'Treino')->first();
        $this->assertNotNull($eventType);

        $event = Event::create([
            'titulo' => 'Training Session Test',
            'tipo' => $eventType->id,
            'data_inicio' => Carbon::now()->addDays(7)->format('Y-m-d H:i:s'),
            'data_fim' => Carbon::now()->addDays(7)->addHours(2)->format('Y-m-d H:i:s'),
            'local' => 'Test Venue',
            'descricao' => 'Test training session',
            'escaloes_elegiveis' => ['Juvenis'],
            'criado_por' => $admin->id,
        ]);
        $this->assertNotNull($event);

        // 4. Convoke member to event
        EventConvocation::create([
            'evento_id' => $event->id,
            'user_id' => $member->id,
            'data_convocatoria' => now()->format('Y-m-d'),
            'estado_confirmacao' => 'convocado',
        ]);

        $this->assertDatabaseHas('event_convocations', [
            'evento_id' => $event->id,
            'user_id' => $member->id,
            'estado_confirmacao' => 'convocado',
        ]);

        // 5. Mark presence
        EventAttendance::create([
            'evento_id' => $event->id,
            'user_id' => $member->id,
            'estado' => 'presente',
            'registado_por' => $admin->id,
            'registado_em' => now(),
        ]);

        $this->assertDatabaseHas('event_attendances', [
            'evento_id' => $event->id,
            'user_id' => $member->id,
            'estado' => 'presente',
        ]);

        // 6. Generate invoice for monthly fee
        $invoice = Invoice::create([
            'user_id' => $member->id,
            'data_fatura' => now()->format('Y-m-d'),
            'data_emissao' => now()->format('Y-m-d'),
            'data_vencimento' => now()->addDays(10)->format('Y-m-d'),
            'mes' => now()->format('F'),
            'tipo' => 'mensalidade',
            'valor_total' => 50.00,
            'estado_pagamento' => 'pendente',
            'observacoes' => 'Monthly fee test',
        ]);

        InvoiceItem::create([
            'fatura_id' => $invoice->id,
            'descricao' => 'Mensalidade ' . now()->format('F Y'),
            'quantidade' => 1,
            'valor_unitario' => 50.00,
            'total_linha' => 50.00,
        ]);

        $this->assertDatabaseHas('invoices', [
            'user_id' => $member->id,
            'estado_pagamento' => 'pendente',
        ]);

        // 7. Pay invoice
        $invoice->update([
            'estado_pagamento' => 'pago',
        ]);

        // 8. Create financial transaction for payment
        $costCenter = CostCenter::first();
        
        $movement = Movement::create([
            'user_id' => $member->id,
            'classificacao' => 'receita',
            'data_emissao' => now()->format('Y-m-d'),
            'data_vencimento' => now()->format('Y-m-d'),
            'valor_total' => 50.00,
            'estado_pagamento' => 'pago',
            'tipo' => 'mensalidade',
            'centro_custo_id' => $costCenter->id,
            'observacoes' => 'Payment from ' . $member->nome_completo,
        ]);

        MovementItem::create([
            'movimento_id' => $movement->id,
            'descricao' => 'Monthly fee payment',
            'quantidade' => 1,
            'valor_unitario' => 50.00,
            'total_linha' => 50.00,
        ]);

        $this->assertDatabaseHas('movements', [
            'classificacao' => 'receita',
            'tipo' => 'mensalidade',
            'valor_total' => 50.00,
        ]);

        // 9. Verify stats updated
        $this->assertEquals(1, Event::where('titulo', 'Training Session Test')->count());
        $this->assertEquals(1, EventAttendance::where('user_id', $member->id)->where('estado', 'presente')->count());
        $this->assertEquals(1, Invoice::where('user_id', $member->id)->where('estado_pagamento', 'pago')->count());
        $this->assertEquals(1, Movement::where('classificacao', 'receita')->where('tipo', 'mensalidade')->count());
    }

    /**
     * Test event creation and attendance workflow
     */
    public function test_event_attendance_workflow(): void
    {
        $admin = User::where('email', 'admin@test.com')->first();
        $this->actingAs($admin);

        // Create athletes
        $athletes = User::factory()->count(5)->create([
            'perfil' => 'atleta',
            'ativo_desportivo' => true,
        ]);

        // Create event
        $eventType = EventType::first();
        $event = Event::create([
            'titulo' => 'Team Practice',
            'tipo' => $eventType->id,
            'data_inicio' => Carbon::now()->addDays(3)->format('Y-m-d H:i:s'),
            'data_fim' => Carbon::now()->addDays(3)->addHours(2)->format('Y-m-d H:i:s'),
            'local' => 'Sports Hall',
            'descricao' => 'Weekly team practice',
            'escaloes_elegiveis' => ['Juvenis'],
            'criado_por' => $admin->id,
        ]);

        // Convoke all athletes
        foreach ($athletes as $athlete) {
            EventConvocation::create([
                'evento_id' => $event->id,
                'user_id' => $athlete->id,
                'data_convocatoria' => now()->format('Y-m-d'),
                'estado_confirmacao' => 'convocado',
            ]);
        }

        // Mark 3 present, 2 absent
        foreach ($athletes->take(3) as $athlete) {
            EventAttendance::create([
                'evento_id' => $event->id,
                'user_id' => $athlete->id,
                'estado' => 'presente',
                'registado_por' => $admin->id,
                'registado_em' => now(),
            ]);
        }

        foreach ($athletes->skip(3) as $athlete) {
            EventAttendance::create([
                'evento_id' => $event->id,
                'user_id' => $athlete->id,
                'estado' => 'ausente',
                'registado_por' => $admin->id,
                'registado_em' => now(),
                'observacoes' => 'Medical excuse',
            ]);
        }

        // Verify
        $this->assertEquals(5, EventConvocation::where('evento_id', $event->id)->count());
        $this->assertEquals(3, EventAttendance::where('evento_id', $event->id)->where('estado', 'presente')->count());
        $this->assertEquals(2, EventAttendance::where('evento_id', $event->id)->where('estado', 'ausente')->count());
    }

    /**
     * Test financial workflow: invoice generation and payment
     */
    public function test_financial_workflow(): void
    {
        $admin = User::where('email', 'admin@test.com')->first();
        $this->actingAs($admin);

        $member = User::factory()->create([
            'perfil' => 'atleta',
        ]);

        // Generate invoice
        $invoice = Invoice::create([
            'user_id' => $member->id,
            'data_fatura' => now()->format('Y-m-d'),
            'data_emissao' => now()->format('Y-m-d'),
            'data_vencimento' => now()->addDays(15)->format('Y-m-d'),
            'mes' => now()->format('F'),
            'tipo' => 'fatura',
            'valor_total' => 0,
            'estado_pagamento' => 'pendente',
        ]);

        // Add items
        $items = [
            ['Monthly Fee', 50.00],
            ['Equipment', 25.00],
        ];

        $total = 0;
        foreach ($items as $itemData) {
            $item = InvoiceItem::create([
                'fatura_id' => $invoice->id,
                'descricao' => $itemData[0],
                'quantidade' => 1,
                'valor_unitario' => $itemData[1],
                'total_linha' => $itemData[1],
            ]);
            $total += $item->total_linha;
        }

        $invoice->update(['valor_total' => $total]);

        // Verify invoice
        $this->assertEquals(75.00, $invoice->valor_total);
        $this->assertEquals(2, $invoice->items()->count());

        // Pay invoice
        $invoice->update([
            'estado_pagamento' => 'pago',
        ]);

        // Create movement for payment
        $costCenter = CostCenter::first();
        Movement::create([
            'user_id' => $member->id,
            'classificacao' => 'receita',
            'data_emissao' => now()->format('Y-m-d'),
            'data_vencimento' => now()->format('Y-m-d'),
            'valor_total' => $invoice->valor_total,
            'estado_pagamento' => 'pago',
            'tipo' => 'pagamento',
            'centro_custo_id' => $costCenter->id,
            'observacoes' => 'Invoice payment',
        ]);

        // Verify payment recorded
        $this->assertEquals('pago', $invoice->fresh()->estado_pagamento);
        $this->assertDatabaseHas('movements', [
            'classificacao' => 'receita',
            'valor_total' => 75.00,
        ]);
    }

    /**
     * Test user CRUD operations
     */
    public function test_user_crud_operations(): void
    {
        $admin = User::where('email', 'admin@test.com')->first();
        $this->actingAs($admin);

        // Create
        $user = User::factory()->create([
            'numero_socio' => '500',
            'nome_completo' => 'CRUD Test User',
        ]);

        $this->assertDatabaseHas('users', [
            'numero_socio' => '500',
            'nome_completo' => 'CRUD Test User',
        ]);

        // Read
        $foundUser = User::where('numero_socio', '500')->first();
        $this->assertEquals('CRUD Test User', $foundUser->nome_completo);

        // Update
        $foundUser->update(['nome_completo' => 'Updated Name']);
        $this->assertDatabaseHas('users', [
            'numero_socio' => '500',
            'nome_completo' => 'Updated Name',
        ]);

        // Delete
        $foundUser->delete();
        $this->assertDatabaseMissing('users', [
            'numero_socio' => '500',
        ]);
    }
}
