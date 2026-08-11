import { User } from '@/types';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { DadosDesportivosTab } from './Sports/DadosDesportivosTab';
import { ConvocatoriasTab } from './Sports/ConvocatoriasTab';
import { RegistoPresencasTab } from './Sports/RegistoPresencasTab';
import { ResultadosTab } from './Sports/ResultadosTab';
import { TreinosTab } from './Sports/TreinosTab';
import { PlaneamentoTab } from './Sports/PlaneamentoTab';

interface NavigationContext {
  eventId?: string;
  convocatoriaId?: string;
  tab?: string;
}

interface SportsTabProps {
  user: User;
  onChange: (field: keyof User, value: any) => void;
  isAdmin: boolean;
  onNavigate?: (view: string, context?: NavigationContext) => void;
}

function normalizeType(value: unknown): string {
  return typeof value === 'string'
    ? value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '_')
    : '';
}

function isAthlete(user: User): boolean {
  const sportsUser = user as any;
  const canonical = Array.isArray(sportsUser.memberTypes) ? sportsUser.memberTypes : [];
  const legacy = Array.isArray(sportsUser.tipo_membro) ? sportsUser.tipo_membro : [];

  return [...canonical, ...legacy].map(normalizeType).includes('atleta');
}

export function SportsTab({ user, onChange, isAdmin, onNavigate }: SportsTabProps) {
  if (!isAthlete(user)) {
    return (
      <div className="p-12 text-center">
        <p className="text-muted-foreground">
          Esta secção está disponível apenas para membros do tipo Atleta.
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-1">
      <Tabs defaultValue="dados" className="space-y-1">
        <TabsList className="grid w-full h-auto grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 bg-slate-200 gap-1 p-1">
          <TabsTrigger value="dados" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">
            Perfil Desportivo
          </TabsTrigger>
          <TabsTrigger value="convocatorias" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">
            Convocatórias
          </TabsTrigger>
          <TabsTrigger value="presencas" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">
            Registo Presenças
          </TabsTrigger>
          <TabsTrigger value="resultados" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">
            Resultados
          </TabsTrigger>
          <TabsTrigger value="treinos" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">
            Treinos
          </TabsTrigger>
          <TabsTrigger value="disciplina" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">
            Disciplina
          </TabsTrigger>
        </TabsList>

        <TabsContent value="dados" className="mt-1 bg-white p-0 rounded-lg border border-white">
          <DadosDesportivosTab user={user} onChange={onChange} isAdmin={isAdmin} />
        </TabsContent>

        <TabsContent value="convocatorias" className="mt-1 bg-white p-0 rounded-lg border border-white">
          <ConvocatoriasTab user={user} onNavigate={onNavigate} />
        </TabsContent>

        <TabsContent value="presencas" className="mt-1 bg-white p-0 rounded-lg border border-white">
          <RegistoPresencasTab user={user} />
        </TabsContent>

        <TabsContent value="resultados" className="mt-1 bg-white p-0 rounded-lg border border-white">
          <ResultadosTab user={user} isAdmin={isAdmin} />
        </TabsContent>

        <TabsContent value="treinos" className="mt-1 bg-white p-0 rounded-lg border border-white">
          <TreinosTab user={user} />
        </TabsContent>

        <TabsContent value="disciplina" className="mt-1 bg-white p-0 rounded-lg border border-white">
          <PlaneamentoTab user={user} />
        </TabsContent>
      </Tabs>
    </div>
  );
}
