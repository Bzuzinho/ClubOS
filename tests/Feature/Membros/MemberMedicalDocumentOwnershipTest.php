<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\AthleteSportsData;
use App\Models\DadosConfiguracao;
use App\Models\User;
use App\Services\Members\MemberDocumentDataResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MemberMedicalDocumentOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_medical_certificate_file_and_metadata_are_owned_by_member_configuration(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
        ]);
        $file = 'data:application/pdf;base64,'.base64_encode('%PDF-1.4 ClubOS F3 medical certificate');

        $response = $this->actingAs($admin)->putJson(
            route('membros.documents.medical.update', ['member' => $member->id]),
            [
                'date' => '2026-08-10',
                'file' => $file,
            ]
        );

        $response->assertOk()->assertJsonPath('data.date', '2026-08-10');

        $configuration = DadosConfiguracao::query()->where('user_id', $member->id)->firstOrFail();
        $this->assertNotNull($configuration->certificado_medico_ficheiro);
        Storage::disk('public')->assertExists($configuration->certificado_medico_ficheiro);
        $this->assertSame(
            '2026-08-10',
            data_get($configuration->configuracao_extra, 'medical_certificate.date')
        );

        // Membros/document management owns the document. F3 must not create or
        // rewrite the athlete technical profile merely because a document changed.
        $this->assertFalse(AthleteSportsData::query()->where('user_id', $member->id)->exists());

        $payload = app(MemberDocumentDataResolver::class)
            ->memberDocumentPayload($member->fresh(['dadosConfiguracao']));
        $this->assertSame('2026-08-10', $payload['data_atestado_medico']);
        $this->assertSame($configuration->certificado_medico_ficheiro, $payload['arquivo_atestado_medico']);
    }
}
