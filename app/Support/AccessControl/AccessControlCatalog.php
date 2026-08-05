<?php

namespace App\Support\AccessControl;

final class AccessControlCatalog
{
    public static function menuModules(): array
    {
        return [
            ['key' => 'inicio', 'label' => 'Início', 'route' => '/dashboard'],
            ['key' => 'membros', 'label' => 'Membros', 'route' => '/membros'],
            ['key' => 'desportivo', 'label' => 'Desportivo', 'route' => '/desportivo'],
            ['key' => 'eventos', 'label' => 'Eventos', 'route' => '/eventos'],
            ['key' => 'financeiro', 'label' => 'Financeiro', 'route' => '/financeiro'],
            ['key' => 'logistica', 'label' => 'Logística', 'route' => '/logistica'],
            ['key' => 'loja', 'label' => 'Loja', 'route' => '/admin/loja'],
            ['key' => 'patrocinios', 'label' => 'Patrocínios', 'route' => '/patrocinios'],
            ['key' => 'comunicacao', 'label' => 'Comunicação', 'route' => '/comunicacao'],
            ['key' => 'marketing', 'label' => 'Marketing', 'route' => '/campanhas-marketing'],
            ['key' => 'website', 'label' => 'Website', 'route' => '/website'],
            ['key' => 'configuracoes', 'label' => 'Configurações', 'route' => '/configuracoes'],
        ];
    }

    public static function landingPages(): array
    {
        return [
            [
                'module_key' => 'inicio',
                'module_label' => 'Início',
                'base_pages' => [
                    ['key' => 'dashboard_geral', 'label' => 'Dashboard geral', 'route' => '/dashboard'],
                ],
            ],
            [
                'module_key' => 'membros',
                'module_label' => 'Membros',
                'base_pages' => [
                    ['key' => 'membros_dashboard', 'label' => 'Dashboard de membros', 'route' => '/membros?tab=dashboard'],
                    ['key' => 'membros_lista', 'label' => 'Lista de membros', 'route' => '/membros?tab=list'],
                    ['key' => 'membros_ficha_propria', 'label' => 'Ficha do próprio utilizador', 'route' => '/membros/__current__'],
                    ['key' => 'membros_educando_principal', 'label' => 'Ficha do educando principal', 'route' => '/membros/__dependent__'],
                ],
            ],
            [
                'module_key' => 'desportivo',
                'module_label' => 'Desportivo',
                'base_pages' => [
                    ['key' => 'desportivo_dashboard', 'label' => 'Dashboard desportivo', 'route' => '/desportivo'],
                    ['key' => 'desportivo_planeamento', 'label' => 'Planeamento', 'route' => '/desportivo/planeamento'],
                    ['key' => 'desportivo_treinos', 'label' => 'Treinos', 'route' => '/desportivo/treinos'],
                    ['key' => 'desportivo_cais', 'label' => 'Cais', 'route' => '/desportivo/cais'],
                    ['key' => 'desportivo_competicoes', 'label' => 'Competições', 'route' => '/desportivo/competicoes'],
                ],
            ],
            [
                'module_key' => 'eventos',
                'module_label' => 'Eventos',
                'base_pages' => [
                    ['key' => 'eventos_calendario', 'label' => 'Calendário de eventos', 'route' => '/eventos'],
                ],
            ],
            [
                'module_key' => 'financeiro',
                'module_label' => 'Financeiro',
                'base_pages' => [
                    ['key' => 'financeiro_dashboard', 'label' => 'Dashboard financeiro', 'route' => '/financeiro'],
                ],
            ],
            [
                'module_key' => 'logistica',
                'module_label' => 'Logística',
                'base_pages' => [
                    ['key' => 'logistica_dashboard', 'label' => 'Dashboard de logística', 'route' => '/logistica'],
                    ['key' => 'logistica_requisicoes', 'label' => 'Requisições', 'route' => '/logistica?tab=requisicoes'],
                    ['key' => 'logistica_stock', 'label' => 'Stock', 'route' => '/logistica?tab=stock'],
                ],
            ],
            [
                'module_key' => 'loja',
                'module_label' => 'Loja',
                'base_pages' => [
                    ['key' => 'loja_dashboard', 'label' => 'Loja', 'route' => '/admin/loja'],
                ],
            ],
            [
                'module_key' => 'patrocinios',
                'module_label' => 'Patrocínios',
                'base_pages' => [
                    ['key' => 'patrocinios_dashboard', 'label' => 'Dashboard de patrocínios', 'route' => '/patrocinios'],
                ],
            ],
            [
                'module_key' => 'comunicacao',
                'module_label' => 'Comunicação',
                'base_pages' => [
                    ['key' => 'comunicacao_dashboard', 'label' => 'Comunicação', 'route' => '/comunicacao'],
                ],
            ],
            [
                'module_key' => 'marketing',
                'module_label' => 'Marketing',
                'base_pages' => [
                    ['key' => 'marketing_campanhas', 'label' => 'Campanhas', 'route' => '/campanhas-marketing'],
                ],
            ],
            [
                'module_key' => 'website',
                'module_label' => 'Website',
                'base_pages' => [
                    ['key' => 'website_dashboard', 'label' => 'Visão geral', 'route' => '/website'],
                    ['key' => 'website_pedidos', 'label' => 'Pedidos', 'route' => '/website?tab=pedidos'],
                    ['key' => 'website_paginas', 'label' => 'Páginas', 'route' => '/website/paginas'],
                ],
            ],
            [
                'module_key' => 'configuracoes',
                'module_label' => 'Configurações',
                'base_pages' => [
                    ['key' => 'configuracoes_permissoes', 'label' => 'Permissões', 'route' => '/configuracoes?tab=geral&subtab=geral-permissoes'],
                    ['key' => 'configuracoes_tipos_utilizador', 'label' => 'Tipos de utilizador', 'route' => '/configuracoes?tab=geral&subtab=geral-tipos-utilizador'],
                ],
            ],
        ];
    }

