import { Head } from '@inertiajs/react';
import { Suspense, lazy, useEffect, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { moduleTabbedContentClass, moduleTabsClass, moduleViewportClass } from '@/lib/module-layout';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { ChartLineUp, Receipt, ArrowsDownUp, Bank, ChartBar, FileText } from '@phosphor-icons/react';
import {
  Fatura,
  FaturaItem,
  Movimento,
  MovimentoItem,
  LancamentoFinanceiro,
  ExtratoBancario,
  ConciliacaoMapa,
  CentroCusto,
  User,
  Product,
  MonthlyFee,
  AgeGroup,
  InvoiceType,
} from './types';

const DashboardTab = lazy(() => import('./DashboardTab').then((module) => ({ default: module.DashboardTab })));
const FaturasTab = lazy(() => import('./FaturasTab').then((module) => ({ default: module.FaturasTab })));
const MovimentosTab = lazy(() => import('./MovimentosTab').then((module) => ({ default: module.MovimentosTab })));
const BancoTab = lazy(() => import('./BancoTab').then((module) => ({ default: module.BancoTab })));
const RelatoriosTab = lazy(() => import('./RelatoriosTab').then((module) => ({ default: module.RelatoriosTab })));
const FiscalDocumentsTab = lazy(() => import('./FiscalDocumentsTab').then((module) => ({ default: module.FiscalDocumentsTab })));

function TabFallback() {
  return <div className="py-8 text-sm text-muted-foreground">A carregar...</div>;
}

interface Props {
  faturas: Fatura[];
  faturaItens: FaturaItem[];
  movimentos: Movimento[];
  movimentoItens: MovimentoItem[];
  lancamentos: LancamentoFinanceiro[];
  extratos: ExtratoBancario[];
  conciliacoes: ConciliacaoMapa[];
  centrosCusto: CentroCusto[];
  users: User[];
  products: Product[];
  mensalidades: MonthlyFee[];
  ageGroups: AgeGroup[];
  invoiceTypes: InvoiceType[];
}

export default function FinanceiroIndex({
  faturas,
  faturaItens,
  movimentos,
  movimentoItens,
  lancamentos,
  extratos,
  conciliacoes,
  centrosCusto,
  users,
  products,
  mensalidades,
  ageGroups,
  invoiceTypes,
}: Props) {
  const [activeTab, setActiveTab] = useState('dashboard');
  const [faturasState, setFaturas] = useState<Fatura[]>(faturas || []);
  const [faturaItensState, setFaturaItens] = useState<FaturaItem[]>(faturaItens || []);
  const [movimentosState, setMovimentos] = useState<Movimento[]>(movimentos || []);
  const [movimentoItensState, setMovimentoItens] = useState<MovimentoItem[]>(movimentoItens || []);
  const [lancamentosState, setLancamentos] = useState<LancamentoFinanceiro[]>(lancamentos || []);
  const [extratosState, setExtratos] = useState<ExtratoBancario[]>(extratos || []);
  const [conciliacoesState, setConciliacoes] = useState<ConciliacaoMapa[]>(conciliacoes || []);
  const [productsState, setProducts] = useState<Product[]>(products || []);

  useEffect(() => {
    setFaturas(faturas || []);
  }, [faturas]);

  useEffect(() => {
    setFaturaItens(faturaItens || []);
  }, [faturaItens]);

  useEffect(() => {
    setMovimentos(movimentos || []);
  }, [movimentos]);

  useEffect(() => {
    setMovimentoItens(movimentoItens || []);
  }, [movimentoItens]);

  useEffect(() => {
    setLancamentos(lancamentos || []);
  }, [lancamentos]);

  useEffect(() => {
    setExtratos(extratos || []);
  }, [extratos]);

  useEffect(() => {
    setConciliacoes(conciliacoes || []);
  }, [conciliacoes]);

  useEffect(() => {
    setProducts(products || []);
  }, [products]);

  return (
    <AuthenticatedLayout
      fullWidth
      header={
        <div>
          <h1 className="text-lg sm:text-xl font-semibold tracking-tight">Modulo Financeiro</h1>
          <p className="text-muted-foreground text-xs mt-0.5">Gestao completa das financas do clube</p>
        </div>
      }
    >
      <Head title="Gestao Financeira" />

      <div className={moduleViewportClass}>
        <Tabs value={activeTab} onValueChange={setActiveTab} className={moduleTabsClass}>
          <div className="w-full">
            <TabsList className="grid h-auto w-full shrink-0 grid-cols-2 gap-1 p-1 text-[11px] sm:h-9 sm:grid-cols-6 sm:text-xs">
              <TabsTrigger value="dashboard" className="flex h-8 items-center justify-center gap-1 px-2 py-1 text-[11px] leading-none sm:h-7 sm:text-xs">
                <ChartLineUp size={14} />
                <span>Dashboard</span>
              </TabsTrigger>
              <TabsTrigger value="faturas" className="flex h-8 items-center justify-center gap-1 px-2 py-1 text-[11px] leading-none sm:h-7 sm:text-xs">
                <Receipt size={14} />
                <span>Mensalidades</span>
              </TabsTrigger>
              <TabsTrigger value="movimentos" className="flex h-8 items-center justify-center gap-1 px-2 py-1 text-[11px] leading-none sm:h-7 sm:text-xs">
                <ArrowsDownUp size={14} />
                <span>Movimentos</span>
              </TabsTrigger>
              <TabsTrigger value="banco" className="flex h-8 items-center justify-center gap-1 px-2 py-1 text-[11px] leading-none sm:h-7 sm:text-xs">
                <Bank size={14} />
                <span>Banco</span>
              </TabsTrigger>
              <TabsTrigger value="relatorios" className="flex h-8 items-center justify-center gap-1 px-2 py-1 text-[11px] leading-none sm:h-7 sm:text-xs">
                <ChartBar size={14} />
                <span>Relatorios</span>
              </TabsTrigger>
              <TabsTrigger value="emissao-fiscal" className="flex h-8 items-center justify-center gap-1 px-2 py-1 text-[11px] leading-none sm:h-7 sm:text-xs">
                <FileText size={14} />
                <span>Emissao Fiscal</span>
              </TabsTrigger>
            </TabsList>
          </div>

          <TabsContent value="dashboard" className={moduleTabbedContentClass}>
            {activeTab === 'dashboard' ? (
              <Suspense fallback={<TabFallback />}>
                <DashboardTab
                  faturas={faturasState}
                  lancamentos={lancamentosState}
                  movimentos={movimentosState}
                  extratos={extratosState}
                  centrosCusto={centrosCusto || []}
                />
              </Suspense>
            ) : null}
          </TabsContent>

          <TabsContent value="faturas" className={moduleTabbedContentClass}>
            {activeTab === 'faturas' ? (
              <Suspense fallback={<TabFallback />}>
                <FaturasTab
                  faturas={faturasState}
                  setFaturas={setFaturas}
                  faturaItens={faturaItensState}
                  setFaturaItens={setFaturaItens}
                  lancamentos={lancamentosState}
                  setLancamentos={setLancamentos}
                  conciliacoes={conciliacoesState}
                  setConciliacoes={setConciliacoes}
                  extratos={extratosState}
                  setExtratos={setExtratos}
                  users={users || []}
                  mensalidades={mensalidades || []}
                  centrosCusto={centrosCusto || []}
                  products={productsState}
                  setProducts={setProducts}
                  invoiceTypes={invoiceTypes || []}
                />
              </Suspense>
            ) : null}
          </TabsContent>

          <TabsContent value="movimentos" className={moduleTabbedContentClass}>
            {activeTab === 'movimentos' ? (
              <Suspense fallback={<TabFallback />}>
                <MovimentosTab
                  movimentos={movimentosState}
                  setMovimentos={setMovimentos}
                  movimentoItens={movimentoItensState}
                  setMovimentoItens={setMovimentoItens}
                  lancamentos={lancamentosState}
                  setLancamentos={setLancamentos}
                  users={users || []}
                  centrosCusto={centrosCusto || []}
                  products={productsState}
                  setProducts={setProducts}
                  faturas={faturasState}
                />
              </Suspense>
            ) : null}
          </TabsContent>

          <TabsContent value="banco" className={moduleTabbedContentClass}>
            {activeTab === 'banco' ? (
              <Suspense fallback={<TabFallback />}>
                <BancoTab
                  extratos={extratosState}
                  setExtratos={setExtratos}
                  lancamentos={lancamentosState}
                  setLancamentos={setLancamentos}
                  faturas={faturasState}
                  setFaturas={setFaturas}
                  movimentos={movimentosState}
                  setMovimentos={setMovimentos}
                  setConciliacoes={setConciliacoes}
                  centrosCusto={centrosCusto || []}
                  users={users || []}
                />
              </Suspense>
            ) : null}
          </TabsContent>

          <TabsContent value="relatorios" className={moduleTabbedContentClass}>
            {activeTab === 'relatorios' ? (
              <Suspense fallback={<TabFallback />}>
                <RelatoriosTab
                  faturas={faturasState}
                  lancamentos={lancamentosState}
                  centrosCusto={centrosCusto || []}
                  users={users || []}
                  ageGroups={ageGroups || []}
                />
              </Suspense>
            ) : null}
          </TabsContent>

          <TabsContent value="emissao-fiscal" className={moduleTabbedContentClass}>
            {activeTab === 'emissao-fiscal' ? (
              <Suspense fallback={<TabFallback />}>
                <FiscalDocumentsTab />
              </Suspense>
            ) : null}
          </TabsContent>
        </Tabs>
      </div>
    </AuthenticatedLayout>
  );
}
