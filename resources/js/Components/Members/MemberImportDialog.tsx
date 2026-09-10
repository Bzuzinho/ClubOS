import { ChangeEvent, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { Download, FileSpreadsheet, Loader2, Upload } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import {
  inferImportMapping,
  MEMBER_IMPORT_FIELDS,
  MEMBER_IMPORT_GROUP_LABELS,
  type MemberImportGroup,
} from '@/lib/member-import';

type ImportStep = 'file' | 'mapping' | 'preview' | 'result';

type RawImportRow = Record<string, unknown>;

interface PreviewMessage {
  row: number;
  field: string;
  message: string;
}

interface PreviewRow {
  row: number;
  is_valid: boolean;
  errors: Array<{ field: string; message: string }>;
  warnings: Array<{ field: string; message: string }>;
  display: Record<string, string | null>;
}

interface PreviewResponse {
  summary: {
    total_rows: number;
    valid_rows: number;
    warning_rows: number;
    error_rows: number;
  };
  rows: PreviewRow[];
  errors: PreviewMessage[];
  warnings: PreviewMessage[];
}

interface ImportResponse {
  created_count: number;
  skipped_count: number;
  error_count: number;
  created_ids: string[];
  errors: PreviewMessage[];
  warnings: PreviewMessage[];
}

const NONE_OPTION = '__none__';

const GROUP_ORDER: MemberImportGroup[] = ['pessoal', 'financeiro', 'desportivo', 'configuracao'];

let xlsxModulePromise: Promise<typeof import('xlsx')> | null = null;

function loadXlsxModule() {
  if (!xlsxModulePromise) {
    xlsxModulePromise = import('xlsx/xlsx.mjs') as Promise<typeof import('xlsx')>;
  }

  return xlsxModulePromise;
}

export function MemberImportDialog() {
  const inputRef = useRef<HTMLInputElement>(null);
  const [open, setOpen] = useState(false);
  const [step, setStep] = useState<ImportStep>('file');
  const [fileName, setFileName] = useState<string | null>(null);
  const [headers, setHeaders] = useState<string[]>([]);
  const [rows, setRows] = useState<RawImportRow[]>([]);
  const [mapping, setMapping] = useState<Record<string, string>>({});
  const [preview, setPreview] = useState<PreviewResponse | null>(null);
  const [result, setResult] = useState<ImportResponse | null>(null);
  const [isPreviewing, setIsPreviewing] = useState(false);
  const [isImporting, setIsImporting] = useState(false);

  const groupedFields = useMemo(() => {
    return GROUP_ORDER.map((group) => ({
      group,
      label: MEMBER_IMPORT_GROUP_LABELS[group],
      fields: MEMBER_IMPORT_FIELDS.filter((field) => field.group === group),
    }));
  }, []);

  const requiredFieldKeys = useMemo(
    () => MEMBER_IMPORT_FIELDS.filter((field) => field.required).map((field) => field.key),
    []
  );

  const missingRequiredMappings = useMemo(() => {
    return requiredFieldKeys.filter((fieldKey) => !mapping[fieldKey]);
  }, [mapping, requiredFieldKeys]);

  function resetState(nextOpen: boolean) {
    setOpen(nextOpen);
    if (nextOpen) {
      return;
    }

    setStep('file');
    setFileName(null);
    setHeaders([]);
    setRows([]);
    setMapping({});
    setPreview(null);
    setResult(null);
    setIsPreviewing(false);
    setIsImporting(false);
  }

  async function handleFileSelection(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) {
      return;
    }

    try {
      const xlsx = await loadXlsxModule();
      const buffer = await file.arrayBuffer();
      const workbook = xlsx.read(buffer, { type: 'array' });
      const sheetName = workbook.SheetNames[0];
      const sheet = workbook.Sheets[sheetName];

      const headerRows = xlsx.utils.sheet_to_json<string[]>(sheet, { header: 1, blankrows: false });
      const [headerRow] = headerRows;

      if (!headerRow || headerRow.length === 0) {
        toast.error('O ficheiro não contém cabeçalhos válidos.');
        return;
      }

      const cleanHeaders = headerRow.map((value) => String(value ?? '').trim()).filter(Boolean);
      const parsedRows = xlsx.utils.sheet_to_json<RawImportRow>(sheet, {
        defval: null,
        raw: false,
        blankrows: false,
      });

      if (parsedRows.length === 0) {
        toast.error('O ficheiro não contém linhas com dados.');
        return;
      }

      setFileName(file.name);
      setHeaders(cleanHeaders);
      setRows(parsedRows);
      setMapping(inferImportMapping(cleanHeaders));
      setPreview(null);
      setResult(null);
      setStep('mapping');
      toast.success('Ficheiro carregado. Faça agora o mapeamento das colunas.');
    } catch (error) {
      console.error(error);
      toast.error('Não foi possível ler o ficheiro selecionado.');
    } finally {
      if (event.target) {
        event.target.value = '';
      }
    }
  }

  function handleChangeMapping(fieldKey: string, value: string) {
    setMapping((current) => {
      const next = { ...current };
      if (value === NONE_OPTION) {
        delete next[fieldKey];
      } else {
        next[fieldKey] = value;
      }
      return next;
    });
  }

  async function handlePreview() {
    if (rows.length === 0) {
      return;
    }

    if (missingRequiredMappings.length > 0) {
      toast.error('Mapeie primeiro todos os campos obrigatórios.');
      return;
    }

    setIsPreviewing(true);

    try {
      const response = await axios.post<PreviewResponse>(route('membros.import.preview'), {
        rows,
        mapping,
        options: {
          import_only_valid_rows: true,
        },
      });

      setPreview(response.data);
      setResult(null);
      setStep('preview');
    } catch (error: any) {
      console.error(error);
      const message = error?.response?.data?.message || 'Não foi possível validar a importação.';
      toast.error(message);
    } finally {
      setIsPreviewing(false);
    }
  }

  async function handleImport() {
    if (!preview) {
      return;
    }

    setIsImporting(true);

    try {
      const response = await axios.post<ImportResponse>(route('membros.import.store'), {
        rows,
        mapping,
        options: {
          import_only_valid_rows: true,
        },
      });

      setResult(response.data);
      setStep('result');
      toast.success(`Importação concluída: ${response.data.created_count} membros criados.`);
    } catch (error: any) {
      console.error(error);
      const message = error?.response?.data?.message || 'Não foi possível concluir a importação.';
      toast.error(message);
    } finally {
      setIsImporting(false);
    }
  }

  function renderFileStep() {
    return (
      <div className="space-y-4">
        <Card className="border-dashed p-4">
          <div className="flex flex-col items-center justify-center gap-3 text-center">
            <div className="rounded-full bg-primary/10 p-3 text-primary">
              <FileSpreadsheet className="h-6 w-6" />
            </div>
            <div className="space-y-1">
              <p className="text-sm font-medium">Carregar Excel ou CSV</p>
              <p className="text-xs text-muted-foreground">
                São aceites ficheiros .xlsx, .xls e .csv. A primeira folha será usada na importação.
              </p>
            </div>
            <input
              ref={inputRef}
              type="file"
              accept=".xlsx,.xls,.csv"
              className="hidden"
              onChange={handleFileSelection}
            />
            <div className="flex flex-wrap items-center justify-center gap-2">
              <Button type="button" onClick={() => inputRef.current?.click()}>
                <Upload className="mr-2 h-4 w-4" />
                Escolher ficheiro
              </Button>
              <Button type="button" variant="outline" onClick={() => window.location.assign(route('membros.import.template'))}>
                <Download className="mr-2 h-4 w-4" />
                Descarregar modelo
              </Button>
            </div>
          </div>
        </Card>

        {fileName ? (
          <Card className="p-4">
            <div className="space-y-2 text-sm">
              <p><span className="font-medium">Ficheiro:</span> {fileName}</p>
              <p><span className="font-medium">Colunas detetadas:</span> {headers.length}</p>
              <p><span className="font-medium">Linhas com dados:</span> {rows.length}</p>
            </div>
          </Card>
        ) : null}
      </div>
    );
  }

  function renderMappingStep() {
    return (
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="text-sm text-muted-foreground">
            {fileName ? `Ficheiro carregado: ${fileName}` : 'Sem ficheiro carregado'}
          </div>
          <Badge variant="outline">{rows.length} linhas</Badge>
        </div>

        <ScrollArea className="h-[440px] pr-4">
          <div className="space-y-4">
            {groupedFields.map(({ group, label, fields }) => (
              <Card key={group} className="p-4">
                <div className="mb-3 flex items-center justify-between">
                  <h3 className="text-sm font-semibold">{label}</h3>
                  <Badge variant="secondary">{fields.length} campos</Badge>
                </div>
                <div className="space-y-3">
                  {fields.map((field) => (
                    <div key={field.key} className="grid gap-2 sm:grid-cols-[220px_minmax(0,1fr)] sm:items-center">
                      <div>
                        <Label className="text-xs font-medium">
                          {field.label}
                          {field.required ? ' *' : ''}
                        </Label>
                        <p className="text-[11px] text-muted-foreground">{field.key}</p>
                      </div>
                      <Select
                        value={mapping[field.key] ?? NONE_OPTION}
                        onValueChange={(value) => handleChangeMapping(field.key, value)}
                      >
                        <SelectTrigger className="h-8 text-xs">
                          <SelectValue placeholder="Escolher coluna" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value={NONE_OPTION}>Não importar</SelectItem>
                          {headers.map((header) => (
                            <SelectItem key={`${field.key}-${header}`} value={header}>
                              {header}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                  ))}
                </div>
              </Card>
            ))}
          </div>
        </ScrollArea>

        {missingRequiredMappings.length > 0 ? (
          <p className="text-xs text-amber-600">
            Faltam mapear os campos obrigatórios: {missingRequiredMappings.join(', ')}.
          </p>
        ) : null}
      </div>
    );
  }

  function renderPreviewStep() {
    if (!preview) {
      return null;
    }

    return (
      <div className="space-y-4">
        <div className="grid gap-2 sm:grid-cols-4">
          <Card className="p-3"><p className="text-xs text-muted-foreground">Total</p><p className="text-lg font-semibold">{preview.summary.total_rows}</p></Card>
          <Card className="p-3"><p className="text-xs text-muted-foreground">Válidas</p><p className="text-lg font-semibold text-emerald-600">{preview.summary.valid_rows}</p></Card>
          <Card className="p-3"><p className="text-xs text-muted-foreground">Avisos</p><p className="text-lg font-semibold text-amber-600">{preview.summary.warning_rows}</p></Card>
          <Card className="p-3"><p className="text-xs text-muted-foreground">Com erro</p><p className="text-lg font-semibold text-rose-600">{preview.summary.error_rows}</p></Card>
        </div>

        <Card className="p-0">
          <ScrollArea className="h-[280px]">
            <Table responsive>
              <TableHeader>
                <TableRow>
                  <TableHead>Linha</TableHead>
                  <TableHead>Nome</TableHead>
                  <TableHead>Nº sócio</TableHead>
                  <TableHead>Email</TableHead>
                  <TableHead>Tipo</TableHead>
                  <TableHead>Estado</TableHead>
                  <TableHead>Resultado</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {preview.rows.map((row) => (
                  <TableRow key={row.row}>
                    <TableCell label="Linha">{row.row}</TableCell>
                    <TableCell label="Nome">{row.display.nome_completo ?? '-'}</TableCell>
                    <TableCell label="Nº sócio">{row.display.numero_socio ?? '-'}</TableCell>
                    <TableCell label="Email">{row.display.email_utilizador ?? '-'}</TableCell>
                    <TableCell label="Tipo">{row.display.tipo_membro ?? '-'}</TableCell>
                    <TableCell label="Estado">{row.display.estado ?? '-'}</TableCell>
                    <TableCell label="Resultado">
                      {row.is_valid ? (
                        row.warnings.length > 0 ? <Badge className="bg-amber-100 text-amber-800">Com avisos</Badge> : <Badge className="bg-emerald-100 text-emerald-800">Válida</Badge>
                      ) : (
                        <Badge className="bg-rose-100 text-rose-800">Com erros</Badge>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </ScrollArea>
        </Card>

        {preview.errors.length > 0 ? (
          <Card className="p-4">
            <h3 className="mb-2 text-sm font-semibold text-rose-700">Erros encontrados</h3>
            <ScrollArea className="h-[120px] pr-3">
              <div className="space-y-2 text-xs text-rose-700">
                {preview.errors.map((error, index) => (
                  <p key={`${error.row}-${error.field}-${index}`}>Linha {error.row} · {error.field}: {error.message}</p>
                ))}
              </div>
            </ScrollArea>
          </Card>
        ) : null}

        {preview.warnings.length > 0 ? (
          <Card className="p-4">
            <h3 className="mb-2 text-sm font-semibold text-amber-700">Avisos</h3>
            <ScrollArea className="h-[100px] pr-3">
              <div className="space-y-2 text-xs text-amber-700">
                {preview.warnings.map((warning, index) => (
                  <p key={`${warning.row}-${warning.field}-${index}`}>Linha {warning.row} · {warning.field}: {warning.message}</p>
                ))}
              </div>
            </ScrollArea>
          </Card>
        ) : null}
      </div>
    );
  }

  function renderResultStep() {
    if (!result) {
      return null;
    }

    return (
      <div className="space-y-4">
        <div className="grid gap-2 sm:grid-cols-3">
          <Card className="p-4"><p className="text-xs text-muted-foreground">Criados</p><p className="text-2xl font-semibold text-emerald-600">{result.created_count}</p></Card>
          <Card className="p-4"><p className="text-xs text-muted-foreground">Ignorados</p><p className="text-2xl font-semibold text-slate-700">{result.skipped_count}</p></Card>
          <Card className="p-4"><p className="text-xs text-muted-foreground">Erros</p><p className="text-2xl font-semibold text-rose-600">{result.error_count}</p></Card>
        </div>

        {result.errors.length > 0 ? (
          <Card className="p-4">
            <h3 className="mb-2 text-sm font-semibold">Linhas rejeitadas</h3>
            <div className="space-y-2 text-xs text-muted-foreground">
              {result.errors.slice(0, 20).map((error, index) => (
                <p key={`${error.row}-${error.field}-${index}`}>Linha {error.row} · {error.field}: {error.message}</p>
              ))}
            </div>
          </Card>
        ) : null}
      </div>
    );
  }

  return (
    <>
      <Button type="button" variant="outline" size="sm" className="h-8 text-xs" onClick={() => setOpen(true)}>
        <Upload className="mr-1 h-4 w-4" />
        Importar utilizadores
      </Button>

      <Dialog open={open} onOpenChange={resetState}>
        <DialogContent className="flex max-h-[92vh] w-[min(96vw,72rem)] max-w-[min(96vw,72rem)] flex-col overflow-hidden p-0">
          <div className="border-b px-5 py-4 sm:px-6">
          <DialogHeader>
            <DialogTitle>Importação de membros</DialogTitle>
            <DialogDescription>
              Carregue um ficheiro Excel/CSV, mapeie as colunas para os campos do módulo e valide antes de importar.
            </DialogDescription>
          </DialogHeader>
          </div>

          <div className="border-b px-5 py-3 sm:px-6">
            <div className="flex flex-wrap gap-2 text-xs">
              <Badge variant={step === 'file' ? 'default' : 'secondary'}>1. Ficheiro</Badge>
              <Badge variant={step === 'mapping' ? 'default' : 'secondary'}>2. Mapeamento</Badge>
              <Badge variant={step === 'preview' ? 'default' : 'secondary'}>3. Preview</Badge>
              <Badge variant={step === 'result' ? 'default' : 'secondary'}>4. Resultado</Badge>
            </div>
          </div>

          <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4 sm:px-6">
            {step === 'file' ? renderFileStep() : null}
            {step === 'mapping' ? renderMappingStep() : null}
            {step === 'preview' ? renderPreviewStep() : null}
            {step === 'result' ? renderResultStep() : null}
          </div>

          <DialogFooter className="border-t px-5 py-4 sm:px-6">
            {step === 'mapping' ? (
              <>
                <Button type="button" variant="outline" onClick={() => setStep('file')}>
                  Voltar
                </Button>
                <Button type="button" onClick={handlePreview} disabled={isPreviewing || rows.length === 0}>
                  {isPreviewing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
                  Validar importação
                </Button>
              </>
            ) : null}

            {step === 'preview' ? (
              <>
                <Button type="button" variant="outline" onClick={() => setStep('mapping')}>
                  Ajustar mapeamento
                </Button>
                <Button
                  type="button"
                  onClick={handleImport}
                  disabled={isImporting || !preview || preview.summary.valid_rows === 0}
                >
                  {isImporting ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
                  Importar linhas válidas
                </Button>
              </>
            ) : null}

            {step === 'result' ? (
              <Button type="button" onClick={() => resetState(false)}>
                Fechar
              </Button>
            ) : null}
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}