    public static function permissionTree(): array
    {
        return [
            [
                'key' => 'membros',
                'label' => 'Membros',
                'node_type' => 'module',
                'module_key' => 'membros',
                'children' => [
                    ['key' => 'membros.lista', 'label' => 'Lista', 'node_type' => 'submodule', 'module_key' => 'membros', 'children' => []],
                    [
                        'key' => 'membros.ficha',
                        'label' => 'Ficha',
                        'node_type' => 'submodule',
                        'module_key' => 'membros',
                        'children' => [
                            [
                                'key' => 'membros.ficha.dashboard',
                                'label' => 'Dashboard',
                                'node_type' => 'tab',
                                'module_key' => 'membros',
                                'children' => [],
                            ],
                            [
                                'key' => 'membros.ficha.pessoal',
                                'label' => 'Pessoal',
                                'node_type' => 'tab',
                                'module_key' => 'membros',
                                'children' => [
                                    ['key' => 'membros.ficha.pessoal.nome_completo', 'label' => 'nome_completo', 'node_type' => 'field', 'module_key' => 'membros', 'children' => []],
                                    ['key' => 'membros.ficha.pessoal.encarregado_educacao', 'label' => 'encarregado_educacao', 'node_type' => 'field', 'module_key' => 'membros', 'children' => []],
                                ],
                            ],
                            [
                                'key' => 'membros.ficha.financeiro',
                                'label' => 'Financeiro',
                                'node_type' => 'tab',
                                'module_key' => 'membros',
                                'children' => [
                                    ['key' => 'membros.ficha.financeiro.conta_corrente', 'label' => 'conta_corrente', 'node_type' => 'field', 'module_key' => 'membros', 'children' => []],
                                ],
                            ],
                            [
                                'key' => 'membros.ficha.desportivo',
                                'label' => 'Desportivo',
                                'node_type' => 'tab',
                                'module_key' => 'membros',
                                'children' => [
                                    ['key' => 'membros.ficha.desportivo.dados_desportivos', 'label' => 'Dados Desportivos', 'node_type' => 'field', 'module_key' => 'membros', 'children' => []],
                                    ['key' => 'membros.ficha.desportivo.informacoes_medicas', 'label' => 'Informações Médicas', 'node_type' => 'field', 'module_key' => 'membros', 'children' => []],
                                    ['key' => 'membros.ficha.desportivo.convocatorias', 'label' => 'Convocatórias', 'node_type' => 'field', 'module_key' => 'membros', 'children' => []],
                                    ['key' => 'membros.ficha.desportivo.presencas', 'label' => 'Registo Presenças', 'node_type' => 'field', 'module_key' => 'membros', 'children' => []],
                                    ['key' => 'membros.ficha.desportivo.resultados', 'label' => 'Resultados', 'node_type' => 'field', 'module_key' => 'membros', 'children' => []],
                                    ['key' => 'membros.ficha.desportivo.treinos', 'label' => 'Treinos', 'node_type' => 'field', 'module_key' => 'membros', 'children' => []],
                                    ['key' => 'membros.ficha.desportivo.disciplina', 'label' => 'Disciplina', 'node_type' => 'field', 'module_key' => 'membros', 'children' => []],
                                    ['key' => 'membros.ficha.desportivo.metros_finais', 'label' => 'metros_finais', 'node_type' => 'field', 'module_key' => 'membros', 'children' => []],
                                ],
                            ],
                            [
                                'key' => 'membros.ficha.configuracao',
                                'label' => 'Configuração',
                                'node_type' => 'tab',
                                'module_key' => 'membros',
                                'children' => [
                                    ['key' => 'membros.ficha.configuracao.perfil', 'label' => 'perfil', 'node_type' => 'field', 'module_key' => 'membros', 'children' => []],
                                ],
                            ],
                            [
                                'key' => 'membros.ficha.comunicacoes',
                                'label' => 'Comunicações',
                                'node_type' => 'tab',
                                'module_key' => 'membros',
                                'children' => [],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'key' => 'desportivo',
                'label' => 'Desportivo',
                'node_type' => 'module',
                'module_key' => 'desportivo',
                'children' => [
                    ['key' => 'desportivo.dashboard', 'label' => 'Dashboard', 'node_type' => 'submodule', 'module_key' => 'desportivo', 'children' => []],
                    ['key' => 'desportivo.planeamento', 'label' => 'Planeamento', 'node_type' => 'submodule', 'module_key' => 'desportivo', 'children' => []],
                    [
                        'key' => 'desportivo.treinos',
                        'label' => 'Treinos',
                        'node_type' => 'submodule',
                        'module_key' => 'desportivo',
                        'children' => [
                            ['key' => 'desportivo.treinos.biblioteca', 'label' => 'Biblioteca', 'node_type' => 'tab', 'module_key' => 'desportivo', 'children' => []],
                            ['key' => 'desportivo.treinos.agendamento', 'label' => 'Agendamento', 'node_type' => 'tab', 'module_key' => 'desportivo', 'children' => []],
                            ['key' => 'desportivo.treinos.cais', 'label' => 'Cais', 'node_type' => 'tab', 'module_key' => 'desportivo', 'children' => []],
                        ],
                    ],
                    ['key' => 'desportivo.presencas', 'label' => 'Presenças', 'node_type' => 'submodule', 'module_key' => 'desportivo', 'children' => []],
                    ['key' => 'desportivo.competicoes', 'label' => 'Competições', 'node_type' => 'submodule', 'module_key' => 'desportivo', 'children' => []],
                    ['key' => 'desportivo.resultados', 'label' => 'Resultados', 'node_type' => 'submodule', 'module_key' => 'desportivo', 'children' => []],
                ],
            ],
            [
                'key' => 'eventos',
                'label' => 'Eventos',
                'node_type' => 'module',
                'module_key' => 'eventos',
                'children' => [
                    ['key' => 'eventos.calendario', 'label' => 'Calendário', 'node_type' => 'submodule', 'module_key' => 'eventos', 'children' => []],
                    ['key' => 'eventos.convocatorias', 'label' => 'Convocatórias', 'node_type' => 'submodule', 'module_key' => 'eventos', 'children' => []],
                    ['key' => 'eventos.resultados', 'label' => 'Resultados', 'node_type' => 'submodule', 'module_key' => 'eventos', 'children' => []],
                ],
            ],
            [
                'key' => 'financeiro',
                'label' => 'Financeiro',
                'node_type' => 'module',
                'module_key' => 'financeiro',
                'children' => [
                    ['key' => 'financeiro.dashboard', 'label' => 'Dashboard', 'node_type' => 'submodule', 'module_key' => 'financeiro', 'children' => []],
                    ['key' => 'financeiro.faturas', 'label' => 'Faturas', 'node_type' => 'submodule', 'module_key' => 'financeiro', 'children' => []],
                    ['key' => 'financeiro.banco', 'label' => 'Banco', 'node_type' => 'submodule', 'module_key' => 'financeiro', 'children' => []],
                    ['key' => 'financeiro.importacao_recibos', 'label' => 'Importação de Recibos', 'node_type' => 'submodule', 'module_key' => 'financeiro', 'children' => []],
                    ['key' => 'financeiro.relatorios', 'label' => 'Relatórios', 'node_type' => 'submodule', 'module_key' => 'financeiro', 'children' => []],
                ],
            ],
            [
                'key' => 'website',
                'label' => 'Website',
                'node_type' => 'module',
                'module_key' => 'website',
                'children' => [
                    ['key' => 'website.dashboard', 'label' => 'Visão geral', 'node_type' => 'submodule', 'module_key' => 'website', 'children' => []],
                    ['key' => 'website.pedidos', 'label' => 'Pedidos', 'node_type' => 'submodule', 'module_key' => 'website', 'children' => []],
                    ['key' => 'website.paginas', 'label' => 'Páginas', 'node_type' => 'submodule', 'module_key' => 'website', 'children' => []],
                    ['key' => 'website.noticias', 'label' => 'Notícias do website', 'node_type' => 'submodule', 'module_key' => 'website', 'children' => []],
                    ['key' => 'website.integracoes', 'label' => 'Integrações do website', 'node_type' => 'submodule', 'module_key' => 'website', 'children' => []],
                ],
            ],
            [
                'key' => 'configuracoes',
                'label' => 'Configurações',
                'node_type' => 'module',
                'module_key' => 'configuracoes',
                'children' => [
                    ['key' => 'configuracoes.tipos_utilizador', 'label' => 'Tipos de Utilizador', 'node_type' => 'submodule', 'module_key' => 'configuracoes', 'children' => []],
                    ['key' => 'configuracoes.permissoes', 'label' => 'Permissões', 'node_type' => 'submodule', 'module_key' => 'configuracoes', 'children' => []],
                    ['key' => 'configuracoes.estados', 'label' => 'Estados', 'node_type' => 'submodule', 'module_key' => 'configuracoes', 'children' => []],
                ],
            ],
        ];
    }

    public static function allMenuModuleKeys(): array
    {
        return array_values(array_map(static fn (array $module) => $module['key'], self::menuModules()));
    }

    public static function allBasePageKeys(): array
    {
        $keys = [];

        foreach (self::landingPages() as $module) {
            foreach ($module['base_pages'] as $page) {
                $keys[] = $page['key'];
            }
        }

        return $keys;
    }

    public static function findLandingModule(string $moduleKey): ?array
    {
        foreach (self::landingPages() as $module) {
            if ($module['module_key'] === $moduleKey) {
                return $module;
            }
        }

        return null;
    }

    public static function findBasePage(string $moduleKey, string $basePageKey): ?array
    {
        $module = self::findLandingModule($moduleKey);

        if ($module === null) {
            return null;
        }

        foreach ($module['base_pages'] as $page) {
            if ($page['key'] === $basePageKey) {
                return $page;
            }
        }

        return null;
    }

    public static function defaultLandingPage(): array
    {
        $module = self::landingPages()[0];
        $page = $module['base_pages'][0];

        return [
            'landing_module_key' => $module['module_key'],
            'landing_module_label' => $module['module_label'],
            'base_page_key' => $page['key'],
            'base_page_label' => $page['label'],
            'route' => $page['route'],
        ];
    }

    public static function flattenPermissionTree(): array
    {
        $flat = [];
        $sortOrder = 1;

        $walk = function (array $nodes, ?string $parentKey = null) use (&$walk, &$flat, &$sortOrder): void {
            foreach ($nodes as $node) {
                $flat[] = [
                    'key' => $node['key'],
                    'label' => $node['label'],
                    'parent_key' => $parentKey,
                    'module_key' => $node['module_key'],
                    'node_type' => $node['node_type'],
                    'sort_order' => $sortOrder++,
                    'active' => true,
                ];

                $walk($node['children'] ?? [], $node['key']);
            }
        };

        $walk(self::permissionTree());

        return $flat;
    }
}
