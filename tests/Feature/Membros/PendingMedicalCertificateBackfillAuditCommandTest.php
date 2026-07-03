<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\AthleteSportsData;
use App\Models\User;
use App\Services\Members\PendingMedicalCertificateBackfillAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PendingMedicalCertificateBackfillAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_audits_only_the_five_configured_cases(): void
    {
        foreach (PendingMedicalCertificateBackfillAuditor::PENDING_USER_IDS as $index => $id) {
            $this->createUserFixture(
                id: $id,
                legacyDate: sprintf('2024-03-%02d', $index + 1),
                perfil: 'atleta',
                tipoMembro: ['atleta'],
                ativoDesportivo: true,
            );
        }

        $externalUser = User::factory()->create([
            'data_atestado_medico' => '2024-12-31',
            'perfil' => 'atleta',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
        ]);

        $exitCode = Artisan::call('members:audit-pending-medical-certificate-backfill', ['--json' => true]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeOutputJson();
        $this->assertSame(5, (int) ($payload['scope']['pending_user_ids_count'] ?? 0));
        $this->assertCount(5, $payload['cases'] ?? []);

        $auditedIds = array_values(array_map(
            static fn (array $row): string => (string) ($row['user_id'] ?? ''),
            array_filter($payload['cases'] ?? [], static fn (mixed $row): bool => is_array($row)),
        ));

        $this->assertNotContains($externalUser->id, $auditedIds);
    }

    public function test_anomalous_years_are_classified_as_invalid_legacy_date(): void
    {
        $this->createUserFixture(
            id: PendingMedicalCertificateBackfillAuditor::PENDING_USER_IDS[0],
            legacyDate: '0024-02-06',
            perfil: 'atleta',
            tipoMembro: ['atleta'],
            ativoDesportivo: true,
        );

        $this->createUserFixture(
            id: PendingMedicalCertificateBackfillAuditor::PENDING_USER_IDS[1],
            legacyDate: '0023-09-04',
            perfil: 'atleta',
            tipoMembro: ['atleta'],
            ativoDesportivo: true,
        );

        $payload = $this->runJsonCommand();
        $byUser = $this->indexCasesByUserId($payload['cases'] ?? []);

        $this->assertSame('invalid_legacy_date', $byUser[PendingMedicalCertificateBackfillAuditor::PENDING_USER_IDS[0]]['classification'] ?? null);
        $this->assertSame('invalid_legacy_date', $byUser[PendingMedicalCertificateBackfillAuditor::PENDING_USER_IDS[1]]['classification'] ?? null);
    }

    public function test_user_without_sports_target_is_missing_sports_target(): void
    {
        $id = PendingMedicalCertificateBackfillAuditor::PENDING_USER_IDS[2];
        $this->createUserFixture(
            id: $id,
            legacyDate: '2024-03-20',
            perfil: 'atleta',
            tipoMembro: ['atleta'],
            ativoDesportivo: true,
        );

        $payload = $this->runJsonCommand();
        $byUser = $this->indexCasesByUserId($payload['cases'] ?? []);

        $this->assertSame('missing_sports_target', $byUser[$id]['classification'] ?? null);
        $this->assertFalse((bool) ($byUser[$id]['target']['athlete_sports_data_exists'] ?? true));
    }

    public function test_non_athlete_user_is_not_sports_member(): void
    {
        $id = PendingMedicalCertificateBackfillAuditor::PENDING_USER_IDS[3];
        $this->createUserFixture(
            id: $id,
            legacyDate: '2024-09-21',
            perfil: 'socio',
            tipoMembro: ['socio'],
            ativoDesportivo: false,
        );

        $payload = $this->runJsonCommand();
        $byUser = $this->indexCasesByUserId($payload['cases'] ?? []);

        $this->assertSame('not_sports_member', $byUser[$id]['classification'] ?? null);
    }

    public function test_user_with_empty_target_date_and_valid_legacy_is_ready_for_backfill(): void
    {
        $id = PendingMedicalCertificateBackfillAuditor::PENDING_USER_IDS[4];
        $this->createUserFixture(
            id: $id,
            legacyDate: '2024-09-21',
            perfil: 'atleta',
            tipoMembro: ['atleta'],
            ativoDesportivo: true,
        );

        AthleteSportsData::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $id,
            'data_atestado_medico' => null,
            'ativo' => true,
        ]);

        $payload = $this->runJsonCommand();
        $byUser = $this->indexCasesByUserId($payload['cases'] ?? []);

        $this->assertSame('ready_for_backfill', $byUser[$id]['classification'] ?? null);
    }

    public function test_command_is_read_only(): void
    {
        $id = PendingMedicalCertificateBackfillAuditor::PENDING_USER_IDS[0];
        $this->createUserFixture(
            id: $id,
            legacyDate: '2024-09-21',
            perfil: 'atleta',
            tipoMembro: ['atleta'],
            ativoDesportivo: true,
        );

        AthleteSportsData::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $id,
            'data_atestado_medico' => null,
            'ativo' => true,
        ]);

        $before = [
            'users' => DB::table('users')->count(),
            'athlete_sports_data' => DB::table('athlete_sports_data')->count(),
            'user_legacy_date' => DB::table('users')->where('id', $id)->value('data_atestado_medico'),
            'target_date' => DB::table('athlete_sports_data')->where('user_id', $id)->value('data_atestado_medico'),
        ];

        $exitCode = Artisan::call('members:audit-pending-medical-certificate-backfill');

        $after = [
            'users' => DB::table('users')->count(),
            'athlete_sports_data' => DB::table('athlete_sports_data')->count(),
            'user_legacy_date' => DB::table('users')->where('id', $id)->value('data_atestado_medico'),
            'target_date' => DB::table('athlete_sports_data')->where('user_id', $id)->value('data_atestado_medico'),
        ];

        $this->assertSame(0, $exitCode);
        $this->assertSame($before, $after);
    }

    public function test_json_output_is_valid_and_report_path_writes_file(): void
    {
        $id = PendingMedicalCertificateBackfillAuditor::PENDING_USER_IDS[0];
        $this->createUserFixture(
            id: $id,
            legacyDate: '2024-09-21',
            perfil: 'atleta',
            tipoMembro: ['atleta'],
            ativoDesportivo: true,
        );

        $relativePath = 'storage/app/audits/test-pending-medical-certificate-backfill-audit.json';
        $absolutePath = base_path($relativePath);

        File::delete($absolutePath);

        try {
            $exitCode = Artisan::call('members:audit-pending-medical-certificate-backfill', [
                '--json' => true,
                '--report-path' => $relativePath,
            ]);

            $this->assertSame(0, $exitCode);

            $payload = $this->decodeOutputJson();
            $this->assertSame(PendingMedicalCertificateBackfillAuditor::VERSION, $payload['version'] ?? null);
            $this->assertTrue(File::exists($absolutePath));

            $decoded = json_decode((string) File::get($absolutePath), true);
            $this->assertIsArray($decoded);
            $this->assertSame(PendingMedicalCertificateBackfillAuditor::VERSION, $decoded['version'] ?? null);
        } finally {
            File::delete($absolutePath);
        }
    }

    private function createUserFixture(
        string $id,
        string $legacyDate,
        string $perfil,
        array $tipoMembro,
        bool $ativoDesportivo,
    ): User {
        return User::factory()->create([
            'id' => $id,
            'name' => 'Audit User ' . substr($id, 0, 8),
            'email' => 'audit+' . substr($id, 0, 8) . '@example.test',
            'perfil' => $perfil,
            'tipo_membro' => $tipoMembro,
            'ativo_desportivo' => $ativoDesportivo,
            'escalao' => [Str::uuid()->toString()],
            'estado' => $ativoDesportivo ? 'ativo' : 'inativo',
            'data_atestado_medico' => $legacyDate,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function runJsonCommand(): array
    {
        $exitCode = Artisan::call('members:audit-pending-medical-certificate-backfill', ['--json' => true]);

        $this->assertSame(0, $exitCode);

        return $this->decodeOutputJson();
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeOutputJson(): array
    {
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param mixed $cases
     * @return array<string,array<string,mixed>>
     */
    private function indexCasesByUserId(mixed $cases): array
    {
        $indexed = [];

        if (!is_array($cases)) {
            return $indexed;
        }

        foreach ($cases as $row) {
            if (!is_array($row)) {
                continue;
            }

            $userId = is_string($row['user_id'] ?? null) ? (string) $row['user_id'] : '';
            if ($userId === '') {
                continue;
            }

            $indexed[$userId] = $row;
        }

        return $indexed;
    }
}
