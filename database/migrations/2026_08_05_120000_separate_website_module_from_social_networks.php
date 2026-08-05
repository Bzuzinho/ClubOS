<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permission_nodes')) {
            $renames = [
                'website_redes' => ['website', 'Website'],
                'website_redes.dashboard' => ['website.dashboard', 'Visão geral'],
                'website_redes.pedidos' => ['website.pedidos', 'Pedidos'],
                'website_redes.paginas' => ['website.paginas', 'Páginas'],
                'website_redes.publicacoes' => ['website.noticias', 'Notícias do website'],
                'website_redes.integracoes' => ['website.integracoes', 'Integrações do website'],
            ];

            foreach ($renames as $oldKey => [$newKey, $label]) {
                DB::table('permission_nodes')->where('key', $oldKey)->update([
                    'key' => $newKey,
                    'label' => $label,
                    'module_key' => 'website',
                    'updated_at' => now(),
                ]);
            }

            DB::table('permission_nodes')->where('module_key', 'website_redes')->update([
                'module_key' => 'website',
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('user_type_menu_modules')) {
            DB::table('user_type_menu_modules')->where('module_key', 'website_redes')->update([
                'module_key' => 'website',
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('user_type_permissions')) {
            DB::table('user_type_permissions')->where('modulo', 'website_redes')->update([
                'modulo' => 'website',
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('user_type_landing_pages')) {
            DB::table('user_type_landing_pages')->where('landing_module_key', 'website_redes')->update([
                'landing_module_key' => 'website',
                'updated_at' => now(),
            ]);

            $basePages = [
                'website_redes_dashboard' => 'website_dashboard',
                'website_redes_pedidos' => 'website_pedidos',
                'website_redes_paginas' => 'website_paginas',
            ];

            foreach ($basePages as $oldKey => $newKey) {
                DB::table('user_type_landing_pages')->where('base_page_key', $oldKey)->update([
                    'base_page_key' => $newKey,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permission_nodes')) {
            $renames = [
                'website' => ['website_redes', 'Website & Redes'],
                'website.dashboard' => ['website_redes.dashboard', 'Visão geral'],
                'website.pedidos' => ['website_redes.pedidos', 'Pedidos'],
                'website.paginas' => ['website_redes.paginas', 'Páginas'],
                'website.noticias' => ['website_redes.publicacoes', 'Notícias e redes sociais'],
                'website.integracoes' => ['website_redes.integracoes', 'Integrações'],
            ];

            foreach ($renames as $oldKey => [$newKey, $label]) {
                DB::table('permission_nodes')->where('key', $oldKey)->update([
                    'key' => $newKey,
                    'label' => $label,
                    'module_key' => 'website_redes',
                    'updated_at' => now(),
                ]);
            }

            DB::table('permission_nodes')->where('module_key', 'website')->update([
                'module_key' => 'website_redes',
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('user_type_menu_modules')) {
            DB::table('user_type_menu_modules')->where('module_key', 'website')->update([
                'module_key' => 'website_redes',
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('user_type_permissions')) {
            DB::table('user_type_permissions')->where('modulo', 'website')->update([
                'modulo' => 'website_redes',
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('user_type_landing_pages')) {
            DB::table('user_type_landing_pages')->where('landing_module_key', 'website')->update([
                'landing_module_key' => 'website_redes',
                'updated_at' => now(),
            ]);

            $basePages = [
                'website_dashboard' => 'website_redes_dashboard',
                'website_pedidos' => 'website_redes_pedidos',
                'website_paginas' => 'website_redes_paginas',
            ];

            foreach ($basePages as $oldKey => $newKey) {
                DB::table('user_type_landing_pages')->where('base_page_key', $oldKey)->update([
                    'base_page_key' => $newKey,
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
