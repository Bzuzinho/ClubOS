<?php

namespace Tests\Feature\Website;

use App\Models\ConvocationGroup;
use App\Models\Event;
use App\Models\User;
use App\Models\WebsiteMedia;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebsitePageManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_pages_start_in_legacy_mode_without_changing_public_templates(): void
    {
        $this->assertDatabaseHas('website_pages', [
            'slug' => 'clube',
            'status' => 'legacy',
            'is_system' => true,
        ]);

        $this->get('/clube')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('PublicSite/Clube'));
    }

    public function test_admin_can_import_and_preview_system_page_without_publishing_it(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = WebsitePage::query()->where('slug', 'clube')->sole();

        $this->actingAs($admin)->post("/website/paginas/{$page->id}/importar")
            ->assertRedirect();

        $this->assertGreaterThan(0, $page->blocks()->count());
        $this->get('/clube')->assertInertia(fn (Assert $response) => $response->component('PublicSite/Clube'));

        $this->actingAs($admin)->get("/website/paginas/{$page->id}/previsualizar")
            ->assertOk()
            ->assertInertia(fn (Assert $response) => $response
                ->component('PublicSite/ManagedPage')
                ->where('preview', true)
                ->where('page.slug', 'clube')
            );
    }

    public function test_home_import_captures_the_complete_current_structure_and_dynamic_sources(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = WebsitePage::query()->where('slug', 'home')->sole();

        $this->actingAs($admin)->post("/website/paginas/{$page->id}/importar")
            ->assertRedirect()
            ->assertSessionHas('success');

        $blocks = $page->fresh()->blocks()->orderBy('sort_order')->get();

        $this->assertSame([
            'hero',
            'announcement',
            'news-and-events',
            'identity',
            'programmes',
            'training',
            'clubos',
            'final-cta',
        ], $blocks->pluck('block_key')->all());
        $this->assertSame(
            ['news', 'events'],
            collect($blocks->firstWhere('block_key', 'news-and-events')->content['items'])
                ->pluck('content.source')
                ->all(),
        );
        $this->assertDatabaseHas('website_page_versions', [
            'website_page_id' => $page->id,
            'action' => 'imported_current_website',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $response) => $response->component('PublicSite/Home'));
    }

    public function test_admin_can_import_the_whole_existing_website_without_publishing_the_drafts(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);

        $this->actingAs($admin)->post('/website/paginas/importar-website-atual')
            ->assertRedirect()
            ->assertSessionHas('success');

        $systemPages = WebsitePage::query()->where('is_system', true)->withCount('blocks')->get();
        $this->assertGreaterThan(0, $systemPages->count());
        $this->assertTrue($systemPages->every(fn (WebsitePage $page): bool => $page->blocks_count > 0));
        $this->assertTrue($systemPages->every(fn (WebsitePage $page): bool => $page->status === 'draft'));

        $this->get('/')->assertInertia(fn (Assert $response) => $response->component('PublicSite/Home'));
        $this->get('/clube')->assertInertia(fn (Assert $response) => $response->component('PublicSite/Clube'));
    }

    public function test_imported_privacy_page_is_decomposed_into_individually_editable_elements(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = WebsitePage::query()->where('slug', 'privacidade')->sole();

        $this->actingAs($admin)->post("/website/paginas/{$page->id}/importar")
            ->assertRedirect()
            ->assertSessionHas('success');

        $block = $page->fresh()->blocks()->where('block_key', 'privacy')->firstOrFail();
        $elements = collect($block->content['elements']);

        $this->assertSame(['eyebrow', 'heading', 'rich_text'], $elements->pluck('type')->all());
        $this->assertSame('Privacidade', $elements->firstWhere('type', 'eyebrow')['content']['text']);
        $this->assertSame('Informação sobre o tratamento de dados', $elements->firstWhere('type', 'heading')['content']['text']);
        $this->assertStringContainsString('Os dados enviados', $elements->firstWhere('type', 'rich_text')['content']['text']);
        $this->assertNotSame(
            $elements->firstWhere('type', 'eyebrow')['id'],
            $elements->firstWhere('type', 'heading')['id'],
        );
    }

    public function test_nested_card_children_keep_independent_content_style_and_links(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = $this->createCustomPage($admin);
        $payload = $this->pagePayload($page, 'autosave');
        $sectionIndex = collect($payload['blocks'])->search(fn (array $block): bool => $block['type'] === 'section');
        $this->assertNotFalse($sectionIndex);

        $elements = $payload['blocks'][$sectionIndex]['content']['elements'];
        $cardIndex = collect($elements)->search(fn (array $element): bool => $element['type'] === 'card');
        $this->assertNotFalse($cardIndex);
        $headingIndex = collect($elements[$cardIndex]['children'])->search(fn (array $element): bool => $element['type'] === 'heading');
        $this->assertNotFalse($headingIndex);

        $elements[$cardIndex]['children'][$headingIndex]['content']['text'] = 'Título independente';
        $elements[$cardIndex]['children'][$headingIndex]['style']['heading_color'] = '#123456';
        $link = $elements[$cardIndex]['children'][$headingIndex];
        $link['id'] = 'element-card-independent-link';
        $link['type'] = 'link';
        $link['content'] = ['label' => 'Abrir calendário', 'url' => '/calendario'];
        $link['children'] = [];
        $elements[$cardIndex]['children'][] = $link;
        $payload['blocks'][$sectionIndex]['content']['elements'] = $elements;

        $this->actingAs($admin)
            ->patchJson("/website/paginas/{$page->id}/autosave", $payload)
            ->assertOk();

        $block = $page->fresh()->blocks()->where('type', 'section')->firstOrFail();
        $card = collect($block->content['elements'])->firstWhere('type', 'card');
        $heading = collect($card['children'])->firstWhere('type', 'heading');
        $savedLink = collect($card['children'])->firstWhere('type', 'link');

        $this->assertSame('Título independente', $heading['content']['text']);
        $this->assertSame('#123456', $heading['style']['heading_color']);
        $this->assertSame('/calendario', $savedLink['content']['url']);
    }

    public function test_importing_a_managed_live_page_replaces_only_the_draft_with_the_exact_published_snapshot(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = $this->createCustomPage($admin);

        $published = $this->pagePayload($page, 'publish');
        $published['blocks'][0]['content']['title'] = 'Título publicado';
        $published['design_settings']['accent_color'] = '#123456';
        $this->actingAs($admin)->patch("/website/paginas/{$page->id}", $published)->assertRedirect();

        $draft = $this->pagePayload($page->fresh(), 'save_draft');
        $draft['blocks'][0]['content']['title'] = 'Título apenas no rascunho';
        $draft['design_settings']['accent_color'] = '#abcdef';
        $this->actingAs($admin)->patch("/website/paginas/{$page->id}", $draft)->assertRedirect();

        $this->actingAs($admin)->post("/website/paginas/{$page->id}/importar")
            ->assertRedirect()
            ->assertSessionHas('success');

        $page->refresh()->load('blocks');
        $this->assertSame('Título publicado', $page->blocks->first()->content['title']);
        $this->assertSame('#123456', $page->design_settings['accent_color']);
        $this->assertSame('Título publicado', $page->published_snapshot['blocks'][0]['content']['title']);
        $this->assertSame('imported_current_website', $page->versions()->latest('version')->value('action'));

        $this->get('/pagina-teste')->assertInertia(fn (Assert $response) => $response
            ->where('page.blocks.0.content.title', 'Título publicado')
            ->where('page.design_settings.accent_color', '#123456')
        );
    }

    public function test_custom_page_is_private_until_published_and_then_enters_public_navigation(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);

        $this->actingAs($admin)->post('/website/paginas', [
            'title' => 'Projeto futuro',
            'slug' => 'projeto-futuro',
            'navigation_label' => 'Projeto',
            'show_in_navigation' => true,
            'sort_order' => 75,
            'meta_title' => 'Projeto futuro',
            'meta_description' => 'Página de teste.',
        ])->assertRedirect();

        $page = WebsitePage::query()->where('slug', 'projeto-futuro')->sole();
        $this->get('/projeto-futuro')->assertNotFound();

        $payload = $this->pagePayload($page, 'publish');
        $payload['blocks'][0]['content']['title'] = 'Um projeto publicado';

        $this->actingAs($admin)->patch("/website/paginas/{$page->id}", $payload)
            ->assertRedirect();

        $this->get('/projeto-futuro')
            ->assertOk()
            ->assertInertia(fn (Assert $response) => $response
                ->component('PublicSite/ManagedPage')
                ->where('page.blocks.0.content.title', 'Um projeto publicado')
                ->where('publicNavigation', fn ($navigation) => collect($navigation)->contains('href', '/projeto-futuro'))
            );
    }

    public function test_custom_page_fallback_never_shadows_existing_single_segment_routes(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);

        $this->get('/clube')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('PublicSite/Clube'));

        $this->actingAs($admin)->get('/dashboard')->assertOk();
        $this->actingAs($admin)->get('/membros')->assertOk();
        $this->actingAs($admin)->get('/financeiro')->assertOk();
    }

    public function test_scheduled_version_does_not_replace_live_snapshot_before_its_date(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = $this->createCustomPage($admin);

        $published = $this->pagePayload($page, 'publish');
        $published['blocks'][0]['content']['title'] = 'Versão atual';
        $this->actingAs($admin)->patch("/website/paginas/{$page->id}", $published)->assertRedirect();

        $scheduled = $this->pagePayload($page->fresh(), 'schedule');
        $scheduled['scheduled_for'] = now()->addDay()->toDateTimeString();
        $scheduled['blocks'][0]['content']['title'] = 'Versão futura';
        $this->actingAs($admin)->patch("/website/paginas/{$page->id}", $scheduled)->assertRedirect();

        $this->get('/pagina-teste')->assertInertia(fn (Assert $response) => $response
            ->where('page.blocks.0.content.title', 'Versão atual')
        );

        $this->travel(2)->days();
        $this->get('/pagina-teste')->assertInertia(fn (Assert $response) => $response
            ->where('page.blocks.0.content.title', 'Versão futura')
        );
    }

    public function test_draft_menu_changes_do_not_leak_into_the_live_navigation(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = $this->createCustomPage($admin);

        $published = $this->pagePayload($page, 'publish');
        $published['show_in_navigation'] = true;
        $published['navigation_label'] = 'Menu publicado';
        $published['sort_order'] = 5;
        $this->actingAs($admin)->patch("/website/paginas/{$page->id}", $published)->assertRedirect();

        $draft = $this->pagePayload($page->fresh(), 'save_draft');
        $draft['show_in_navigation'] = false;
        $draft['navigation_label'] = 'Menu em rascunho';
        $draft['sort_order'] = 999;
        $this->actingAs($admin)->patch("/website/paginas/{$page->id}", $draft)->assertRedirect();

        $this->get('/pagina-teste')->assertInertia(fn (Assert $response) => $response
            ->where('publicNavigation', fn ($navigation) => collect($navigation)->contains(fn ($item) => $item['label'] === 'Menu publicado' && $item['href'] === '/pagina-teste'))
        );
    }

    public function test_slug_is_locked_after_first_publication(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = $this->createCustomPage($admin);

        $this->actingAs($admin)->patch("/website/paginas/{$page->id}", $this->pagePayload($page, 'publish'))
            ->assertRedirect();

        $draft = $this->pagePayload($page->fresh(), 'save_draft');
        $draft['slug'] = 'endereco-novo';

        $this->actingAs($admin)->patch("/website/paginas/{$page->id}", $draft)
            ->assertRedirect()
            ->assertSessionHasErrors('slug');

        $this->assertDatabaseHas('website_pages', ['id' => $page->id, 'slug' => 'pagina-teste']);
        $this->get('/pagina-teste')->assertOk();
        $this->get('/endereco-novo')->assertNotFound();
    }

    public function test_system_page_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = WebsitePage::query()->where('slug', 'inscricao')->sole();

        $this->actingAs($admin)->delete("/website/paginas/{$page->id}")
            ->assertRedirect()
            ->assertSessionHasErrors('page');

        $this->assertNotSoftDeleted($page);
    }

    public function test_registration_page_cannot_be_published_without_its_public_form(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = WebsitePage::query()->where('slug', 'inscricao')->sole();
        $this->actingAs($admin)->post("/website/paginas/{$page->id}/importar")->assertRedirect();

        $payload = $this->pagePayload($page->fresh(), 'publish');
        $payload['blocks'] = collect($payload['blocks'])->reject(fn ($block) => $block['type'] === 'registration_form')->values()->all();

        $this->actingAs($admin)->patch("/website/paginas/{$page->id}", $payload)
            ->assertRedirect()
            ->assertSessionHasErrors('blocks');

        $this->get('/inscricao')->assertInertia(fn (Assert $response) => $response->component('PublicSite/Inscricao'));
    }

    public function test_registration_page_cannot_keep_the_block_but_remove_the_nested_form_element(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = WebsitePage::query()->where('slug', 'inscricao')->sole();
        $this->actingAs($admin)->post("/website/paginas/{$page->id}/importar")->assertRedirect();

        $payload = $this->pagePayload($page->fresh(), 'publish');
        $formIndex = collect($payload['blocks'])->search(fn (array $block): bool => $block['type'] === 'registration_form');
        $this->assertNotFalse($formIndex);
        $payload['blocks'][$formIndex]['content']['elements'] = collect($payload['blocks'][$formIndex]['content']['elements'])
            ->reject(fn (array $element): bool => $element['type'] === 'registration_form')
            ->values()
            ->all();

        $this->actingAs($admin)->patch("/website/paginas/{$page->id}", $payload)
            ->assertRedirect()
            ->assertSessionHasErrors('blocks');

        $this->get('/inscricao')->assertInertia(fn (Assert $response) => $response->component('PublicSite/Inscricao'));
    }

    public function test_visual_editor_autosaves_design_behavior_and_version_without_publishing(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = $this->createCustomPage($admin);
        $payload = $this->pagePayload($page, 'autosave');
        $payload['design_settings']['background_color'] = '#f4f8fb';
        $payload['design_settings']['heading_font'] = 'montserrat';
        $payload['blocks'][0]['style']['background_color'] = '#eaf5fb';
        $payload['blocks'][0]['style']['heading_size'] = 48;
        $payload['blocks'][0]['settings']['animation'] = 'slide-up';
        $payload['blocks'][0]['settings']['anchor_id'] = 'apresentacao';

        $this->actingAs($admin)
            ->patchJson("/website/paginas/{$page->id}/autosave", $payload)
            ->assertOk()
            ->assertJsonPath('version.action', 'autosave');

        $page->refresh()->load('blocks');
        $this->assertSame('#f4f8fb', $page->design_settings['background_color']);
        $this->assertSame('#eaf5fb', $page->blocks->first()->style['background_color']);
        $this->assertSame('slide-up', $page->blocks->first()->settings['animation']);
        $this->assertSame('apresentacao', $page->versions()->latest('version')->first()->snapshot['blocks'][0]['settings']['anchor_id']);
        $this->get('/pagina-teste')->assertNotFound();
    }

    public function test_editor_exposes_real_dynamic_data_sources_and_aggregate_statistics(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = $this->createCustomPage($admin);
        $event = Event::query()->create([
            'titulo' => 'Torneio público',
            'descricao' => 'Competição aberta ao público.',
            'data_inicio' => today()->addWeek(),
            'tipo' => 'competicao',
            'visibilidade' => 'publico',
            'estado' => 'agendado',
            'criado_por' => $admin->id,
        ]);
        ConvocationGroup::query()->create([
            'evento_id' => $event->id,
            'data_criacao' => now(),
            'criado_por' => $admin->id,
            'atletas_ids' => [$admin->id],
            'tipo_custo' => 'sem_custo',
            'hora_encontro' => '08:30',
            'local_encontro' => 'Piscinas Municipais',
        ]);

        $this->actingAs($admin)
            ->get("/website/paginas/{$page->id}/editar")
            ->assertOk()
            ->assertInertia(fn (Assert $response) => $response
                ->component('Website/Pages/Edit')
                ->where('dataSources.0.value', 'news')
                ->where('dataSources.1.value', 'events')
                ->where('dataSources.2.value', 'convocations')
                ->where('dataSources.3.value', 'partners')
                ->where('dataSources.4.value', 'statistics')
                ->has('dynamicData.statistics', 6)
                ->has('dynamicData.news')
                ->has('dynamicData.events')
                ->where('dynamicData.convocations.0.title', 'Torneio público')
                ->where('dynamicData.convocations.0.athleteCount', 1)
                ->missing('dynamicData.convocations.0.athletes')
                ->has('dynamicData.partners')
            );
    }

    public function test_visual_editor_preserves_nested_elements_and_dynamic_data_configuration(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = $this->createCustomPage($admin);
        $payload = $this->pagePayload($page, 'autosave');
        $sectionIndex = collect($payload['blocks'])->search(fn (array $block): bool => $block['type'] === 'section');
        $this->assertNotFalse($sectionIndex);
        $payload['blocks'][$sectionIndex]['content'] = [
            'eyebrow' => 'Atualidade',
            'title' => 'Notícias e agenda',
            'intro' => 'Dados públicos do ClubOS.',
            'columns_desktop' => 6,
            'columns_tablet' => 2,
            'columns_mobile' => 1,
            'gap' => 24,
            'align_items' => 'start',
            'items' => [[
                'id' => 'element-news-feed',
                'type' => 'data_collection',
                'is_visible' => true,
                'content' => [
                    'source' => 'statistics',
                    'limit' => 4,
                    'layout' => 'metrics',
                    'columns' => 2,
                    'show_image' => true,
                    'show_meta' => true,
                    'show_description' => true,
                    'show_link' => true,
                    'link_label' => 'Ler notícia',
                ],
                'style' => [
                    'column_span' => 4,
                    'tablet_span' => 2,
                    'mobile_span' => 1,
                    'heading_font' => 'poppins',
                    'heading_size' => 28,
                    'body_font' => 'inter',
                    'body_size' => 15,
                    'heading_weight' => 700,
                    'body_weight' => 400,
                    'line_height' => 1.7,
                    'margin_top' => 18,
                    'margin_right' => 12,
                    'margin_bottom' => 24,
                    'margin_left' => 12,
                    'padding_top' => 8,
                    'padding_right' => 10,
                    'padding_bottom' => 8,
                    'padding_left' => 10,
                    'data_card_background' => '#eef6fb',
                    'data_card_text_color' => '#123456',
                    'data_card_border_color' => '#77aacc',
                    'data_card_border_width' => 3,
                    'data_card_radius' => 20,
                    'data_card_shadow' => 'medium',
                    'data_card_padding' => 26,
                ],
                'settings' => [
                    'animation' => 'slide-up',
                    'animation_delay' => 150,
                    'hide_mobile' => false,
                    'hide_desktop' => false,
                    'open_link_new_tab' => false,
                ],
            ]],
        ];

        $this->actingAs($admin)
            ->patchJson("/website/paginas/{$page->id}/autosave", $payload)
            ->assertOk();

        $block = $page->fresh()->blocks()->where('type', 'section')->firstOrFail();
        $this->assertSame('section', $block->type);
        $this->assertSame('statistics', $block->content['items'][0]['content']['source']);
        $this->assertSame('metrics', $block->content['items'][0]['content']['layout']);
        $this->assertSame(4, $block->content['items'][0]['style']['column_span']);
        $this->assertSame('poppins', $block->content['items'][0]['style']['heading_font']);
        $this->assertSame(18, $block->content['items'][0]['style']['margin_top']);
        $this->assertSame(26, $block->content['items'][0]['style']['data_card_padding']);
        $this->assertSame('#77aacc', $block->content['items'][0]['style']['data_card_border_color']);
        $this->assertSame('slide-up', $block->content['items'][0]['settings']['animation']);
        $snapshot = $page->fresh()->versions()->latest('version')->first()->snapshot;
        $sectionSnapshot = collect($snapshot['blocks'])->firstWhere('type', 'section');
        $this->assertSame('statistics', $sectionSnapshot['content']['items'][0]['content']['source']);
        $this->assertSame(24, $sectionSnapshot['content']['items'][0]['style']['margin_bottom']);
    }

    public function test_old_website_redes_address_redirects_to_independent_website_module(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);

        $this->actingAs($admin)->get('/website-redes/paginas')->assertRedirect('/website/paginas');
    }

    public function test_media_used_as_page_background_cannot_be_deleted(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = $this->createCustomPage($admin);

        $this->actingAs($admin)->post('/website/media', [
            'image' => UploadedFile::fake()->image('piscina.jpg', 1200, 800),
            'alt_text' => 'Piscina de competição',
        ])->assertRedirect();

        $media = WebsiteMedia::query()->sole();
        Storage::disk('public')->assertExists($media->path);

        $payload = $this->pagePayload($page, 'save_draft');
        $payload['design_settings']['background_image'] = $media->url;
        $payload['design_settings']['background_position'] = 'center top';
        $this->actingAs($admin)->patch("/website/paginas/{$page->id}", $payload)->assertRedirect();

        $this->actingAs($admin)->delete("/website/media/{$media->id}")
            ->assertRedirect()
            ->assertSessionHasErrors('media');

        $this->assertDatabaseHas('website_media', ['id' => $media->id, 'deleted_at' => null]);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_media_upload_rejects_files_above_eight_megabytes_inside_the_application(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['perfil' => 'admin']);

        $this->actingAs($admin)->post('/website/media', [
            'image' => UploadedFile::fake()->image('demasiado-grande.jpg', 2400, 1600)->size(9000),
            'alt_text' => 'Imagem demasiado grande',
        ])->assertSessionHasErrors('image');

        $this->assertDatabaseCount('website_media', 0);
    }

    private function createCustomPage(User $admin): WebsitePage
    {
        $this->actingAs($admin)->post('/website/paginas', [
            'title' => 'Página teste',
            'slug' => 'pagina-teste',
            'navigation_label' => 'Teste',
            'show_in_navigation' => false,
            'sort_order' => 90,
            'meta_title' => 'Página teste',
            'meta_description' => null,
        ])->assertRedirect();

        return WebsitePage::query()->where('slug', 'pagina-teste')->sole();
    }

    /** @return array<string, mixed> */
    private function pagePayload(WebsitePage $page, string $operation): array
    {
        $page->load('blocks');

        return [
            'title' => $page->title,
            'slug' => $page->slug,
            'navigation_label' => $page->navigation_label,
            'show_in_navigation' => (bool) $page->show_in_navigation,
            'sort_order' => (int) $page->sort_order,
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'design_settings' => $page->design_settings ?? [
                'background_color' => '#ffffff',
                'text_color' => '#102c44',
                'heading_color' => '#062b54',
                'accent_color' => '#f2e613',
                'heading_font' => 'inter',
                'body_font' => 'inter',
                'base_font_size' => 16,
                'content_width' => 'standard',
                'background_image' => null,
                'background_position' => 'center top',
            ],
            'operation' => $operation,
            'scheduled_for' => null,
            'blocks' => $page->blocks->map(fn ($block): array => [
                'id' => $block->id,
                'block_key' => $block->block_key,
                'type' => $block->type,
                'is_visible' => (bool) $block->is_visible,
                'content' => $block->content,
                'style' => $block->style,
                'settings' => $block->settings,
            ])->all(),
        ];
    }
}
