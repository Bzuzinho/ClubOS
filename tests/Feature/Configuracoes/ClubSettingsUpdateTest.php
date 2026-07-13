<?php

namespace Tests\Feature\Configuracoes;

use App\Models\ClubSetting;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Financeiro\MonthlyFeeCycleLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ClubSettingsUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_club_settings_can_be_created_via_method_spoofed_form_submission(): void
    {
        Storage::fake('public');

        $user = User::factory()->admin()->create();
        $logo = UploadedFile::fake()->image('club-logo.png');

        $this->actingAs($user)
            ->post(route('configuracoes.clube.update'), [
                '_method' => 'put',
                'nome_clube' => 'Clube Benedita',
                'sigla' => 'CB',
                'morada' => 'Rua Principal 1',
                'codigo_postal' => '2475-000',
                'localidade' => 'Benedita',
                'telefone' => '912345678',
                'email' => 'geral@clubebenedita.pt',
                'website' => 'https://clubebenedita.pt',
                'nif' => '123456789',
                'iban' => 'PT50000201231234567890154',
                'logo' => $logo,
            ])
            ->assertRedirect(route('configuracoes'));

        $settings = ClubSetting::query()->first();

        $this->assertNotNull($settings);
        $this->assertSame('Clube Benedita', $settings->nome_clube);
        $this->assertSame('CB', $settings->sigla);
        $this->assertSame('Rua Principal 1', $settings->morada);
        $this->assertSame('2475-000', $settings->codigo_postal);
        $this->assertSame('Benedita', $settings->localidade);
        $this->assertSame('912345678', $settings->telefone);
        $this->assertSame('geral@clubebenedita.pt', $settings->email);
        $this->assertSame('https://clubebenedita.pt', $settings->website);
        $this->assertSame('123456789', $settings->nif);
        $this->assertSame('PT50000201231234567890154', $settings->iban);
        $this->assertStringStartsWith('/storage/club-logos/', (string) $settings->logo_url);

        $storedPath = ltrim((string) str_replace('/storage/', '', $settings->logo_url), '/');
        Storage::disk('public')->assertExists($storedPath);
    }

    public function test_existing_club_settings_are_updated(): void
    {
        Storage::fake('public');

        $user = User::factory()->admin()->create();
        $existing = ClubSetting::create([
            'nome_clube' => 'Nome Antigo',
            'sigla' => 'OLD',
            'logo_url' => '/storage/club-logos/old.png',
        ]);

        $this->actingAs($user)
            ->post(route('configuracoes.clube.update'), [
                '_method' => 'put',
                'nome_clube' => 'Nome Novo',
                'sigla' => 'NEW',
                'morada' => 'Nova morada',
                'codigo_postal' => '2500-000',
                'localidade' => 'Caldas da Rainha',
                'telefone' => '934567890',
                'email' => 'novo@clube.pt',
                'website' => 'https://novo.clube.pt',
                'nif' => '987654321',
                'iban' => 'PT50000201239876543210987',
            ])
            ->assertRedirect(route('configuracoes'));

        $existing->refresh();

        $this->assertSame('Nome Novo', $existing->nome_clube);
        $this->assertSame('NEW', $existing->sigla);
        $this->assertSame('Nova morada', $existing->morada);
        $this->assertSame('2500-000', $existing->codigo_postal);
        $this->assertSame('Caldas da Rainha', $existing->localidade);
        $this->assertSame('934567890', $existing->telefone);
        $this->assertSame('novo@clube.pt', $existing->email);
        $this->assertSame('https://novo.clube.pt', $existing->website);
        $this->assertSame('987654321', $existing->nif);
        $this->assertSame('PT50000201239876543210987', $existing->iban);
        $this->assertSame('/storage/club-logos/old.png', $existing->logo_url);
    }

    public function test_club_settings_update_rolls_back_when_monthly_cycle_reconciliation_fails(): void
    {
        $user = User::factory()->admin()->create();
        $settings = ClubSetting::create([
            'nome_clube' => 'Nome Antigo',
            'sigla' => 'OLD',
            'monthly_fee_due_day' => 1,
        ]);
        $invoice = Invoice::create([
            'user_id' => $user->id,
            'data_fatura' => '2026-08-01',
            'mes' => '2026-08',
            'data_emissao' => '2026-08-01',
            'data_vencimento' => '2026-08-01',
            'valor_total' => 40,
            'valor_pago' => 0,
            'valor_em_aberto' => 40,
            'oculta' => true,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'origem_tipo' => 'monthly_fee',
        ]);

        $lifecycle = \Mockery::mock(MonthlyFeeCycleLifecycleService::class);
        $lifecycle->shouldReceive('reconcileFutureInvoicesForSettingsChange')
            ->once()
            ->andThrow(new RuntimeException('forced cycle reconciliation failure'));
        $this->app->instance(MonthlyFeeCycleLifecycleService::class, $lifecycle);

        $this->actingAs($user)
            ->from(route('configuracoes'))
            ->put(route('configuracoes.clube.update'), [
                'nome_clube' => 'Nome Novo',
                'sigla' => 'NEW',
                'monthly_fee_due_day' => 15,
            ])
            ->assertServerError();

        $settings->refresh();
        $invoice->refresh();

        $this->assertSame('Nome Antigo', $settings->nome_clube);
        $this->assertSame('OLD', $settings->sigla);
        $this->assertSame(1, $settings->monthly_fee_due_day);
        $this->assertSame('pendente', $invoice->estado_pagamento);
        $this->assertSame('2026-08-01', $invoice->data_vencimento->toDateString());
    }
}
