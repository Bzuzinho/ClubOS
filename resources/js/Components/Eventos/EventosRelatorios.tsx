import { useMemo, useState } from 'react';
import { Card } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/Components/ui/table';
import {
  DownloadSimple,
  Printer,
  ChartBar,
  Calendar,
  Users,
  Trophy,
  CheckCircle,
  ListChecks,
} from '@phosphor-icons/react';

interface EventosRelatoriosProps {
  events: any[];
  convocatorias?: any[];
  attendances?: any[];
  results?: any[];
  users?: any[];
  ageGroups?: any[];
  canViewConvocations?: boolean;
}

export function EventosRelatorios({
  events = [],
  convocatorias = [],
  attendances = [],
  results = [],
  users = [],
  ageGroups = [],
  canViewConvocations = false,
}: EventosRelatoriosProps) {
  const [activeTab, setActiveTab] = useState('geral');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');

  const normalizeStatus = (value: unknown): string => String(value ?? '').trim().toLowerCase();
  const sameId = (left: unknown, right: unknown): boolean => String(left ?? '') === String(right ?? '');

  const getEscaloesNames = (escaloes: string[] = []) => {
    if (!Array.isArray(escaloes) || escaloes.length === 0) {
      return '-';
    }

    const labels = escaloes.map((escalaoId) => {
      const ageGroup = ageGroups.find((group: any) => group.id === escalaoId);
      return ageGroup?.nome || escalaoId;
    });

    return labels.join(', ');
  };

  const reportEvents = useMemo(() => events.filter((event: any) => {
    const eventDate = String(event.data_inicio || '').slice(0, 10);
    return (!dateFrom || eventDate >= dateFrom) && (!dateTo || eventDate <= dateTo);
  }), [events, dateFrom, dateTo]);

  const reportEventIds = useMemo(
    () => new Set(reportEvents.map((event: any) => String(event.id))),
    [reportEvents]
  );
  const reportConvocations = useMemo(
    () => convocatorias.filter((item: any) => reportEventIds.has(String(item.evento_id))),
    [convocatorias, reportEventIds]
  );
  const reportAttendances = useMemo(
    () => attendances.filter((item: any) => reportEventIds.has(String(item.evento_id))),
    [attendances, reportEventIds]
  );
  const reportResults = useMemo(
    () => results.filter((item: any) => reportEventIds.has(String(item.evento_id))),
    [results, reportEventIds]
  );

  // Relatório Geral
  const relatorioGeral = useMemo(() => {
    const eventosById = new Map(reportEvents.map((event: any) => [String(event.id), event]));

    const totalEventos = reportEvents.length;
    const eventosAgendados = reportEvents.filter((e) => normalizeStatus(e.estado) === 'agendado').length;
    const eventosEmCurso = reportEvents.filter((e) => normalizeStatus(e.estado) === 'em_curso').length;
    const eventosConcluidos = reportEvents.filter((e) => normalizeStatus(e.estado) === 'concluido').length;
    const eventosCancelados = reportEvents.filter((e) => normalizeStatus(e.estado) === 'cancelado').length;

    const totalConvocatorias = reportConvocations.length;
    const convocatoriasConfirmadas = reportConvocations.filter(
      (c: any) => normalizeStatus(c.estado_confirmacao) === 'confirmado'
    ).length;
    const convocatoriasPendentes = reportConvocations.filter(
      (c: any) => normalizeStatus(c.estado_confirmacao) === 'pendente'
    ).length;
    const convocatoriasRecusadas = reportConvocations.filter(
      (c: any) => normalizeStatus(c.estado_confirmacao) === 'recusado'
    ).length;

    const totalPresencas = reportAttendances.length;
    const presentes = reportAttendances.filter((a: any) => normalizeStatus(a.estado) === 'presente').length;
    const ausentes = reportAttendances.filter((a: any) => normalizeStatus(a.estado) === 'ausente').length;
    const justificados = reportAttendances.filter((a: any) => normalizeStatus(a.estado) === 'justificado').length;
    const mediaPresencas =
      totalPresencas > 0 ? ((presentes / totalPresencas) * 100).toFixed(1) : 0;

    const totalResultados = reportResults.length;
    const resultadosConcluidos = reportResults.filter((result: any) => {
      const estadoDoEvento =
        normalizeStatus(result?.event?.estado) ||
        normalizeStatus(eventosById.get(String(result?.evento_id))?.estado);

      return estadoDoEvento === 'concluido';
    });

    const primeirosLugares = resultadosConcluidos.filter((r: any) => Number(r.classificacao) === 1).length;
    const segundosLugares = resultadosConcluidos.filter((r: any) => Number(r.classificacao) === 2).length;
    const terceirosLugares = resultadosConcluidos.filter((r: any) => Number(r.classificacao) === 3).length;

    // Distribuição por tipo
    const distribuicaoPorTipo = reportEvents.reduce((acc: any, event: any) => {
      const tipo = event.tipo || 'Outro';
      acc[tipo] = (acc[tipo] || 0) + 1;
      return acc;
    }, {});

    return {
      totalEventos,
      eventosAgendados,
      eventosEmCurso,
      eventosConcluidos,
      eventosCancelados,
      totalConvocatorias,
      convocatoriasConfirmadas,
      convocatoriasPendentes,
      convocatoriasRecusadas,
      totalPresencas,
      presentes,
      ausentes,
      justificados,
      mediaPresencas,
      totalResultados,
      primeirosLugares,
      segundosLugares,
      terceirosLugares,
      distribuicaoPorTipo,
    };
  }, [reportEvents, reportConvocations, reportAttendances, reportResults]);

  // Relatório por Evento
  const relatorioPorEvento = useMemo(() => {
    return reportEvents.map((event: any) => {
      const eventConvocatorias = reportConvocations.filter(
        (c: any) => sameId(c.evento_id, event.id)
      );
      const eventAttendances = reportAttendances.filter((a: any) => sameId(a.evento_id, event.id));
      const presentes = eventAttendances.filter((a: any) => normalizeStatus(a.estado) === 'presente').length;
      const ausentes = eventAttendances.filter((a: any) => normalizeStatus(a.estado) === 'ausente').length;
      const justificados = eventAttendances.filter(
        (a: any) => normalizeStatus(a.estado) === 'justificado'
      ).length;
      const taxaPresenca =
        eventAttendances.length > 0
          ? ((presentes / eventAttendances.length) * 100).toFixed(1)
          : 0;

      return {
        id: event.id,
        titulo: event.titulo,
        tipo: event.tipo,
        data: event.data_inicio,
        estado: event.estado,
        convocatorias: eventConvocatorias.length,
        presentes,
        ausentes,
        justificados,
        taxaPresenca,
      };
    });
  }, [reportEvents, reportConvocations, reportAttendances]);

  // Relatório por Atleta
  const relatorioPorAtleta = useMemo(() => {
    return users.map((user: any) => {
      const userConvocatorias = reportConvocations.filter((c: any) => sameId(c.user_id, user.id));
      const userAttendances = reportAttendances.filter((a: any) => sameId(a.user_id, user.id));
      const presentes = userAttendances.filter((a: any) => normalizeStatus(a.estado) === 'presente').length;
      const ausentes = userAttendances.filter((a: any) => normalizeStatus(a.estado) === 'ausente').length;
      const justificados = userAttendances.filter(
        (a: any) => normalizeStatus(a.estado) === 'justificado'
      ).length;
      const taxaPresenca =
        userAttendances.length > 0
          ? ((presentes / userAttendances.length) * 100).toFixed(1)
          : 0;

      const userResults = reportResults.filter((r: any) => sameId(r.user_id, user.id));
      const podios = userResults.filter(
        (r: any) => {
          if (!(Number(r.classificacao) >= 1 && Number(r.classificacao) <= 3)) {
            return false;
          }

          const estadoDoEvento =
            normalizeStatus(r?.event?.estado) ||
            normalizeStatus(reportEvents.find((event: any) => sameId(event.id, r.evento_id))?.estado);

          return estadoDoEvento === 'concluido';
        }
      ).length;

      return {
        id: user.id,
        nome: user.nome_completo,
        escalao: getEscaloesNames(Array.isArray(user.escalao) ? user.escalao : []),
        convocatorias: userConvocatorias.length,
        presentes,
        ausentes,
        justificados,
        taxaPresenca,
        resultados: userResults.length,
        podios,
      };
    });
  }, [users, reportConvocations, reportAttendances, reportResults, reportEvents, ageGroups]);

  const exportToCSV = (data: any[], filename: string) => {
    if (data.length === 0) return;

    const escapeCsv = (value: unknown) => `"${String(value ?? '').replace(/"/g, '""')}"`;
    const headers = Object.keys(data[0]).map(escapeCsv).join(',');
    const rows = data.map((row) => Object.values(row).map(escapeCsv).join(',')).join('\r\n');
    const csvContent = `\uFEFF${headers}\r\n${rows}`;

    const element = document.createElement('a');
    const objectUrl = URL.createObjectURL(new Blob([csvContent], { type: 'text/csv;charset=utf-8' }));
    element.setAttribute('href', objectUrl);
    element.setAttribute('download', filename);
    element.style.display = 'none';
    document.body.appendChild(element);
    element.click();
    document.body.removeChild(element);
    URL.revokeObjectURL(objectUrl);
  };

  const relatorioGeralCsv = useMemo(() => {
    const rows = [
      { indicador: 'Total de eventos', valor: relatorioGeral.totalEventos },
      { indicador: 'Eventos agendados', valor: relatorioGeral.eventosAgendados },
      { indicador: 'Eventos a decorrer', valor: relatorioGeral.eventosEmCurso },
      { indicador: 'Eventos concluídos', valor: relatorioGeral.eventosConcluidos },
      { indicador: 'Eventos cancelados', valor: relatorioGeral.eventosCancelados },
      { indicador: 'Presenças', valor: relatorioGeral.totalPresencas },
      { indicador: 'Taxa média de presença', valor: `${relatorioGeral.mediaPresencas}%` },
      { indicador: 'Resultados', valor: relatorioGeral.totalResultados },
    ];

    if (canViewConvocations) {
      rows.splice(5, 0, { indicador: 'Convocatórias', valor: relatorioGeral.totalConvocatorias });
    }

    return rows;
  }, [canViewConvocations, relatorioGeral]);

  return (
    <div className="space-y-4">
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <TabsList className="grid h-auto w-full grid-cols-3 gap-1 p-1 sm:w-auto">
            <TabsTrigger value="geral" className="px-2 py-2 text-xs sm:text-sm">
              <ChartBar size={16} className="mr-1 sm:mr-2" />
              Relatório Geral
            </TabsTrigger>
            <TabsTrigger value="evento" className="px-2 py-2 text-xs sm:text-sm">
              <Calendar size={16} className="mr-1 sm:mr-2" />
              Por Evento
            </TabsTrigger>
            <TabsTrigger value="atleta" className="px-2 py-2 text-xs sm:text-sm">
              <Users size={16} className="mr-1 sm:mr-2" />
              Por Atleta
            </TabsTrigger>
          </TabsList>

          <div className="flex w-full flex-wrap gap-2 sm:w-auto">
            <Input type="date" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} aria-label="Data inicial" className="h-9 w-[145px]" />
            <Input type="date" value={dateTo} onChange={(event) => setDateTo(event.target.value)} aria-label="Data final" className="h-9 w-[145px]" />
            <Button
              size="sm"
              variant="outline"
              className="flex-1 sm:flex-none"
              onClick={() => {
                if (activeTab === 'evento') {
                  exportToCSV(
                    canViewConvocations
                      ? relatorioPorEvento
                      : relatorioPorEvento.map(({ convocatorias: _convocatorias, ...row }) => row),
                    'relatorio-eventos.csv'
                  );
                } else if (activeTab === 'atleta') {
                  exportToCSV(
                    canViewConvocations
                      ? relatorioPorAtleta
                      : relatorioPorAtleta.map(({ convocatorias: _convocatorias, ...row }) => row),
                    'relatorio-atletas.csv'
                  );
                } else {
                  exportToCSV(relatorioGeralCsv, 'relatorio-geral-eventos.csv');
                }
              }}
            >
              <DownloadSimple size={16} className="mr-2" />
              Exportar CSV
            </Button>
            <Button
              size="sm"
              variant="outline"
              className="flex-1 sm:flex-none"
              onClick={() => window.print()}
            >
              <Printer size={16} className="mr-2" />
              Imprimir
            </Button>
          </div>
        </div>

        {/* Relatório Geral */}
        <TabsContent value="geral" className="space-y-4">
          <div className={`grid gap-4 md:grid-cols-2 ${canViewConvocations ? 'lg:grid-cols-4' : 'lg:grid-cols-3'}`}>
            {/* Card: Total de Eventos */}
            <Card className="p-4">
              <div className="flex items-center justify-between mb-2">
                <h3 className="text-sm font-medium text-muted-foreground">Total de Eventos</h3>
                <Calendar size={20} className="text-blue-600" />
              </div>
              <div className="text-3xl font-bold">{relatorioGeral.totalEventos}</div>
              <div className="mt-2 text-xs text-muted-foreground space-y-1">
                <div className="flex justify-between">
                  <span>Agendados:</span>
                  <span className="font-medium">{relatorioGeral.eventosAgendados}</span>
                </div>
                <div className="flex justify-between">
                  <span>A decorrer:</span>
                  <span className="font-medium">{relatorioGeral.eventosEmCurso}</span>
                </div>
                <div className="flex justify-between">
                  <span>Concluídos:</span>
                  <span className="font-medium">{relatorioGeral.eventosConcluidos}</span>
                </div>
                <div className="flex justify-between">
                  <span>Cancelados:</span>
                  <span className="font-medium">{relatorioGeral.eventosCancelados}</span>
                </div>
              </div>
            </Card>

            {/* Card: Total de Convocatórias */}
            {canViewConvocations ? <Card className="p-4">
              <div className="flex items-center justify-between mb-2">
                <h3 className="text-sm font-medium text-muted-foreground">
                  Total de Convocatórias
                </h3>
                <ListChecks size={20} className="text-purple-600" />
              </div>
              <div className="text-3xl font-bold">{relatorioGeral.totalConvocatorias}</div>
              <div className="mt-2 text-xs text-muted-foreground space-y-1">
                <div className="flex justify-between">
                  <span>Confirmadas:</span>
                  <span className="font-medium text-green-600">
                    {relatorioGeral.convocatoriasConfirmadas}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span>Pendentes:</span>
                  <span className="font-medium text-yellow-600">
                    {relatorioGeral.convocatoriasPendentes}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span>Recusadas:</span>
                  <span className="font-medium text-red-600">
                    {relatorioGeral.convocatoriasRecusadas}
                  </span>
                </div>
              </div>
            </Card> : null}

            {/* Card: Total de Presenças */}
            <Card className="p-4">
              <div className="flex items-center justify-between mb-2">
                <h3 className="text-sm font-medium text-muted-foreground">Total de Presenças</h3>
                <CheckCircle size={20} className="text-green-600" />
              </div>
              <div className="text-3xl font-bold">{relatorioGeral.totalPresencas}</div>
              <div className="mt-2 text-xs text-muted-foreground space-y-1">
                <div className="flex justify-between">
                  <span>Presentes:</span>
                  <span className="font-medium text-green-600">
                    {relatorioGeral.presentes}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span>Ausentes:</span>
                  <span className="font-medium text-red-600">
                    {relatorioGeral.ausentes}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span>Justificados:</span>
                  <span className="font-medium text-yellow-600">
                    {relatorioGeral.justificados}
                  </span>
                </div>
                <div className="flex justify-between pt-1 border-t">
                  <span>Média:</span>
                  <span className="font-medium">{relatorioGeral.mediaPresencas}%</span>
                </div>
              </div>
            </Card>

            {/* Card: Total de Resultados */}
            <Card className="p-4">
              <div className="flex items-center justify-between mb-2">
                <h3 className="text-sm font-medium text-muted-foreground">
                  Total de Resultados
                </h3>
                <Trophy size={20} className="text-yellow-600" />
              </div>
              <div className="text-3xl font-bold">{relatorioGeral.totalResultados}</div>
              <div className="mt-2 text-xs text-muted-foreground space-y-1">
                <div className="flex justify-between">
                  <span>🥇 1º Lugares:</span>
                  <span className="font-medium text-yellow-600">
                    {relatorioGeral.primeirosLugares}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span>🥈 2º Lugares:</span>
                  <span className="font-medium text-gray-500">
                    {relatorioGeral.segundosLugares}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span>🥉 3º Lugares:</span>
                  <span className="font-medium text-amber-600">
                    {relatorioGeral.terceirosLugares}
                  </span>
                </div>
              </div>
            </Card>
          </div>

          {/* Distribuição por Tipo */}
          <Card className="p-4">
            <h3 className="text-lg font-semibold mb-4">Distribuição por Tipo de Evento</h3>
            <div className="space-y-2">
              {Object.entries(relatorioGeral.distribuicaoPorTipo).map(([tipo, count]) => (
                <div key={tipo} className="flex items-center gap-4">
                  <div className="min-w-32">
                    <Badge variant="outline" className="capitalize">
                      {tipo}
                    </Badge>
                  </div>
                  <div className="flex-1">
                    <div className="h-8 bg-muted rounded-full overflow-hidden">
                      <div
                        className="h-full bg-primary"
                        style={{
                          width: `${(Number(count) / relatorioGeral.totalEventos) * 100}%`,
                        }}
                      />
                    </div>
                  </div>
                  <div className="min-w-16 text-right font-semibold">{String(count)}</div>
                </div>
              ))}
            </div>
          </Card>
        </TabsContent>

        {/* Relatório por Evento */}
        <TabsContent value="evento">
          <Card className="p-4">
            <h3 className="text-lg font-semibold mb-4">Resumo por Evento</h3>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Evento</TableHead>
                    <TableHead>Tipo</TableHead>
                    <TableHead>Data</TableHead>
                    <TableHead>Estado</TableHead>
                    {canViewConvocations ? <TableHead className="text-center">Convocatórias</TableHead> : null}
                    <TableHead className="text-center">Presentes</TableHead>
                    <TableHead className="text-center">Ausentes</TableHead>
                    <TableHead className="text-center">Justificados</TableHead>
                    <TableHead className="text-center">Taxa Presença</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {relatorioPorEvento.map((evento) => (
                    <TableRow key={evento.id}>
                      <TableCell className="font-medium">{evento.titulo}</TableCell>
                      <TableCell>
                        <Badge variant="outline" className="capitalize">
                          {evento.tipo}
                        </Badge>
                      </TableCell>
                      <TableCell>{evento.data}</TableCell>
                      <TableCell>
                        <Badge
                          variant={
                            evento.estado === 'concluido'
                              ? 'default'
                              : evento.estado === 'em_curso'
                              ? 'secondary'
                              : 'outline'
                          }
                        >
                          {evento.estado === 'em_curso' ? 'A decorrer' : evento.estado}
                        </Badge>
                      </TableCell>
                      {canViewConvocations ? <TableCell className="text-center">{evento.convocatorias}</TableCell> : null}
                      <TableCell className="text-center text-green-600 font-medium">
                        {evento.presentes}
                      </TableCell>
                      <TableCell className="text-center text-red-600 font-medium">
                        {evento.ausentes}
                      </TableCell>
                      <TableCell className="text-center text-yellow-600 font-medium">
                        {evento.justificados}
                      </TableCell>
                      <TableCell className="text-center font-semibold">
                        {evento.taxaPresenca}%
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </Card>
        </TabsContent>

        {/* Relatório por Atleta */}
        <TabsContent value="atleta">
          <Card className="p-4">
            <h3 className="text-lg font-semibold mb-4">Resumo por Atleta</h3>
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Atleta</TableHead>
                    <TableHead>Escalão</TableHead>
                    {canViewConvocations ? <TableHead className="text-center">Convocatórias</TableHead> : null}
                    <TableHead className="text-center">Presentes</TableHead>
                    <TableHead className="text-center">Ausentes</TableHead>
                    <TableHead className="text-center">Justificados</TableHead>
                    <TableHead className="text-center">Taxa Presença</TableHead>
                    <TableHead className="text-center">Resultados</TableHead>
                    <TableHead className="text-center">Pódios</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {relatorioPorAtleta.map((atleta) => (
                    <TableRow key={atleta.id}>
                      <TableCell className="font-medium">{atleta.nome}</TableCell>
                      <TableCell>{atleta.escalao}</TableCell>
                      {canViewConvocations ? <TableCell className="text-center">{atleta.convocatorias}</TableCell> : null}
                      <TableCell className="text-center text-green-600 font-medium">
                        {atleta.presentes}
                      </TableCell>
                      <TableCell className="text-center text-red-600 font-medium">
                        {atleta.ausentes}
                      </TableCell>
                      <TableCell className="text-center text-yellow-600 font-medium">
                        {atleta.justificados}
                      </TableCell>
                      <TableCell className="text-center font-semibold">
                        {atleta.taxaPresenca}%
                      </TableCell>
                      <TableCell className="text-center">{atleta.resultados}</TableCell>
                      <TableCell className="text-center">
                        {atleta.podios > 0 ? (
                          <Badge variant="secondary" className="bg-yellow-100 text-yellow-700">
                            {atleta.podios}
                          </Badge>
                        ) : (
                          '-'
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  );
}
