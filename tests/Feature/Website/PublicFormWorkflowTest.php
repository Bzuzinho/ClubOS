<?php

namespace Tests\Feature\Website;

use App\Mail\PublicFormSubmissionReceived;
use App\Models\DadosConfiguracao;
use App\Models\DadosPessoais;
use App\Models\InAppAlert;
use App\Models\PublicFormSubmission;
use App\Models\PublicRegistrationIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicFormWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_request_queues_club_email_and_creates_admin_alert(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['perfil' => 'admin']);

        $this->from('/junta-te')->post('/junta-te', $this->contactPayload())
            ->assertRedirect('/junta-te');

        $submission = PublicFormSubmission::query()->sole();

        Mail::assertQueued(PublicFormSubmissionReceived::class, function (PublicFormSubmissionReceived $mail) use ($submission): bool {
            return $mail->hasTo('beneditasportclubnatacao@gmail.com')
                && $mail->submission->is($submission);
        });

        $this->assertNotNull($submission->fresh()->email_queued_at);
        $this->assertDatabaseHas('in_app_alerts', [
            'user_id' => $admin->id,
            'link' => '/website-redes/pedidos/'.$submission->id,
            'is_read' => false,
        ]);
        $this->assertNotNull($submission->fresh()->admin_notified_at);
    }

    public function test_registration_creates_inactive_canonical_member_without_platform_or_financial_access(): void
    {
        Mail::fake();
        User::factory()->create(['perfil' => 'admin']);

        $this->from('/inscricao')->post('/inscricao', $this->registrationPayload())
            ->assertRedirect('/inscricao');

        $submission = PublicFormSubmission::query()->sole();
        $member = User::query()->findOrFail($submission->user_id);

        $this->assertSame('inativo', $member->estado);
        $this->assertFalse((bool) $member->ativo_desportivo);
        $this->assertNull($member->numero_socio);
        $this->assertNull($member->email_utilizador);
        $this->assertStringEndsWith('@bscn.invalid', $member->email);

        $this->assertDatabaseHas('dados_pessoais', [
            'user_id' => $member->id,
            'nome_completo' => 'Atleta Menor',
            'localidade' => 'Benedita',
            'contacto' => '912345678',
            'email_secundario' => 'familia@example.com',
        ]);

        $configuration = DadosConfiguracao::query()->where('user_id', $member->id)->sole();
        $this->assertFalse((bool) $configuration->platform_access_enabled);
        $this->assertFalse((bool) $configuration->acesso_portal_ativo);
        $this->assertSame('Pré-inscrição — Website', $configuration->configuracao_extra['origem_label']);
        $this->assertDatabaseMissing('dados_financeiros', ['user_id' => $member->id]);
        $this->assertDatabaseHas('public_registration_identities', ['user_id' => $member->id]);
        Mail::assertQueued(PublicFormSubmissionReceived::class);
    }

    public function test_repeated_registration_reuses_member_but_preserves_both_submissions(): void
    {
        Mail::fake();

        $this->post('/inscricao', $this->registrationPayload())->assertRedirect();
        $firstMemberId = PublicFormSubmission::query()->sole()->user_id;

        $secondPayload = $this->registrationPayload();
        $secondPayload['notes'] = 'Segundo envio com informação adicional.';
        $this->post('/inscricao', $secondPayload)->assertRedirect();

        $this->assertDatabaseCount('public_form_submissions', 2);
        $this->assertDatabaseCount('public_registration_identities', 1);
        $this->assertSame(1, DadosPessoais::query()->where('nome_completo', 'Atleta Menor')->count());
        $this->assertSame([$firstMemberId], PublicFormSubmission::query()->pluck('user_id')->unique()->values()->all());
    }

    public function test_siblings_can_share_guardian_email_without_sharing_member_record(): void
    {
        Mail::fake();

        $this->post('/inscricao', $this->registrationPayload())->assertRedirect();

        $sibling = $this->registrationPayload();
        $sibling['athleteName'] = 'Irmão do Atleta';
        $sibling['birthDate'] = today()->subYears(10)->toDateString();
        $this->post('/inscricao', $sibling)->assertRedirect();

        $this->assertDatabaseCount('public_form_submissions', 2);
        $this->assertDatabaseCount('public_registration_identities', 2);
        $this->assertSame(2, PublicFormSubmission::query()->pluck('user_id')->unique()->count());
    }

    public function test_existing_canonical_member_is_associated_instead_of_duplicated(): void
    {
        Mail::fake();
        $existing = User::factory()->create(['name' => 'Atleta Menor']);
        DadosPessoais::query()->create([
            'user_id' => $existing->id,
            'nome_completo' => 'Atleta Menor',
            'data_nascimento' => today()->subYears(12)->toDateString(),
        ]);

        $usersBefore = User::query()->count();
        $this->post('/inscricao', $this->registrationPayload())->assertRedirect();

        $this->assertSame($usersBefore, User::query()->count());
        $this->assertSame($existing->id, PublicFormSubmission::query()->sole()->user_id);
    }

    public function test_backoffice_lists_opens_and_updates_submission_status(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['perfil' => 'admin']);
        $this->post('/junta-te', $this->contactPayload())->assertRedirect();
        $submission = PublicFormSubmission::query()->sole();

        $this->actingAs($admin)->get('/website-redes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WebsiteRedes/Index')
                ->where('summary.new', 1)
                ->where('submissions.data.0.id', $submission->id)
            );

        $this->actingAs($admin)->get('/website-redes/pedidos/'.$submission->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WebsiteRedes/Index')
                ->where('selectedSubmission.id', $submission->id)
            );

        $this->actingAs($admin)->patch('/website-redes/pedidos/'.$submission->id.'/estado', [
            'status' => 'contacted',
        ])->assertRedirect();

        $submission->refresh();
        $this->assertSame('contacted', $submission->status);
        $this->assertSame($admin->id, $submission->processed_by);
        $this->assertNotNull($submission->processed_at);
        $this->assertSame(1, InAppAlert::query()->where('user_id', $admin->id)->count());
    }

    /** @return array<string, mixed> */
    private function contactPayload(): array
    {
        return [
            'athleteName' => 'Atleta Adulto',
            'birthDate' => today()->subYears(25)->toDateString(),
            'email' => 'atleta@example.com',
            'phone' => '912345678',
            'program' => 'Masters',
            'experience' => 'Entre 2 e 5 anos',
            'notes' => 'Pedido de informação.',
            'consent' => true,
            'company' => '',
        ];
    }

    /** @return array<string, mixed> */
    private function registrationPayload(): array
    {
        return [
            'athleteName' => 'Atleta Menor',
            'birthDate' => today()->subYears(12)->toDateString(),
            'locality' => 'Benedita',
            'email' => 'familia@example.com',
            'phone' => '912345678',
            'program' => 'Formação competitiva',
            'experience' => 'Até 2 anos',
            'guardianName' => 'Encarregado Teste',
            'guardianRelationship' => 'Mãe',
            'guardianEmail' => 'encarregado@example.com',
            'guardianPhone' => '919876543',
            'consent' => true,
            'accuracy' => true,
            'company' => '',
        ];
    }
}
