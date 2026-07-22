<?php

declare(strict_types=1);

namespace Tests\Feature\Pessoas;

use App\Models\AthleteSportsData;
use App\Models\DadosFinanceiros;
use App\Models\DadosPessoais;
use App\Models\Familia;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MemberModelAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_user_member_is_clean(): void
    {
        $user = $this->validMember();

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);

        $this->assertContains('member_model_clean', collect($payload['findings'])->pluck('code')->all());
        $this->assertSame(0, $payload['summary']['warning_count']);
        $this->assertSame(0, $payload['summary']['critical_count']);
    }

    public function test_duplicate_case_insensitive_email_generates_duplicate_user_email(): void
    {
        $this->validMember(['email' => 'duplicado@example.test']);
        $this->validMember(['email' => 'DUPLICADO@example.test']);

        $payload = $this->audit(['--json' => true]);

        $this->assertContains('duplicate_user_email', collect($payload['findings'])->pluck('code')->all());
        $this->assertGreaterThanOrEqual(1, $payload['summary']['duplicate_identity_count']);
    }

    public function test_user_without_name_generates_missing_identity(): void
    {
        $user = $this->validMember(['name' => ' ', 'nome_completo' => ' '], ['nome_completo' => ' ']);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);

        $this->assertContains('user_missing_required_identity', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_member_profile_linked_to_missing_user_generates_invalid_link(): void
    {
        $missingUserId = (string) Str::uuid();
        Schema::dropIfExists('dados_pessoais');
        Schema::create('dados_pessoais', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('nome_completo')->nullable();
            $table->date('data_nascimento')->nullable();
            $table->string('nif')->nullable();
            $table->string('documento_identificacao')->nullable();
            $table->string('contacto')->nullable();
            $table->timestamps();
        });

        DB::table('dados_pessoais')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $missingUserId,
            'nome_completo' => 'Ficha Orfa',
            'data_nascimento' => '2000-01-01 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->audit(['--json' => true]);

        $this->assertContains('member_user_link_invalid', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_minor_without_guardian_generates_minor_without_guardian(): void
    {
        $minor = $this->validMember([
            'menor' => true,
            'data_nascimento' => now()->subYears(12)->toDateString(),
        ], [
            'data_nascimento' => now()->subYears(12)->toDateString(),
        ]);

        $payload = $this->audit(['--json' => true, '--user' => $minor->id]);

        $this->assertContains('minor_without_guardian', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_guardian_without_dependents_is_reported_as_non_actionable_info(): void
    {
        $guardianType = $this->userType('encarregado', 'Encarregado de Educacao');
        $guardian = $this->validMember([
            'perfil' => 'encarregado',
            'tipo_membro' => ['Encarregado de Educacao'],
        ], role: $guardianType);

        $payload = $this->audit(['--json' => true, '--user' => $guardian->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'guardian_without_dependents');

        $this->assertNotNull($finding);
        $this->assertSame('info', $finding['severity']);
        $this->assertFalse($finding['actionable']);
    }

    public function test_active_athlete_without_sports_profile_is_reported(): void
    {
        $athleteType = $this->userType('atleta', 'Atleta');
        $athlete = $this->validMember([
            'perfil' => 'atleta',
            'tipo_membro' => ['Atleta'],
            'ativo_desportivo' => true,
        ], role: $athleteType);

        $payload = $this->audit(['--json' => true, '--user' => $athlete->id]);

        $this->assertContains('active_athlete_without_sports_profile', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_member_without_type_is_reported(): void
    {
        $user = $this->validMember(['tipo_membro' => null], role: false);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);

        $this->assertContains('member_missing_type', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_member_with_unknown_status_is_reported(): void
    {
        $user = $this->validMember(['estado' => 'hibernado']);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);

        $this->assertContains('member_unknown_status', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_user_without_role_is_reported(): void
    {
        $user = $this->validMember(role: false);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);

        $this->assertContains('user_missing_role', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_orphan_permission_rows_are_reported(): void
    {
        Schema::dropIfExists('user_user_type');
        Schema::create('user_user_type', function ($table): void {
            $table->uuid('user_id');
            $table->uuid('user_type_id');
            $table->timestamps();
        });
        Schema::dropIfExists('user_type_permissions');
        Schema::create('user_type_permissions', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_type_id');
            $table->string('modulo');
            $table->boolean('pode_ver')->default(true);
            $table->boolean('pode_editar')->default(false);
            $table->boolean('pode_eliminar')->default(false);
            $table->timestamps();
        });

        DB::statement('PRAGMA foreign_keys=OFF');
        DB::table('user_user_type')->insert([
            'user_id' => (string) Str::uuid(),
            'user_type_id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_type_permissions')->insert([
            'id' => (string) Str::uuid(),
            'user_type_id' => (string) Str::uuid(),
            'modulo' => 'membros',
            'pode_ver' => true,
            'pode_editar' => false,
            'pode_eliminar' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement('PRAGMA foreign_keys=ON');

        $payload = $this->audit(['--json' => true]);

        $this->assertContains('permission_orphan_user', collect($payload['findings'])->pluck('code')->all());
        $this->assertGreaterThanOrEqual(1, $payload['summary']['permission_issue_count']);
    }

    public function test_administrative_user_is_excluded_as_info_not_warning(): void
    {
        $admin = User::factory()->create([
            'perfil' => 'admin',
            'tipo_membro' => ['Admin'],
            'ativo_desportivo' => false,
            'estado' => 'ativo',
        ]);

        $payload = $this->audit(['--json' => true, '--user' => $admin->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'administrative_user_excluded_from_member_audit');

        $this->assertNotNull($finding);
        $this->assertSame('info', $finding['severity']);
        $this->assertSame(0, $payload['summary']['warning_count']);
    }

    public function test_only_actionable_removes_info_findings(): void
    {
        $admin = User::factory()->create([
            'perfil' => 'admin',
            'tipo_membro' => ['Admin'],
            'ativo_desportivo' => false,
            'estado' => 'ativo',
        ]);

        $payload = $this->audit(['--json' => true, '--only-actionable' => true, '--user' => $admin->id]);

        $this->assertSame([], $payload['findings']);
        $this->assertSame(0, $payload['summary']['info_count']);
    }

    public function test_fail_on_warning_returns_exit_one_when_warning_exists(): void
    {
        $user = $this->validMember(role: false);

        $exitCode = Artisan::call('people:audit-member-model', [
            '--user' => $user->id,
            '--fail-on-warning' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_json_output_is_valid_and_contains_schema_contract(): void
    {
        $this->validMember();

        $payload = $this->audit(['--json' => true]);

        $this->assertSame('c1-member-model-audit-v1', $payload['version']);
        $this->assertArrayHasKey('schema_detected', $payload);
        $this->assertSame('dados_pessoais', $payload['schema_detected']['member_profile_table']);
    }

    public function test_report_path_writes_json_file(): void
    {
        $this->validMember();
        $path = storage_path('app/testing/member-model-audit.json');
        File::delete($path);

        Artisan::call('people:audit-member-model', [
            '--json' => true,
            '--report-path' => $path,
        ]);

        $this->assertFileExists($path);
        $payload = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('c1-member-model-audit-v1', $payload['version']);
        File::delete($path);
    }

    public function test_audit_is_read_only(): void
    {
        $this->validMember();
        $before = $this->snapshot();

        Artisan::call('people:audit-member-model', ['--json' => true]);

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param array<string,mixed> $userOverrides
     * @param array<string,mixed> $profileOverrides
     */
    private function validMember(array $userOverrides = [], array $profileOverrides = [], UserType|false|null $role = null): User
    {
        $user = User::factory()->create(array_merge([
            'perfil' => 'socio',
            'tipo_membro' => ['Socio'],
            'estado' => 'ativo',
            'menor' => false,
            'ativo_desportivo' => false,
            'data_nascimento' => now()->subYears(30)->toDateString(),
        ], $userOverrides));

        DadosPessoais::query()->create(array_merge([
            'user_id' => $user->id,
            'nome_completo' => $user->nome_completo ?: $user->name,
            'data_nascimento' => $user->data_nascimento,
            'nif' => fake()->unique()->numerify('#########'),
            'documento_identificacao' => fake()->unique()->numerify('########'),
            'contacto' => '912345678',
        ], $profileOverrides));

        if ($role !== false) {
            $role ??= $this->userType('socio', 'Socio');
            DB::table('user_user_type')->insert([
                'user_id' => $user->id,
                'user_type_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function userType(string $codigo, string $nome): UserType
    {
        return UserType::query()->firstOrCreate(
            ['codigo' => $codigo],
            ['nome' => $nome, 'descricao' => $nome, 'ativo' => true],
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function audit(array $arguments): array
    {
        Artisan::call('people:audit-member-model', $arguments);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string,int>
     */
    private function snapshot(): array
    {
        return [
            'users' => User::query()->count(),
            'dados_pessoais' => DadosPessoais::query()->count(),
            'dados_financeiros' => DadosFinanceiros::query()->count(),
            'athlete_sports_data' => AthleteSportsData::query()->count(),
            'user_guardian' => DB::table('user_guardian')->count(),
            'familias' => Familia::query()->count(),
            'familia_user' => DB::table('familia_user')->count(),
            'user_user_type' => DB::table('user_user_type')->count(),
            'user_type_permissions' => DB::table('user_type_permissions')->count(),
        ];
    }
}
