import type { ComponentProps } from 'react';
import { DesportivoTreinosTab } from '@/Components/Desportivo/DesportivoTreinosTab';
import { SectionTitle } from '@/components/sports/shared';

type TrainingsTabProps = ComponentProps<typeof DesportivoTreinosTab>;

export function TrainingsTab(props: TrainingsTabProps) {
  return (
    <div className="space-y-3">
      <SectionTitle
        title="Treinos"
        subtitle="Sessões concretas: calendário, agendamento, atletas e operação. Os planos reutilizáveis vivem na Biblioteca."
      />
      <div className="training-sessions-only">
        <style>{`
          .training-sessions-only > div > :first-child { display: none; }
          .training-sessions-only > div > :nth-child(2) > :first-child { display: none; }
          @media (min-width: 1024px) {
            .training-sessions-only > div > :nth-child(2) { grid-template-columns: minmax(0, 1fr) !important; }
          }
        `}</style>
        <DesportivoTreinosTab {...props} />
      </div>
    </div>
  );
}
