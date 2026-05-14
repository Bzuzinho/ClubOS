<?php

namespace App\Services\Financeiro;

use RuntimeException;

class ReceiptPdfTextExtractor
{
    public function extract(string $absolutePath): string
    {
        if (class_exists('Smalot\\PdfParser\\Parser')) {
            $parser = new \Smalot\PdfParser\Parser();
            $document = $parser->parseFile($absolutePath);
            $text = trim($document->getText());

            if ($text !== '') {
                return $text;
            }
        }

        $commandText = $this->extractWithPdftotext($absolutePath);
        if ($commandText !== null && trim($commandText) !== '') {
            return trim($commandText);
        }

        $naiveText = $this->extractNaively($absolutePath);
        if ($naiveText !== '') {
            return $naiveText;
        }

        throw new RuntimeException('Nao foi possivel extrair texto do PDF com os backends disponiveis.');
    }

    private function extractWithPdftotext(string $absolutePath): ?string
    {
        if (!function_exists('exec')) {
            return null;
        }

        $output = [];
        $exitCode = 1;
        exec('pdftotext -layout '.escapeshellarg($absolutePath).' - 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0) {
            return null;
        }

        return implode(PHP_EOL, $output);
    }

    private function extractNaively(string $absolutePath): string
    {
        $contents = @file_get_contents($absolutePath);

        if ($contents === false || $contents === '') {
            return '';
        }

        preg_match_all('/\(((?:\\\\|\\\)|[^)])*)\)\s*Tj/s', $contents, $matches);
        $strings = array_map(static function (string $value): string {
            $decoded = str_replace(['\\(', '\\)', '\\n', '\\r', '\\t'], ['(', ')', "\n", "\r", "\t"], $value);

            return trim($decoded);
        }, $matches[1] ?? []);

        return trim(implode("\n", array_filter($strings)));
    }
}