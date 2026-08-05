<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permission_nodes')) {
            return;
        }

        $now = now();
        $definitions = [
            ['key' => 'website_redes', 'label' => 'Website & Redes', 'node_type' => 'module'],
            ['key' => 'website_redes.dashboard', 'label' => 'Visão geral', 'node_type' => 'submodule'],
            ['key' => 'website_redes.pedidos', 'label' => 'Pedidos', 'node_type' => 'submodule'],
            ['key' => 'website_redes.paginas', 'label' => 'Páginas', 'node_type' => 'submodule'],
            ['key' => 'website_redes.publicacoes', 'label' => 'Notícias e redes sociais', 'node_type' => 'submodule'],
            ['key' => 'website_redes.integracoes', 'label' => 'Integrações', 'node_type' => 'submodule'],
        ];

        $rootId = DB::table('permission_nodes')->where('key', 'website_redes')->value('id') ?: (string) Str::uuid();
        $sortOrder = ((int) DB::table('permission_nodes')->max('sort_order')) + 1;

        foreach ($definitions as $index => $definition) {
            $id = $definition['key'] === 'website_redes'
                ? $rootId
                : (DB::table('permission_nodes')->where('key', $definition['key'])->value('id') ?: (string) Str::uuid());

            DB::table('permission_nodes')->updateOrInsert(
                ['key' => $definition['key']],
                [
                    'id' => $id,
                    'label' => $definition['label'],
                    'parent_id' => $definition['key'] === 'website_redes' ? null : $rootId,
                    'module_key' => 'website_redes',
                    'node_type' => $definition['node_type'],
                    'sort_order' => $sortOrder + $index,
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        if (! Schema::hasTable('user_types')) {
            return;
        }

        $privilegedTypeIds = DB::table('user_types')
            ->whereIn('codigo', ['administrador', 'direcao'])
            ->pluck('id');

        foreach ($privilegedTypeIds as $userTypeId) {
            if (Schema::hasTable('user_type_menu_modules')) {
                DB::table('user_type_menu_modules')->updateOrInsert(
                    ['user_type_id' => $userTypeId, 'module_key' => 'website_redes'],
                    [
                        'id' => (string) Str::uuid(),
                        'sort_order' => ((int) DB::table('user_type_menu_modules')->where('user_type_id', $userTypeId)->max('sort_order')) + 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }

            $hasConfiguredPermissions = Schema::hasTable('user_type_permissions')
                && Schema::hasColumn('user_type_permissions', 'permission_node_id')
                && DB::table('user_type_permissions')
                    ->where('user_type_id', $userTypeId)
                    ->whereNotNull('permission_node_id')
                    ->exists();

            if ($hasConfiguredPermissions) {
                DB::table('user_type_permissions')->updateOrInsert(
                    ['user_type_id' => $userTypeId, 'permission_node_id' => $rootId],
                    [
                        'id' => (string) Str::uuid(),
                        'modulo' => 'website_redes',
                        'submodulo' => null,
                        'separador' => null,
                        'campo' => null,
                        'pode_ver' => true,
                        'pode_criar' => true,
                        'pode_editar' => true,
                        'pode_eliminar' => true,
                        'can_view' => true,
                        'can_edit' => true,
                        'can_delete' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('user_type_menu_modules')) {
            DB::table('user_type_menu_modules')->where('module_key', 'website_redes')->delete();
        }

        if (! Schema::hasTable('permission_nodes')) {
            return;
        }

        $nodeIds = DB::table('permission_nodes')->where('module_key', 'website_redes')->pluck('id');

        if (Schema::hasTable('user_type_permissions') && Schema::hasColumn('user_type_permissions', 'permission_node_id')) {
            DB::table('user_type_permissions')->whereIn('permission_node_id', $nodeIds)->delete();
        }

        DB::table('permission_nodes')->where('module_key', 'website_redes')->delete();
    }
};
