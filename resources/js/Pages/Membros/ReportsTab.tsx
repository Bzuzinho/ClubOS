import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import {
    CaretDown,
    DownloadSimple,
    FilePdf,
    Funnel,
    ListBullets,
    SpinnerGap,
    UsersThree,
    X,
} from '@phosphor-icons/react';

import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';

interface ReportOption {
    value: string;
    label: string;
}

interface BreakdownRow extends ReportOption {
    count: number;
}

interface ReportRow {
    id: string;
    numero_socio: string;
    nome_completo: string;
    email_utilizador: string;
    estado: string;
    estado_label: string;
    user_type_values: string[];
    user_type_labels: string[];
    age_group_ids: string[];
    age_group_labels: string[];
}

interface MemberReportPayload {
    mode: 'normal' | 'detailed';
    filters: {
        user_types: string[];
        age_groups: string[];
        statuses: string[];
    };
    options: {
        user_types: ReportOption[];
        age_groups: ReportOption[];
        statuses: ReportOption[];
    };
    summary: {
        total: number;
        ativos: number;
        inativos: number;
        suspensos: number;
    };
    breakdowns: {
        statuses: BreakdownRow[];
        user_types: BreakdownRow[];
        age_groups: BreakdownRow[];
    };
    rows: ReportRow[];
    generated_at: string;
}

interface DraftFilters {
    mode: 'normal' | 'detailed';
    user_types: string[];
    age_groups: string[];
    statuses: string[];
}

interface MultiFilterProps {
    label: string;
    allLabel: string;
    options: ReportOption[];
    values: string[];
    onChange: (values: string[]) => void;
}

const emptyFilters: DraftFilters = {
    mode: 'normal',
    user_types: [],
    age_groups: [],
    statuses: [],
};

function MultiFilter({ label, allLabel, options, values, onChange }: MultiFilterProps) {
    const selectedLabels = options
        .filter((option) => values.includes(option.value))
        .map((option) => option.label);

    const buttonLabel = selectedLabels.length === 0
        ? allLabel
        : selectedLabels.length === 1
            ? selectedLabels[0]
            : `${selectedLabels.length} selecionados`;

    const toggle = (value: string, checked: boolean) => {
        if (checked) {
            onChange(Array.from(new Set([...values, value])));
            return;
        }

        onChange(values.filter((item) => item !== value));
    };

    return (
        <div className="min-w-0 space-y-1">
            <span className="text-[11px] font-medium text-muted-foreground">{label}</span>
            <Popover>
                <PopoverTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        className="h-9 w-full justify-between px-3 text-xs font-normal sm:min-w-[180px]"
                    >
                        <span className="truncate">{buttonLabel}</span>
                        <CaretDown size={13} className="ml-2 shrink-0" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="start" className="w-[280px] p-2">
                    <div className="mb-1 flex items-center justify-between border-b pb-2">
                        <span className="text-xs font-medium">{label}</span>
                        {values.length > 0 ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-7 px-2 text-[11px]"
                                onClick={() => onChange([])}
                            >
                                Limpar
                            </Button>
                        ) : null}
                    </div>
                    <div className="max-h-64 space-y-1 overflow-y-auto py-1">
                        {options.length === 0 ? (
                            <p className="px-2 py-3 text-xs text-muted-foreground">Sem opções disponíveis.</p>
                        ) : (
                            options.map((option) => {
                                const checked = values.includes(option.value);

                                return (
                                    <label
                                        key={option.value}
                                        className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-xs hover:bg-muted"
                                    >
                                        <Checkbox
                                            checked={checked}
                                            onCheckedChange={(value) => toggle(option.value, value === true)}
                                        />
                                        <span className="min-w-0 flex-1 truncate">{option.label}</span>
                                    </label>
                                );
                            })
                        )}
                    </div>
                </PopoverContent>
            </Popover>
        </div>
    );
}

