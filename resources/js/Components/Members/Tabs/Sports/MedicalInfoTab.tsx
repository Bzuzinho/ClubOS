import { useMemo, useState } from 'react';
import { format } from 'date-fns';
import { pt } from 'date-fns/locale';
import { User } from '@/types';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Pencil, Plus, Trash2 } from 'lucide-react';

interface MedicalInfoTabProps {
  user: User;
  onChange: (field: keyof User, value: any) => void;
  isAdmin: boolean;
}

interface MedicalRecordRow {
  id: string;
  data: string;
  condicao_fisica: string;
  paragem: boolean;
  tempo_paragem: string;
}

interface MedicalInfoState {
  apto: '' | 'apto' | 'nao_apto';
  registos: MedicalRecordRow[];
  observacoesLegadas: string;
}

function createMedicalRecordRow(): MedicalRecordRow {
  return {
    id: crypto.randomUUID(),
    data: '',
    condicao_fisica: '',
    paragem: false,
    tempo_paragem: '',
  };
}

function parseMedicalInfo(rawValue: unknown): MedicalInfoState {
  if (rawValue == null || rawValue === '') {
    return { apto: '', registos: [], observacoesLegadas: '' };
  }

  try {
    const parsed = typeof rawValue === 'string' ? JSON.parse(rawValue) : rawValue;
    const rawRegistos = Array.isArray(parsed)
      ? parsed
      : Array.isArray((parsed as any)?.registos)
        ? (parsed as any).registos
        : Array.isArray((parsed as any)?.records)
          ? (parsed as any).records
          : [];

    const aptoRaw = (parsed as any)?.apto;
    const aptoValue = aptoRaw === 'apto' || aptoRaw === true
      ? 'apto'
      : aptoRaw === 'nao_apto' || aptoRaw === false
        ? 'nao_apto'
        : '';

    return {
      apto: aptoValue,
      registos: rawRegistos.map((registo: any) => {
        const condicao =
          typeof registo?.condicao_fisica === 'string'
            ? registo.condicao_fisica
            : typeof registo?.condicao === 'string'
              ? registo.condicao
              : typeof registo?.descricao === 'string'
                ? registo.descricao
                : '';

        const paragem =
          typeof registo?.paragem === 'boolean'
            ? registo.paragem
            : typeof registo?.paragem === 'string'
              ? ['sim', 'true', '1'].includes(registo.paragem.toLowerCase())
              : Boolean(registo?.paragem);

        const tempoParagem =
          typeof registo?.tempo_paragem === 'string'
            ? registo.tempo_paragem
            : typeof registo?.tempoParagem === 'string'
              ? registo.tempoParagem
              : typeof registo?.tempo === 'string'
                ? registo.tempo
                : '';

        const data =
          typeof registo?.data === 'string'
            ? registo.data
            : typeof registo?.date === 'string'
              ? registo.date
              : '';

        return {
          id: registo?.id || crypto.randomUUID(),
          data,
          condicao_fisica: condicao,
          paragem,
          tempo_paragem: tempoParagem,
        };
      }),
      observacoesLegadas:
        typeof (parsed as any)?.observacoes_legadas === 'string'
          ? (parsed as any).observacoes_legadas
          : typeof (parsed as any)?.observacoes === 'string'
            ? (parsed as any).observacoes
            : '',
    };
  } catch {
    return {
      apto: '',
      registos: [],
      observacoesLegadas: typeof rawValue === 'string' ? rawValue : '',
    };
  }
}

function serializeMedicalInfo(state: MedicalInfoState): string {
  if (state.apto === '' && state.registos.length === 0 && state.observacoesLegadas.trim() === '') {
    return '';
  }

  return JSON.stringify({
    version: 1,
    apto: state.apto || null,
    registos: state.registos,
    observacoes_legadas: state.observacoesLegadas || null,
  });
}

