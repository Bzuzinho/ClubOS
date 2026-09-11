import { User } from '@/types';
import { useMemo, useState } from 'react';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useKV } from '@/hooks/useKV';
import { Pencil, Plus, Trash2 } from 'lucide-react';

type EstadoDisciplinar = 'normal' | 'alerta' | 'critico';

interface DisciplinaryStatusRow {
  user_id: string;
  estado: EstadoDisciplinar;
  updated_at: string;
}

interface ParticipationRecord {
  id: string;
  user_id: string;
  data: string;
  descricao_comportamento: string;
  classificacao: number;
  aviso_ee?: boolean;
  created_at: string;
  updated_at: string;
}

interface ParticipationDraft {
  id: string;
  data: string;
  descricao_comportamento: string;
  classificacao: string;
  aviso_ee: 'sim' | 'nao';
}

function createDraft(): ParticipationDraft {
  return {
    id: crypto.randomUUID(),
    data: new Date().toISOString().slice(0, 10),
    descricao_comportamento: '',
    classificacao: '3',
    aviso_ee: 'nao',
  };
}

interface PlaneamentoTabProps {
  user: User;
}

export function PlaneamentoTab({ user }: PlaneamentoTabProps) {
  const [disciplinaryStatuses, setDisciplinaryStatuses] = useKV<DisciplinaryStatusRow[]>('club-discipline-status', []);
  const [participationRecords, setParticipationRecords] = useKV<ParticipationRecord[]>('club-discipline-records', []);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [draft, setDraft] = useState<ParticipationDraft>(() => createDraft());

  const userId = String(user.id);
  const isMinor = Boolean((user as any).menor);

  const currentStatus = useMemo<EstadoDisciplinar>(() => {
    return disciplinaryStatuses.find((row) => row.user_id === userId)?.estado || 'normal';
  }, [disciplinaryStatuses, userId]);

  const records = useMemo(() => {
    return (participationRecords || [])
      .filter((row) => row.user_id === userId)
      .sort((left, right) => {
        const dateDelta = new Date(right.data).getTime() - new Date(left.data).getTime();
        if (dateDelta !== 0) return dateDelta;
        return new Date(right.updated_at).getTime() - new Date(left.updated_at).getTime();
      });
  }, [participationRecords, userId]);

  const resetEditor = () => {
    setEditingId(null);
    setDraft(createDraft());
  };

  const updateStatus = (estado: EstadoDisciplinar) => {
    const timestamp = new Date().toISOString();
    setDisciplinaryStatuses((current) => {
      const rows = current || [];
      const index = rows.findIndex((row) => row.user_id === userId);
      if (index >= 0) {
        const next = [...rows];
        next[index] = { ...next[index], estado, updated_at: timestamp };
        return next;
      }

      return [...rows, { user_id: userId, estado, updated_at: timestamp }];
    });
  };

  const startCreate = () => {
    setEditingId('new');
    setDraft(createDraft());
  };

  const startEdit = (record: ParticipationRecord) => {
    setEditingId(record.id);
    setDraft({
      id: record.id,
      data: record.data,
      descricao_comportamento: record.descricao_comportamento,
      classificacao: String(record.classificacao),
      aviso_ee: record.aviso_ee ? 'sim' : 'nao',
    });
  };

  const saveRecord = () => {
    const descricao = draft.descricao_comportamento.trim();
    const classificacao = Number(draft.classificacao);
    if (!draft.data || !descricao || Number.isNaN(classificacao) || classificacao < 1 || classificacao > 5) {
      return;
    }

    const timestamp = new Date().toISOString();

    setParticipationRecords((current) => {
      const rows = current || [];
      const index = rows.findIndex((row) => row.id === draft.id);

      const nextRow: ParticipationRecord = {
        id: draft.id,
        user_id: userId,
        data: draft.data,
        descricao_comportamento: descricao,
        classificacao,
        aviso_ee: isMinor ? draft.aviso_ee === 'sim' : false,
        created_at: index >= 0 ? rows[index].created_at : timestamp,
        updated_at: timestamp,
      };

      if (index >= 0) {
        const next = [...rows];
        next[index] = nextRow;
        return next;
      }

      return [nextRow, ...rows];
    });

    resetEditor();
  };

  const deleteRecord = (id: string) => {
    setParticipationRecords((current) => (current || []).filter((row) => row.id !== id));
    if (editingId === id) {
      resetEditor();
    }
  };

  return (
    <Card className="p-2 space-y-2">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-2">
          <Label className="text-xs whitespace-nowrap">Estado Disciplinar</Label>
          <Select value={currentStatus} onValueChange={(value) => updateStatus(value as EstadoDisciplinar)}>
            <SelectTrigger className="h-7 w-[160px] text-xs bg-white">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="normal">Normal</SelectItem>
              <SelectItem value="alerta">Alerta</SelectItem>
              <SelectItem value="critico">Critico</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <Button type="button" size="sm" className="h-7 text-xs" onClick={startCreate}>
          <Plus size={14} className="mr-1" />
          Criar Participação
        </Button>
      </div>

      {editingId && (
        <div className="rounded-md border bg-slate-50 p-2 space-y-2">
          <div className={`grid gap-1 ${isMinor ? 'grid-cols-1 md:grid-cols-4 lg:grid-cols-5' : 'grid-cols-1 md:grid-cols-3 lg:grid-cols-4'}`}>
            <div>
              <Label className="text-xs">Data</Label>
              <Input
                type="date"
                value={draft.data}
                onChange={(event) => setDraft((current) => ({ ...current, data: event.target.value }))}
                className="h-7 text-xs bg-white mt-1"
              />
            </div>

            <div className={isMinor ? 'md:col-span-2 lg:col-span-2' : 'md:col-span-1 lg:col-span-2'}>
              <Label className="text-xs">Descrição do Comportamento</Label>
              <Input
                value={draft.descricao_comportamento}
                onChange={(event) => setDraft((current) => ({ ...current, descricao_comportamento: event.target.value }))}
                className="h-7 text-xs bg-white mt-1"
                placeholder="Descreve o comportamento observado"
              />
            </div>

            <div>
              <Label className="text-xs">Classificação (1-5)</Label>
              <Select value={draft.classificacao} onValueChange={(value) => setDraft((current) => ({ ...current, classificacao: value }))}>
                <SelectTrigger className="h-7 text-xs bg-white mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="1">1</SelectItem>
                  <SelectItem value="2">2</SelectItem>
                  <SelectItem value="3">3</SelectItem>
                  <SelectItem value="4">4</SelectItem>
                  <SelectItem value="5">5</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {isMinor && (
              <div>
                <Label className="text-xs">Aviso ao EE</Label>
                <Select value={draft.aviso_ee} onValueChange={(value) => setDraft((current) => ({ ...current, aviso_ee: value as 'sim' | 'nao' }))}>
                  <SelectTrigger className="h-7 text-xs bg-white mt-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="sim">Sim</SelectItem>
                    <SelectItem value="nao">Não</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            )}
          </div>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" size="sm" className="h-7 text-xs" onClick={resetEditor}>
              Cancelar
            </Button>
            <Button
              type="button"
              size="sm"
              className="h-7 text-xs"
              onClick={saveRecord}
              disabled={!draft.data || !draft.descricao_comportamento.trim() || Number(draft.classificacao) < 1 || Number(draft.classificacao) > 5}
            >
              Guardar
            </Button>
          </div>
        </div>
      )}

      <div className="border rounded-md overflow-hidden">
        <Table responsive>
          <TableHeader>
            <TableRow>
              <TableHead className="text-xs h-8">Data</TableHead>
              <TableHead className="text-xs h-8">Descrição do Comportamento</TableHead>
              <TableHead className="text-xs h-8">Classificação</TableHead>
              {isMinor && <TableHead className="text-xs h-8">Aviso ao EE</TableHead>}
              <TableHead className="text-xs h-8 text-right">Ações</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {records.length === 0 ? (
              <TableRow>
                <TableCell colSpan={isMinor ? 5 : 4} className="text-xs text-center text-muted-foreground py-4">
                  Sem participações registadas.
                </TableCell>
              </TableRow>
            ) : (
              records.map((record) => (
                <TableRow key={record.id}>
                  <TableCell label="Data" className="text-xs whitespace-nowrap">{record.data}</TableCell>
                  <TableCell label="Descrição do Comportamento" className="text-xs">{record.descricao_comportamento}</TableCell>
                  <TableCell label="Classificação" className="text-xs">{record.classificacao}</TableCell>
                  {isMinor && <TableCell label="Aviso ao EE" className="text-xs">{record.aviso_ee ? 'Sim' : 'Não'}</TableCell>}
                  <TableCell label="Ações" className="text-right">
                    <div className="flex justify-end gap-1">
                      <Button type="button" variant="outline" size="icon" className="h-7 w-7" onClick={() => startEdit(record)}>
                        <Pencil size={14} />
                      </Button>
                      <Button type="button" variant="outline" size="icon" className="h-7 w-7 text-red-600" onClick={() => deleteRecord(record.id)}>
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
    </Card>
  );
}
