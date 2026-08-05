<?php

namespace Tests\Feature\Website;

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

        $this->actingAs($admin)->post("/website-redes/paginas/{$page->id}/importar")
            ->assertRedirect();

        $this->assertGreaterThan(0, $page->blocks()->count());
        $this->get('/clube')->assertInertia(fn (Assert $response) => $response->component('PublicSite/Clube'));

        $this->actingAs($admin)->get("/website-redes/paginas/{$page->id}/previsualizar")
            ->assertOk()
            ->assertInertia(fn (Assert $response) => $response
                ->component('PublicSite/ManagedPage')
                ->where('preview', true)
                ->where('page.slug', 'clube')
            );
    }

    public function test_custom_page_is_private_until_published_and_then_enters_public_navigation(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);

        $this->actingAs($admin)->post('/website-redes/paginas', [
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

        $this->actingAs($admin)->patch("/website-redes/paginas/{$page->id}", $payload)
            ->assertRedirect();

        $this->get('/projeto-futuro')
            ->assertOk()
            ->assertInertia(fn (Assert $response) => $response
                ->component('PublicSite/ManagedPage')
                ->where('page.blocks.0.content.title', 'Um projeto publicado')
                ->where('publicNavigation', fn ($navigation) => collect($navigation)->contains('href', '/projeto-futuro'))
            );
    }

    public function test_scheduled_version_does_not_replace_live_snapshot_before_its_date(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = $this->createCustomPage($admin);

        $published = $this->pagePayload($page, 'publish');
        $published['blocks'][0]['content']['title'] = 'Versão atual';
        $this->actingAs($admin)->patch("/website-redes/paginas/{$page->id}", $published)->assertRedirect();

        $scheduled = $this->pagePayload($page->fresh(), 'schedule');
        $scheduled['scheduled_for'] = now()->addDay()->toDateTimeString();
        $scheduled['blocks'][0]['content']['title'] = 'Versão futura';
        $this->actingAs($admin)->patch("/website-redes/paginas/{$page->id}", $scheduled)->assertRedirect();

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
        $this->actingAs($admin)->patch("/website-redes/paginas/{$page->id}", $published)->assertRedirect();

        $draft = $this->pagePayload($page->fresh(), 'save_draft');
        $draft['show_in_navigation'] = false;
        $draft['navigation_label'] = 'Menu em rascunho';
        $draft['sort_order'] = 999;
        $this->actingAs($admin)->patch("/website-redes/paginas/{$page->id}", $draft)->assertRedirect();

        $this->get('/pagina-teste')->assertInertia(fn (Assert $response) => $response
            ->where('publicNavigation', fn ($navigation) => collect($navigation)->contains(fn ($item) => $item['label'] === 'Menu publicado' && $item['href'] === '/pagina-teste'))
        );
    }

    public function test_slug_is_locked_after_first_publication(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = $this->createCustomPage($admin);

        $this->actingAs($admin)->patch("/website-redes/paginas/{$page->id}", $this->pagePayload($page, 'publish'))
            ->assertRedirect();

        $draft = $this->pagePayload($page->fresh(), 'save_draft');
        $draft['slug'] = 'endereco-novo';

        $this->actingAs($admin)->patch("/website-redes/paginas/{$page->id}", $draft)
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

        $this->actingAs($admin)->delete("/website-redes/paginas/{$page->id}")
            ->assertRedirect()
            ->assertSessionHasErrors('page');

        $this->assertNotSoftDeleted($page);
    }

    public function test_registration_page_cannot_be_published_without_its_public_form(): void
    {
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = WebsitePage::query()->where('slug', 'inscricao')->sole();
        $this->actingAs($admin)->post("/website-redes/paginas/{$page->id}/importar")->assertRedirect();

        $payload = $this->pagePayload($page->fresh(), 'publish');
        $payload['blocks'] = collect($payload['blocks'])->reject(fn ($block) => $block['type'] === 'registration_form')->values()->all();

        $this->actingAs($admin)->patch("/website-redes/paginas/{$page->id}", $payload)
            ->assertRedirect()
            ->assertSessionHasErrors('blocks');

        $this->get('/inscricao')->assertInertia(fn (Assert $response) => $response->component('PublicSite/Inscricao'));
    }

    public function test_media_in_use_cannot_be_deleted(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['perfil' => 'admin']);
        $page = $this->createCustomPage($admin);

        $this->actingAs($admin)->post('/website-redes/media', [
            'image' => UploadedFile::fake()->image('piscina.jpg', 1200, 800),
            'alt_text' => 'Piscina de competição',
        ])->assertRedirect();

        $media = WebsiteMedia::query()->sole();
        Storage::disk('public')->assertExists($media->path);

        $payload = $this->pagePayload($page, 'save_draft');
        $payload['blocks'][0]['content']['image'] = $media->url;
        $this->actingAs($admin)->patch("/website-redes/paginas/{$page->id}", $payload)->assertRedirect();

        $this->actingAs($admin)->delete("/website-redes/media/{$media->id}")
            ->assertRedirect()
            ->assertSessionHasErrors('media');

        $this->assertDatabaseHas('website_media', ['id' => $media->id, 'deleted_at' => null]);
        Storage::disk('public')->assertExists($media->path);
    }

    private function createCustomPage(User $admin): WebsitePage
    {
        $this->actingAs($admin)->post('/website-redes/paginas', [
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
            'operation' => $operation,
            'scheduled_for' => null,
            'blocks' => $page->blocks->map(fn ($block): array => [
                'id' => $block->id,
                'block_key' => $block->block_key,
                'type' => $block->type,
                'is_visible' => (bool) $block->is_visible,
                'content' => $block->content,
            ])->all(),
        ];
    }
}
