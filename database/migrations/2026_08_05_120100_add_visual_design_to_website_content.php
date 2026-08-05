<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_pages', function (Blueprint $table): void {
            $table->json('design_settings')->nullable()->after('meta_description');
        });

        Schema::table('website_page_blocks', function (Blueprint $table): void {
            $table->json('style')->nullable()->after('content');
            $table->json('settings')->nullable()->after('style');
        });
    }

    public function down(): void
    {
        Schema::table('website_page_blocks', function (Blueprint $table): void {
            $table->dropColumn(['style', 'settings']);
        });

        Schema::table('website_pages', function (Blueprint $table): void {
            $table->dropColumn('design_settings');
        });
    }
};
