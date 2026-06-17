<?php

namespace App\Http\Controllers\Financeiro;

use App\Http\Controllers\Controller;
use App\Services\Financeiro\BankReconciliationAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BankReconciliationAuditController extends Controller
{
    public function __construct(
        private readonly BankReconciliationAuditService $auditService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $this->validateAuditFilters($request, true);

        $result = $this->auditService->paginate($data);

        return response()->json([
            'rows' => $result['rows'],
            'data' => $result['rows'],
            'meta' => $result['meta'],
            'summary' => $result['summary'],
            'filters' => [
                'estado' => $data['estado'] ?? BankReconciliationAuditService::STATE_ALL,
                'date_from' => $data['date_from'] ?? null,
                'date_to' => $data['date_to'] ?? null,
                'search' => $data['search'] ?? '',
                'user_id' => $data['user_id'] ?? null,
                'family_id' => $data['family_id'] ?? null,
                'metodo' => $data['metodo'] ?? null,
                'has_credit' => $data['has_credit'] ?? null,
                'sort_by' => $data['sort_by'] ?? 'data_movimento',
                'sort_direction' => $data['sort_direction'] ?? 'desc',
            ],
            'export' => [
                'max_rows' => BankReconciliationAuditService::EXPORT_LIMIT,
                'supports' => [
                    'csv' => true,
                    'xlsx' => $this->auditService->supportsXlsxExport(),
                ],
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $filters = $this->validateAuditFilters($request, false);
        $format = strtolower((string) ($request->query('format', 'csv')));

        if (!in_array($format, ['csv', 'xlsx'], true)) {
            return response()->json([
                'message' => 'Formato de exportacao invalido. Use csv ou xlsx.',
            ], 422);
        }

        if ($format === 'xlsx') {
            if (!$this->auditService->supportsXlsxExport()) {
                return response()->json([
                    'message' => 'Exportacao Excel/XLSX nao esta disponivel neste ambiente.',
                ], 422);
            }

            return response()->json([
                'message' => 'Exportacao Excel/XLSX ainda nao foi ativada para esta auditoria.',
            ], 422);
        }

        $result = $this->auditService->exportRows($filters);
        $rows = $result['rows'];
        $meta = $result['meta'] ?? [];

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Export-Total-Filtered' => (string) ($meta['total_filtered'] ?? 0),
            'X-Export-Row-Count' => (string) ($meta['exported_rows'] ?? 0),
            'X-Export-Limit' => (string) ($meta['limit'] ?? BankReconciliationAuditService::EXPORT_LIMIT),
            'X-Export-Truncated' => !empty($meta['truncated']) ? '1' : '0',
        ];

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Data do movimento',
                'Descricao',
                'Referencia',
                'Valor',
                'Estado de conciliacao',
                'Valor alocado',
                'Valor por alocar',
                'Metodo de conciliacao',
                'Alvo / utilizador / familia',
                'Conciliado por',
                'Conciliado em',
                'Mensalidades/Faturas liquidadas',
                'Movimentos liquidados',
                'Credito criado',
                'Documento fiscal emitido',
                'Bloqueado para desconciliar',
                'Historico de desconciliacao / observacao',
            ], ';');

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['data_movimento'] ?? '',
                    (string) ($row['descricao'] ?? ''),
                    (string) ($row['referencia'] ?? ''),
                    $this->formatNumber($row['valor'] ?? null),
                    (string) ($row['estado_conciliacao'] ?? ''),
                    $this->formatNumber($row['valor_alocado'] ?? null),
                    $this->formatNumber($row['valor_por_alocar'] ?? null),
                    (string) ($row['metodo_conciliacao'] ?? ''),
                    $this->buildTargetLabel($row),
                    (string) ($row['reconciled_by_name'] ?? ''),
                    $this->formatDateTime($row['reconciled_at'] ?? null),
                    (string) (($row['target_summary']['faturas_afetadas'] ?? 0)),
                    (string) (($row['target_summary']['movimentos_afetados'] ?? 0)),
                    $this->formatNumber($row['target_summary']['valor_credito_criado'] ?? 0),
                    !empty($row['flags']['tem_documento_fiscal_emitido']) ? 'Sim' : 'Nao',
                    !empty($row['flags']['bloqueado_para_desconciliar']) ? 'Sim' : 'Nao',
                    $this->buildUnreconciliationHistoryLabel($row),
                ], ';');
            }

            fclose($handle);
        }, $this->buildFilename('csv', $filters), $headers);
    }

    public function exportSummary(Request $request): StreamedResponse
    {
        $filters = $this->validateAuditFilters($request, false);
        $result = $this->auditService->exportRows($filters);
        $summary = $result['summary'] ?? [];
        $meta = $result['meta'] ?? [];

        $exportedBy = $request->user()?->nome_completo
            ?? $request->user()?->name
            ?? $request->user()?->email
            ?? 'Sistema';

        $period = '-';
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $period = trim(sprintf(
                '%s ate %s',
                (string) ($filters['date_from'] ?? 'inicio'),
                (string) ($filters['date_to'] ?? 'fim')
            ));
        }

        return response()->streamDownload(function () use ($summary, $meta, $exportedBy, $period): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['Metrica', 'Valor'], ';');
            fputcsv($handle, ['Total de linhas no filtro', (string) ($summary['total_linhas'] ?? 0)], ';');
            fputcsv($handle, ['Total conciliado', (string) ($summary['total_conciliado'] ?? 0)], ';');
            fputcsv($handle, ['Total parcial', (string) ($summary['total_parcial'] ?? 0)], ';');
            fputcsv($handle, ['Total por conciliar', (string) ($summary['total_por_conciliar'] ?? 0)], ';');
            fputcsv($handle, ['Total alocado', $this->formatNumber($summary['total_alocado'] ?? 0)], ';');
            fputcsv($handle, ['Total por alocar', $this->formatNumber($summary['total_por_alocar'] ?? 0)], ';');
            fputcsv($handle, ['Total credito criado', $this->formatNumber($summary['total_credito_criado'] ?? 0)], ';');
            fputcsv($handle, ['Data/hora da exportacao', CarbonImmutable::now()->format('Y-m-d H:i:s')], ';');
            fputcsv($handle, ['Utilizador que exportou', $exportedBy], ';');
            fputcsv($handle, ['Periodo filtrado', $period], ';');
            fputcsv($handle, ['Linhas exportadas (detalhado)', (string) ($meta['exported_rows'] ?? 0)], ';');
            fputcsv($handle, ['Exportacao truncada por limite', !empty($meta['truncated']) ? 'Sim' : 'Nao'], ';');
            fputcsv($handle, ['Limite maximo de linhas por exportacao', (string) ($meta['limit'] ?? BankReconciliationAuditService::EXPORT_LIMIT)], ';');

            fclose($handle);
        }, $this->buildFilename('resumo.csv', $filters), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function validateAuditFilters(Request $request, bool $withPagination): array
    {
        $rules = [
            'estado' => ['nullable', Rule::in([
                BankReconciliationAuditService::STATE_ALL,
                BankReconciliationAuditService::STATE_UNRECONCILED,
                BankReconciliationAuditService::STATE_PARTIAL,
                BankReconciliationAuditService::STATE_RECONCILED,
            ])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'exists:users,id'],
            'family_id' => ['nullable', 'exists:familias,id'],
            'metodo' => ['nullable', Rule::in([
                BankReconciliationAuditService::METHOD_AUTOMATIC_SUGGESTION,
                BankReconciliationAuditService::METHOD_ASSISTED_ALLOCATION,
                BankReconciliationAuditService::METHOD_EXPENSE_FROM_STATEMENT,
                BankReconciliationAuditService::METHOD_MANUAL_PAYMENT,
                BankReconciliationAuditService::METHOD_OTHER,
            ])],
            'has_credit' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', Rule::in(['data_movimento', 'valor'])],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];

        if ($withPagination) {
            $rules['per_page'] = ['nullable', 'integer', 'min:5', 'max:200'];
        }

        return $request->validate($rules);
    }

    private function buildFilename(string $suffix, array $filters): string
    {
        $base = 'conciliacao-bancaria-auditoria';
        $today = CarbonImmutable::now()->format('Y-m-d');

        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $from = (string) ($filters['date_from'] ?? 'inicio');
            $to = (string) ($filters['date_to'] ?? 'fim');

            return sprintf('%s-%s_a_%s.%s', $base, $from, $to, ltrim($suffix, '.'));
        }

        return sprintf('%s-%s.%s', $base, $today, ltrim($suffix, '.'));
    }

    private function formatNumber(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function formatDateTime(?string $value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            return CarbonImmutable::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function buildTargetLabel(array $row): string
    {
        $targetSummary = $row['target_summary'] ?? [];
        $names = $targetSummary['nomes'] ?? [];

        if (is_array($names) && $names !== []) {
            return implode(' | ', array_map(static fn ($name) => (string) $name, $names));
        }

        return (string) ($targetSummary['nome_principal'] ?? '');
    }

    private function buildUnreconciliationHistoryLabel(array $row): string
    {
        $history = $row['historico_desconciliacoes'] ?? [];

        if (!is_array($history) || $history === []) {
            return '';
        }

        $chunks = [];
        foreach ($history as $event) {
            if (!is_array($event)) {
                continue;
            }

            $chunks[] = trim(sprintf(
                '%s | %s | %s | %s',
                (string) ($event['cancelled_at'] ?? '-'),
                (string) ($event['cancelled_by_name'] ?? '-'),
                (string) ($event['tipo'] ?? '-'),
                (string) ($event['motivo'] ?? '-')
            ));
        }

        return implode(' || ', array_filter($chunks));
    }
}