function escapeHtml(value: unknown): string {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

export default function MembrosReportsTab() {
    const [report, setReport] = useState<MemberReportPayload | null>(null);
    const [draftFilters, setDraftFilters] = useState<DraftFilters>(emptyFilters);
    const [loading, setLoading] = useState(true);
    const [exportingExcel, setExportingExcel] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const requestId = useRef(0);

    const loadReport = useCallback(async (filters: DraftFilters) => {
        const currentRequest = ++requestId.current;
        setLoading(true);
        setError(null);

        try {
            const response = await axios.get<MemberReportPayload>(route('api.membros.reports'), {
                params: filters,
            });

            if (currentRequest !== requestId.current) {
                return;
            }

            setReport(response.data);
            setDraftFilters({
                mode: response.data.mode,
                user_types: response.data.filters.user_types,
                age_groups: response.data.filters.age_groups,
                statuses: response.data.filters.statuses,
            });
        } catch {
            if (currentRequest === requestId.current) {
                setError('Não foi possível gerar o relatório. Volta a tentar.');
            }
        } finally {
            if (currentRequest === requestId.current) {
                setLoading(false);
            }
        }
    }, []);

    useEffect(() => {
        void loadReport(emptyFilters);
    }, [loadReport]);

    const appliedFilterLabels = useMemo(() => {
        if (!report) {
            return [];
        }

        const labels: string[] = [];
        const optionLabel = (options: ReportOption[], value: string) =>
            options.find((option) => option.value === value)?.label || value;

        report.filters.user_types.forEach((value) => labels.push(`Tipo: ${optionLabel(report.options.user_types, value)}`));
        report.filters.age_groups.forEach((value) => labels.push(`Escalão: ${optionLabel(report.options.age_groups, value)}`));
        report.filters.statuses.forEach((value) => labels.push(`Estado: ${optionLabel(report.options.statuses, value)}`));

        return labels;
    }, [report]);

    const hasDraftFilters =
        draftFilters.user_types.length > 0 ||
        draftFilters.age_groups.length > 0 ||
        draftFilters.statuses.length > 0 ||
        draftFilters.mode !== 'normal';

    const exportExcel = async () => {
        if (!report || report.summary.total === 0) {
            return;
        }

        setExportingExcel(true);

        try {
            const XLSX = await import('xlsx');
            const workbook = XLSX.utils.book_new();
            const summaryRows: Array<Array<string | number>> = [
                ['Relatório de Membros'],
                ['Gerado em', new Date(report.generated_at).toLocaleString('pt-PT')],
                ['Formato', report.mode === 'detailed' ? 'Detalhado' : 'Normal'],
                ['Filtros', appliedFilterLabels.length > 0 ? appliedFilterLabels.join(' | ') : 'Sem filtros'],
                [],
                ['Resumo', 'Total'],
                ['Membros filtrados', report.summary.total],
                ['Ativos', report.summary.ativos],
                ['Inativos', report.summary.inativos],
                ['Suspensos', report.summary.suspensos],
                [],
                ['Distribuição por estado', 'Total'],
                ...report.breakdowns.statuses.map((item) => [item.label, item.count]),
                [],
                ['Distribuição por tipo de utilizador', 'Total'],
                ...report.breakdowns.user_types.map((item) => [item.label, item.count]),
                [],
                ['Distribuição por escalão', 'Total'],
                ...report.breakdowns.age_groups.map((item) => [item.label, item.count]),
            ];

            const summarySheet = XLSX.utils.aoa_to_sheet(summaryRows);
            summarySheet['!cols'] = [{ wch: 34 }, { wch: 70 }];
            XLSX.utils.book_append_sheet(workbook, summarySheet, 'Resumo');

            if (report.mode === 'detailed') {
                const detailRows = report.rows.map((member) => ({
                    'Nº Sócio': member.numero_socio || '-',
                    Nome: member.nome_completo,
                    Email: member.email_utilizador || '-',
                    Estado: member.estado_label,
                    'Tipo de utilizador': member.user_type_labels.join(', ') || '-',
                    Escalão: member.age_group_labels.join(', ') || '-',
                }));
                const detailSheet = XLSX.utils.json_to_sheet(detailRows);
                detailSheet['!cols'] = [
                    { wch: 14 },
                    { wch: 34 },
                    { wch: 34 },
                    { wch: 14 },
                    { wch: 30 },
                    { wch: 24 },
                ];
                XLSX.utils.book_append_sheet(workbook, detailSheet, 'Membros');
            }

            const date = new Date().toISOString().slice(0, 10);
            XLSX.writeFile(workbook, `relatorio-membros-${date}.xlsx`);
        } finally {
            setExportingExcel(false);
        }
    };

    const exportPdf = () => {
        if (!report || report.summary.total === 0) {
            return;
        }

        const popup = window.open('', '_blank', 'width=1100,height=800');
        if (!popup) {
            setError('O navegador bloqueou a janela do PDF. Autoriza pop-ups para o ClubOS e tenta novamente.');
            return;
        }

        popup.opener = null;

        const breakdownTable = (title: string, rows: BreakdownRow[]) => `
            <section>
                <h2>${escapeHtml(title)}</h2>
                <table>
                    <thead><tr><th>Categoria</th><th class="number">Total</th></tr></thead>
                    <tbody>
                        ${rows.length > 0
                            ? rows.map((row) => `<tr><td>${escapeHtml(row.label)}</td><td class="number">${row.count}</td></tr>`).join('')
                            : '<tr><td colspan="2">Sem dados</td></tr>'}
                    </tbody>
                </table>
            </section>
        `;

        const detail = report.mode === 'detailed'
            ? `
                <section class="detail">
                    <h2>Detalhe por membro</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Nº Sócio</th>
                                <th>Nome</th>
                                <th>Email</th>
                                <th>Estado</th>
                                <th>Tipo</th>
                                <th>Escalão</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${report.rows.map((member) => `
                                <tr>
                                    <td>${escapeHtml(member.numero_socio || '-')}</td>
                                    <td>${escapeHtml(member.nome_completo)}</td>
                                    <td>${escapeHtml(member.email_utilizador || '-')}</td>
                                    <td>${escapeHtml(member.estado_label)}</td>
                                    <td>${escapeHtml(member.user_type_labels.join(', ') || '-')}</td>
                                    <td>${escapeHtml(member.age_group_labels.join(', ') || '-')}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </section>
            `
            : '';

        popup.document.write(`
            <!doctype html>
            <html lang="pt">
                <head>
                    <meta charset="utf-8" />
                    <title>Relatório de Membros</title>
                    <style>
                        @page { size: A4; margin: 12mm; }
                        * { box-sizing: border-box; }
                        body { font-family: Arial, sans-serif; color: #111827; font-size: 10px; margin: 0; }
                        h1 { font-size: 20px; margin: 0 0 4px; }
                        h2 { font-size: 12px; margin: 16px 0 6px; }
                        .meta { color: #4b5563; margin-bottom: 12px; line-height: 1.5; }
                        .summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 12px 0; }
                        .summary div { border: 1px solid #d1d5db; border-radius: 6px; padding: 8px; }
                        .summary strong { display: block; font-size: 18px; margin-top: 3px; }
                        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
                        th, td { border: 1px solid #d1d5db; padding: 5px 6px; text-align: left; vertical-align: top; }
                        th { background: #f3f4f6; }
                        .number { text-align: right; width: 80px; }
                        .detail { break-before: auto; }
                        .detail tr { break-inside: avoid; }
                        .note { margin-top: 10px; color: #6b7280; font-size: 9px; }
                    </style>
                </head>
                <body>
                    <h1>Relatório de Membros</h1>
                    <div class="meta">
                        Gerado em ${escapeHtml(new Date(report.generated_at).toLocaleString('pt-PT'))}<br />
                        Formato: ${report.mode === 'detailed' ? 'Detalhado' : 'Normal'}<br />
                        Filtros: ${escapeHtml(appliedFilterLabels.length > 0 ? appliedFilterLabels.join(' · ') : 'Sem filtros')}
                    </div>
                    <div class="summary">
                        <div>Total<strong>${report.summary.total}</strong></div>
                        <div>Ativos<strong>${report.summary.ativos}</strong></div>
                        <div>Inativos<strong>${report.summary.inativos}</strong></div>
                        <div>Suspensos<strong>${report.summary.suspensos}</strong></div>
                    </div>
                    ${breakdownTable('Distribuição por estado', report.breakdowns.statuses)}
                    ${breakdownTable('Distribuição por tipo de utilizador', report.breakdowns.user_types)}
                    ${breakdownTable('Distribuição por escalão', report.breakdowns.age_groups)}
                    ${detail}
                    <p class="note">Quando um membro tem vários tipos de utilizador ou vários escalões aplicáveis, conta em cada categoria correspondente.</p>
                </body>
            </html>
        `);
        popup.document.close();

        window.setTimeout(() => {
            popup.focus();
            popup.print();
        }, 250);
    };

    return (
        <div className="space-y-3">
            <Card className="p-3">
                <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 className="text-sm font-semibold">Relatórios de Membros</h2>
                        <p className="mt-0.5 text-[11px] text-muted-foreground">
                            Filtra a população do clube, consulta os totais e exporta o resultado em PDF ou Excel.
                        </p>
                    </div>
                    <Badge variant="outline" className="w-fit text-[10px]">
                        Escalão canónico da época atual
                    </Badge>
                </div>

                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="space-y-1">
                        <span className="text-[11px] font-medium text-muted-foreground">Formato</span>
                        <Select
                            value={draftFilters.mode}
                            onValueChange={(value) => setDraftFilters((current) => ({
                                ...current,
                                mode: value as DraftFilters['mode'],
                            }))}
                        >
                            <SelectTrigger className="h-9 text-xs">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="normal">Normal — agregados</SelectItem>
                                <SelectItem value="detailed">Detalhado — agregados + membros</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <MultiFilter
                        label="Tipos de utilizador"
                        allLabel="Todos os tipos"
                        options={report?.options.user_types ?? []}
                        values={draftFilters.user_types}
                        onChange={(values) => setDraftFilters((current) => ({ ...current, user_types: values }))}
                    />

                    <MultiFilter
                        label="Escalões"
                        allLabel="Todos os escalões"
                        options={report?.options.age_groups ?? []}
                        values={draftFilters.age_groups}
                        onChange={(values) => setDraftFilters((current) => ({ ...current, age_groups: values }))}
                    />

                    <MultiFilter
                        label="Estado"
                        allLabel="Todos os estados"
                        options={report?.options.statuses ?? []}
                        values={draftFilters.statuses}
                        onChange={(values) => setDraftFilters((current) => ({ ...current, statuses: values }))}
                    />
                </div>

                <div className="mt-3 flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        size="sm"
                        className="h-8 text-xs"
                        disabled={loading}
                        onClick={() => void loadReport(draftFilters)}
                    >
                        {loading ? <SpinnerGap size={14} className="mr-1.5 animate-spin" /> : <Funnel size={14} className="mr-1.5" />}
                        Aplicar filtros
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="h-8 text-xs"
                        disabled={loading || !hasDraftFilters}
                        onClick={() => void loadReport(emptyFilters)}
                    >
                        <X size={14} className="mr-1.5" />
                        Limpar
                    </Button>

                    <div className="ml-auto flex flex-wrap gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="h-8 text-xs"
                            disabled={!report || report.summary.total === 0 || loading}
                            onClick={exportPdf}
                            title="Abre a vista de impressão para guardar o relatório como PDF"
                        >
                            <FilePdf size={14} className="mr-1.5" />
                            Exportar PDF
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="h-8 text-xs"
                            disabled={!report || report.summary.total === 0 || loading || exportingExcel}
                            onClick={() => void exportExcel()}
                        >
                            {exportingExcel
                                ? <SpinnerGap size={14} className="mr-1.5 animate-spin" />
                                : <DownloadSimple size={14} className="mr-1.5" />}
                            Exportar Excel
                        </Button>
                    </div>
                </div>

                {appliedFilterLabels.length > 0 ? (
                    <div className="mt-3 flex flex-wrap gap-1.5 border-t pt-3">
                        {appliedFilterLabels.map((label) => (
                            <Badge key={label} variant="secondary" className="text-[10px]">
                                {label}
                            </Badge>
                        ))}
                    </div>
                ) : null}
            </Card>

            {error ? (
                <Card className="border-destructive/40 p-3 text-xs text-destructive">{error}</Card>
            ) : null}

            {loading && !report ? (
                <Card className="flex min-h-[220px] items-center justify-center p-6">
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                        <SpinnerGap size={18} className="animate-spin" />
                        A gerar relatório…
                    </div>
                </Card>
            ) : report ? (
                <>
                    <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
                        {[
                            ['Total filtrado', report.summary.total],
                            ['Ativos', report.summary.ativos],
                            ['Inativos', report.summary.inativos],
                            ['Suspensos', report.summary.suspensos],
                        ].map(([label, value]) => (
                            <Card key={String(label)} className="p-3">
                                <p className="text-[11px] text-muted-foreground">{label}</p>
                                <p className="mt-1 text-2xl font-semibold">{value}</p>
                            </Card>
                        ))}
                    </div>

                    <div className="grid gap-2 lg:grid-cols-3">
                        {[
                            ['Por estado', report.breakdowns.statuses],
                            ['Por tipo de utilizador', report.breakdowns.user_types],
                            ['Por escalão', report.breakdowns.age_groups],
                        ].map(([title, rows]) => (
                            <Card key={String(title)} className="p-3">
                                <h3 className="mb-2 text-xs font-semibold">{String(title)}</h3>
                                <div className="space-y-1.5">
                                    {(rows as BreakdownRow[]).length > 0 ? (
                                        (rows as BreakdownRow[]).map((item) => (
                                            <div key={item.value} className="flex items-center justify-between gap-3 text-xs">
                                                <span className="truncate text-muted-foreground">{item.label}</span>
                                                <span className="font-semibold tabular-nums">{item.count}</span>
                                            </div>
                                        ))
                                    ) : (
                                        <p className="text-xs text-muted-foreground">Sem dados para os filtros aplicados.</p>
                                    )}
                                </div>
                            </Card>
                        ))}
                    </div>

                    {report.mode === 'detailed' ? (
                        <Card className="overflow-hidden p-0">
                            <div className="flex items-center justify-between border-b px-3 py-2">
                                <div className="flex items-center gap-2">
                                    <ListBullets size={16} />
                                    <div>
                                        <h3 className="text-xs font-semibold">Detalhe por membro</h3>
                                        <p className="text-[10px] text-muted-foreground">{report.rows.length} linhas</p>
                                    </div>
                                </div>
                            </div>
                            {report.rows.length > 0 ? (
                                <div className="w-full overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="min-w-[90px]">Nº Sócio</TableHead>
                                                <TableHead className="min-w-[220px]">Nome</TableHead>
                                                <TableHead className="min-w-[220px]">Email</TableHead>
                                                <TableHead className="min-w-[100px]">Estado</TableHead>
                                                <TableHead className="min-w-[180px]">Tipo</TableHead>
                                                <TableHead className="min-w-[160px]">Escalão</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {report.rows.map((member) => (
                                                <TableRow key={member.id}>
                                                    <TableCell className="text-xs">{member.numero_socio || '-'}</TableCell>
                                                    <TableCell className="text-xs font-medium">{member.nome_completo}</TableCell>
                                                    <TableCell className="text-xs text-muted-foreground">{member.email_utilizador || '-'}</TableCell>
                                                    <TableCell>
                                                        <Badge variant="outline" className="text-[10px]">{member.estado_label}</Badge>
                                                    </TableCell>
                                                    <TableCell className="text-xs">{member.user_type_labels.join(', ') || '-'}</TableCell>
                                                    <TableCell className="text-xs">{member.age_group_labels.join(', ') || '-'}</TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            ) : (
                                <div className="flex min-h-[160px] items-center justify-center p-6">
                                    <div className="text-center">
                                        <UsersThree size={28} className="mx-auto mb-2 text-muted-foreground" />
                                        <p className="text-xs font-medium">Sem membros para os filtros aplicados</p>
                                    </div>
                                </div>
                            )}
                        </Card>
                    ) : null}

                    <p className="px-1 text-[10px] text-muted-foreground">
                        O escalão usa o perfil desportivo canónico da época atual; só recorre ao valor legacy quando ainda não existir perfil canónico.
                        Um membro com vários tipos ou escalões conta em cada categoria correspondente.
                    </p>
                </>
            ) : null}
        </div>
    );
}
