<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use ReflectionClass;
use RuntimeException;

class LegacyStoreCatalogGuard
{
    public const FORBIDDEN_SOURCE_TOKENS = [
        'loja_produtos',
        'loja_produto_variantes',
        'loja_produto_id',
        'loja_produto_variante_id',
        'use app\\models\\lojaproduto;',
        'use app\\models\\lojaprodutovariante;',
        'lojaproduto::',
        'lojaprodutovariante::',
    ];

    public function forbiddenSourceTokens(): array
    {
        return self::FORBIDDEN_SOURCE_TOKENS;
    }

    public function assertSourceIsLegacyFree(string $className): void
    {
        if (! class_exists($className)) {
            return;
        }

        $reflection = new ReflectionClass($className);
        $path = $reflection->getFileName();

        if (! $path || ! is_file($path)) {
            return;
        }

        $source = strtolower((string) file_get_contents($path));
        $hits = [];

        foreach (self::FORBIDDEN_SOURCE_TOKENS as $token) {
            if (str_contains($source, $token)) {
                $hits[] = $token;
            }
        }

        if ($hits !== []) {
            $message = sprintf(
                'LegacyStoreCatalogGuard blocked forbidden legacy store tokens [%s] in %s',
                implode(', ', array_unique($hits)),
                $className,
            );

            $this->failOrWarn($message);
        }
    }

    private function failOrWarn(string $message): void
    {
        if (app()->environment(['local', 'testing']) || config('app.debug')) {
            throw new RuntimeException($message);
        }

        Log::warning($message);
    }
}