<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_pages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('navigation_label')->nullable();
            $table->string('status')->default('legacy');
            $table->boolean('is_system')->default(false);
            $table->boolean('show_in_navigation')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->json('published_snapshot')->nullable();
            $table->json('scheduled_snapshot')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'show_in_navigation', 'sort_order']);
        });

        Schema::create('website_page_blocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('website_page_id');
            $table->string('block_key');
            $table->string('type');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->json('content');
            $table->timestamps();

            $table->foreign('website_page_id')->references('id')->on('website_pages')->cascadeOnDelete();
            $table->unique(['website_page_id', 'block_key']);
            $table->index(['website_page_id', 'sort_order']);
        });

        Schema::create('website_page_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('website_page_id');
            $table->unsignedInteger('version');
            $table->string('action');
            $table->json('snapshot');
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('website_page_id')->references('id')->on('website_pages')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['website_page_id', 'version']);
        });

        Schema::create('website_media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text', 220);
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['created_at', 'deleted_at']);
        });

        $now = now();
        $pages = [
            ['slug' => 'home', 'title' => 'Início', 'navigation_label' => null, 'show_in_navigation' => false, 'sort_order' => 0],
            ['slug' => 'clube', 'title' => 'O clube', 'navigation_label' => 'O clube', 'show_in_navigation' => true, 'sort_order' => 10],
            ['slug' => 'competicao', 'title' => 'Natação de competição', 'navigation_label' => 'Natação', 'show_in_navigation' => true, 'sort_order' => 20],
            ['slug' => 'treinos', 'title' => 'Treinos', 'navigation_label' => 'Treinos', 'show_in_navigation' => true, 'sort_order' => 30],
            ['slug' => 'noticias', 'title' => 'Notícias', 'navigation_label' => 'Notícias', 'show_in_navigation' => true, 'sort_order' => 40],
            ['slug' => 'calendario', 'title' => 'Calendário', 'navigation_label' => 'Calendário', 'show_in_navigation' => true, 'sort_order' => 50],
            ['slug' => 'parceiros', 'title' => 'Parceiros', 'navigation_label' => 'Parceiros', 'show_in_navigation' => true, 'sort_order' => 60],
            ['slug' => 'contactos', 'title' => 'Contactos', 'navigation_label' => 'Contactos', 'show_in_navigation' => true, 'sort_order' => 70],
            ['slug' => 'junta-te', 'title' => 'Pedir contacto', 'navigation_label' => null, 'show_in_navigation' => false, 'sort_order' => 80],
            ['slug' => 'inscricao', 'title' => 'Inscreve-te', 'navigation_label' => null, 'show_in_navigation' => false, 'sort_order' => 90],
            ['slug' => 'privacidade', 'title' => 'Privacidade', 'navigation_label' => null, 'show_in_navigation' => false, 'sort_order' => 100],
        ];

        DB::table('website_pages')->insert(array_map(static fn (array $page): array => [
            'id' => (string) Str::uuid(),
            'slug' => $page['slug'],
            'title' => $page['title'],
            'navigation_label' => $page['navigation_label'],
            'status' => 'legacy',
            'is_system' => true,
            'show_in_navigation' => $page['show_in_navigation'],
            'sort_order' => $page['sort_order'],
            'meta_title' => $page['title'],
            'meta_description' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $pages));
    }

    public function down(): void
    {
        Schema::dropIfExists('website_media');
        Schema::dropIfExists('website_page_versions');
        Schema::dropIfExists('website_page_blocks');
        Schema::dropIfExists('website_pages');
    }
};
