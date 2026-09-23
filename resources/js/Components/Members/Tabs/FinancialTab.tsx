import { Label } from '@/Components/ui/label';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Card } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { format } from 'date-fns';
import { useMemo } from 'react';
import { useKV } from '@/hooks/useKV';
import { DollarSign, TrendingUp, TrendingDown } from 'lucide-react';

interface MonthlyFee {
  id: string;
  designacao: string;
  valor: number;
  ativo?: boolean;
}

interface CostCenter {
  id: string;
  nome: string;
  ativo: boolean;
}

interface FinancialTabProps {
  user: any;
  onChange: (field: string, value: any) => void;
  isAdmin: boolean;
  faturas?: any[];
  movimentos?: any[];
  monthlyFees?: MonthlyFee[];
  costCenters?: CostCenter[];
}

export function FinancialTab({
  user,
  onChange,
  isAdmin,
  faturas = [],
  movimentos = [],
  monthlyFees = [],
  costCenters = [],
}: FinancialTabProps) {
  // Load from KV for convocation-related movements
  const [movimentosKV] = useKV<any[]>('club-movimentos', []);
  const [movimentoItensKV] = useKV<any[]>('club-movimento-itens', []);
  const toNumber = (value: unknown) => {
    if (typeof value === 'number') return value;
    if (typeof value === 'string') {
      const parsed = parseFloat(value);
      return Number.isNaN(parsed) ? 0 : parsed;
    }
    return 0;
  };

  const normalizeCurrencyAmount = (value: unknown) => {
    const amount = toNumber(value);

    return Math.abs(amount) < 0.005 ? 0 : amount;
  };

  const getStartOfToday = () => {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return today;
  };

  const isFutureInvoice = (fatura: any) => new Date(fatura.data_fatura) > getStartOfToday();
  const currentAccountSummary = user.current_account_summary || {};

  const userFaturas = useMemo(() => {
    return (faturas || [])
      .filter((f) => f.user_id === user.id && !isFutureInvoice(f))
      .sort((a, b) => new Date(b.data_emissao).getTime() - new Date(a.data_emissao).getTime());
  }, [faturas, user.id]);

  const contaCorrente = useMemo(() => {
    return toNumber(currentAccountSummary.net_debt ?? 0);
  }, [currentAccountSummary.net_debt]);

  const mensalidadesAbertas = useMemo(() => {
    return toNumber(currentAccountSummary.monthly_fees_open_amount ?? 0);
  }, [currentAccountSummary.monthly_fees_open_amount]);

  const movimentosAbertos = useMemo(() => {
    return toNumber(currentAccountSummary.revenue_movements_open_amount ?? 0);
  }, [currentAccountSummary.revenue_movements_open_amount]);

  const valorPago = useMemo(() => {
    return userFaturas.reduce((sum, fatura) => sum + toNumber(fatura.valor_pago ?? (fatura.estado_pagamento === 'pago' ? fatura.valor_total : 0)), 0);
  }, [userFaturas]);

  const creditoDisponivel = useMemo(() => {
    return toNumber(currentAccountSummary.available_credit ?? user.credito_disponivel ?? 0);
  }, [currentAccountSummary.available_credit, user.credito_disponivel]);

  const formatSignedEuro = (value: number) => {
    const normalizedValue = normalizeCurrencyAmount(value);
    const absoluteValue = Math.abs(normalizedValue).toFixed(2);
    return normalizedValue < 0 ? `-€${absoluteValue}` : `€${absoluteValue}`;
  };

  const getEstadoBadge = (estado: string) => {
    const variants: Record<string, string> = {
      pendente: 'bg-yellow-100 text-yellow-800',
      pago: 'bg-green-100 text-green-800',
      vencido: 'bg-red-100 text-red-800',
      parcial: 'bg-sky-100 text-sky-800',
      cancelado: 'bg-slate-100 text-slate-700',
    };
    return <Badge className={`${variants[estado] || 'bg-gray-100 text-gray-800'} text-xs px-1 py-0`}>{estado.toUpperCase()}</Badge>;
  };

  const mensalidadesDisponiveis = (monthlyFees || []).filter((fee) => fee.ativo !== false);
  const centrosCustoAtivos = (costCenters || []).filter((center) => center.ativo);

  const userMovimentos = useMemo(() => {
    return (movimentos || [])
      .filter(m => m.user_id === user.id)
      .map((movimento) => {
        const movementAmount = normalizeCurrencyAmount(movimento.valor_total ?? movimento.valor ?? 0);

        return {
          ...movimento,
          displayAmount: movementAmount,
        };
      })
      .sort((a, b) => new Date(b.data_emissao).getTime() - new Date(a.data_emissao).getTime());
  }, [movimentos, user.id]);

  // Linhas de responsabilidade - movimentos de convocatória onde o atleta foi convocado
  const linhasResponsabilidade = useMemo(() => {
    const atletaNome = user.nome_completo || '';
    
    // Filter movimento items where description contains athlete name and movimento is despesa type
    const atletaLinhas = (movimentoItensKV || [])
      .filter(item => item.descricao?.includes?.(atletaNome))
      .map(item => {
        const movimento = (movimentosKV || []).find(m => m.id === item.movimento_id);
        return {
          ...item,
          movimento: movimento,
        };
      })
      .filter(line => line.movimento && line.movimento.classificacao === 'despesa')
      .sort((a, b) => new Date(b.movimento.data_emissao).getTime() - new Date(a.movimento.data_emissao).getTime());
    
    return atletaLinhas;
  }, [movimentoItensKV, movimentosKV, user.nome_completo]);

  return (
    <div className="space-y-1">
      {/* Cards Resumo */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-1">
        <Card className="p-0">
          <div className="flex flex-col items-center justify-center gap-2 px-2 py-1">
            <div className="text-xs text-muted-foreground flex items-center justify-center gap-1 leading-none">
              <DollarSign size={14} />
              Conta Corrente
            </div>
            <div className={`text-lg font-bold leading-none ${contaCorrente > 0 ? 'text-red-600' : 'text-green-600'}`}>
              {formatSignedEuro(contaCorrente)}
            </div>
          </div>
        </Card>

        <Card className="p-0">
          <div className="flex flex-col items-center justify-center gap-2 px-2 py-1">
            <div className="text-xs text-muted-foreground flex items-center justify-center gap-1 leading-none">
              <TrendingUp size={14} />
              Mensalidades
            </div>
            <div className="text-lg font-bold text-amber-600 leading-none">
              {mensalidadesAbertas.toFixed(2)}€
            </div>
          </div>
        </Card>

        <Card className="p-0">
          <div className="flex flex-col items-center justify-center gap-2 px-2 py-1">
            <div className="text-xs text-muted-foreground flex items-center justify-center gap-1 leading-none">
              <TrendingDown size={14} />
              Movimentos
            </div>
            <div className="text-lg font-bold text-amber-600 leading-none">
              {movimentosAbertos.toFixed(2)}€
            </div>
          </div>
        </Card>

        <Card className="p-0">
          <div className="flex flex-col items-center justify-center gap-2 px-2 py-1">
            <div className="text-xs text-muted-foreground flex items-center justify-center gap-1 leading-none">
              📊
              Valor Pago
            </div>
            <div className="text-lg font-bold leading-none">
              {valorPago.toFixed(2)}€
            </div>
          </div>
        </Card>
      </div>

      {/* Configurações e Ajustes */}
      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-1">
        <Card className="p-2">
          <h3 className="text-xs font-semibold mb-1.5">Mensalidade</h3>
          <div className="space-y-1">
            <Label htmlFor="tipo_mensalidade" className="text-xs">Tipo</Label>
            <Select
              value={user.tipo_mensalidade || undefined}
              onValueChange={(value) => onChange('tipo_mensalidade', value)}
              disabled={!isAdmin}
            >
              <SelectTrigger id="tipo_mensalidade" className="h-7 text-xs bg-white">
                <SelectValue placeholder="Selecionar" />
              </SelectTrigger>
              <SelectContent>
                {mensalidadesDisponiveis.length === 0 ? (
                  <div className="px-2 py-4 text-center text-xs text-muted-foreground">
                    Nenhuma configurada
                  </div>
                ) : (
                  mensalidadesDisponiveis.map((m) => (
                    <SelectItem key={m.id} value={m.id}>
                      {m.designacao} - €{toNumber(m.valor).toFixed(2)}
                    </SelectItem>
                  ))
                )}
              </SelectContent>
            </Select>
          </div>
        </Card>

        <Card className="p-2">
          <h3 className="text-xs font-semibold mb-1.5">Centro de Custos</h3>
          <div className="space-y-1">
            <Label className="text-xs">Selecionar</Label>
            {centrosCustoAtivos.length === 0 ? (
              <div className="text-xs text-muted-foreground p-2 border rounded text-center">
                Nenhum configurado
              </div>
            ) : (
              <Select
                value={user.centro_custo?.[0]?.id || user.centro_custo?.[0] || 'none'}
                onValueChange={(value) => {
                  if (!isAdmin) return;
                  onChange('centro_custo', value === 'none' ? [] : [value]);
                }}
                disabled={!isAdmin}
              >
                <SelectTrigger className="h-7 text-xs bg-white">
                  <SelectValue placeholder="Selecionar" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">Sem centro</SelectItem>
                  {centrosCustoAtivos.map((c) => (
                    <SelectItem key={c.id} value={c.id}>
                      {c.nome}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </div>
        </Card>

        <Card className="p-2">
          <h3 className="text-xs font-semibold mb-1.5">Desconto</h3>
          <div className="space-y-1">
            <Label htmlFor="discount_type" className="text-xs">Tipo</Label>
            <Select
              value={user.discount_type || 'none'}
              onValueChange={(value) => onChange('discount_type', value === 'none' ? '' : value)}
              disabled={!isAdmin}
            >
              <SelectTrigger id="discount_type" className="h-7 text-xs bg-white">
                <SelectValue placeholder="Sem desconto" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="none">Sem desconto</SelectItem>
                <SelectItem value="percent">Percentual</SelectItem>
                <SelectItem value="fixed">Valor fixo</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </Card>

        <Card className="p-2">
          <h3 className="text-xs font-semibold mb-1.5">Valor do Desconto</h3>
          <div className="space-y-1">
            <Label htmlFor="discount_value" className="text-xs">
              {user.discount_type === 'percent' ? 'Percentagem' : 'Valor'}
            </Label>
            <Input
              id="discount_value"
              type="number"
              min={0}
              step="0.01"
              value={user.discount_value ?? ''}
              disabled={!isAdmin || !user.discount_type}
              onChange={(e) => onChange('discount_value', e.target.value === '' ? '' : Number(e.target.value))}
              className="h-7 text-xs bg-white"
            />
          </div>
        </Card>

        <Card className="p-2">
          <h3 className="text-xs font-semibold mb-1.5">Motivo</h3>
          <Input
            id="discount_reason"
            value={user.discount_reason || ''}
            disabled={!isAdmin}
            onChange={(e) => onChange('discount_reason', e.target.value)}
            className="h-7 text-xs bg-white"
            placeholder="Opcional"
          />
        </Card>

      </div>

      <Card className="p-2">
        <h3 className="text-xs font-semibold mb-1">Ajustes operacionais</h3>
        <p className="text-xs text-muted-foreground">
          Ajustes de conta corrente devem ser feitos por Movimento manual no Financeiro.
        </p>
      </Card>

      {/* Histórico Financeiro */}
      <Card className="p-2">
        <h3 className="text-xs font-semibold mb-1.5">Histórico de Faturas</h3>
        {userFaturas.length === 0 ? (
          <div className="p-4 text-center text-xs text-muted-foreground">
            Nenhuma fatura encontrada
          </div>
        ) : (
          <ScrollArea className="h-[240px]">
            <Table responsive>
              <TableHeader>
                <TableRow className="text-xs">
                  <TableHead className="text-xs h-7 py-1">Emissão</TableHead>
                  <TableHead className="text-xs h-7 py-1 hidden sm:table-cell">Vencimento</TableHead>
                  <TableHead className="text-xs h-7 py-1">Nominal</TableHead>
                  <TableHead className="text-xs h-7 py-1 hidden md:table-cell">Pago</TableHead>
                  <TableHead className="text-xs h-7 py-1">Em aberto</TableHead>
                  <TableHead className="text-xs h-7 py-1">Estado</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {userFaturas.map((fatura) => (
                  <TableRow key={fatura.id} className="text-xs">
                    <TableCell label="Emissão" className="py-1">{format(new Date(fatura.data_emissao), 'dd/MM/yy')}</TableCell>
                    <TableCell label="Vencimento" className="py-1 hidden sm:table-cell">{format(new Date(fatura.data_vencimento), 'dd/MM/yy')}</TableCell>
                    <TableCell label="Nominal" className="font-semibold py-1">€{toNumber(fatura.valor_total).toFixed(2)}</TableCell>
                    <TableCell label="Pago" className="py-1 hidden md:table-cell">€{toNumber(fatura.valor_pago ?? 0).toFixed(2)}</TableCell>
                    <TableCell label="Em aberto" className="font-semibold py-1">€{toNumber(fatura.valor_em_aberto ?? fatura.valor_total).toFixed(2)}</TableCell>
                    <TableCell label="Estado" className="py-1">{getEstadoBadge(fatura.estado_pagamento)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </ScrollArea>
        )}
      </Card>

      {/* Movimentos */}
      {userMovimentos.length > 0 && (
        <Card className="p-2">
          <h3 className="text-xs font-semibold mb-1.5">Movimentos</h3>
          <ScrollArea className="h-[240px]">
            <Table responsive>
              <TableHeader>
                <TableRow className="text-xs">
                  <TableHead className="text-xs h-7 py-1">Data</TableHead>
                  <TableHead className="text-xs h-7 py-1">Evento</TableHead>
                  <TableHead className="text-xs h-7 py-1">Valor</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {userMovimentos.map((movimento) => (
                  <TableRow key={movimento.id} className="text-xs">
                    <TableCell label="Data" className="py-1">{format(new Date(movimento.data_emissao), 'dd/MM/yy')}</TableCell>
                    <TableCell label="Evento" className="py-1">{movimento.evento_nome}</TableCell>
                    <TableCell label="Valor" className={`font-semibold py-1 ${movimento.displayAmount < 0 ? 'text-red-600' : movimento.displayAmount > 0 ? 'text-green-600' : 'text-foreground'}`}>
                      {formatSignedEuro(movimento.displayAmount)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </ScrollArea>
        </Card>
      )}

      {/* Linhas de Responsabilidade - Inscrições em Eventos */}
      {linhasResponsabilidade.length > 0 && (
        <Card className="p-2">
          <h3 className="text-xs font-semibold mb-1.5 flex items-center gap-1.5">
            <span>📋</span> Inscrições em Eventos — histórico legado
          </h3>
          <p className="mb-2 text-xs text-muted-foreground">
            Consulta histórica. Alterações financeiras são efetuadas apenas no módulo Financeiro.
          </p>
          <ScrollArea className="h-[300px]">
            <Table responsive>
              <TableHeader>
                <TableRow className="text-xs">
                  <TableHead className="text-xs h-7 py-1">Data</TableHead>
                  <TableHead className="text-xs h-7 py-1">Inscrição</TableHead>
                  <TableHead className="text-xs h-7 py-1 text-right">Valor</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {linhasResponsabilidade.map((linha) => (
                  <TableRow key={linha.id} className="text-xs hover:bg-muted/50">
                    <TableCell label="Data" className="py-1">
                      {linha.movimento ? format(new Date(linha.movimento.data_emissao), 'dd/MM/yy') : '-'}
                    </TableCell>
                    <TableCell label="Inscrição" className="py-1 text-muted-foreground">
                      <div className="break-words max-w-sm" title={linha.descricao}>
                        {linha.descricao}
                      </div>
                    </TableCell>
                    <TableCell label="Valor" className="py-1 text-right font-semibold">
                      €{toNumber(linha.valor_unitario).toFixed(2)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </ScrollArea>
        </Card>
      )}

    </div>
  );
}
