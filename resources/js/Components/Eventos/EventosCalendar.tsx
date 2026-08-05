import { useEffect, useMemo, useState } from 'react';
import { Card } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import {
  startOfMonth,
  endOfMonth,
  eachDayOfInterval,
  isSameMonth,
  isSameDay,
  addMonths,
  subMonths,
  startOfWeek,
  endOfWeek,
  format,
  isWithinInterval,
  startOfDay,
  endOfDay,
} from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { CalendarBlank, CaretLeft, CaretRight, Clock, MapPin } from '@phosphor-icons/react';

interface Event {
  id: string;
  titulo: string;
  data_inicio: string;
  data_fim?: string;
  hora_inicio?: string;
  local: string;
  tipo: string;
  estado: string;
  escaloes_elegiveis?: string[];
}

interface AgeGroup {
  id: string;
  nome: string;
}

interface EventosCalendarProps {
  events: Event[];
  ageGroups?: AgeGroup[];
  isActive?: boolean;
}

export function EventosCalendar({ events = [], ageGroups = [], isActive = true }: EventosCalendarProps) {
  const [currentDate, setCurrentDate] = useState(new Date());
  const [typeFilter, setTypeFilter] = useState('todos');
  const [selectedDay, setSelectedDay] = useState<Date | null>(new Date());

  useEffect(() => {
    if (isActive) {
      const today = new Date();
      setCurrentDate(today);
      setSelectedDay(today);
    }
  }, [isActive]);

  const ageGroupLabelById = useMemo(() => {
    return new Map(ageGroups.map((group) => [group.id, group.nome]));
  }, [ageGroups]);

  const monthStart = startOfMonth(currentDate);
  const monthEnd = endOfMonth(currentDate);
  const calendarStart = startOfWeek(monthStart, { weekStartsOn: 1 });
  const calendarEnd = endOfWeek(monthEnd, { weekStartsOn: 1 });

  const daysInCalendar = eachDayOfInterval({
    start: calendarStart,
    end: calendarEnd,
  });

  const filteredEvents = useMemo(() => {
    return events.filter((event) => {
      const matchesType = typeFilter === 'todos' || event.tipo === typeFilter;
      return matchesType && event.estado !== 'cancelado';
    });
  }, [events, typeFilter]);

  const getEventsForDay = (day: Date) => {
    return filteredEvents.filter((event) => {
      const start = startOfDay(new Date(event.data_inicio));
      const end = endOfDay(new Date(event.data_fim || event.data_inicio));

      return isWithinInterval(day, { start, end });
    });
  };

  const getEventTypeClass = (tipo: string) => {
    switch (tipo) {
      case 'prova':
        return 'bg-red-100 text-red-700';
      case 'estagio':
      case 'estágio':
      case 'competicao':
      case 'competição':
        return 'bg-blue-100 text-blue-700';
      case 'reuniao':
      case 'reunião':
        return 'bg-amber-100 text-amber-700';
      case 'evento_interno':
        return 'bg-purple-100 text-purple-700';
      case 'treino':
        return 'bg-emerald-100 text-emerald-700';
      default:
        return 'bg-gray-100 text-gray-700';
    }
  };

  const getEventTypeLabel = (tipo: string) => {
    switch (tipo) {
      case 'prova':
        return 'Prova';
      case 'estagio':
      case 'estágio':
        return 'Estágio';
      case 'reuniao':
      case 'reunião':
        return 'Reunião';
      case 'evento_interno':
        return 'Evento Interno';
      case 'treino':
        return 'Treino';
      case 'competicao':
      case 'competição':
        return 'Competição';
      default:
        return (tipo || 'outro')
          .replace(/_/g, ' ')
          .replace(/\b\w/g, (letter) => letter.toUpperCase());
    }
  };

  const getEventTypeDotClass = (tipo: string) => {
    switch (tipo) {
      case 'prova':
        return 'bg-red-500';
      case 'estagio':
      case 'estágio':
      case 'competicao':
      case 'competição':
        return 'bg-blue-500';
      case 'reuniao':
      case 'reunião':
        return 'bg-amber-500';
      case 'evento_interno':
        return 'bg-purple-500';
      case 'treino':
        return 'bg-emerald-500';
      default:
        return 'bg-gray-500';
    }
  };

  const eventTypeOptions = useMemo(() => {
    return Array.from(new Set(events.map((event) => event.tipo).filter(Boolean)))
      .sort((left, right) => getEventTypeLabel(left).localeCompare(getEventTypeLabel(right)))
      .map((value) => ({ value, label: getEventTypeLabel(value) }));
  }, [events]);

  const weekDays = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom'];
  const dayEvents = selectedDay ? getEventsForDay(selectedDay) : [];

  const legendItems = eventTypeOptions.map((option) => ({
    key: option.value,
    label: option.label,
    color: getEventTypeDotClass(option.value),
  }));

  return (
    <div className="space-y-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-wrap items-center gap-2">
          <Button
            size="icon"
            variant="outline"
            className="h-8 w-8"
            onClick={() => setCurrentDate(subMonths(currentDate, 1))}
          >
            <CaretLeft size={14} />
          </Button>
          <h2 className="min-w-[170px] text-center text-2xl font-semibold">
            {format(currentDate, 'MMMM yyyy', { locale: ptBR })}
          </h2>
          <Button
            size="sm"
            variant="outline"
            className="h-8"
            onClick={() => setCurrentDate(new Date())}
          >
            Hoje
          </Button>
          <Button
            size="icon"
            variant="outline"
            className="h-8 w-8"
            onClick={() => setCurrentDate(addMonths(currentDate, 1))}
          >
            <CaretRight size={14} />
          </Button>
        </div>

        <label className="flex w-full sm:w-[180px] items-center">
          <span className="sr-only">Tipo de Evento</span>
          <select
            value={typeFilter}
            onChange={(event) => setTypeFilter(event.target.value)}
            className="h-8 w-full rounded-md border border-input bg-white px-3 text-sm shadow-sm outline-none focus:ring-2 focus:ring-ring"
          >
            <option value="todos">Todos os Tipos</option>
            {eventTypeOptions.map((option) => (
              <option key={option.value} value={option.value}>{option.label}</option>
            ))}
          </select>
        </label>
      </div>

      <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
        <Card className="lg:col-span-2 p-3">
          <div className="grid grid-cols-7 gap-2">
            {weekDays.map((day) => (
              <div key={day} className="py-2 text-center text-xs font-semibold text-muted-foreground">
                {day}
              </div>
            ))}

            {daysInCalendar.map((day) => {
              const dayItems = getEventsForDay(day);
              const isCurrentMonth = isSameMonth(day, currentDate);
              const isToday = isSameDay(day, new Date());
              const isSelected = selectedDay ? isSameDay(day, selectedDay) : false;

              return (
                <button
                  key={day.toISOString()}
                  onClick={() => setSelectedDay(day)}
                  className={`min-h-[88px] rounded-lg border p-1.5 text-left transition ${
                    !isCurrentMonth ? 'bg-muted/40 text-muted-foreground' : 'bg-background'
                  } ${isToday ? 'border-primary' : 'border-border'} ${
                    isSelected ? 'ring-1 ring-primary' : 'hover:bg-muted/40'
                  }`}
                >
                  <div className="mb-1 text-xs font-medium">{format(day, 'd')}</div>
                  <div className="space-y-1">
                    {dayItems.slice(0, 2).map((event) => (
                      <div
                        key={event.id}
                        className={`rounded px-1.5 py-1 text-[10px] font-medium leading-tight whitespace-normal break-words ${getEventTypeClass(event.tipo)}`}
                        title={event.titulo}
                      >
                        {event.titulo}
                      </div>
                    ))}
                    {dayItems.length > 2 && (
                      <div className="px-1 text-[10px] text-muted-foreground">
                        +{dayItems.length - 2} mais
                      </div>
                    )}
                  </div>
                </button>
              );
            })}
          </div>
        </Card>

        <Card className="p-3">
          <div className="space-y-3">
            <h3 className="text-sm font-semibold">
              {selectedDay ? format(selectedDay, "d 'de' MMMM", { locale: ptBR }) : 'Selecione um dia'}
            </h3>

            {selectedDay && dayEvents.length > 0 ? (
              <div className="space-y-2">
                {dayEvents.map((event) => (
                  <Card key={event.id} className="p-2.5">
                    <div className="space-y-1.5">
                      <div className="flex items-center justify-between gap-2">
                        <p className="text-sm font-medium truncate">{event.titulo}</p>
                        <Badge variant="secondary" className="text-[10px]">
                          {getEventTypeLabel(event.tipo)}
                        </Badge>
                      </div>

                      {event.hora_inicio && (
                        <div className="flex items-center gap-1 text-xs text-muted-foreground">
                          <Clock size={12} />
                          <span>{event.hora_inicio}</span>
                        </div>
                      )}

                      {event.local && (
                        <div className="flex items-center gap-1 text-xs text-muted-foreground">
                          <MapPin size={12} />
                          <span className="truncate">{event.local}</span>
                        </div>
                      )}

                      {event.tipo === 'treino' && Array.isArray(event.escaloes_elegiveis) && event.escaloes_elegiveis.length > 0 && (
                        <div className="flex flex-wrap gap-1 pt-0.5">
                          {event.escaloes_elegiveis.map((escalao) => (
                            <Badge key={escalao} variant="outline" className="text-[10px]">
                              {ageGroupLabelById.get(escalao) ?? escalao}
                            </Badge>
                          ))}
                        </div>
                      )}
                    </div>
                  </Card>
                ))}
              </div>
            ) : (
              <div className="flex min-h-[220px] flex-col items-center justify-center text-center text-muted-foreground">
                <CalendarBlank size={28} className="mb-2" />
                <p className="text-sm">Clique num dia para ver os eventos</p>
              </div>
            )}
          </div>
        </Card>
      </div>

      <Card className="p-3">
        <h4 className="mb-2 text-sm font-semibold">Legenda</h4>
        <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
          {legendItems.map((item) => (
            <div key={item.key} className="flex items-center gap-1.5">
              <span className={`h-2.5 w-2.5 rounded-full ${item.color}`} />
              <span>{item.label}</span>
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}
