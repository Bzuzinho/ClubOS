import { useMemo, useState } from 'react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import type { Macrocycle, MesocyclePlan } from '@/Components/Desportivo/types';

interface CalendarTraining {
  id: string;
  numero_treino?: string | null;
  data: string;
  macrocycle_id?: string | null;
  mesociclo_id?: string | null;
  microciclo_id?: string | null;
}

interface Props {
  macrocycles: Macrocycle[];
  mesocycles?: MesocyclePlan[];
  microcycles?: Array<{ id: string; mesociclo_id?: string | null; macrocycle_id?: string | null }>;
  trainings?: CalendarTraining[];
}

const monthLabel = new Intl.DateTimeFormat('pt-PT', { month: 'short', year: 'numeric' });

function startOfMonth(value: Date): Date {
  return new Date(value.getFullYear(), value.getMonth(), 1);
}

function endOfMonth(value: Date): Date {
  return new Date(value.getFullYear(), value.getMonth() + 1, 0);
}

function addMonths(value: Date, months: number): Date {
  return new Date(value.getFullYear(), value.getMonth() + months, 1);
}

function addDays(date: Date, days: number): Date {
  const d = new Date(date);
  d.setDate(d.getDate() + days);
  return d;
}

function toDate(value?: string | null): Date | null {
  if (!value) return null;
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function daysBetween(start: Date, end: Date): number {
  const ms = end.getTime() - start.getTime();
  return Math.max(Math.floor(ms / (1000 * 60 * 60 * 24)), 0);
}

function barColor(type: string): string {
  if (type.toLowerCase().includes('compet')) return 'bg-rose-500/70';
  if (type.toLowerCase().includes('taper')) return 'bg-amber-500/70';
  if (type.toLowerCase().includes('espec')) return 'bg-indigo-500/70';
  if (type.toLowerCase().includes('transi')) return 'bg-slate-500/70';
  return 'bg-emerald-500/70';
}

function formatTrainingNumber(value?: string | null): string {
  if (!value) return '?';
  const match = value.match(/#?(\d+)/);
  return match ? match[1] : value;
}

function normalizeId(value?: string | null): string | null {
  if (value === null || value === undefined) return null;
  const normalized = String(value).trim();
  return normalized.length > 0 ? normalized : null;
}

export function TrainingsOverlayCalendar({ macrocycles, mesocycles = [], microcycles = [], trainings = [] }: Props) {
  const safeMacrocycles = Array.isArray(macrocycles) ? macrocycles : [];
  const safeMesocycles = Array.isArray(mesocycles) ? mesocycles : [];
  const safeMicrocycles = Array.isArray(microcycles) ? microcycles : [];
  const safeTrainings = Array.isArray(trainings) ? trainings : [];
  const [centerMonth, setCenterMonth] = useState<Date>(() => startOfMonth(new Date()));
  const today = new Date();

  const mesocycleByMicrocycle = useMemo(() => {
    const map = new Map<string, string>();
    safeMicrocycles.forEach((microcycle) => {
      if (!microcycle.id || !microcycle.mesociclo_id) return;
      map.set(microcycle.id, microcycle.mesociclo_id);
    });
    return map;
  }, [safeMicrocycles]);

  const macrocycleByMesocycle = useMemo(() => {
    const map = new Map<string, string>();
    safeMesocycles.forEach((mesocycle) => {
      const mesocycleId = normalizeId(mesocycle.id);
      const macrocycleId = normalizeId(mesocycle.macrociclo_id);
      if (!mesocycleId || !macrocycleId) return;
      map.set(mesocycleId, macrocycleId);
    });
    return map;
  }, [safeMesocycles]);

  const minDate = useMemo(() => startOfMonth(centerMonth), [centerMonth]);
  const maxDate = useMemo(() => endOfMonth(centerMonth), [centerMonth]);
  const totalDays = Math.max(daysBetween(minDate, maxDate), 1);
  const daysInMonth = daysBetween(minDate, maxDate) + 1;

  const dayMarkers = useMemo(() => {
    return Array.from({ length: daysInMonth }, (_, idx) => ({
      key: `day-${idx + 1}`,
      day: idx + 1,
      left: Math.min((idx / totalDays) * 100, 100),
    }));
  }, [daysInMonth, totalDays]);

  const monthStarts = Array.from({ length: 2 }, (_, idx) => {
    const monthDate = addMonths(minDate, idx);
    return {
      key: `start-${monthDate.getFullYear()}-${monthDate.getMonth()}`,
      left: Math.min((daysBetween(minDate, monthDate) / totalDays) * 100, 100),
    };
  });

  const weekSeparators = [] as Array<{ key: string; left: number }>;
  let cursor = new Date(minDate);
  let weekIdx = 0;
  while (cursor <= maxDate) {
    weekSeparators.push({
      key: `week-${weekIdx}`,
      left: (daysBetween(minDate, cursor) / totalDays) * 100,
    });
    cursor = addDays(cursor, 7);
    weekIdx += 1;
  }

  const todayInWindow = today >= minDate && today <= maxDate;
  const todayLeft = (daysBetween(minDate, today) / totalDays) * 100;

  const barPosition = (startDate?: string | null, endDate?: string | null) => {
    const startRaw = toDate(startDate) ?? minDate;
    const endRaw = toDate(endDate) ?? startRaw;

    const start = startRaw < minDate ? minDate : startRaw;
    const end = endRaw > maxDate ? maxDate : endRaw;

    if (end < minDate || start > maxDate) {
      return { left: -1, width: 0 };
    }

    const leftDays = daysBetween(minDate, start);
    const spanDays = Math.max(daysBetween(start, end) + 1, 1);

    return {
      left: Math.min((leftDays / totalDays) * 100, 100),
      width: Math.max((spanDays / totalDays) * 100, 1),
    };
  };

  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-sm">Mapa de Treinos (mensal)</CardTitle>
        <div className="mt-1 flex items-center justify-between">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-7 px-2"
            onClick={() => setCenterMonth((prev) => addMonths(prev, -1))}
          >
            ←
          </Button>
          <p className="text-xs font-medium capitalize text-muted-foreground">{monthLabel.format(centerMonth)}</p>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-7 px-2"
            onClick={() => setCenterMonth((prev) => addMonths(prev, 1))}
          >
            →
          </Button>
        </div>
      </CardHeader>
      <CardContent className="space-y-0 pt-0">
        <div className="relative h-2 overflow-hidden border-b border-border/40 -mt-2">
          {dayMarkers.map((marker) => (
            <span
              key={marker.key}
              className="absolute top-0 -translate-x-1/2 text-[7px] leading-none text-muted-foreground"
              style={{ left: `${marker.left}%` }}
            >
              {marker.day}
            </span>
          ))}
        </div>

        <div className="relative h-3 rounded bg-muted/40 overflow-hidden">
          {weekSeparators.map((week) => (
            <div
              key={week.key}
              className="absolute top-0 bottom-0 w-px bg-border/70"
              style={{ left: `${week.left}%` }}
            />
          ))}
          {monthStarts.map((month) => (
            <div
              key={month.key}
              className="absolute top-0 bottom-0 w-px bg-foreground/20"
              style={{ left: `${month.left}%` }}
            />
          ))}
          {todayInWindow && (
            <>
              <div
                className="absolute -top-5 -translate-x-1/2 rounded bg-red-500 px-1.5 py-0.5 text-[9px] font-medium text-white z-20"
                style={{ left: `${todayLeft}%` }}
              >
                Hoje
              </div>
              <div
                className="absolute top-0 bottom-0 w-0.5 bg-red-500 z-10"
                style={{ left: `${todayLeft}%` }}
              />
            </>
          )}
        </div>

        <div className="space-y-2">
          {safeMacrocycles.length === 0 && (
            <p className="text-xs text-muted-foreground">Sem macrociclos para mostrar.</p>
          )}

          {safeMacrocycles.map((macro) => {
            const macroBar = barPosition(macro.data_inicio, macro.data_fim);
            const macroId = normalizeId(macro.id);
            const relatedMesocycles = safeMesocycles.filter((meso) => normalizeId(meso.macrociclo_id) === macroId);

            if (macroBar.width <= 0) return null;

            return (
              <div key={macro.id} className="space-y-1">
                <div className="text-[11px] font-medium truncate">{macro.nome}</div>
                <div className="h-3 rounded bg-muted relative overflow-hidden">
                  {weekSeparators.map((week) => (
                    <div
                      key={`${macro.id}-${week.key}`}
                      className="absolute top-0 bottom-0 w-px bg-border/60"
                      style={{ left: `${week.left}%` }}
                    />
                  ))}
                  {todayInWindow && (
                    <div
                      className="absolute top-0 bottom-0 w-0.5 bg-red-500/90 z-10"
                      style={{ left: `${todayLeft}%` }}
                    />
                  )}
                  <div
                    className={`h-3 rounded absolute top-0 ${barColor(macro.tipo)}`}
                    style={{
                      left: `${macroBar.left}%`,
                      width: `${macroBar.width}%`,
                    }}
                  />
                </div>

                {relatedMesocycles.map((meso) => {
                  const mesoBar = barPosition(meso.data_inicio, meso.data_fim);
                  const mesoId = normalizeId(meso.id);

                  // Only show trainings explicitly assigned to this mesocycle
                  const markersOnBar = safeTrainings
                    .filter((training) => {
                      const trainingDate = toDate(training.data);
                      if (!trainingDate || !mesoId) return false;
                      if (trainingDate < minDate || trainingDate > maxDate) return false;
                      const resolvedMesoId = normalizeId(training.mesociclo_id)
                        ?? (training.microciclo_id ? (normalizeId(mesocycleByMicrocycle.get(training.microciclo_id)) ?? null) : null);
                      return resolvedMesoId === mesoId;
                    })
                    .map((training) => {
                      const trainingDate = toDate(training.data);
                      if (!trainingDate) return null;
                      return {
                        key: training.id,
                        left: Math.min((daysBetween(minDate, trainingDate) / totalDays) * 100, 100),
                        number: formatTrainingNumber(training.numero_treino),
                      };
                    })
                    .filter((marker): marker is { key: string; left: number; number: string } => marker !== null);

                  if (mesoBar.width <= 0) return null;

                  return (
                    <div key={meso.id} className="pl-3">
                      <div className="text-[10px] text-muted-foreground truncate">{meso.nome}</div>
                      <div className="relative h-6 overflow-hidden">
                        {weekSeparators.map((week) => (
                          <div
                            key={`${meso.id}-${week.key}`}
                            className="absolute top-0 bottom-0 w-px bg-border/50"
                            style={{ left: `${week.left}%` }}
                          />
                        ))}
                        {todayInWindow && (
                          <div
                            className="absolute top-0 bottom-0 w-0.5 bg-red-500/90 z-10"
                            style={{ left: `${todayLeft}%` }}
                          />
                        )}
                        <div
                          className="absolute top-3 h-2 rounded bg-blue-500/70"
                          style={{
                            left: `${mesoBar.left}%`,
                            width: `${mesoBar.width}%`,
                          }}
                        />
                        {markersOnBar.map((marker) => (
                          <div
                            key={marker.key}
                            className="absolute top-0 z-20 -translate-x-1/2"
                            style={{ left: `${marker.left}%` }}
                          >
                            <span className="inline-flex rounded border border-blue-600 bg-white/95 px-1 text-[9px] leading-none font-semibold text-blue-700 shadow-sm">
                              #{marker.number}
                            </span>
                          </div>
                        ))}
                      </div>
                    </div>
                  );
                })}

                {relatedMesocycles.length === 0 && (
                  <p className="pl-3 text-[10px] text-muted-foreground">Sem mesociclos neste macrociclo.</p>
                )}
              </div>
            );
          })}
        </div>

        {/* Treinos sem macrociclo nem mesociclo — posicionados por data */}
        {(() => {
          const unassigned = safeTrainings.filter((training) => {
            const trainingDate = toDate(training.data);
            if (!trainingDate) return false;
            if (trainingDate < minDate || trainingDate > maxDate) return false;
            const hasMacrocycle = !!normalizeId(training.macrocycle_id);
            const resolvedMesoId = normalizeId(training.mesociclo_id)
              ?? (training.microciclo_id ? (normalizeId(mesocycleByMicrocycle.get(training.microciclo_id)) ?? null) : null);
            return !hasMacrocycle && !resolvedMesoId;
          });

          if (unassigned.length === 0) return null;

          return (
            <div className="mt-3 border-t border-border/40 pt-2">
              <div className="text-[10px] text-muted-foreground mb-1">Treinos sem ciclo</div>
              <div className="relative h-6 overflow-hidden">
                {weekSeparators.map((week) => (
                  <div
                    key={`unassigned-${week.key}`}
                    className="absolute top-0 bottom-0 w-px bg-border/30"
                    style={{ left: `${week.left}%` }}
                  />
                ))}
                {todayInWindow && (
                  <div
                    className="absolute top-0 bottom-0 w-0.5 bg-red-500/80 z-10"
                    style={{ left: `${todayLeft}%` }}
                  />
                )}
                {unassigned.map((training) => {
                  const trainingDate = toDate(training.data);
                  if (!trainingDate) return null;
                  const left = Math.min((daysBetween(minDate, trainingDate) / totalDays) * 100, 100);
                  return (
                    <div
                      key={training.id}
                      className="absolute top-0 -translate-x-1/2"
                      style={{ left: `${left}%` }}
                    >
                      <span className="inline-flex rounded border border-slate-400 bg-slate-50 px-1 text-[9px] font-medium text-slate-600">
                        #{formatTrainingNumber(training.numero_treino)}
                      </span>
                    </div>
                  );
                })}
              </div>
            </div>
          );
        })()}
      </CardContent>
    </Card>
  );
}
