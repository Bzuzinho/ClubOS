import { FormEvent } from 'react';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import type { CaisAthlete, CaisStatus, MetricDefinition, QuickType } from './types';

const STATUSES: Array<{ value: CaisStatus; label: string }> = [
  { value: 'presente', label: 'Presente' }, { value: 'ausente', label: 'Ausente' },
  { value: 'dispensado', label: 'Dispensado' }, { value: 'atrasado', label: 'Atrasado' },
];

export interface FullRegisterState {
  open: boolean;
  athleteId: string | null;
  status: CaisStatus;
  behavior: string;
  material: string;
  technical_note: string;
  advice: string;
  metrics: Record<string, string>;
}

interface Props {
  quick: { open: boolean; athleteId: string | null; type: QuickType; value: string | null };
  quickAthlete: CaisAthlete | null;
  quickDefinition?: MetricDefinition;
  onQuickOpen: (open: boolean) => void;
  onQuickValue: (value: string | null) => void;
  onQuickSave: () => void;
  full: FullRegisterState;
  fullAthlete: CaisAthlete | null;
  behaviorDefinition?: MetricDefinition;
  materialDefinition?: MetricDefinition;
  extraDefinitions: MetricDefinition[];
  onFullChange: (patch: Partial<FullRegisterState>) => void;
  onFullSave: (event: FormEvent) => void;
}

export function CaisRegisterDialogs(props: Props) {
  const { quick, quickAthlete, quickDefinition, full, fullAthlete, behaviorDefinition, materialDefinition, extraDefinitions } = props;
  return (
    <>
      <Dialog open={quick.open} onOpenChange={props.onQuickOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader><DialogTitle>{quickDefinition?.name ?? 'Registo rápido'} — {quickAthlete?.name}</DialogTitle></DialogHeader>
          {quickDefinition?.input_type === 'choice' ? (
            <div className="flex flex-wrap gap-2">{quickDefinition.options.map((option) => <Button key={option} type="button" size="sm" variant={quick.value === option ? 'default' : 'outline'} onClick={() => props.onQuickValue(option)}>{option}</Button>)}</div>
          ) : <Input value={quick.value ?? ''} onChange={(event) => props.onQuickValue(event.target.value)} />}
          <DialogFooter><Button variant="outline" onClick={() => props.onQuickOpen(false)}>Cancelar</Button><Button onClick={props.onQuickSave}>Guardar</Button></DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={full.open} onOpenChange={(open) => props.onFullChange({ open })}>
        <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
          <DialogHeader><DialogTitle>Registo do atleta — {fullAthlete?.name}</DialogTitle></DialogHeader>
          <form onSubmit={props.onFullSave} className="space-y-4">
            <div><Label>Presença</Label><div className="mt-1 flex gap-2">{STATUSES.map((option) => <Button key={option.value} type="button" size="sm" variant={full.status === option.value ? 'default' : 'outline'} onClick={() => props.onFullChange({ status: option.value })}>{option.label}</Button>)}</div></div>
            <div><Label>Comportamento</Label><div className="mt-1 flex flex-wrap gap-2">{(behaviorDefinition?.options ?? []).map((option) => <Button key={option} type="button" size="sm" variant={full.behavior === option ? 'default' : 'outline'} onClick={() => props.onFullChange({ behavior: full.behavior === option ? '' : option })}>{option}</Button>)}</div></div>
            <div><Label>Material</Label><div className="mt-1 flex flex-wrap gap-2">{(materialDefinition?.options ?? []).map((option) => <Button key={option} type="button" size="sm" variant={full.material === option ? 'default' : 'outline'} onClick={() => props.onFullChange({ material: full.material === option ? '' : option })}>{option}</Button>)}</div></div>
            <div className="grid gap-3 sm:grid-cols-2">
              {extraDefinitions.map((definition) => (
                <div key={definition.code}>
                  <Label>{definition.name}{definition.unit ? ` (${definition.unit})` : ''}</Label>
                  {definition.input_type === 'choice' ? (
                    <select className="mt-1 h-9 w-full rounded-md border bg-background px-3 text-sm" value={full.metrics[definition.code] ?? ''} onChange={(event) => props.onFullChange({ metrics: { ...full.metrics, [definition.code]: event.target.value } })}>
                      <option value="">Sem registo</option>{definition.options.map((option) => <option key={option} value={option}>{option}</option>)}
                    </select>
                  ) : <Input className="mt-1" type={definition.input_type === 'number' ? 'number' : 'text'} value={full.metrics[definition.code] ?? ''} onChange={(event) => props.onFullChange({ metrics: { ...full.metrics, [definition.code]: event.target.value } })} />}
                </div>
              ))}
            </div>
            <div><Label>Nota técnica</Label><Textarea className="mt-1" value={full.technical_note} onChange={(event) => props.onFullChange({ technical_note: event.target.value })} /></div>
            <div><Label>Aconselhamento</Label><Textarea className="mt-1" value={full.advice} onChange={(event) => props.onFullChange({ advice: event.target.value })} /></div>
            <DialogFooter><Button type="button" variant="outline" onClick={() => props.onFullChange({ open: false })}>Cancelar</Button><Button type="submit">Guardar registo</Button></DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </>
  );
}
