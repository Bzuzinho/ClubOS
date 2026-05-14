<?php

namespace App\Services\Financeiro;

use App\Models\Invoice;
use App\Models\ReceiptImportBatch;
use App\Models\ReceiptImportItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class ReceiptImportService
{
    public const PENDING_DIRECTORY = 'private/imports/receipts/pending';
    private const BATCH_DIRECTORY = 'private/imports/receipts/batches';

    public function __construct(
        private readonly ReceiptPdfTextExtractor $pdfTextExtractor,
        private readonly ReceiptMatchingService $matchingService,
    ) {
    }

    public function createBatchFromZip(UploadedFile $zipFile, User $actor, array $options = []): ReceiptImportBatch
    {
        $zip = new ZipArchive();
        $status = $zip->open($zipFile->getRealPath());

        if ($status !== true) {
            throw new RuntimeException('Nao foi possivel abrir o ZIP de recibos.');
        }

        $files = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (!str_ends_with(Str::lower($name), '.pdf')) {
                continue;
            }

            $contents = $zip->getFromIndex($index);
            if ($contents === false) {
                continue;
            }

            $files[] = [
                'name' => basename($name),
                'contents' => $contents,
            ];
        }

        $zip->close();

        return $this->createBatchFromPayloads($files, $actor, array_merge($options, [
            'source_type' => 'zip_upload',
            'source_name' => $zipFile->getClientOriginalName(),
        ]));
    }

    public function createBatchFromPendingDirectory(?string $relativeDirectory, User $actor, array $options = []): ReceiptImportBatch
    {
        $directory = trim((string) ($relativeDirectory ?: self::PENDING_DIRECTORY), '/');
        $disk = Storage::disk('local');

        $files = collect($disk->files($directory))
            ->filter(fn (string $path) => str_ends_with(Str::lower($path), '.pdf'))
            ->map(fn (string $path) => [
                'name' => basename($path),
                'contents' => $disk->get($path),
                'original_path' => $path,
            ])
            ->values()
            ->all();

        return $this->createBatchFromPayloads($files, $actor, array_merge($options, [
            'source_type' => 'pending_directory',
            'source_name' => basename($directory),
            'source_path' => $directory,
        ]));
    }

    public function createBatchFromPayloads(array $files, User $actor, array $options = []): ReceiptImportBatch
    {
        return DB::transaction(function () use ($files, $actor, $options) {
            $batch = ReceiptImportBatch::query()->create([
                'source_type' => $options['source_type'] ?? 'manual_upload',
                'source_name' => $options['source_name'] ?? null,
                'source_path' => $options['source_path'] ?? null,
                'status' => ReceiptImportBatch::STATUS_PENDING_REVIEW,
                'created_by' => $actor->id,
                'metadata' => Arr::except($options, ['source_type', 'source_name', 'source_path']),
            ]);

            $processedCount = 0;
            foreach ($files as $file) {
                $item = $this->createItemForPayload($batch, $file);
                if ($item->status !== ReceiptImportItem::STATUS_FAILED) {
                    $processedCount++;
                }
            }

            $batch->forceFill([
                'items_count' => $batch->items()->count(),
                'processed_count' => $processedCount,
                'status' => $processedCount > 0 ? ReceiptImportBatch::STATUS_PROCESSED : ReceiptImportBatch::STATUS_FAILED,
            ])->save();

            return $batch->fresh(['items.user', 'items.invoice']);
        });
    }

    private function createItemForPayload(ReceiptImportBatch $batch, array $file): ReceiptImportItem
    {
        $disk = Storage::disk('local');
        $fileName = (string) ($file['name'] ?? 'recibo.pdf');
        $contents = $file['contents'] ?? null;

        if (!is_string($contents) || $contents === '') {
            return $batch->items()->create([
                'file_name' => $fileName,
                'storage_path' => '',
                'file_hash' => hash('sha256', $fileName.'-missing'),
                'status' => ReceiptImportItem::STATUS_FAILED,
                'failure_reason' => 'Conteudo do PDF vazio ou invalido.',
            ]);
        }

        $hash = hash('sha256', $contents);
        $storedPath = self::BATCH_DIRECTORY.'/'.$batch->id.'/'.$this->sanitizeFileName($fileName);
        $disk->put($storedPath, $contents);
        $absolutePath = storage_path('app/'.$storedPath);

        $item = $batch->items()->create([
            'file_name' => $fileName,
            'storage_path' => $storedPath,
            'file_hash' => $hash,
            'status' => ReceiptImportItem::STATUS_PENDING_REVIEW,
            'metadata' => [
                'original_path' => $file['original_path'] ?? null,
            ],
        ]);

        try {
            $text = $this->pdfTextExtractor->extract($absolutePath);
            $extracted = $this->extractReceiptData($text, $fileName);
            $duplicate = $this->resolveDuplicate($item, $hash, $extracted['numero_recibo'] ?? null);

            $item->fill([
                'numero_recibo' => $extracted['numero_recibo'],
                'recibo_emitido_em' => $extracted['recibo_emitido_em'],
                'valor' => $extracted['valor'],
                'extracted_name' => $extracted['extracted_name'],
                'extracted_nif' => $extracted['extracted_nif'],
                'extracted_member_number' => $extracted['extracted_member_number'],
                'extracted_email' => $extracted['extracted_email'],
                'extracted_period_label' => $extracted['extracted_period_label'],
                'extracted_period_start' => $extracted['extracted_period_start'],
                'extracted_period_end' => $extracted['extracted_period_end'],
                'extracted_text' => $text,
                'extraction_payload' => $extracted,
                'duplicate_of_item_id' => $duplicate?->id,
                'status' => $duplicate ? ReceiptImportItem::STATUS_DUPLICATE : ReceiptImportItem::STATUS_PENDING_REVIEW,
                'failure_reason' => $duplicate ? 'Recibo duplicado por hash ou numero de recibo.' : null,
            ]);
            $item->save();

            if (!$duplicate) {
                $item = $this->matchingService->matchItem($item->fresh());
            }
        } catch (Throwable $exception) {
            Log::warning('Receipt import failed during extraction.', [
                'batch_id' => $batch->id,
                'item_id' => $item->id,
                'file_name' => $fileName,
                'error' => $exception->getMessage(),
            ]);

            $item->update([
                'status' => ReceiptImportItem::STATUS_FAILED,
                'failure_reason' => $exception->getMessage(),
            ]);
        }

        return $item->fresh();
    }

    private function resolveDuplicate(ReceiptImportItem $item, string $hash, ?string $receiptNumber): ?ReceiptImportItem
    {
        $duplicate = ReceiptImportItem::query()
            ->whereKeyNot($item->id)
            ->where('file_hash', $hash)
            ->first();

        if ($duplicate) {
            return $duplicate;
        }

        if ($receiptNumber) {
            $duplicate = ReceiptImportItem::query()
                ->whereKeyNot($item->id)
                ->where('numero_recibo', $receiptNumber)
                ->first();

            if ($duplicate) {
                return $duplicate;
            }

            $invoiceId = Invoice::query()->where('numero_recibo', $receiptNumber)->value('id');
            if ($invoiceId) {
                return ReceiptImportItem::query()->firstWhere('invoice_id', $invoiceId);
            }
        }

        return null;
    }

    private function extractReceiptData(string $text, string $fileName): array
    {
        $receiptNumber = $this->match('/recibo\s*(?:n\.?|n[oº]?|numero)?\s*[:#-]?\s*([A-Z0-9\/-]+)/iu', $text)
            ?? $this->match('/([A-Z]{1,4}[-\/]\d{3,})/', $fileName);
        $issuedAt = $this->parseDate($this->match('/(\d{2}[\/\.-]\d{2}[\/\.-]\d{4})/', $text));
        $amount = $this->parseAmount($text);
        $periodLabel = $this->extractPeriodLabel($text);
        $periodStart = $this->parsePeriodStart($periodLabel, $issuedAt);

        return [
            'numero_recibo' => $receiptNumber,
            'recibo_emitido_em' => $issuedAt?->toDateString(),
            'valor' => $amount,
            'extracted_name' => $this->match('/(?:cliente|nome|socio|atleta)\s*:?\s*([A-ZÁÀÂÃÉÈÊÍÌÎÓÒÔÕÚÙÛÇ][^\n]+)/iu', $text),
            'extracted_nif' => $this->match('/\b(?:NIF|Contribuinte)\s*:?\s*(\d{9})\b/iu', $text),
            'extracted_member_number' => $this->match('/(?:socio|s[oó]cio|membro)\s*(?:n\.?|numero)?\s*:?\s*([A-Z0-9-]+)/iu', $text),
            'extracted_email' => $this->match('/([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})/iu', $text),
            'extracted_period_label' => $periodLabel,
            'extracted_period_start' => $periodStart?->startOfMonth()->toDateString(),
            'extracted_period_end' => $periodStart?->endOfMonth()->toDateString(),
        ];
    }

    private function extractPeriodLabel(string $text): ?string
    {
        if (preg_match('/\b(20\d{2}[\/-](0[1-9]|1[0-2]))\b/', $text, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\b(janeiro|fevereiro|marco|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\s+de\s+(20\d{2})\b/iu', Str::lower(Str::ascii($text)), $matches)) {
            return $matches[1].' '.$matches[2];
        }

        return null;
    }

    private function parsePeriodStart(?string $label, ?Carbon $fallback): ?Carbon
    {
        if ($label && preg_match('/(20\d{2})[\/-](0[1-9]|1[0-2])/', $label, $matches)) {
            return Carbon::createFromDate((int) $matches[1], (int) $matches[2], 1);
        }

        return $fallback?->copy()->startOfMonth();
    }

    private function parseAmount(string $text): ?float
    {
        preg_match_all('/(\d{1,3}(?:[\.\s]\d{3})*,\d{2}|\d+\.\d{2})\s*(?:EUR|€)?/iu', $text, $matches);
        $values = collect($matches[1] ?? [])
            ->map(function (string $value): float {
                $normalized = str_replace(['.', ' '], '', $value);
                $normalized = str_replace(',', '.', $normalized);

                return round((float) $normalized, 2);
            })
            ->filter(fn (float $value) => $value > 0)
            ->values();

        return $values->isNotEmpty() ? $values->max() : null;
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value);
            } catch (Throwable) {
            }
        }

        return null;
    }

    private function match(string $pattern, string $subject): ?string
    {
        if (!preg_match($pattern, $subject, $matches)) {
            return null;
        }

        $value = trim((string) ($matches[1] ?? ''));

        return $value !== '' ? $value : null;
    }

    private function sanitizeFileName(string $fileName): string
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION) ?: 'pdf';
        $name = pathinfo($fileName, PATHINFO_FILENAME);

        return Str::slug($name).'-'.Str::random(8).'.'.Str::lower($extension);
    }
}