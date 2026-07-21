<?php

namespace Tests\Feature\Loja;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyStoreToolingBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_store_catalog_runtime_no_longer_exists_inside_app_code(): void
    {
        $workspaceRoot = base_path();

        $allowedFiles = [
            realpath(app_path('Support/LegacyStoreCatalogGuard.php')),
        ];

        $hits = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getRealPath();

            if ($path === false || in_array($path, $allowedFiles, true)) {
                continue;
            }

            $source = strtolower((string) file_get_contents($path));

            if (
                str_contains($source, 'use app\\models\\lojaproduto;') ||
                str_contains($source, 'use app\\models\\lojaprodutovariante;') ||
                str_contains($source, 'lojaproduto::') ||
                str_contains($source, 'lojaprodutovariante::') ||
                str_contains($source, 'loja_produtos') ||
                str_contains($source, 'loja_produto_variantes')
            ) {
                $hits[] = str_replace($workspaceRoot . DIRECTORY_SEPARATOR, '', $path);
            }
        }

        $this->assertSame([], $hits, 'Legacy store catalog references still exist in app/ after structural removal.');
    }

    public function test_legacy_store_order_and_cart_runtime_no_longer_exists_inside_app_code(): void
    {
        $workspaceRoot = base_path();

        $allowedFiles = [
            realpath(app_path('Services/Inventario/StoreLogisticsStockAuditService.php')),
        ];

        $hits = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getRealPath();

            if ($path === false || in_array($path, $allowedFiles, true)) {
                continue;
            }

            $source = strtolower((string) file_get_contents($path));

            if (
                str_contains($source, 'use app\\models\\storecartitem;') ||
                str_contains($source, 'use app\\models\\storeorder;') ||
                str_contains($source, 'use app\\models\\storeorderitem;') ||
                str_contains($source, 'storecartitem::') ||
                str_contains($source, 'storeorder::') ||
                str_contains($source, 'storeorderitem::') ||
                str_contains($source, 'store_cart_items') ||
                str_contains($source, 'store_orders') ||
                str_contains($source, 'store_order_items')
            ) {
                $hits[] = str_replace($workspaceRoot . DIRECTORY_SEPARATOR, '', $path);
            }
        }

        $this->assertSame([], $hits, 'Legacy store order/cart references still exist in app/ after structural removal.');
    }
}
