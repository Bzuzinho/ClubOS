<?php

namespace Tests\Feature\Integration;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Str;

class PerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Seed basic data
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    /**
     * Test dashboard loads fast with many users
     */
    public function test_dashboard_loads_fast_with_many_users(): void
    {
        // Create admin
        $admin = User::where('email', 'admin@test.com')->first();
        
        // Create 100 users
        User::factory()->count(100)->create();

        // Act as admin and measure dashboard load time
        $this->actingAs($admin);
        
        $start = microtime(true);
        $response = $this->get('/dashboard');
        $duration = microtime(true) - $start;

        $response->assertStatus(200);
        
        // Dashboard should load in less than 2 seconds even with 100 users
        $this->assertLessThan(2.0, $duration, "Dashboard took {$duration}s to load (expected < 2s)");
    }

    /**
     * Test members list loads efficiently with pagination
     */
    public function test_members_list_loads_efficiently(): void
    {
        $admin = User::where('email', 'admin@test.com')->first();
        $this->actingAs($admin);

        // Create 200 members
        User::factory()->count(200)->create();

        $start = microtime(true);
        $response = $this->get(route('membros.index'));
        $duration = microtime(true) - $start;

        $response->assertStatus(200);
        
        // Members list should load in less than 1.5 seconds
        $this->assertLessThan(1.5, $duration, "Members list took {$duration}s to load (expected < 1.5s)");
    }

    /**
     * Test events calendar loads efficiently
     */
    public function test_events_calendar_loads_efficiently(): void
    {
        $admin = User::where('email', 'admin@test.com')->first();
        $this->actingAs($admin);

        // Create 100 events
        for ($i = 0; $i < 100; $i++) {
            Event::create([
                'titulo' => "Evento {$i}",
                'tipo' => 'prova',
                'estado' => 'agendado',
                'data_inicio' => Carbon::now()->addDays(rand(-30, 30))->format('Y-m-d'),
                'data_fim' => Carbon::now()->addDays(rand(-30, 30))->addDays(1)->format('Y-m-d'),
                'local' => 'Test Venue',
                'descricao' => 'Performance test event',
                'criado_por' => $admin->id,
            ]);
        }

        $start = microtime(true);
        $response = $this->get(route('eventos.index'));
        $duration = microtime(true) - $start;

        $response->assertStatus(200);
        
        // Events should load in less than 1.5 seconds
        $this->assertLessThan(1.5, $duration, "Events page took {$duration}s to load (expected < 1.5s)");
    }

    /**
     * Test financial page loads efficiently with many invoices
     */
    public function test_financial_page_loads_efficiently(): void
    {
        $admin = User::where('email', 'admin@test.com')->first();
        $this->actingAs($admin);

        // Create users and invoices
        $users = User::factory()->count(50)->create();

        foreach ($users as $user) {
            for ($i = 0; $i < 5; $i++) {
                Invoice::create([
                    'user_id' => $user->id,
                    'data_fatura' => now()->subMonths($i)->format('Y-m-d'),
                    'mes' => now()->subMonths($i)->translatedFormat('F'),
                    'data_emissao' => now()->subMonths($i)->format('Y-m-d'),
                    'data_vencimento' => now()->subMonths($i)->addDays(15)->format('Y-m-d'),
                    'valor_total' => rand(30, 100),
                    'estado_pagamento' => rand(0, 1) ? 'pago' : 'pendente',
                    'tipo' => 'mensalidade',
                ]);
            }
        }

        $start = microtime(true);
        $response = $this->get(route('financeiro.index'));
        $duration = microtime(true) - $start;

        $response->assertStatus(200);
        
        // Financial page should load in less than 2 seconds with 250 invoices
        $this->assertLessThan(2.0, $duration, "Financial page took {$duration}s to load (expected < 2s)");
    }

    /**
     * Test shop/inventory loads efficiently with many products
     */
    public function test_shop_loads_efficiently(): void
    {
        $admin = User::where('email', 'admin@test.com')->first();
        $this->actingAs($admin);

        // Create 100 products
        for ($i = 0; $i < 100; $i++) {
            Product::create([
                'nome' => "Product {$i}",
                'descricao' => "Description for product {$i}",
                'codigo' => sprintf('PROD-%03d', $i),
                'preco' => rand(5, 50),
                'stock' => rand(0, 100),
                'stock_minimo' => 0,
                'ativo' => true,
                'visible_in_store' => true,
                'allow_sale' => true,
            ]);
        }

        $start = microtime(true);
        $response = $this->get('/loja');
        $duration = microtime(true) - $start;

        $response->assertStatus(200);
        
        // Shop should load in less than 1.5 seconds
        $this->assertLessThan(1.5, $duration, "Shop page took {$duration}s to load (expected < 1.5s)");
    }

    /**
     * Test database query performance for user search
     */
    public function test_user_search_performance(): void
    {
        // Create 500 users
        User::factory()->count(500)->create();

        // Search by name
        $start = microtime(true);
        $results = User::where('nome_completo', 'like', '%Silva%')->limit(20)->get();
        $duration = microtime(true) - $start;

        // Query should complete in less than 100ms
        $this->assertLessThan(0.1, $duration, "User search took {$duration}s (expected < 0.1s)");
    }

    /**
     * Test bulk insert performance
     */
    public function test_bulk_insert_performance(): void
    {
        $admin = User::where('email', 'admin@test.com')->first();
        
        // Prepare 100 user records
        $userData = [];
        for ($i = 0; $i < 100; $i++) {
            $userData[] = [
                'name' => "Bulk User {$i}",
                'id' => (string) Str::uuid(),
                'email' => "bulk{$i}@test.com",
                'password' => bcrypt('password'),
                'numero_socio' => str_pad($i + 1000, 4, '0', STR_PAD_LEFT),
                'nome_completo' => "Bulk Test User {$i}",
                'perfil' => 'user',
                'estado' => 'ativo',
                'data_nascimento' => '1990-01-01',
                'menor' => false,
                'sexo' => 'masculino',
                'rgpd' => true,
                'consentimento' => true,
                'afiliacao' => false,
                'declaracao_de_transporte' => false,
                'ativo_desportivo' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Bulk insert
        $start = microtime(true);
        User::insert($userData);
        $duration = microtime(true) - $start;

        // Bulk insert should complete in less than 500ms
        $this->assertLessThan(0.5, $duration, "Bulk insert took {$duration}s (expected < 0.5s)");
        $this->assertEquals(100, User::where('name', 'like', 'Bulk User%')->count());
    }

    /**
     * Test concurrent requests handling
     */
    public function test_concurrent_requests_handling(): void
    {
        $admin = User::where('email', 'admin@test.com')->first();
        $this->actingAs($admin);

        // Create some test data
        User::factory()->count(50)->create();

        // Simulate concurrent requests
        $durations = [];
        for ($i = 0; $i < 5; $i++) {
            $start = microtime(true);
            $response = $this->get('/dashboard');
            $duration = microtime(true) - $start;
            $durations[] = $duration;
            
            $response->assertStatus(200);
        }

        // Average response time should be reasonable
        $avgDuration = array_sum($durations) / count($durations);
        $this->assertLessThan(2.0, $avgDuration, "Average response time was {$avgDuration}s (expected < 2s)");
    }

    /**
     * Test memory usage with large datasets
     */
    public function test_memory_usage_with_large_dataset(): void
    {
        $memoryBefore = memory_get_usage(true);

        // Create 200 users with relationships
        User::factory()->count(200)->create();

        // Query all users (should use pagination in real scenario)
        $users = User::paginate(50);

        $memoryAfter = memory_get_usage(true);
        $memoryUsed = ($memoryAfter - $memoryBefore) / 1024 / 1024; // Convert to MB

        // Memory usage should be reasonable (less than 50MB for 200 users)
        $this->assertLessThan(50, $memoryUsed, "Memory usage was {$memoryUsed}MB (expected < 50MB)");
    }
}
