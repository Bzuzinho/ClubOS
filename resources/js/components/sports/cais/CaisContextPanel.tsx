import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import type { SelectedSession } from './types';

export function CaisContextPanel({ session }: { session: SelectedSession }) {
  return (
    <Card className="h-fit">
      <CardHeader className="border-b py-3"><CardTitle className="text-sm">Contexto da sessão</CardTitle></CardHeader>
      <CardContent className="p-2">
        <Tabs defaultValue="treino">
          <TabsList className="grid w-full grid-cols-3">
            <TabsTrigger value="treino" className="text-xs">Treino</TabsTrigger>
            <TabsTrigger value="ocorrencias" className="text-xs">Ocorrências</TabsTrigger>
            <TabsTrigger value="notas" className="text-xs">Notas</TabsTrigger>
          </TabsList>
          <TabsContent value="treino" className="mt-2 space-y-2">
            {session.blocks.map((block) => (
              <div key={`${block.name}-${block.rounds}`} className="overflow-hidden rounded-md border">
                <div className="flex items-center justify-between border-b bg-muted/30 px-2 py-1.5 text-[11px] font-medium">
                  <span>{block.name}{block.rounds > 1 ? ` · ${block.rounds} rondas` : ''}</span><span>{block.volume_m.toLocaleString('pt-PT')} m</span>
                </div>
                {block.series.map((line) => (
                  <div key={line.id} className="grid grid-cols-[60px_1fr_42px_58px_58px] gap-1 border-b px-2 py-1.5 text-[10px] last:border-b-0">
                    <b>{line.repeticoes}×{line.distancia_m || ''}</b>
                    <span>{line.exercicio ?? line.estilo ?? 'Série'}</span>
                    <span className="text-muted-foreground">{line.zona ?? '—'}</span>
                    <span className="text-muted-foreground">{line.saida ?? line.intervalo ?? '—'}</span>
                    <span className="text-muted-foreground">{line.timing_mode === 'each_rep' ? 'cada rep.' : line.timing_mode === 'whole_series' ? 'série' : 'sem tempo'}</span>
                  </div>
                ))}
              </div>
            ))}
          </TabsContent>
          <TabsContent value="ocorrencias" className="mt-2 space-y-2">
            {session.occurrences.length === 0 ? <p className="py-6 text-center text-xs text-muted-foreground">Sem ocorrências registadas.</p> : session.occurrences.map((item) => (
              <div key={item.id} className="rounded-md border p-2 text-xs"><b>{item.type.replaceAll('_', ' ')}</b><p className="mt-1 text-[11px] text-muted-foreground">{item.reason}</p></div>
            ))}
          </TabsContent>
          <TabsContent value="notas" className="mt-2"><p className="text-xs text-muted-foreground">Notas individuais são registadas no atleta através de + Registo.</p></TabsContent>
        </Tabs>
      </CardContent>
    </Card>
  );
}
