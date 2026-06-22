<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\AthleteSportsData;
use App\Models\DadosConfiguracao;
use App\Models\DadosFinanceiros;
use App\Models\DadosPessoais;
use App\Models\Familia;
use App\Models\Invoice;
use App\Models\Movement;
use App\Models\User;
use App\Services\Members\MemberDataMigrationService;
use App\Services\Members\MemberDataReadService;
use App\Services\Members\MemberDataWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemberDataDualWriteOperationalValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_profile_update_keeps_dual_write_parity_read_consistency_and_non_target_modules_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $guardian = User::factory()->create(['perfil' => 'encarregado_educacao']);

        $member = User::factory()->create([
            'nome_completo' => 'Operacional Antes',
            'name' => 'Operacional Antes',
            'email_utilizador' => 'operacional.antes@example.test',
            'email' => 'operacional.antes@example.test',
            'sexo' => 'masculino',
            'estado' => 'ativo',
            'numero_socio' => 'M2-600',
            'contacto' => '910000001',
            'morada' => 'Rua Legacy 1',
            'email_secundario' => 'legacy.sec@example.test',
            'rgpd' => true,
            'consentimento' => true,
            'declaracao_de_transporte' => true,
            'afiliacao' => true,
        ]);

        app(MemberDataWriteService::class)->persistFromMemberRequest(
            $member,
            [
                'nome_completo' => $member->nome_completo,
                'data_nascimento' => $member->getAttribute('data_nascimento'),
                'sexo' => $member->sexo,
                'nif' => $member->getAttribute('nif'),
                'cc' => $member->getAttribute('cc'),
                'data_validade_cc' => $member->getAttribute('data_validade_cc'),
                'nacionalidade' => $member->getAttribute('nacionalidade'),
                'morada' => $member->morada,
                'codigo_postal' => $member->getAttribute('codigo_postal'),
                'localidade' => $member->getAttribute('localidade'),
                'distrito' => $member->getAttribute('distrito'),
                'concelho' => $member->getAttribute('concelho'),
                'contacto' => $member->contacto,
                'contacto_telefonico' => $member->getAttribute('contacto_telefonico'),
                'email_secundario' => $member->email_secundario,
                'perfil' => $member->perfil,
                'notas' => $member->getAttribute('notas'),
                'rgpd' => $member->rgpd,
                'data_rgpd' => $member->getAttribute('data_rgpd'),
                'consentimento' => $member->consentimento,
                'data_consentimento' => $member->getAttribute('data_consentimento'),
                'declaracao_de_transporte' => $member->declaracao_de_transporte,
                'afiliacao' => $member->afiliacao,
                'num_federacao' => $member->getAttribute('num_federacao'),
                'data_afiliacao' => $member->getAttribute('data_afiliacao'),
                'arquivo_afiliacao' => $member->getAttribute('arquivo_afiliacao'),
                'declaracao_transporte' => $member->getAttribute('declaracao_transporte'),
                'arquivo_atestado_medico' => $member->getAttribute('arquivo_atestado_medico'),
            ],
            (string) $member->id
        );

        $financeData = DadosFinanceiros::query()->create([
            'user_id' => $member->id,
            'discount_type' => 'fixed',
            'discount_value' => 12,
            'discount_reason' => 'Operacional baseline',
        ]);

        $invoice = Invoice::query()->create([
            'user_id' => $member->id,
            'data_fatura' => now()->toDateString(),
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(10)->toDateString(),
            'valor_total' => 45.90,
            'tipo' => 'mensalidade',
            'estado_pagamento' => 'pendente',
            'mes' => '2026-06',
        ]);

        $movement = Movement::query()->create([
            'user_id' => $member->id,
            'classificacao' => 'receita',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(10)->toDateString(),
            'valor_total' => 25.00,
            'tipo' => 'quotas',
            'estado_pagamento' => 'pendente',
            'observacoes' => 'Movimento baseline',
        ]);

        $sports = AthleteSportsData::query()->create([
            'user_id' => $member->id,
            'num_federacao' => 'FED-OP-001',
            'ativo' => true,
        ]);

        DB::table('user_guardian')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $member->id,
            'guardian_id' => $guardian->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $familia = Familia::query()->create([
            'nome' => 'Familia Operacional',
            'responsavel_user_id' => $guardian->id,
            'ativo' => true,
        ]);

        DB::table('familia_user')->insert([
            'id' => (string) Str::uuid(),
            'familia_id' => $familia->id,
            'user_id' => $member->id,
            'papel_na_familia' => 'educando',
            'pode_editar' => false,
            'pode_ver_financeiro' => true,
            'pode_ver_desportivo' => true,
            'pode_ver_documentos' => true,
            'pode_ver_comunicacoes' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $auditBefore = app(MemberDataMigrationService::class)->buildAuditReport(['user_id' => (string) $member->id]);

        $financialSnapshotBefore = [
            'invoice_count' => Invoice::query()->count(),
            'movement_count' => Movement::query()->count(),
            'finance_count' => DadosFinanceiros::query()->count(),
        ];

        $sportsSnapshotBefore = [
            'athlete_sports_count' => AthleteSportsData::query()->count(),
            'athlete_num_federacao' => (string) $sports->num_federacao,
        ];

        $familySnapshotBefore = [
            'user_guardian_count' => DB::table('user_guardian')->count(),
            'familia_user_count' => DB::table('familia_user')->count(),
        ];

        $payload = $this->baseUpdatePayload($member, [
            'nome_completo' => 'Operacional Depois',
            'telefone' => '919111222',
            'notas' => '0',
            'email_secundario' => 'sec.operacional@example.test',
            'morada' => 'Rua Nova Operacional',
            'codigo_postal' => '4000-100',
            'localidade' => 'Porto',
            'rgpd' => false,
            'consentimento' => false,
            'declaracao_de_transporte' => false,
            'afiliacao' => false,
        ]);

        $this->actingAs($admin)
            ->from(route('membros.show', $member))
            ->put(route('membros.update', $member), $payload)
            ->assertRedirect(route('membros.show', ['member' => $member->id]));

        $member->refresh();
        $personal = DadosPessoais::query()->where('user_id', $member->id)->firstOrFail();
        $config = DadosConfiguracao::query()->where('user_id', $member->id)->firstOrFail();

        $this->assertSame('919111222', $personal->contacto);
        $this->assertSame('0', (string) $personal->observacoes);
        $this->assertSame('Operacional Depois', $personal->nome_completo);
        $this->assertSame('sec.operacional@example.test', $personal->email_secundario);

        $this->assertFalse((bool) $config->consentimento_rgpd);
        $this->assertFalse((bool) $config->consentimento_imagem);
        $this->assertFalse((bool) $config->declaracao_transporte);

        $this->assertSame('Operacional Depois', $member->name);
        $this->assertSame('Operacional Depois', $member->nome_completo);

        if (Schema::hasColumn('users', 'contacto')) {
            $this->assertSame('919111222', (string) $member->contacto);
        }

        if (Schema::hasColumn('users', 'email_secundario')) {
            $this->assertSame('sec.operacional@example.test', (string) $member->email_secundario);
        }

        $legacyObservationColumns = array_values(array_filter(['observacoes', 'notas'], static fn (string $col): bool => Schema::hasColumn('users', $col)));

        if ($legacyObservationColumns === []) {
            // No legacy equivalent: users table must not be mutated for observations.
            $this->assertTrue(true);
        } elseif (in_array('observacoes', $legacyObservationColumns, true)) {
            $this->assertSame('0', (string) $member->getAttribute('observacoes'));
        } elseif (in_array('notas', $legacyObservationColumns, true)) {
            $this->assertSame('0', (string) $member->notas);
        }

        $this->assertSame(1, DadosPessoais::query()->where('user_id', $member->id)->count());
        $this->assertSame(1, DadosConfiguracao::query()->where('user_id', $member->id)->count());

        $merged = app(MemberDataReadService::class)
            ->mergedMemberPayload($member->fresh()->loadMissing(['dadosPessoais', 'dadosConfiguracao']), $member->fresh()->toArray());

        $this->assertSame('Operacional Depois', $merged['nome_completo']);
        $this->assertSame('919111222', $merged['contacto']);
        $this->assertFalse((bool) $merged['rgpd']);
        $this->assertFalse((bool) $merged['consentimento']);
        $this->assertSame('0', (string) $merged['observacoes']);

        $audit = app(MemberDataMigrationService::class)->buildAuditReport(['user_id' => (string) $member->id]);
        $this->assertLessThanOrEqual(
            (int) ($auditBefore['summary']['conflicts_dados_pessoais'] ?? 0),
            (int) ($audit['summary']['conflicts_dados_pessoais'] ?? 0)
        );
        $this->assertLessThanOrEqual(
            (int) ($auditBefore['summary']['conflicts_dados_configuracao'] ?? 0),
            (int) ($audit['summary']['conflicts_dados_configuracao'] ?? 0)
        );

        $this->actingAs($admin)->put(route('membros.update', $member), $payload)->assertRedirect();

        $this->assertSame(1, DadosPessoais::query()->where('user_id', $member->id)->count());
        $this->assertSame(1, DadosConfiguracao::query()->where('user_id', $member->id)->count());

        $this->assertSame($financialSnapshotBefore['invoice_count'], Invoice::query()->count());
        $this->assertSame($financialSnapshotBefore['movement_count'], Movement::query()->count());
        $this->assertSame($financialSnapshotBefore['finance_count'], DadosFinanceiros::query()->count());
        $this->assertSame('fixed', (string) $financeData->fresh()->discount_type);
        $this->assertSame('12.00', (string) $financeData->fresh()->discount_value);

        $this->assertSame($sportsSnapshotBefore['athlete_sports_count'], AthleteSportsData::query()->count());
        $this->assertSame($sportsSnapshotBefore['athlete_num_federacao'], (string) $sports->fresh()->num_federacao);

        $this->assertSame($familySnapshotBefore['user_guardian_count'], DB::table('user_guardian')->count());
        $this->assertSame($familySnapshotBefore['familia_user_count'], DB::table('familia_user')->count());

        $this->assertSame($invoice->id, (string) $invoice->fresh()->id);
        $this->assertSame($movement->id, (string) $movement->fresh()->id);
    }

    public function test_read_fallback_still_works_when_personal_row_is_removed_after_update(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'nome_completo' => 'Fallback Personal Legacy',
            'name' => 'Fallback Personal Legacy',
            'email_utilizador' => 'fallback.personal.legacy@example.test',
            'email' => 'fallback.personal.legacy@example.test',
            'sexo' => 'masculino',
            'estado' => 'ativo',
            'numero_socio' => 'M2-601',
            'morada' => 'Rua legacy personal',
        ]);

        $payload = $this->baseUpdatePayload($member, ['morada' => 'Rua dual write']);

        $this->actingAs($admin)->put(route('membros.update', $member), $payload)->assertRedirect();

        DadosPessoais::query()->where('user_id', $member->id)->delete();

        $result = app(MemberDataReadService::class)->personalPayload($member->fresh());
        $this->assertSame('Rua dual write', $result['morada']);
    }

    public function test_read_fallback_still_works_when_configuration_row_is_removed_after_update(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'nome_completo' => 'Fallback Config Legacy',
            'name' => 'Fallback Config Legacy',
            'email_utilizador' => 'fallback.config.legacy@example.test',
            'email' => 'fallback.config.legacy@example.test',
            'sexo' => 'feminino',
            'estado' => 'ativo',
            'numero_socio' => 'M2-602',
            'rgpd' => false,
        ]);

        $payload = $this->baseUpdatePayload($member, ['rgpd' => true]);

        $this->actingAs($admin)->put(route('membros.update', $member), $payload)->assertRedirect();

        DadosConfiguracao::query()->where('user_id', $member->id)->delete();

        $result = app(MemberDataReadService::class)->configurationPayload($member->fresh());
        $this->assertTrue((bool) $result['consentimento_rgpd']);
    }

    public function test_portal_profile_update_keeps_dual_write_for_member_personal_data(): void
    {
        $member = User::factory()->create([
            'nome_completo' => 'Portal Operacional Antes',
            'name' => 'Portal Operacional Antes',
            'email_utilizador' => 'portal.operacional.antes@example.test',
            'email' => 'portal.operacional.antes@example.test',
            'sexo' => 'masculino',
            'perfil' => 'atleta',
            'estado' => 'ativo',
            'numero_socio' => 'M2-603',
            'morada' => 'Morada Portal Antes',
            'nif' => '123123123',
            'contacto' => '910333444',
        ]);

        $payload = [
            'nome_completo' => 'Portal Operacional Depois',
            'data_nascimento' => '2011-07-21',
            'nif' => '999888777',
            'cc' => 'CC999888',
            'morada' => 'Morada Portal Depois',
            'codigo_postal' => '4300-300',
            'localidade' => 'Porto',
            'nacionalidade' => 'Portuguesa',
            'sexo' => 'masculino',
            'contacto' => '910999888',
            'email_secundario' => 'portal.operacional.sec@example.test',
        ];

        $this->actingAs($member)
            ->patch(route('portal.profile.update'), $payload)
            ->assertRedirect(route('portal.profile'));

        $member->refresh();
        $personal = DadosPessoais::query()->where('user_id', $member->id)->firstOrFail();

        $this->assertSame('Portal Operacional Depois', $member->nome_completo);
        $this->assertSame('Portal Operacional Depois', $member->name);
        $this->assertSame('999888777', $member->nif);
        $this->assertSame('Morada Portal Depois', $member->morada);

        $this->assertSame('Portal Operacional Depois', $personal->nome_completo);
        $this->assertSame('999888777', $personal->nif);
        $this->assertSame('Morada Portal Depois', $personal->morada);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function baseUpdatePayload(User $member, array $overrides = []): array
    {
        return array_merge([
            'nome_completo' => (string) $member->nome_completo,
            'email_utilizador' => (string) $member->email_utilizador,
            'numero_socio' => (string) $member->numero_socio,
            'sexo' => (string) $member->sexo,
            'estado' => (string) $member->estado,
            'tipo_membro' => $member->tipo_membro ?? [],
            'perfil' => $member->perfil,
        ], $overrides);
    }
}