export function MedicalInfoTab({ user, onChange, isAdmin }: MedicalInfoTabProps) {
  const [editingMedicalId, setEditingMedicalId] = useState<string | null>(null);
  const [medicalDraft, setMedicalDraft] = useState<MedicalRecordRow>(() => createMedicalRecordRow());

  const medicalInfo = useMemo(() => parseMedicalInfo(user.informacoes_medicas), [user.informacoes_medicas]);

  const updateMedicalInfo = (updater: (current: MedicalInfoState) => MedicalInfoState) => {
    const nextState = updater(medicalInfo);
    onChange('informacoes_medicas', serializeMedicalInfo(nextState));
  };

  const resetMedicalEditor = () => {
    setEditingMedicalId(null);
    setMedicalDraft(createMedicalRecordRow());
  };

  const handleCreateMedicalRecord = () => {
    setEditingMedicalId('new');
    setMedicalDraft(createMedicalRecordRow());
  };

  const handleEditMedicalRecord = (record: MedicalRecordRow) => {
    setEditingMedicalId(record.id);
    setMedicalDraft({ ...record });
  };

  const handleSaveMedicalRecord = () => {
    if (!medicalDraft.condicao_fisica.trim()) {
      return;
    }

    updateMedicalInfo((current) => {
      const existingIndex = current.registos.findIndex((registo) => registo.id === medicalDraft.id);
      const nextRegistos = [...current.registos];

      if (existingIndex >= 0) {
        nextRegistos[existingIndex] = medicalDraft;
      } else {
        nextRegistos.unshift(medicalDraft);
      }

      return {
        ...current,
        registos: nextRegistos,
      };
    });

    resetMedicalEditor();
  };

  const handleDeleteMedicalRecord = (recordId: string) => {
    updateMedicalInfo((current) => ({
      ...current,
      registos: current.registos.filter((registo) => registo.id !== recordId),
    }));

    if (editingMedicalId === recordId) {
      resetMedicalEditor();
    }
  };

  return (
    <Card className="p-2 space-y-2">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-2">
          <Label className="text-xs whitespace-nowrap">Apto</Label>
          <Select
            value={medicalInfo.apto || 'por_definir'}
            onValueChange={(value) => updateMedicalInfo((current) => ({
              ...current,
              apto: value === 'por_definir' ? '' : value as 'apto' | 'nao_apto',
            }))}
            disabled={!isAdmin}
          >
            <SelectTrigger className="h-7 w-[140px] text-xs bg-white">
              <SelectValue placeholder="Selecionar" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="por_definir">Por definir</SelectItem>
              <SelectItem value="apto">Apto</SelectItem>
              <SelectItem value="nao_apto">Não apto</SelectItem>
            </SelectContent>
          </Select>
          <Badge variant="outline" className={medicalInfo.apto === 'apto' ? 'text-emerald-700 border-emerald-300' : medicalInfo.apto === 'nao_apto' ? 'text-red-700 border-red-300' : 'text-slate-600 border-slate-300'}>
            {medicalInfo.apto === 'apto' ? 'Apto' : medicalInfo.apto === 'nao_apto' ? 'Não apto' : 'Por definir'}
          </Badge>
        </div>

        <Button type="button" size="sm" className="h-7 text-xs" onClick={handleCreateMedicalRecord} disabled={!isAdmin}>
          <Plus size={14} className="mr-1" />
          Criar Condição
        </Button>
      </div>

      {medicalInfo.observacoesLegadas && (
        <div className="rounded-md border border-amber-200 bg-amber-50 px-2 py-1.5 text-xs text-amber-900">
          <span className="font-medium">Observações anteriores:</span> {medicalInfo.observacoesLegadas}
        </div>
      )}

      {editingMedicalId && (
        <div className="rounded-md border bg-slate-50 p-2 space-y-2">
          <div className="grid grid-cols-1 md:grid-cols-4 gap-1">
            <div>
              <Label className="text-xs">Data</Label>
              <Input
                type="date"
                value={medicalDraft.data}
                onChange={(e) => setMedicalDraft((current) => ({ ...current, data: e.target.value }))}
                className="h-7 text-xs bg-white mt-1"
                disabled={!isAdmin}
              />
            </div>

            <div>
              <Label className="text-xs">Condição Física</Label>
              <Input
                value={medicalDraft.condicao_fisica}
                onChange={(e) => setMedicalDraft((current) => ({ ...current, condicao_fisica: e.target.value }))}
                className="h-7 text-xs bg-white mt-1"
                disabled={!isAdmin}
                placeholder="Ex: Entorse, gripe, limitação"
              />
            </div>

            <div>
              <Label className="text-xs">Paragem</Label>
              <Select
                value={medicalDraft.paragem ? 'sim' : 'nao'}
                onValueChange={(value) => setMedicalDraft((current) => ({
                  ...current,
                  paragem: value === 'sim',
                  tempo_paragem: value === 'sim' ? current.tempo_paragem : '',
                }))}
                disabled={!isAdmin}
              >
                <SelectTrigger className="h-7 text-xs bg-white mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="sim">Sim</SelectItem>
                  <SelectItem value="nao">Não</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div>
              <Label className="text-xs">Tempo de Paragem</Label>
              <Input
                value={medicalDraft.tempo_paragem}
                onChange={(e) => setMedicalDraft((current) => ({ ...current, tempo_paragem: e.target.value }))}
                className="h-7 text-xs bg-white mt-1"
                disabled={!isAdmin || !medicalDraft.paragem}
                placeholder="Ex: 7 dias"
              />
            </div>
          </div>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" size="sm" className="h-7 text-xs" onClick={resetMedicalEditor}>
              Cancelar
            </Button>
            <Button type="button" size="sm" className="h-7 text-xs" onClick={handleSaveMedicalRecord} disabled={!isAdmin || !medicalDraft.condicao_fisica.trim()}>
              Guardar
            </Button>
          </div>
        </div>
      )}

      <div className="border rounded-md overflow-hidden">
        <div className="sm:hidden p-2 space-y-2">
          {medicalInfo.registos.length === 0 ? (
            <div className="text-xs text-center text-muted-foreground py-2">Sem registos médicos.</div>
          ) : (
            medicalInfo.registos.map((registo) => (
              <div key={registo.id} className="rounded-md border bg-white p-2 space-y-2">
                <div className="grid grid-cols-2 gap-x-2 gap-y-1 text-xs">
                  <span className="text-muted-foreground">Data</span>
                  <span className="text-right">{registo.data ? format(new Date(registo.data), 'dd/MM/yyyy', { locale: pt }) : '-'}</span>
                  <span className="text-muted-foreground">Condição</span>
                  <span className="text-right break-words">{registo.condicao_fisica || '-'}</span>
                  <span className="text-muted-foreground">Paragem</span>
                  <span className="text-right">{registo.paragem ? 'Sim' : 'Não'}</span>
                  <span className="text-muted-foreground">Tempo</span>
                  <span className="text-right break-words">{registo.paragem ? (registo.tempo_paragem || '-') : '-'}</span>
                </div>

                <div className="flex justify-end gap-1">
                  <Button type="button" variant="outline" size="icon" className="h-7 w-7" onClick={() => handleEditMedicalRecord(registo)} disabled={!isAdmin}>
                    <Pencil size={14} />
                  </Button>
                  <Button type="button" variant="outline" size="icon" className="h-7 w-7 text-red-600" onClick={() => handleDeleteMedicalRecord(registo.id)} disabled={!isAdmin}>
                    <Trash2 size={14} />
                  </Button>
                </div>
              </div>
            ))
          )}
        </div>

        <div className="hidden sm:block">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="text-xs h-8">Data</TableHead>
                <TableHead className="text-xs h-8">Condição Física</TableHead>
                <TableHead className="text-xs h-8">Paragem</TableHead>
                <TableHead className="text-xs h-8">Tempo de Paragem</TableHead>
                <TableHead className="text-xs h-8 text-right">Ações</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {medicalInfo.registos.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={5} className="text-xs text-center text-muted-foreground py-4">
                    Sem registos médicos.
                  </TableCell>
                </TableRow>
              ) : (
                medicalInfo.registos.map((registo) => (
                  <TableRow key={registo.id}>
                    <TableCell className="text-xs whitespace-nowrap">{registo.data ? format(new Date(registo.data), 'dd/MM/yyyy', { locale: pt }) : '-'}</TableCell>
                    <TableCell className="text-xs">{registo.condicao_fisica || '-'}</TableCell>
                    <TableCell className="text-xs">{registo.paragem ? 'Sim' : 'Não'}</TableCell>
                    <TableCell className="text-xs">{registo.paragem ? (registo.tempo_paragem || '-') : '-'}</TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-1">
                        <Button type="button" variant="outline" size="icon" className="h-7 w-7" onClick={() => handleEditMedicalRecord(registo)} disabled={!isAdmin}>
                          <Pencil size={14} />
                        </Button>
                        <Button type="button" variant="outline" size="icon" className="h-7 w-7 text-red-600" onClick={() => handleDeleteMedicalRecord(registo.id)} disabled={!isAdmin}>
                          <Trash2 size={14} />
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </div>
      </div>
    </Card>
  );
}