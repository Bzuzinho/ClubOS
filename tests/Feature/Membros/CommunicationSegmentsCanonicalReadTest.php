<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Http\Controllers\ComunicacaoController;
use App\Models\CommunicationSegment;
use App\Models\DadosPessoais;
use App\Models\User;
use App\Services\Communication\InternalCommunicationService;
use App\Services\Communication\SegmentResolverService;
use App\Services\Members\UsersLegacyReadScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CommunicationSegmentsCanonicalReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanner_reports_no_communication_segment_findings_for_controller_and_services(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        foreach ([
            'app/Http/Controllers/ComunicacaoController.php',
            'app/Services/Communication/InternalCommunicationService.php',
            'app/Services/Communication/SegmentResolverService.php',
        ] as $path) {
            $result = $scanner->scan([$path], $scanner->defaultAllowlist());

            $communicationFindings = collect($result['findings'] ?? [])->filter(
                fn (array $finding): bool => ($finding['remediation_group'] ?? null) === 'communication_segments'
            );

            $this->assertCount(0, $communicationFindings, sprintf('Unexpected communication_segments findings in %s', $path));
        }
    }

    public function test_default_allowlist_does_not_include_communication_controller_or_services(): void
    {
        $allowlist = app(UsersLegacyReadScanner::class)->defaultAllowlist();

        $this->assertNotContains('app/Http/Controllers/ComunicacaoController.php', $allowlist);
        $this->assertNotContains('app/Services/Communication/InternalCommunicationService.php', $allowlist);
        $this->assertNotContains('app/Services/Communication/SegmentResolverService.php', $allowlist);
    }

    public function test_controller_recipient_and_author_payloads_use_canonical_name_and_contact_with_safe_fallback(): void
    {
        $canonicalUser = User::factory()->create([
            'name' => 'Fallback Auth Name',
            'nome_completo' => 'Legacy Display Name',
            'contacto' => '919000001',
            'telemovel' => '919000002',
            'contacto_telefonico' => '919000003',
            'estado' => 'ativo',
            'email' => 'canonical@example.test',
            'tipo_membro' => ['atleta'],
        ]);

        DadosPessoais::query()->create([
            'user_id' => $canonicalUser->id,
            'nome_completo' => 'Canonical Communication Name',
            'contacto' => '969111222',
        ]);

        $fallbackUser = User::factory()->create([
            'name' => 'Fallback Auth Only',
            'nome_completo' => 'Legacy Fallback Name',
            'contacto' => '913111222',
            'telemovel' => '913333444',
            'contacto_telefonico' => '913555666',
            'estado' => 'ativo',
            'email' => 'fallback@example.test',
            'tipo_membro' => ['encarregado_educacao'],
        ]);

        /** @var ComunicacaoController $controller */
        $controller = app(ComunicacaoController::class);

        $buildFilterOptions = new \ReflectionMethod($controller, 'buildFilterOptions');
        $buildFilterOptions->setAccessible(true);
        $filterOptions = $buildFilterOptions->invoke($controller);

        $buildRecipientOptions = new \ReflectionMethod($controller, 'buildRecipientOptions');
        $buildRecipientOptions->setAccessible(true);
        $recipientOptions = $buildRecipientOptions->invoke($controller, false);

        $authorRows = collect($filterOptions['authors'] ?? []);
        $recipientRows = collect($recipientOptions);

        $canonicalAuthor = $authorRows->firstWhere('id', $canonicalUser->id);
        $fallbackAuthor = $authorRows->firstWhere('id', $fallbackUser->id);
        $canonicalRecipient = $recipientRows->firstWhere('id', $canonicalUser->id);
        $fallbackRecipient = $recipientRows->firstWhere('id', $fallbackUser->id);

        $this->assertIsArray($canonicalAuthor);
        $this->assertSame('Canonical Communication Name', $canonicalAuthor['nome_completo']);

        $this->assertIsArray($fallbackAuthor);
        $this->assertSame('Legacy Fallback Name', $fallbackAuthor['nome_completo']);

        $this->assertIsArray($canonicalRecipient);
        $this->assertSame('Canonical Communication Name', $canonicalRecipient['nome_completo']);
        $this->assertSame('969111222', $canonicalRecipient['contacto']);
        $this->assertSame('969111222', $canonicalRecipient['telemovel']);
        $this->assertSame('969111222', $canonicalRecipient['contacto_telefonico']);

        $this->assertIsArray($fallbackRecipient);
        $this->assertSame('Legacy Fallback Name', $fallbackRecipient['nome_completo']);
        $this->assertSame('913111222', $fallbackRecipient['contacto']);
        $this->assertSame('913111222', $fallbackRecipient['telemovel']);
        $this->assertSame('913111222', $fallbackRecipient['contacto_telefonico']);
    }

    public function test_internal_and_segment_services_use_canonical_communication_payload_with_safe_fallback(): void
    {
        $sender = User::factory()->create([
            'name' => 'Sender Auth Name',
            'nome_completo' => 'Legacy Sender Name',
            'estado' => 'ativo',
            'email' => 'sender@example.test',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $sender->id,
            'nome_completo' => 'Canonical Sender Name',
        ]);

        $canonicalRecipient = User::factory()->create([
            'name' => 'Recipient Auth Name',
            'nome_completo' => 'Legacy Recipient Name',
            'contacto' => '911000000',
            'telemovel' => '922000000',
            'contacto_telefonico' => '933000000',
            'estado' => 'ativo',
            'email' => 'recipient@example.test',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $canonicalRecipient->id,
            'nome_completo' => 'Canonical Recipient Name',
            'contacto' => '944555666',
        ]);

        $fallbackRecipient = User::factory()->create([
            'name' => 'Fallback Recipient Auth',
            'nome_completo' => 'Legacy Fallback Recipient',
            'contacto' => '955111222',
            'telemovel' => '966111222',
            'contacto_telefonico' => '977111222',
            'estado' => 'ativo',
            'email' => 'fallback-recipient@example.test',
        ]);

        /** @var InternalCommunicationService $internalService */
        $internalService = app(InternalCommunicationService::class);
        $message = $internalService->send($sender, [
            'subject' => 'Mensagem interna',
            'message' => 'Conteudo de teste',
            'recipient_ids' => [$canonicalRecipient->id, $fallbackRecipient->id],
            'type' => 'info',
        ]);

        $sentFeed = $internalService->sentFeed($sender->id);
        $sentEntry = $sentFeed->firstWhere('message_id', $message->id);

        $this->assertIsArray($sentEntry);

        $sentRecipients = collect($sentEntry['recipients'] ?? []);
        $canonicalSentRecipient = $sentRecipients->firstWhere('id', $canonicalRecipient->id);
        $fallbackSentRecipient = $sentRecipients->firstWhere('id', $fallbackRecipient->id);

        $this->assertIsArray($canonicalSentRecipient);
        $this->assertSame('Canonical Recipient Name', $canonicalSentRecipient['name']);

        $this->assertIsArray($fallbackSentRecipient);
        $this->assertSame('Legacy Fallback Recipient', $fallbackSentRecipient['name']);

        $receivedFeed = $internalService->receivedFeed($canonicalRecipient->id);
        $receivedEntry = $receivedFeed->firstWhere('message_id', $message->id);

        $this->assertIsArray($receivedEntry);
        $this->assertSame('Canonical Sender Name', data_get($receivedEntry, 'sender.name'));

        $segment = CommunicationSegment::query()->create([
            'name' => 'Segmento manual',
            'slug' => 'segmento-manual',
            'type' => 'manual',
            'description' => 'Teste',
            'rules_json' => [
                'user_ids' => [$canonicalRecipient->id, $fallbackRecipient->id],
            ],
            'is_active' => true,
            'created_by' => $sender->id,
        ]);

        /** @var SegmentResolverService $segmentResolver */
        $segmentResolver = app(SegmentResolverService::class);
        $resolvedRecipients = $segmentResolver->resolveRecipients($segment)->keyBy('user_id');

        $this->assertSame('Canonical Recipient Name', $resolvedRecipients[$canonicalRecipient->id]['name']);
        $this->assertSame('944555666', $resolvedRecipients[$canonicalRecipient->id]['phone']);

        $this->assertSame('Legacy Fallback Recipient', $resolvedRecipients[$fallbackRecipient->id]['name']);
        $this->assertSame('955111222', $resolvedRecipients[$fallbackRecipient->id]['phone']);
    }
}