<?php

declare(strict_types=1);

namespace App\Console\Commands\Members;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;

final class BackfillMemberContactCommand extends Command
{
    protected $signature = 'members:backfill-contact
        {--dry-run : Forca modo simulacao sem escrita}
        {--commit : Ativa modo de escrita}
        {--confirm= : Token obrigatorio para escrita (BACKFILL_CONTACT)}
        {--user-id= : Processa apenas um utilizador}
        {--limit= : Limita utilizadores analisados}
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar relatorio JSON}';

    protected $description = 'Backfill seguro de dados_pessoais.contacto a partir de users sem sobrescrever valor canonico';

    public function handle(): int
    {
        $commitRequested = (bool) $this->option('commit');
        $forceDryRun = (bool) $this->option('dry-run');
        $confirmToken = is_string($this->option('confirm')) ? trim((string) $this->option('confirm')) : '';

        if ($commitRequested && $confirmToken !== 'BACKFILL_CONTACT') {
            $message = 'Escrita bloqueada: use --commit --confirm=BACKFILL_CONTACT para permitir escrita.';

            if ((bool) $this->option('json')) {
                $this->line($this->toJson([
                    'error' => $message,
                    'summary' => [
                        'dry_run' => true,
                        'committed' => false,
                    ],
                    'items' => [],
                ]));
            } else {
                $this->error($message);
            }

            return 2;
        }

        $isConfirmedCommit = $commitRequested && $confirmToken === 'BACKFILL_CONTACT';
        $dryRun = !$isConfirmedCommit || $forceDryRun;

        $users = $this->buildQuery()
            ->when($this->option('user-id'), fn (Builder $query, mixed $id): Builder => $query->where('id', $id))
            ->when($this->option('limit'), function (Builder $query, mixed $limit): Builder {
                $parsed = (int) $limit;
                if ($parsed > 0) {
                    $query->limit($parsed);
                }

                return $query;
            })
            ->get();

        $summary = [
            'total_users_analyzed' => $users->count(),
            'candidates' => 0,
            'updated' => 0,
            'skipped_existing_canonical' => 0,
            'skipped_empty_fallback' => 0,
            'skipped_missing_dados_pessoais' => 0,
            'dry_run' => $dryRun,
            'committed' => !$dryRun,
        ];

        $items = [];

        foreach ($users as $user) {
            $dadosPessoais = $user->dadosPessoais;

            if ($dadosPessoais === null) {
                $summary['skipped_missing_dados_pessoais']++;
                $this->pushItem($items, [
                    'user_id' => (string) $user->id,
                    'name' => $user->name,
                    'numero_socio' => $user->numero_socio,
                    'source_field' => null,
                    'masked_value' => null,
                    'action' => 'skipped',
                ]);

                continue;
            }

            $canonicalValue = $dadosPessoais->getAttribute('contacto');
            if ($this->hasValue($canonicalValue)) {
                $summary['skipped_existing_canonical']++;
                $this->pushItem($items, [
                    'user_id' => (string) $user->id,
                    'name' => $user->name,
                    'numero_socio' => $user->numero_socio,
                    'source_field' => 'dados_pessoais.contacto',
                    'masked_value' => $this->maskValue((string) $canonicalValue),
                    'action' => 'skipped',
                ]);

                continue;
            }

            [$sourceField, $sourceValue] = $this->firstFallbackValue($user);
            if (!$this->hasValue($sourceValue) || $sourceField === null) {
                $summary['skipped_empty_fallback']++;
                $this->pushItem($items, [
                    'user_id' => (string) $user->id,
                    'name' => $user->name,
                    'numero_socio' => $user->numero_socio,
                    'source_field' => null,
                    'masked_value' => null,
                    'action' => 'skipped',
                ]);

                continue;
            }

            $summary['candidates']++;

            $action = 'would_update';
            if (!$dryRun) {
                $dadosPessoais->forceFill([
                    'contacto' => $sourceValue,
                ])->save();

                $summary['updated']++;
                $action = 'updated';
            }

            $this->pushItem($items, [
                'user_id' => (string) $user->id,
                'name' => $user->name,
                'numero_socio' => $user->numero_socio,
                'source_field' => $sourceField,
                'masked_value' => $this->maskValue((string) $sourceValue),
                'action' => $action,
            ]);
        }

        $payload = [
            'timestamp' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'options' => [
                'dry_run' => $forceDryRun,
                'commit' => $commitRequested,
                'confirm' => $confirmToken !== '' ? '***' : null,
                'user_id' => $this->option('user-id'),
                'limit' => $this->option('limit'),
                'json' => (bool) $this->option('json'),
                'report_path' => $this->option('report-path'),
            ],
            'summary' => $summary,
            'items' => $items,
        ];

        $this->writeReportFileIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson([
                'summary' => $summary,
                'items' => $items,
            ]));

            return self::SUCCESS;
        }

        $this->renderHumanReport($summary, $items);

        return self::SUCCESS;
    }

    private function buildQuery(): Builder
    {
        return User::query()
            ->with('dadosPessoais')
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * @return array{0: string|null, 1: mixed}
     */
    private function firstFallbackValue(User $user): array
    {
        foreach (['contacto', 'telemovel', 'contacto_telefonico'] as $field) {
            $value = $user->getAttribute($field);
            if ($this->hasValue($value)) {
                return [$field, $value];
            }
        }

        return [null, null];
    }

    private function hasValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        return true;
    }

    private function maskValue(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return '';
        }

        $suffix = substr($normalized, -3);

        return '******' . $suffix;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $item
     */
    private function pushItem(array &$items, array $item): void
    {
        if (count($items) >= 20) {
            return;
        }

        $items[] = $item;
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<int, array<string, mixed>> $items
     */
    private function renderHumanReport(array $summary, array $items): void
    {
        $mode = $summary['dry_run'] ? 'dry-run (sem escrita)' : 'commit confirmado (com escrita)';

        $this->info(sprintf('Backfill contacto em dados_pessoais (%s)', $mode));
        $this->newLine();

        $this->table(
            ['Metrica', 'Valor'],
            [
                ['total_users_analyzed', $summary['total_users_analyzed']],
                ['candidates', $summary['candidates']],
                ['updated', $summary['updated']],
                ['skipped_existing_canonical', $summary['skipped_existing_canonical']],
                ['skipped_empty_fallback', $summary['skipped_empty_fallback']],
                ['skipped_missing_dados_pessoais', $summary['skipped_missing_dados_pessoais']],
                ['dry_run', $summary['dry_run'] ? 'true' : 'false'],
                ['committed', $summary['committed'] ? 'true' : 'false'],
            ]
        );

        $this->newLine();

        if ($items === []) {
            $this->line('Sem itens para mostrar.');
        } else {
            $rows = array_map(static fn (array $item): array => [
                $item['user_id'] ?? null,
                $item['name'] ?? null,
                $item['numero_socio'] ?? null,
                $item['source_field'] ?? null,
                $item['masked_value'] ?? null,
                $item['action'] ?? null,
            ], $items);

            $this->table(
                ['user_id', 'name', 'numero_socio', 'source_field', 'source_value_preview', 'action'],
                $rows
            );
        }

        $reportPath = is_string($this->option('report-path')) ? trim((string) $this->option('report-path')) : '';
        if ($reportPath !== '') {
            $this->newLine();
            $this->line('Relatorio JSON gravado em: ' . $this->resolveReportPath($reportPath));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeReportFileIfRequested(array $payload): void
    {
        $reportPath = is_string($this->option('report-path')) ? trim((string) $this->option('report-path')) : '';
        if ($reportPath === '') {
            return;
        }

        $absolutePath = $this->resolveReportPath($reportPath);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, $this->toJson($payload));
    }

    private function resolveReportPath(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
