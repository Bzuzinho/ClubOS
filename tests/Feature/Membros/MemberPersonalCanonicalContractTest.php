<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\DadosPessoais;
use App\Models\User;
use App\Services\Members\MemberDataReadService;
use App\Services\Members\UsersLegacyReadScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MemberPersonalCanonicalContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_data_read_service_uses_canonical_estado_civil_when_present_in_dados_pessoais(): void
    {
        $user = User::factory()->create([
            'estado_civil' => 'solteiro',
        ]);

        $dadosPessoais = new DadosPessoais();
        $dadosPessoais->setAttribute('estado_civil', 'casado');
        $user->setRelation('dadosPessoais', $dadosPessoais);

        /** @var MemberDataReadService $service */
        $service = app(MemberDataReadService::class);
        $payload = $service->personalPayload($user);

        $this->assertArrayHasKey('estado_civil', $payload);
        $this->assertSame('casado', $payload['estado_civil']);
    }

    public function test_member_data_read_service_falls_back_to_users_estado_civil_when_canonical_value_is_empty(): void
    {
        $user = User::factory()->create([
            'estado_civil' => 'uniao_de_facto',
        ]);

        $dadosPessoais = new DadosPessoais();
        $dadosPessoais->setAttribute('estado_civil', '   ');
        $user->setRelation('dadosPessoais', $dadosPessoais);

        /** @var MemberDataReadService $service */
        $service = app(MemberDataReadService::class);
        $payload = $service->personalPayload($user);

        $this->assertSame('uniao_de_facto', $payload['estado_civil']);
    }

    public function test_scanner_has_no_direct_users_estado_civil_read_findings_in_default_audit_scope(): void
    {
        /** @var UsersLegacyReadScanner $scanner */
        $scanner = app(UsersLegacyReadScanner::class);

        $result = $scanner->scan([
            'app',
        ], $scanner->defaultAllowlist());

        $estadoCivilFindings = array_values(array_filter(
            $result['findings'] ?? [],
            static fn (array $finding): bool => ($finding['field'] ?? null) === 'estado_civil',
        ));

        $this->assertSame([], $estadoCivilFindings);
    }
}
