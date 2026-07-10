<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\DadosPessoais;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Members\MemberDataReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsersCanonicalWriteContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_users_store_creates_canonical_personal_row(): void
    {
        $admin = User::factory()->admin()->create();

        $payload = [
            'nome_completo' => 'API Canonico Store',
            'email' => 'api.store@example.test',
            'data_nascimento' => '2010-01-15',
            'estado' => 'ativo',
            'perfil' => 'atleta',
            'sexo' => 'M',
            'morada' => 'Rua API Store',
            'contacto' => '910000001',
            'nif' => '123456789',
            'password' => 'password123',
        ];

        $response = $this->actingAs($admin)->postJson('/api/users', $payload);

        $response->assertCreated();

        $userId = (string) $response->json('id');
        $user = User::query()->findOrFail($userId);

        $this->assertSame('api.store@example.test', $user->email);
        $this->assertTrue(Hash::check('password123', (string) $user->password));

        $personal = DadosPessoais::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($personal, 'A API Users deve criar linha em dados_pessoais no store.');

        if ($personal !== null) {
            $this->assertSame('API Canonico Store', $personal->nome_completo);
            $this->assertSame('2010-01-15', optional($personal->data_nascimento)->format('Y-m-d'));
            $this->assertSame('M', $personal->sexo);
            $this->assertSame('Rua API Store', $personal->morada);
            $this->assertSame('910000001', $personal->contacto);
            $this->assertSame('123456789', $personal->nif);
        }

        $payloadFromReadService = app(MemberDataReadService::class)->personalPayload($user->fresh());

        $this->assertSame('API Canonico Store', $payloadFromReadService['nome_completo']);
        $this->assertSame('2010-01-15', $payloadFromReadService['data_nascimento']);
        $this->assertSame('M', $payloadFromReadService['sexo']);
        $this->assertSame('Rua API Store', $payloadFromReadService['morada']);
        $this->assertSame('910000001', $payloadFromReadService['contacto']);
        $this->assertSame('123456789', $payloadFromReadService['nif']);
    }

    public function test_api_users_update_writes_canonical_personal_row(): void
    {
        $admin = User::factory()->admin()->create();

        $member = User::factory()->create([
            'nome_completo' => 'Legacy Nome Antes Update',
            'data_nascimento' => '2007-03-05',
            'sexo' => 'F',
            'morada' => 'Rua Legacy Update',
            'contacto' => '910000020',
            'nif' => '987654321',
            'estado' => 'ativo',
            'perfil' => 'atleta',
        ]);

        $updatePayload = [
            'nome_completo' => 'API Canonico Update',
            'data_nascimento' => '2011-02-20',
            'sexo' => 'M',
            'morada' => 'Rua API Update',
            'contacto' => '910000002',
            'nif' => '223344556',
            'estado' => 'ativo',
            'perfil' => 'atleta',
        ];

        $this->actingAs($admin)
            ->putJson('/api/users/' . $member->id, $updatePayload)
            ->assertOk();

        $member = $member->fresh();

        $personal = DadosPessoais::query()->where('user_id', $member->id)->first();
        $this->assertNotNull($personal, 'A API Users deve criar/atualizar dados_pessoais no update.');

        if ($personal !== null) {
            $this->assertSame('API Canonico Update', $personal->nome_completo);
            $this->assertSame('2011-02-20', optional($personal->data_nascimento)->format('Y-m-d'));
            $this->assertSame('M', $personal->sexo);
            $this->assertSame('Rua API Update', $personal->morada);
            $this->assertSame('910000002', $personal->contacto);
            $this->assertSame('223344556', $personal->nif);
        }

        $payloadFromReadService = app(MemberDataReadService::class)->personalPayload($member);

        $this->assertSame('API Canonico Update', $payloadFromReadService['nome_completo']);
        $this->assertSame('2011-02-20', $payloadFromReadService['data_nascimento']);
        $this->assertSame('M', $payloadFromReadService['sexo']);
        $this->assertSame('Rua API Update', $payloadFromReadService['morada']);
        $this->assertSame('910000002', $payloadFromReadService['contacto']);
        $this->assertSame('223344556', $payloadFromReadService['nif']);
    }

    public function test_api_users_update_reconciles_future_monthly_fees_when_member_becomes_inactive(): void
    {
        $admin = User::factory()->admin()->create();

        $member = User::factory()->athlete()->create([
            'estado' => 'ativo',
            'ativo_desportivo' => true,
        ]);

        $futureInvoice = Invoice::query()->create([
            'user_id' => $member->id,
            'tipo' => 'mensalidade',
            'mes' => now()->addMonth()->format('Y-m'),
            'data_fatura' => now()->addMonth()->startOfMonth()->toDateString(),
            'data_emissao' => now()->addMonth()->startOfMonth()->toDateString(),
            'data_vencimento' => now()->addMonth()->endOfMonth()->toDateString(),
            'valor_total' => 35.00,
            'valor_pago' => 0,
            'valor_em_aberto' => 35.00,
            'estado_pagamento' => 'pendente',
            'oculta' => true,
        ]);

        $this->actingAs($admin)
            ->putJson('/api/users/' . $member->id, [
                'estado' => 'inativo',
            ])
            ->assertOk();

        $futureInvoice->refresh();

        $this->assertSame('cancelado', $futureInvoice->estado_pagamento);
        $this->assertSame('0.00', (string) $futureInvoice->valor_em_aberto);
        $this->assertFalse((bool) $futureInvoice->oculta);
    }

    public function test_api_users_store_does_not_mirror_full_personal_payload_to_users(): void
    {
        $admin = User::factory()->admin()->create();

        $payload = [
            'nome_completo' => 'API Mirror Store',
            'email' => 'api.mirror.store@example.test',
            'data_nascimento' => '2012-04-10',
            'estado' => 'ativo',
            'perfil' => 'atleta',
            'sexo' => 'M',
            'morada' => 'Rua Mirror Store',
            'contacto' => '910000003',
            'nif' => '334455667',
            'password' => 'password123',
        ];

        $response = $this->actingAs($admin)->postJson('/api/users', $payload);
        $response->assertCreated();

        $member = User::query()->findOrFail((string) $response->json('id'));

        // Contract (target for M4.3-F2): users should not be the primary sink
        // for personal data once API Users writes canonically.
        $this->assertNotSame('API Mirror Store', (string) $member->nome_completo);
        $this->assertNotSame('2012-04-10', optional($member->data_nascimento)->format('Y-m-d'));
        $this->assertNotSame('M', (string) $member->sexo);
        $this->assertNotSame('Rua Mirror Store', (string) $member->morada);
        $this->assertNotSame('910000003', (string) $member->contacto);
        $this->assertNotSame('334455667', (string) $member->nif);

        // Operational/auth fields may remain in users.
        $this->assertSame('api.mirror.store@example.test', $member->email);
        $this->assertSame('atleta', $member->perfil);
        $this->assertSame('ativo', $member->estado);
        $this->assertNotEmpty($member->password);
    }

    public function test_api_users_update_does_not_mirror_full_personal_payload_to_users(): void
    {
        $admin = User::factory()->admin()->create();

        $member = User::factory()->create([
            'nome_completo' => 'Legacy Nome Antes',
            'nif' => '111222333',
            'morada' => 'Rua Legacy Antes',
            'contacto' => '910000099',
            'sexo' => 'F',
            'data_nascimento' => '2009-09-09',
            'estado' => 'ativo',
            'perfil' => 'atleta',
        ]);

        $payload = [
            'nome_completo' => 'API Update Mirror Target',
            'data_nascimento' => '2013-01-31',
            'sexo' => 'M',
            'morada' => 'Rua Update Mirror',
            'contacto' => '910000004',
            'nif' => '445566778',
            'estado' => 'ativo',
            'perfil' => 'atleta',
        ];

        $this->actingAs($admin)
            ->putJson('/api/users/' . $member->id, $payload)
            ->assertOk();

        $member = $member->fresh();

        $personal = DadosPessoais::query()->where('user_id', $member->id)->first();
        $this->assertNotNull($personal, 'O update da API Users deve escrever dados canónicos em dados_pessoais.');

        if ($personal !== null) {
            $this->assertSame('API Update Mirror Target', $personal->nome_completo);
            $this->assertSame('445566778', $personal->nif);
            $this->assertSame('Rua Update Mirror', $personal->morada);
        }

        // Contract (target for M4.3-F2): full personal payload should not mutate
        // legacy personal fields on users.
        $this->assertSame('Legacy Nome Antes', (string) $member->nome_completo);
        $this->assertSame('111222333', (string) $member->nif);
        $this->assertSame('Rua Legacy Antes', (string) $member->morada);
    }

    public function test_api_users_show_returns_compatible_response_after_canonical_personal_data_exists(): void
    {
        $admin = User::factory()->admin()->create();

        $member = User::factory()->create([
            'email' => 'api.show@example.test',
            'estado' => 'ativo',
            'perfil' => 'atleta',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $member->id,
            'nome_completo' => 'API Canonico Show',
            'data_nascimento' => '2010-06-12',
            'sexo' => 'M',
            'morada' => 'Rua API Show',
            'contacto' => '910000005',
            'nif' => '556677889',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/users/' . $member->id);

        $response->assertOk();
        $response->assertJsonStructure([
            'id',
            'email',
            'estado',
            'perfil',
        ]);
    }

    public function test_api_users_endpoints_require_authentication(): void
    {
        $member = User::factory()->create();

        $this->postJson('/api/users', [
            'nome_completo' => 'Sem Auth',
            'email' => 'no-auth@example.test',
            'data_nascimento' => '2010-01-01',
            'estado' => 'ativo',
            'perfil' => 'atleta',
            'sexo' => 'M',
            'password' => 'password123',
        ])->assertUnauthorized();

        $this->putJson('/api/users/' . $member->id, [
            'nome_completo' => 'Sem Auth Update',
        ])->assertUnauthorized();

        $this->getJson('/api/users/' . $member->id)->assertUnauthorized();
    }
}
