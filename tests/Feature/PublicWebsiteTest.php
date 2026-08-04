<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\NewsItem;
use App\Models\PublicFormSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_website_routes_are_available_and_login_remains_at_login(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('PublicSite/Home'));

        foreach ([
            'clube' => 'Clube',
            'competicao' => 'Competicao',
            'treinos' => 'Treinos',
            'noticias' => 'Noticias',
            'calendario' => 'Calendario',
            'parceiros' => 'Parceiros',
            'contactos' => 'Contactos',
            'junta-te' => 'JuntaTe',
            'inscricao' => 'Inscricao',
            'privacidade' => 'Privacidade',
        ] as $route => $component) {
            $this->get('/'.$route)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('PublicSite/'.$component));
        }

        $this->get('/login')->assertOk();
    }

    public function test_home_receives_published_news_and_public_future_events_from_clubos(): void
    {
        $author = User::factory()->create();

        $news = NewsItem::query()->create([
            'titulo' => 'Notícia pública do clube',
            'conteudo' => '<p>Conteúdo publicado através do ClubOS.</p>',
            'destaque' => true,
            'autor' => $author->id,
            'data_publicacao' => now()->subMinute(),
            'categorias' => ['Clube'],
        ]);

        $event = Event::query()->create([
            'titulo' => 'Prova pública do BSCN',
            'descricao' => 'Evento confirmado.',
            'data_inicio' => today()->addWeek(),
            'tipo' => 'prova',
            'visibilidade' => 'publico',
            'estado' => 'agendado',
            'criado_por' => $author->id,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PublicSite/Home')
                ->where('news.0.id', (string) $news->id)
                ->where('events.0.id', (string) $event->id)
            );
    }

    public function test_contact_request_is_validated_and_stored_separately(): void
    {
        $this->from('/junta-te')->post('/junta-te', [
            'athleteName' => 'Atleta Adulto',
            'birthDate' => today()->subYears(25)->toDateString(),
            'email' => 'atleta@example.com',
            'phone' => '912345678',
            'program' => 'Masters',
            'experience' => 'Entre 2 e 5 anos',
            'notes' => 'Pedido de informação.',
            'consent' => true,
            'company' => '',
        ])->assertRedirect('/junta-te');

        $this->assertDatabaseHas('public_form_submissions', [
            'type' => 'contact',
            'athlete_name' => 'Atleta Adulto',
            'email' => 'atleta@example.com',
            'status' => 'new',
        ]);
    }

    public function test_registration_is_stored_and_minor_requires_complete_guardian_data(): void
    {
        $payload = [
            'athleteName' => 'Atleta Menor',
            'birthDate' => today()->subYears(12)->toDateString(),
            'locality' => 'Benedita',
            'email' => 'familia@example.com',
            'phone' => '912345678',
            'program' => 'Formação competitiva',
            'experience' => 'Até 2 anos',
            'consent' => true,
            'accuracy' => true,
            'company' => '',
        ];

        $this->from('/inscricao')->post('/inscricao', $payload)
            ->assertSessionHasErrors(['guardianName', 'guardianRelationship', 'guardianEmail', 'guardianPhone']);

        $this->from('/inscricao')->post('/inscricao', [
            ...$payload,
            'guardianName' => 'Encarregado Teste',
            'guardianRelationship' => 'Mãe',
            'guardianEmail' => 'encarregado@example.com',
            'guardianPhone' => '919876543',
        ])->assertRedirect('/inscricao');

        $submission = PublicFormSubmission::query()->sole();
        $this->assertSame('registration', $submission->type);
        $this->assertSame('Encarregado Teste', $submission->guardian_name);
    }

    public function test_honeypot_accepts_response_without_persisting_spam(): void
    {
        $this->from('/junta-te')->post('/junta-te', [
            'company' => 'Spam Company',
        ])->assertRedirect('/junta-te');

        $this->assertDatabaseCount('public_form_submissions', 0);
    }
}
