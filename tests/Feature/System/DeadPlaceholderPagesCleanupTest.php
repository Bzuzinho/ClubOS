<?php

namespace Tests\Feature\System;

use Tests\TestCase;

class DeadPlaceholderPagesCleanupTest extends TestCase
{
    public function test_retired_placeholder_pages_are_physically_absent(): void
    {
        $retiredPages = [
            'Loja/Create.tsx',
            'Loja/Edit.tsx',
            'Loja/Show.tsx',
            'Comunicacao/Create.tsx',
            'Comunicacao/Edit.tsx',
            'Comunicacao/Show.tsx',
            'Eventos/Edit.tsx',
            'Eventos/Show.tsx',
            'CampanhasMarketing/Create.tsx',
            'CampanhasMarketing/Edit.tsx',
            'CampanhasMarketing/Show.tsx',
        ];

        foreach ($retiredPages as $page) {
            $this->assertFileDoesNotExist(
                resource_path('js/Pages/'.$page),
                "Retired placeholder page [{$page}] must not return to runtime source.",
            );
        }
    }

    public function test_canonical_module_workspaces_remain_present(): void
    {
        $canonicalPages = [
            'Store/StoreHomePage.tsx',
            'Comunicacao/Index.tsx',
            'Eventos/Index.tsx',
            'CampanhasMarketing/Index.tsx',
        ];

        foreach ($canonicalPages as $page) {
            $this->assertFileExists(
                resource_path('js/Pages/'.$page),
                "Canonical workspace [{$page}] is required after placeholder cleanup.",
            );
        }
    }
}
