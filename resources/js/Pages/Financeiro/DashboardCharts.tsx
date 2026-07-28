import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
  PieChart,
  Pie,
  Cell,
  LineChart,
  Line,
} from 'recharts';
import { ReactNode, useEffect, useRef, useState } from 'react';
import { Card } from '@/Components/ui/card';

interface DashboardChartsProps {
  tiposFaturaData: Array<{ name: string; value: number }>;
  monthlyData: Array<{ mes: string; receitas: number; despesas: number }>;
  centrosCustoData: Array<{ nome: string; despesas: number; receitas: number }>;
  colors: string[];
}

function ChartMountGuard({
  className,
  children,
}: {
  className: string;
  children: ReactNode;
}) {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const [isReady, setIsReady] = useState(false);

  useEffect(() => {
    const element = containerRef.current;

    if (!element) {
      return;
    }

    const updateSize = () => {
      const nextReady = element.clientWidth > 0 && element.clientHeight > 0;
      setIsReady((current) => (current === nextReady ? current : nextReady));
    };

    updateSize();

    if (typeof ResizeObserver === 'undefined') {
      setIsReady(true);
      return;
    }

    const observer = new ResizeObserver(() => updateSize());
    observer.observe(element);

    return () => observer.disconnect();
  }, []);

  return (
    <div ref={containerRef} className={`${className} min-w-0`}>
      {isReady ? children : <div className="h-full w-full" />}
    </div>
  );
}

export default function DashboardCharts({
  tiposFaturaData,
  monthlyData,
  centrosCustoData,
  colors,
}: DashboardChartsProps) {
  return (
    <>
      <div className="grid gap-2 sm:gap-3 grid-cols-1 lg:grid-cols-2">
        <Card className="p-2 sm:p-2.5">
          <h3 className="font-semibold text-xs sm:text-sm mb-1.5">Distribuição de Faturas por Tipo</h3>
          <ChartMountGuard className="h-[120px] sm:h-[140px]">
            {tiposFaturaData.length > 0 ? (
              <ResponsiveContainer width="100%" height="100%" minWidth={0} minHeight={120} initialDimension={{ width: 1, height: 120 }}>
                <PieChart>
                  <Pie
                    data={tiposFaturaData}
                    cx="50%"
                    cy="50%"
                    labelLine={false}
                    label={(entry) => entry.name}
                    outerRadius={45}
                    fill="#8884d8"
                    dataKey="value"
                  >
                    {tiposFaturaData.map((entry, index) => (
                      <Cell key={`${entry.name}-${index}`} fill={colors[index % colors.length]} />
                    ))}
                  </Pie>
                  <Tooltip />
                </PieChart>
              </ResponsiveContainer>
            ) : (
              <div className="h-full flex items-center justify-center text-muted-foreground text-xs">
                Sem dados disponíveis
              </div>
            )}
          </ChartMountGuard>
        </Card>
      </div>

      <div className="grid gap-2 sm:gap-3 grid-cols-1 lg:grid-cols-2">
        <Card className="p-2 sm:p-2.5">
          <h3 className="font-semibold text-xs sm:text-sm mb-1.5">Evolução Mensal (últimos 6 meses)</h3>
          <ChartMountGuard className="h-[180px] sm:h-[200px]">
            <ResponsiveContainer width="100%" height="100%" minWidth={0} minHeight={180} initialDimension={{ width: 1, height: 180 }}>
              <LineChart data={monthlyData}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="mes" tick={{ fontSize: 10 }} />
                <YAxis tick={{ fontSize: 10 }} />
                <Tooltip formatter={(value) => `€${Number(value).toFixed(2)}`} contentStyle={{ fontSize: '11px' }} />
                <Legend wrapperStyle={{ fontSize: '10px' }} />
                <Line type="monotone" dataKey="receitas" stroke="oklch(0.55 0.15 150)" strokeWidth={2} name="Receitas" />
                <Line type="monotone" dataKey="despesas" stroke="oklch(0.55 0.22 25)" strokeWidth={2} name="Despesas" />
              </LineChart>
            </ResponsiveContainer>
          </ChartMountGuard>
        </Card>

        <Card className="p-2 sm:p-2.5">
          <h3 className="font-semibold text-xs sm:text-sm mb-1.5">Despesas e Receitas por Centro de Custo</h3>
          <ChartMountGuard className="h-[180px] sm:h-[200px]">
            {centrosCustoData.length > 0 ? (
              <ResponsiveContainer width="100%" height="100%" minWidth={0} minHeight={180} initialDimension={{ width: 1, height: 180 }}>
                <BarChart data={centrosCustoData}>
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="nome" tick={{ fontSize: 10 }} />
                  <YAxis tick={{ fontSize: 10 }} />
                  <Tooltip formatter={(value) => `€${Number(value).toFixed(2)}`} contentStyle={{ fontSize: '11px' }} />
                  <Legend wrapperStyle={{ fontSize: '10px' }} />
                  <Bar dataKey="receitas" fill="oklch(0.55 0.15 150)" name="Receitas" />
                  <Bar dataKey="despesas" fill="oklch(0.55 0.22 25)" name="Despesas" />
                </BarChart>
              </ResponsiveContainer>
            ) : (
              <div className="h-full flex items-center justify-center text-muted-foreground text-xs">
                Nenhum centro de custo configurado
              </div>
            )}
          </ChartMountGuard>
        </Card>
      </div>
    </>
  );
}
