<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserLegacyMassAssignmentGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_model_does_not_allow_mass_assignment_for_personal_legacy_fields(): void
    {
        $fields = config('member_user_legacy_fields.categories.member_personal_legacy.fields', []);

        $user = new User();

        foreach ($fields as $field) {
            $this->assertFalse($user->isFillable($field), sprintf('Expected [%s] to be guarded in User::$fillable.', $field));
        }
    }

    public function test_user_model_does_not_allow_mass_assignment_for_configuration_legacy_fields(): void
    {
        $fields = config('member_user_legacy_fields.categories.member_configuration_legacy.fields', []);

        $user = new User();

        foreach ($fields as $field) {
            $this->assertFalse($user->isFillable($field), sprintf('Expected [%s] to be guarded in User::$fillable.', $field));
        }
    }

    public function test_user_model_does_not_allow_mass_assignment_for_data_validade_cc(): void
    {
        $user = new User();

        $this->assertFalse($user->isFillable('data_validade_cc'));
    }

    public function test_user_model_does_not_allow_mass_assignment_for_family_json_mirrors(): void
    {
        $user = new User();

        foreach (['encarregado_educacao', 'educandos'] as $field) {
            $this->assertFalse($user->isFillable($field), sprintf('Expected family JSON mirror [%s] to be guarded in User::$fillable.', $field));
        }
    }

    public function test_user_model_still_allows_operational_auth_fields(): void
    {
        $user = new User();

        foreach (['name', 'email', 'password', 'numero_socio', 'perfil', 'estado', 'email_utilizador'] as $field) {
            $this->assertTrue($user->isFillable($field), sprintf('Expected [%s] to remain fillable.', $field));
        }
    }

    public function test_user_model_still_allows_currently_retained_operational_categories(): void
    {
        $user = new User();

        foreach ([
            'inscricao',
            'ativo_desportivo',
            'escalao',
            'numero_pmb',
            'data_inscricao',
            'tipo_membro',
            'menor',
            'foto_perfil',
        ] as $field) {
            $this->assertTrue($user->isFillable($field), sprintf('Expected [%s] to remain fillable.', $field));
        }
    }

    public function test_mass_assignment_ignores_legacy_personal_and_configuration_fields(): void
    {
        $email = 'legacy-guard-' . uniqid('', true) . '@example.test';

        $payload = [
            'name' => 'Teste',
            'email' => $email,
            'password' => 'password',
            'numero_socio' => 'T-001',
            'perfil' => 'atleta',
            'estado' => 'ativo',
            'nome_completo' => 'Nao deve gravar',
            'nif' => '123456789',
            'morada' => 'Rua Teste',
            'contacto' => '910000000',
            'rgpd' => true,
            'consentimento' => true,
            'num_federacao' => 'FED123',
        ];

        try {
            $created = User::query()->create($payload);
            $user = $created->fresh();

            $this->assertNotNull($user);
            $this->assertSame('Teste', $user->name);
            $this->assertSame($email, $user->email);
            $this->assertTrue(Hash::check('password', (string) $user->password));
            $this->assertSame('T-001', $user->numero_socio);
            $this->assertSame('atleta', $user->perfil);
            $this->assertSame('ativo', $user->estado);

            $this->assertLegacyFieldNotPersisted('nome_completo', 'Nao deve gravar', $user->id);
            $this->assertLegacyFieldNotPersisted('nif', '123456789', $user->id);
            $this->assertLegacyFieldNotPersisted('morada', 'Rua Teste', $user->id);
            $this->assertLegacyFieldNotPersisted('contacto', '910000000', $user->id);
            $this->assertLegacyFieldNotPersisted('rgpd', true, $user->id);
            $this->assertLegacyFieldNotPersisted('consentimento', true, $user->id);
            $this->assertLegacyFieldNotPersisted('num_federacao', 'FED123', $user->id);
        } catch (MassAssignmentException $exception) {
            $this->assertTrue(true, 'MassAssignmentException is also a safe behavior for guarded legacy fields.');
        }
    }

    private function assertLegacyFieldNotPersisted(string $field, mixed $value, string $userId): void
    {
        if (!Schema::hasColumn('users', $field)) {
            $this->assertTrue(true);

            return;
        }

        $this->assertDatabaseMissing('users', [
            'id' => $userId,
            $field => $value,
        ]);
    }
}
