import { Button } from '@/Components/ui/button';
import type { CaisAthlete, CaisStatus, QuickType, RegisterMetric, ViewMode } from './types';

const STATUS_OPTIONS: Array<{ value: CaisStatus; label: string; short: string; active: string }> = [
  { value: 'presente', label: 'Presente', short: 'P', active: 'border-emerald-300 bg-emerald-100 text-emerald-800' },
  { value: 'ausente', label: 'Ausente', short: 'A', active: 'border-rose-300 bg-rose-100 text-rose-800' },
  { value: 'dispensado', label: 'Dispensado', short: 'D', active: 'border-amber-300 bg-amber-100 text-amber-800' },
  { value: 'atrasado', label: 'Atrasado', short: 'T', active: 'border-sky-300 bg-sky-100 text-sky-800' },
];

function metricChip(metric: RegisterMetric): string | null {
  if (!metric.value) return null;
  if (metric.code === 'heart_rate') return `FC: ${metric.value}${metric.unit ? ` ${metric.unit}` : ''}`;
  return `${metric.name}: ${metric.value}${metric.unit ? ` ${metric.unit}` : ''}`;
}

interface Props {
  athletes: CaisAthlete[];
  view: ViewMode;
  onPresence: (athlete: CaisAthlete, status: CaisStatus) => void;
  onQuick: (athlete: CaisAthlete, type: QuickType) => void;
  onFull: (athlete: CaisAthlete) => void;
}

export function CaisAthleteList({ athletes, view, onPresence, onQuick, onFull }: Props) {
  if (view === 'cards') {
    return (
      <div className="grid gap-2 p-2 md:grid-cols-2 xl:grid-cols-3">
        {athletes.map((athlete) => (
          <div key={athlete.id} className="rounded-lg border p-3">
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0">
                <div className="truncate whitespace-nowrap text-xs font-semibold">{athlete.name}</div>
                <div className="truncate whitespace-nowrap text-[10px] text-muted-foreground">{[athlete.lane, athlete.group].filter(Boolean).join(' · ') || 'Sem pista/grupo'}</div>
              </div>
              <span className="text-[10px] text-muted-foreground">{athlete.status}</span>
            </div>
            <div className="mt-2 flex gap-1">
              {STATUS_OPTIONS.map((option) => (
                <button key={option.value} type="button" title={option.label} onClick={() => onPresence(athlete, option.value)} className={`h-7 w-7 rounded-md border text-[10px] font-semibold ${athlete.status === option.value ? option.active : 'bg-white text-muted-foreground'}`}>{option.short}</button>
              ))}
            </div>
            <div className="mt-2 flex flex-wrap gap-1">
              <Button size="sm" variant="outline" className="h-7 text-[10px]" onClick={() => onQuick(athlete, 'behavior')}>Comportamento</Button>
              <Button size="sm" variant="outline" className="h-7 text-[10px]" onClick={() => onQuick(athlete, 'material')}>Material</Button>
              <Button size="sm" className="h-7 text-[10px]" onClick={() => onFull(athlete)}>+ Registo</Button>
            </div>
          </div>
        ))}
      </div>
    );
  }

  return (
    <div className="max-h-[calc(100vh-285px)] overflow-y-auto">
      {athletes.map((athlete) => (
        <div key={athlete.id} className="grid grid-cols-[minmax(240px,320px)_112px_minmax(300px,1fr)_18px] items-center gap-3 border-b px-3 py-2 last:border-b-0 hover:bg-muted/20">
          <div className="min-w-0">
            <div className="truncate whitespace-nowrap text-xs font-medium" title={athlete.name}>{athlete.name}</div>
            <div className="truncate whitespace-nowrap text-[10px] text-muted-foreground">{[athlete.lane, athlete.group].filter(Boolean).join(' · ') || 'Sem pista/grupo'}</div>
          </div>
          <div className="flex gap-1 whitespace-nowrap">
            {STATUS_OPTIONS.map((option) => (
              <button key={option.value} type="button" title={option.label} onClick={() => onPresence(athlete, option.value)} className={`inline-flex h-7 w-7 items-center justify-center rounded-md border text-[10px] font-semibold ${athlete.status === option.value ? option.active : 'bg-white text-muted-foreground hover:bg-muted'}`}>{option.short}</button>
            ))}
          </div>
          <div className="flex items-center justify-end gap-1 whitespace-nowrap">
            <Button size="sm" variant="outline" className={`h-7 px-2 text-[10px] ${athlete.register.behavior ? 'border-sky-200 bg-sky-50 text-sky-700' : ''}`} onClick={() => onQuick(athlete, 'behavior')}>{athlete.register.behavior ? `Comp.: ${athlete.register.behavior}` : 'Comportamento'}</Button>
            <Button size="sm" variant="outline" className={`h-7 px-2 text-[10px] ${athlete.register.material ? 'border-sky-200 bg-sky-50 text-sky-700' : ''}`} onClick={() => onQuick(athlete, 'material')}>{athlete.register.material ?? 'Material'}</Button>
            {(athlete.register.metrics ?? []).map((metric) => {
              const label = metricChip(metric);
              return label ? <Button key={metric.code} size="sm" variant="outline" className="h-7 border-sky-200 bg-sky-50 px-2 text-[10px] text-sky-700" onClick={() => onFull(athlete)}>{label}</Button> : null;
            })}
            <Button size="sm" variant="outline" className="h-7 px-2 text-[10px]" onClick={() => onFull(athlete)}>+ Registo</Button>
          </div>
          <button type="button" className="text-muted-foreground" onClick={() => onFull(athlete)}>›</button>
        </div>
      ))}
    </div>
  );
}
