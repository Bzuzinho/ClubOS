import { FormEvent, useMemo, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { moduleTabbedContentClass, moduleTabsClass, moduleViewportClass } from '@/lib/module-layout';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Input } from '@/Components/ui/input';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/Components/ui/dialog';

// ─── Types ──────────────────────────────────────────────────────────────────

type Product = {
  id: string;
  codigo: string;
  nome: string;
  categoria?: string | null;
  preco: number;
  stock: number;
  stock_reservado: number;
  stock_disponivel: number;
  stock_minimo: number;
  status: 'ok' | 'baixo';
  ativo: boolean;
  allow_request: boolean;
  allow_loan: boolean;
  tracks_stock: boolean;
  supplier?: { id: string; nome: string } | null;
};

type Supplier = {
  id: string;
  nome: string;
  nif?: string | null;
  email?: string | null;
  telefone?: string | null;
  categoria?: string | null;
  morada?: string | null;
  notas?: string | null;
  ativo: boolean;
};

type RequestItem = {
  id: string;
  article_id: string | null;
  article_name_snapshot: string;
  quantity: number;
  unit_price: number;
  line_total: number;
};

type LogisticsRequest = {
  id: string;
  requester_user_id?: string | null;
  requester_name_snapshot: string;
  requester_area?: string | null;
  requester_type?: string | null;
  status: 'draft' | 'pending' | 'approved' | 'invoiced' | 'delivered' | 'cancelled';
  total_amount: number;
  financial_invoice_id?: string | null;
  notes?: string | null;
  approved_at?: string | null;
  delivered_at?: string | null;
  created_at: string;
  items: RequestItem[];
};

type Loan = {
  id: string;
  borrower_user_id?: string | null;
  borrower_name_snapshot: string;
  article_id?: string | null;
  article_name_snapshot: string;
  quantity: number;
  loan_date: string;
  due_date?: string | null;
  return_date?: string | null;
  notes?: string | null;
  status: 'active' | 'overdue' | 'returned' | 'cancelled';
};

type StockMovement = {
  id: string;
  article_id?: string | null;
  movement_type: string;
  quantity: number;
  unit_cost?: number | null;
  notes?: string | null;
  created_at: string;
  article?: { id: string; nome: string } | null;
};

type SupplierPurchase = {
  id: string;
  supplier_id: string;
  supplier_name_snapshot: string;
  invoice_reference: string;
  invoice_date: string;
  total_amount: number;
  notes?: string | null;
  financial_movement_id?: string | null;
  items: Array<{
    article_id: string;
    quantity: number;
    unit_cost: number;
  }>;
};

type User = {
  id: string;
  nome_completo: string;
};

type UserType = {
  id: string;
  nome: string;
};

type Dashboard = {
  stock_valuation: number;
  low_stock_alerts: number;
  pending_requests: number;
  active_loans: number;
  latest_supplier_purchases: SupplierPurchase[];
  latest_financial_actions: Array<{
    type: string;
    label: string;
    reference: string;
    date: string;
  }>;
};

interface Props {
  tab?: string;
  products: Product[];
  suppliers: Supplier[];
  users: User[];
  userTypes: UserType[];
  requests: LogisticsRequest[];
  loans: Loan[];
  stockMovements: StockMovement[];
  supplierPurchases: SupplierPurchase[];
  dashboard: Dashboard;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function euro(value: number): string {
  return new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR' }).format(value || 0);
}

function formatDateYmd(value?: string | null): string {
  if (!value) return '-';
  return value.slice(0, 10);
}

const wi = 'bg-white';
const ws = 'bg-white';

const statusReqLabel: Record<string, string> = {
  draft: 'Rascunho', pending: 'Pendente', approved: 'Aprovada',
  invoiced: 'Faturada', delivered: 'Entregue', cancelled: 'Cancelada',
};

const statusLoanLabel: Record<string, string> = {
  active: 'Ativo', overdue: 'Em atraso', returned: 'Devolvido', cancelled: 'Cancelado',
};

const movTypeLabel: Record<string, string> = {
  entry: 'Entrada', exit: 'Saída', reservation: 'Reserva',
  cancel_reservation: 'Anula Reserva', deliver_reservation: 'Entrega Reserva', return: 'Devolução',
};

// ─── Main Component ───────────────────────────────────────────────────────────

export default function LogisticaIndex({
  tab = 'dashboard', products, suppliers, users, userTypes, requests, loans, stockMovements, supplierPurchases, dashboard,
}: Props) {
  const [activeTab, setActiveTab] = useState(tab);

  // ── Requisições state ──
  const [reqDialogOpen, setReqDialogOpen] = useState(false);
  const [editingReqId, setEditingReqId] = useState<string | null>(null);
  const [reqSearch, setReqSearch] = useState('');
  const [reqStatusFilter, setReqStatusFilter] = useState('all');
  const [reqTypeFilter, setReqTypeFilter] = useState('all');

  // ── Stock state ──
  const [stockDialogOpen, setStockDialogOpen] = useState(false);
  const [stockSearch, setStockSearch] = useState('');
  const [stockCategoryFilter, setStockCategoryFilter] = useState('all');
  const [stockStatusFilter, setStockStatusFilter] = useState('all');

  // ── Empréstimos state ──
  const [loanDialogOpen, setLoanDialogOpen] = useState(false);
  const [editingLoanId, setEditingLoanId] = useState<string | null>(null);
  const [loanSearch, setLoanSearch] = useState('');
  const [loanStatusFilter, setLoanStatusFilter] = useState('all');

  // ── Fornecedores state ──
  const [purchaseDialogOpen, setPurchaseDialogOpen] = useState(false);
  const [editingPurchaseId, setEditingPurchaseId] = useState<string | null>(null);
  const [purchaseSearch, setPurchaseSearch] = useState('');
  const [purchaseSupplierFilter, setPurchaseSupplierFilter] = useState('all');
  const [purchaseDateFilter, setPurchaseDateFilter] = useState('');

  // ── Forms ──
  const requestForm = useForm({
    requester_user_id: '',
    requester_name_snapshot: '',
    requester_area: '',
    requester_type: '',
    status: 'pending',
    notes: '',
    items: [{ article_id: '', quantity: 1, unit_price: '' }],
  });

  const requestEditForm = useForm({
    requester_user_id: '',
    requester_name_snapshot: '',
    requester_area: '',
    requester_type: '',
    notes: '',
    items: [{ article_id: '', quantity: 1, unit_price: '' }],
  });

  const stockForm = useForm({
    article_id: '',
    movement_type: 'entry',
    quantity: 1,
    notes: '',
  });

  const loanForm = useForm({
    borrower_user_id: '',
    borrower_name_snapshot: '',
    article_id: '',
    quantity: 1,
    loan_date: new Date().toISOString().slice(0, 10),
    due_date: '',
    notes: '',
  });

  const loanEditForm = useForm({
    borrower_user_id: '',
    borrower_name_snapshot: '',
    article_id: '',
    quantity: 1,
    loan_date: new Date().toISOString().slice(0, 10),
    due_date: '',
    notes: '',
  });

  const purchaseForm = useForm({
    supplier_id: '',
    invoice_reference: '',
    invoice_date: new Date().toISOString().slice(0, 10),
    notes: '',
    items: [{ article_id: '', quantity: 1, unit_cost: '' }],
  });

  const purchaseEditForm = useForm({
    supplier_id: '',
    invoice_reference: '',
    invoice_date: new Date().toISOString().slice(0, 10),
    notes: '',
    items: [{ article_id: '', quantity: 1, unit_cost: '' }],
  });

  // ── Derived data ──
  const selectableProducts = useMemo(() => products.filter((p) => p.ativo), [products]);
  const stockManagedProducts = useMemo(() => selectableProducts.filter((p) => p.tracks_stock), [selectableProducts]);
  const requestableProducts = useMemo(() => stockManagedProducts.filter((p) => p.allow_request), [stockManagedProducts]);
  const loanableProducts = useMemo(() => stockManagedProducts.filter((p) => p.allow_loan), [stockManagedProducts]);

  const productCategories = useMemo(
    () => [...new Set(products.map((p) => p.categoria).filter(Boolean) as string[])],
    [products],
  );

  const filteredRequests = useMemo(() => {
    const s = reqSearch.trim().toLowerCase();
    return requests.filter((r) => {
      const matchesStatus = reqStatusFilter === 'all' || r.status === reqStatusFilter;
      const matchesType = reqTypeFilter === 'all' || r.requester_type === reqTypeFilter;
      const matchesSearch = !s || r.requester_name_snapshot.toLowerCase().includes(s) ||
        (r.requester_area || '').toLowerCase().includes(s);
      return matchesStatus && matchesType && matchesSearch;
    });
  }, [requests, reqSearch, reqStatusFilter, reqTypeFilter]);

  const filteredProducts = useMemo(() => {
    const s = stockSearch.trim().toLowerCase();
    return products.filter((p) => {
      const matchesCat = stockCategoryFilter === 'all' || p.categoria === stockCategoryFilter;
      const matchesSt = stockStatusFilter === 'all' || p.status === stockStatusFilter;
      const matchesSearch = !s || p.nome.toLowerCase().includes(s) || p.codigo.toLowerCase().includes(s);
      return matchesCat && matchesSt && matchesSearch;
    });
  }, [products, stockSearch, stockCategoryFilter, stockStatusFilter]);

  const filteredLoans = useMemo(() => {
    const s = loanSearch.trim().toLowerCase();
    return loans.filter((loan) => {
      const matchesSt = loanStatusFilter === 'all' || loan.status === loanStatusFilter;
      const matchesSearch = !s ||
        loan.borrower_name_snapshot.toLowerCase().includes(s) ||
        loan.article_name_snapshot.toLowerCase().includes(s);
      return matchesSt && matchesSearch;
    });
  }, [loans, loanSearch, loanStatusFilter]);

  const filteredSupplierPurchases = useMemo(() => {
    const s = purchaseSearch.trim().toLowerCase();
    return supplierPurchases.filter((p) => {
      const matchesSupplier = purchaseSupplierFilter === 'all' || p.supplier_id === purchaseSupplierFilter;
      const matchesDate = !purchaseDateFilter || formatDateYmd(p.invoice_date) === purchaseDateFilter;
      const matchesSearch = !s ||
        p.supplier_name_snapshot.toLowerCase().includes(s) ||
        p.invoice_reference.toLowerCase().includes(s);
      return matchesSupplier && matchesDate && matchesSearch;
    });
  }, [supplierPurchases, purchaseSearch, purchaseSupplierFilter, purchaseDateFilter]);

  // ── Requisitions handlers ──
  const submitRequest = (e: FormEvent) => {
    e.preventDefault();
    requestForm.post(route('logistica.requisicoes.store'), {
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => { setReqDialogOpen(false); requestForm.reset(); setActiveTab('requisicoes'); },
    });
  };

  const openEditRequest = (r: LogisticsRequest) => {
    setEditingReqId(r.id);
    requestEditForm.setData({
      requester_user_id: r.requester_user_id || '',
      requester_name_snapshot: r.requester_name_snapshot,
      requester_area: r.requester_area || '',
      requester_type: r.requester_type || '',
      notes: r.notes || '',
      items: r.items.length > 0
        ? r.items.map((item) => ({
            article_id: item.article_id || '',
            quantity: item.quantity,
            unit_price: String(item.unit_price ?? ''),
          }))
        : [{ article_id: '', quantity: 1, unit_price: '' }],
    });
    setReqDialogOpen(true);
  };

  const submitRequestUpdate = (e: FormEvent) => {
    e.preventDefault();
    if (!editingReqId) return;
    requestEditForm.put(route('logistica.requisicoes.update', editingReqId), {
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => { setReqDialogOpen(false); setEditingReqId(null); requestEditForm.reset(); setActiveTab('requisicoes'); },
    });
  };

  const deleteRequest = (id: string) => {
    if (!confirm('Apagar esta requisição? Permitido enquanto estiver em rascunho, pendente, aprovada, faturada ou entregue.')) return;
    router.delete(route('logistica.requisicoes.destroy', id), { preserveState: true, preserveScroll: true, onSuccess: () => setActiveTab('requisicoes') });
  };

  // ── Stock handlers ──
  const submitStock = (e: FormEvent) => {
    e.preventDefault();
    stockForm.post(route('logistica.stock.movimentos.store'), {
      preserveState: false,
      onSuccess: () => { setStockDialogOpen(false); stockForm.reset(); setActiveTab('stock'); },
    });
  };

  // ── Loan handlers ──
  const submitLoan = (e: FormEvent) => {
    e.preventDefault();
    loanForm.post(route('logistica.emprestimos.store'), {
      preserveState: false,
      onSuccess: () => { setLoanDialogOpen(false); loanForm.reset(); },
    });
  };

  const openEditLoan = (loan: Loan) => {
    setEditingLoanId(loan.id);
    loanEditForm.setData({
      borrower_user_id: loan.borrower_user_id || '',
      borrower_name_snapshot: loan.borrower_name_snapshot,
      article_id: loan.article_id || '',
      quantity: loan.quantity,
      loan_date: formatDateYmd(loan.loan_date),
      due_date: loan.due_date ? formatDateYmd(loan.due_date) : '',
      notes: loan.notes || '',
    });
    setLoanDialogOpen(true);
  };

  const submitLoanUpdate = (e: FormEvent) => {
    e.preventDefault();
    if (!editingLoanId) return;
    loanEditForm.put(route('logistica.emprestimos.update', editingLoanId), {
      preserveState: false,
      onSuccess: () => { setLoanDialogOpen(false); setEditingLoanId(null); loanEditForm.reset(); },
    });
  };

  const deleteLoan = (id: string) => {
    if (!confirm('Apagar empréstimo? O stock será reposto automaticamente.')) return;
    router.delete(route('logistica.emprestimos.destroy', id), { preserveState: false });
  };

  // ── Purchase handlers ──
  const submitPurchase = (e: FormEvent) => {
    e.preventDefault();
    purchaseForm.post(route('logistica.fornecedores.compras.store'), {
      preserveState: false,
      onSuccess: () => { setPurchaseDialogOpen(false); purchaseForm.reset(); setActiveTab('stock'); },
    });
  };

  const openEditPurchase = (p: SupplierPurchase) => {
    setEditingPurchaseId(p.id);
    purchaseEditForm.setData({
      supplier_id: p.supplier_id,
      invoice_reference: p.invoice_reference,
      invoice_date: formatDateYmd(p.invoice_date),
      notes: p.notes || '',
      items: p.items.length > 0
        ? p.items.map((item) => ({
            article_id: item.article_id,
            quantity: item.quantity,
            unit_cost: String(item.unit_cost ?? ''),
          }))
        : [{ article_id: '', quantity: 1, unit_cost: '' }],
    });
    setPurchaseDialogOpen(true);
  };

  const submitPurchaseUpdate = (e: FormEvent) => {
    e.preventDefault();
    if (!editingPurchaseId) return;
    purchaseEditForm.put(route('logistica.fornecedores.compras.update', editingPurchaseId), {
      preserveState: false,
      onSuccess: () => { setPurchaseDialogOpen(false); setEditingPurchaseId(null); purchaseEditForm.reset(); setActiveTab('stock'); },
    });
  };

  const deletePurchase = (id: string) => {
    if (!confirm('Apagar compra? O stock e o financeiro serão recalculados.')) return;
    router.delete(route('logistica.fornecedores.compras.destroy', id), { preserveState: false, onSuccess: () => setActiveTab('stock') });
  };

  // ── Inline item list helpers ──
  function reqItems(editing: boolean) {
    return editing ? requestEditForm.data.items : requestForm.data.items;
  }
  function setReqItems(editing: boolean, val: typeof requestForm.data.items) {
    if (editing) requestEditForm.setData('items', val);
    else requestForm.setData('items', val);
  }

  function purchaseItems(editing: boolean) {
    return editing ? purchaseEditForm.data.items : purchaseForm.data.items;
  }
  function setPurchaseItems(editing: boolean, val: typeof purchaseForm.data.items) {
    if (editing) purchaseEditForm.setData('items', val);
    else purchaseForm.setData('items', val);
  }

  // ─── Render ───────────────────────────────────────────────────────────────

  return (
    <AuthenticatedLayout
      fullWidth
      header={
        <div>
          <h1 className="text-lg sm:text-xl font-semibold tracking-tight">Logística</h1>
          <p className="text-muted-foreground text-xs mt-0.5">Operações internas de materiais, stock, requisições e fornecedores</p>
        </div>
      }
    >
      <Head title="Logística" />

      <div className={moduleViewportClass}>
        <Tabs value={activeTab} onValueChange={setActiveTab} className={moduleTabsClass}>
          <TabsList className="grid w-full shrink-0 grid-cols-2 sm:grid-cols-5 h-auto gap-1">
            <TabsTrigger value="dashboard">Dashboard</TabsTrigger>
            <TabsTrigger value="requisicoes">Requisições</TabsTrigger>
            <TabsTrigger value="stock">Stock</TabsTrigger>
            <TabsTrigger value="emprestimos">Empréstimos</TabsTrigger>
            <TabsTrigger value="fornecedores">Fornecedores</TabsTrigger>
          </TabsList>

        {/* ── Dashboard ──────────────────────────────────────────────────── */}
        <TabsContent value="dashboard" className={`${moduleTabbedContentClass} space-y-3`}>
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <Card><CardHeader><CardTitle className="text-sm">Valorização de Stock</CardTitle></CardHeader><CardContent className="text-xl font-semibold">{euro(dashboard.stock_valuation)}</CardContent></Card>
            <Card><CardHeader><CardTitle className="text-sm">Alertas de Stock</CardTitle></CardHeader><CardContent className="text-xl font-semibold">{dashboard.low_stock_alerts}</CardContent></Card>
            <Card><CardHeader><CardTitle className="text-sm">Requisições Pendentes</CardTitle></CardHeader><CardContent className="text-xl font-semibold">{dashboard.pending_requests}</CardContent></Card>
            <Card><CardHeader><CardTitle className="text-sm">Empréstimos Ativos</CardTitle></CardHeader><CardContent className="text-xl font-semibold">{dashboard.active_loans}</CardContent></Card>
          </div>
          <div className="grid lg:grid-cols-2 gap-3">
            <Card>
              <CardHeader><CardTitle className="text-sm">Últimas Compras a Fornecedor</CardTitle></CardHeader>
              <CardContent>
                <div className="space-y-2">
                  {dashboard.latest_supplier_purchases.map((p) => (
                    <div key={p.id} className="flex items-center justify-between text-sm border rounded-md px-3 py-2">
                      <span>{p.supplier_name_snapshot} · {p.invoice_reference}</span>
                      <span className="font-medium">{euro(p.total_amount)}</span>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardHeader><CardTitle className="text-sm">Integrações Financeiras Recentes</CardTitle></CardHeader>
              <CardContent>
                <div className="space-y-2">
                  {dashboard.latest_financial_actions.map((action, idx) => (
                    <div key={`${action.reference}-${idx}`} className="flex items-center justify-between text-sm border rounded-md px-3 py-2">
                      <span>{action.label}</span>
                      <Badge variant="secondary">{action.reference.slice(0, 8)}</Badge>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        {/* ── Requisições ────────────────────────────────────────────────── */}
        <TabsContent value="requisicoes" className={`${moduleTabbedContentClass} space-y-3`}>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle className="text-sm">Lista de Requisições</CardTitle>
              <Dialog
                open={reqDialogOpen}
                onOpenChange={(open) => {
                  setReqDialogOpen(open);
                  if (!open) { setEditingReqId(null); requestForm.reset(); requestEditForm.reset(); }
                }}
              >
                <DialogTrigger asChild>
                  <Button onClick={() => setEditingReqId(null)}>Nova Requisição</Button>
                </DialogTrigger>
                <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
                  <DialogHeader>
                    <DialogTitle>{editingReqId ? 'Editar Requisição' : 'Nova Requisição'}</DialogTitle>
                  </DialogHeader>
                  <form onSubmit={editingReqId ? submitRequestUpdate : submitRequest} className="space-y-3">
                    <div className="grid md:grid-cols-3 gap-3">
                      <div>
                        <Label>Utilizador (opcional)</Label>
                        <Select
                          value={editingReqId ? requestEditForm.data.requester_user_id : requestForm.data.requester_user_id}
                          onValueChange={(v) => editingReqId ? requestEditForm.setData('requester_user_id', v) : requestForm.setData('requester_user_id', v)}
                        >
                          <SelectTrigger className={ws}><SelectValue placeholder="Selecionar" /></SelectTrigger>
                          <SelectContent>{users.map((u) => <SelectItem key={u.id} value={u.id}>{u.nome_completo}</SelectItem>)}</SelectContent>
                        </Select>
                      </div>
                      <div>
                        <Label>Nome do requisitante</Label>
                        <Input className={wi}
                          value={editingReqId ? requestEditForm.data.requester_name_snapshot : requestForm.data.requester_name_snapshot}
                          onChange={(e) => editingReqId ? requestEditForm.setData('requester_name_snapshot', e.target.value) : requestForm.setData('requester_name_snapshot', e.target.value)}
                          required />
                      </div>
                      <div>
                        <Label>Tipo</Label>
                        <Select
                          value={editingReqId ? requestEditForm.data.requester_type : requestForm.data.requester_type}
                          onValueChange={(v) => editingReqId ? requestEditForm.setData('requester_type', v) : requestForm.setData('requester_type', v)}
                        >
                          <SelectTrigger className={ws}><SelectValue /></SelectTrigger>
                          <SelectContent>
                            {userTypes.map((ut) => <SelectItem key={ut.id} value={ut.nome}>{ut.nome}</SelectItem>)}
                          </SelectContent>
                        </Select>
                      </div>
                    </div>
                    <div className="grid md:grid-cols-2 gap-3">
                      <div>
                        <Label>Área</Label>
                        <Input className={wi}
                          value={editingReqId ? requestEditForm.data.requester_area : requestForm.data.requester_area}
                          onChange={(e) => editingReqId ? requestEditForm.setData('requester_area', e.target.value) : requestForm.setData('requester_area', e.target.value)} />
                      </div>
                      <div>
                        <Label>Notas</Label>
                        <Input className={wi}
                          value={editingReqId ? requestEditForm.data.notes : requestForm.data.notes}
                          onChange={(e) => editingReqId ? requestEditForm.setData('notes', e.target.value) : requestForm.setData('notes', e.target.value)} />
                      </div>
                    </div>
                    <div className="space-y-2">
                      <Label>Artigos</Label>
                      {reqItems(!!editingReqId).map((item, idx) => (
                        <div key={idx} className="grid grid-cols-12 gap-2">
                          <div className="col-span-6">
                            <Select value={item.article_id} onValueChange={(v) => {
                              const product = selectableProducts.find((p) => p.id === v);
                              setReqItems(!!editingReqId, reqItems(!!editingReqId).map((l, i) => i === idx ? { ...l, article_id: v, unit_price: product ? String(product.preco) : l.unit_price } : l));
                            }}>
                              <SelectTrigger className={ws}><SelectValue placeholder="Artigo" /></SelectTrigger>
                              <SelectContent>{requestableProducts.map((p) => <SelectItem key={p.id} value={p.id}>{p.codigo} · {p.nome}</SelectItem>)}</SelectContent>
                            </Select>
                          </div>
                          <div className="col-span-3">
                            <Input className={wi} type="number" min={1} placeholder="Qtd." value={item.quantity}
                              onChange={(e) => setReqItems(!!editingReqId, reqItems(!!editingReqId).map((l, i) => i === idx ? { ...l, quantity: Number(e.target.value || 1) } : l))} />
                          </div>
                          <div className="col-span-3">
                            <Input className={wi} type="number" step="0.01" min={0} placeholder="Preço unit. (opcional)" value={item.unit_price}
                              onChange={(e) => setReqItems(!!editingReqId, reqItems(!!editingReqId).map((l, i) => i === idx ? { ...l, unit_price: e.target.value } : l))} />
                          </div>
                        </div>
                      ))}
                      <Button type="button" variant="secondary" onClick={() => setReqItems(!!editingReqId, [...reqItems(!!editingReqId), { article_id: '', quantity: 1, unit_price: '' }])}>
                        Adicionar item
                      </Button>
                    </div>
                    <Button type="submit" disabled={requestForm.processing || requestEditForm.processing}>
                      {editingReqId ? 'Guardar alterações' : 'Criar Requisição'}
                    </Button>
                  </form>
                </DialogContent>
              </Dialog>
            </CardHeader>
            <CardContent>
              <div className="grid md:grid-cols-4 gap-3 mb-4">
                <div>
                  <Label>Pesquisar</Label>
                  <Input className={wi} placeholder="Requisitante ou área" value={reqSearch} onChange={(e) => setReqSearch(e.target.value)} />
                </div>
                <div>
                  <Label>Estado</Label>
                  <Select value={reqStatusFilter} onValueChange={setReqStatusFilter}>
                    <SelectTrigger className={ws}><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Todos</SelectItem>
                      {Object.entries(statusReqLabel).map(([k, v]) => <SelectItem key={k} value={k}>{v}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label>Tipo</Label>
                  <Select value={reqTypeFilter} onValueChange={setReqTypeFilter}>
                    <SelectTrigger className={ws}><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Todos</SelectItem>
                      {userTypes.map((ut) => <SelectItem key={ut.id} value={ut.nome}>{ut.nome}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="flex items-end">
                  <Button type="button" variant="secondary" onClick={() => { setReqSearch(''); setReqStatusFilter('all'); setReqTypeFilter('all'); }}>
                    Limpar filtros
                  </Button>
                </div>
              </div>
              <div className="space-y-3 md:hidden">
                {filteredRequests.map((r) => (
                  <div key={r.id} className="rounded-lg border p-3 space-y-3">
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <div className="font-medium truncate">{r.requester_name_snapshot}</div>
                        <div className="text-xs text-muted-foreground">{r.requester_area || '-'}</div>
                      </div>
                      <Badge variant={r.status === 'delivered' ? 'secondary' : r.status === 'cancelled' ? 'outline' : 'default'}>
                        {statusReqLabel[r.status] ?? r.status}
                      </Badge>
                    </div>

                    <div className="grid grid-cols-2 gap-2 text-sm">
                      <div>
                        <div className="text-xs text-muted-foreground">Tipo</div>
                        <div>{r.requester_type || '-'}</div>
                      </div>
                      <div>
                        <div className="text-xs text-muted-foreground">Total</div>
                        <div>{euro(r.total_amount)}</div>
                      </div>
                      <div>
                        <div className="text-xs text-muted-foreground">Data</div>
                        <div>{formatDateYmd(r.created_at)}</div>
                      </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                      {['draft', 'pending', 'approved', 'invoiced', 'delivered'].includes(r.status) && (
                        <>
                          <Button size="sm" variant="secondary" onClick={() => openEditRequest(r)}>Editar</Button>
                          <Button size="sm" variant="destructive" onClick={() => deleteRequest(r.id)}>Apagar</Button>
                        </>
                      )}
                      <Button size="sm" variant="secondary" onClick={() => router.post(route('logistica.requisicoes.approve', r.id))} disabled={!['draft', 'pending'].includes(r.status)}>Aprovar</Button>
                      <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => router.post(route('logistica.requisicoes.invoice', r.id), {}, { preserveState: true, preserveScroll: true, onSuccess: () => setActiveTab('requisicoes') })}
                        disabled={r.status !== 'approved' || !!r.financial_invoice_id}
                      >
                        Faturar
                      </Button>
                      <Button size="sm" onClick={() => router.post(route('logistica.requisicoes.deliver', r.id))} disabled={!['approved', 'invoiced'].includes(r.status)}>Entregar</Button>
                    </div>
                  </div>
                ))}
              </div>

              <div className="hidden md:block">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Requisitante</TableHead>
                      <TableHead>Área</TableHead>
                      <TableHead>Tipo</TableHead>
                      <TableHead>Estado</TableHead>
                      <TableHead>Total</TableHead>
                      <TableHead>Data</TableHead>
                      <TableHead>Ações</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {filteredRequests.map((r) => (
                      <TableRow key={r.id}>
                        <TableCell>{r.requester_name_snapshot}</TableCell>
                        <TableCell>{r.requester_area || '-'}</TableCell>
                        <TableCell>{r.requester_type || '-'}</TableCell>
                        <TableCell>
                          <Badge variant={r.status === 'delivered' ? 'secondary' : r.status === 'cancelled' ? 'outline' : 'default'}>
                            {statusReqLabel[r.status] ?? r.status}
                          </Badge>
                        </TableCell>
                        <TableCell>{euro(r.total_amount)}</TableCell>
                        <TableCell>{formatDateYmd(r.created_at)}</TableCell>
                        <TableCell>
                          <div className="flex flex-wrap gap-1">
                            {['draft', 'pending', 'approved', 'invoiced', 'delivered'].includes(r.status) && (
                              <>
                                <Button size="sm" variant="secondary" onClick={() => openEditRequest(r)}>Editar</Button>
                                <Button size="sm" variant="destructive" onClick={() => deleteRequest(r.id)}>Apagar</Button>
                              </>
                            )}
                            <Button size="sm" variant="secondary" onClick={() => router.post(route('logistica.requisicoes.approve', r.id))} disabled={!['draft', 'pending'].includes(r.status)}>Aprovar</Button>
                            <Button
                              size="sm"
                              variant="secondary"
                              onClick={() => router.post(route('logistica.requisicoes.invoice', r.id), {}, { preserveState: true, preserveScroll: true, onSuccess: () => setActiveTab('requisicoes') })}
                              disabled={r.status !== 'approved' || !!r.financial_invoice_id}
                            >
                              Faturar
                            </Button>
                            <Button size="sm" onClick={() => router.post(route('logistica.requisicoes.deliver', r.id))} disabled={!['approved', 'invoiced'].includes(r.status)}>Entregar</Button>
                          </div>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* ── Stock ──────────────────────────────────────────────────────── */}
        <TabsContent value="stock" className={`${moduleTabbedContentClass} space-y-3`}>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle className="text-sm">Mapa de Stock</CardTitle>
              <Dialog open={stockDialogOpen} onOpenChange={(open) => { setStockDialogOpen(open); if (!open) stockForm.reset(); }}>
                <DialogTrigger asChild>
                  <Button>Registar Movimento</Button>
                </DialogTrigger>
                <DialogContent className="max-w-lg">
                  <DialogHeader>
                    <DialogTitle>Registar Movimento de Stock</DialogTitle>
                  </DialogHeader>
                  <form onSubmit={submitStock} className="space-y-3">
                    <div>
                      <Label>Artigo</Label>
                      <Select value={stockForm.data.article_id} onValueChange={(v) => stockForm.setData('article_id', v)}>
                        <SelectTrigger className={ws}><SelectValue placeholder="Selecionar" /></SelectTrigger>
                        <SelectContent>{stockManagedProducts.map((p) => <SelectItem value={p.id} key={p.id}>{p.nome}</SelectItem>)}</SelectContent>
                      </Select>
                    </div>
                    <div>
                      <Label>Tipo de movimento</Label>
                      <Select value={stockForm.data.movement_type} onValueChange={(v) => stockForm.setData('movement_type', v)}>
                        <SelectTrigger className={ws}><SelectValue /></SelectTrigger>
                        <SelectContent>
                          <SelectItem value="entry">Entrada</SelectItem>
                          <SelectItem value="exit">Saída</SelectItem>
                          <SelectItem value="reservation">Reserva</SelectItem>
                          <SelectItem value="cancel_reservation">Anula Reserva</SelectItem>
                          <SelectItem value="deliver_reservation">Entrega Reserva</SelectItem>
                          <SelectItem value="return">Devolução</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                    <div>
                      <Label>Quantidade</Label>
                      <Input className={wi} type="number" value={stockForm.data.quantity} onChange={(e) => stockForm.setData('quantity', Number(e.target.value || 0))} />
                    </div>
                    <div>
                      <Label>Notas</Label>
                      <Input className={wi} value={stockForm.data.notes} onChange={(e) => stockForm.setData('notes', e.target.value)} />
                    </div>
                    <Button type="submit" disabled={stockForm.processing}>Registar</Button>
                  </form>
                </DialogContent>
              </Dialog>
            </CardHeader>
            <CardContent>
              <div className="grid md:grid-cols-4 gap-3 mb-4">
                <div>
                  <Label>Pesquisar</Label>
                  <Input className={wi} placeholder="Nome ou código" value={stockSearch} onChange={(e) => setStockSearch(e.target.value)} />
                </div>
                <div>
                  <Label>Categoria</Label>
                  <Select value={stockCategoryFilter} onValueChange={setStockCategoryFilter}>
                    <SelectTrigger className={ws}><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Todas</SelectItem>
                      {productCategories.map((c) => <SelectItem key={c} value={c}>{c}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label>Estado</Label>
                  <Select value={stockStatusFilter} onValueChange={setStockStatusFilter}>
                    <SelectTrigger className={ws}><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Todos</SelectItem>
                      <SelectItem value="ok">OK</SelectItem>
                      <SelectItem value="baixo">Stock Baixo</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="flex items-end">
                  <Button type="button" variant="secondary" onClick={() => { setStockSearch(''); setStockCategoryFilter('all'); setStockStatusFilter('all'); }}>
                    Limpar filtros
                  </Button>
                </div>
              </div>
              <div className="space-y-3 md:hidden">
                {filteredProducts.map((p) => (
                  <div key={p.id} className="rounded-lg border p-3 space-y-3">
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <div className="font-medium truncate">{p.nome}</div>
                        <div className="text-xs text-muted-foreground">{p.codigo}</div>
                      </div>
                      <Badge variant={p.status === 'baixo' ? 'destructive' : 'secondary'}>
                        {p.status === 'baixo' ? 'Baixo' : 'OK'}
                      </Badge>
                    </div>
                    <div className="grid grid-cols-2 gap-2 text-sm">
                      <div>
                        <div className="text-xs text-muted-foreground">Categoria</div>
                        <div>{p.categoria || '-'}</div>
                      </div>
                      <div>
                        <div className="text-xs text-muted-foreground">Stock</div>
                        <div>{p.stock}</div>
                      </div>
                      <div>
                        <div className="text-xs text-muted-foreground">Reservado</div>
                        <div>{p.stock_reservado}</div>
                      </div>
                      <div>
                        <div className="text-xs text-muted-foreground">Mínimo</div>
                        <div>{p.stock_minimo}</div>
                      </div>
                    </div>
                  </div>
                ))}
              </div>

              <div className="hidden md:block">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Código</TableHead>
                      <TableHead>Nome</TableHead>
                      <TableHead>Categoria</TableHead>
                      <TableHead>Stock</TableHead>
                      <TableHead>Reservado</TableHead>
                      <TableHead>Mínimo</TableHead>
                      <TableHead>Estado</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {filteredProducts.map((p) => (
                      <TableRow key={p.id}>
                        <TableCell>{p.codigo}</TableCell>
                        <TableCell>{p.nome}</TableCell>
                        <TableCell>{p.categoria || '-'}</TableCell>
                        <TableCell>{p.stock}</TableCell>
                        <TableCell>{p.stock_reservado}</TableCell>
                        <TableCell>{p.stock_minimo}</TableCell>
                        <TableCell>
                          <Badge variant={p.status === 'baixo' ? 'destructive' : 'secondary'}>
                            {p.status === 'baixo' ? 'Baixo' : 'OK'}
                          </Badge>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader><CardTitle className="text-sm">Histórico de Movimentos</CardTitle></CardHeader>
            <CardContent>
              <div className="max-h-[280px] overflow-y-auto">
                <div className="space-y-3 md:hidden">
                  {stockMovements.map((m) => (
                    <div key={m.id} className="rounded-lg border p-3 space-y-2">
                      <div className="flex items-start justify-between gap-3">
                        <div className="font-medium">{m.article?.nome ?? '-'}</div>
                        <div className="text-xs text-muted-foreground">{formatDateYmd(m.created_at)}</div>
                      </div>
                      <div className="grid grid-cols-2 gap-2 text-sm">
                        <div>
                          <div className="text-xs text-muted-foreground">Tipo</div>
                          <div>{movTypeLabel[m.movement_type] ?? m.movement_type}</div>
                        </div>
                        <div>
                          <div className="text-xs text-muted-foreground">Quantidade</div>
                          <div>{m.quantity}</div>
                        </div>
                      </div>
                      <div>
                        <div className="text-xs text-muted-foreground">Notas</div>
                        <div className="text-sm">{m.notes || '-'}</div>
                      </div>
                    </div>
                  ))}
                </div>

                <div className="hidden md:block">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Artigo</TableHead>
                        <TableHead>Tipo</TableHead>
                        <TableHead>Qtd.</TableHead>
                        <TableHead>Data</TableHead>
                        <TableHead>Notas</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {stockMovements.map((m) => (
                        <TableRow key={m.id}>
                          <TableCell>{m.article?.nome ?? '-'}</TableCell>
                          <TableCell>{movTypeLabel[m.movement_type] ?? m.movement_type}</TableCell>
                          <TableCell>{m.quantity}</TableCell>
                          <TableCell>{formatDateYmd(m.created_at)}</TableCell>
                          <TableCell>{m.notes || '-'}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* ── Empréstimos ────────────────────────────────────────────────── */}
        <TabsContent value="emprestimos" className={`${moduleTabbedContentClass} space-y-3`}>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle className="text-sm">Empréstimos</CardTitle>
              <Dialog
                open={loanDialogOpen}
                onOpenChange={(open) => {
                  setLoanDialogOpen(open);
                  if (!open) { setEditingLoanId(null); loanForm.reset(); loanEditForm.reset(); }
                }}
              >
                <DialogTrigger asChild>
                  <Button onClick={() => setEditingLoanId(null)}>Novo Empréstimo</Button>
                </DialogTrigger>
                <DialogContent className="max-w-2xl">
                  <DialogHeader>
                    <DialogTitle>{editingLoanId ? 'Editar Empréstimo' : 'Novo Empréstimo'}</DialogTitle>
                  </DialogHeader>
                  <form onSubmit={editingLoanId ? submitLoanUpdate : submitLoan} className="space-y-3">
                    <div className="grid md:grid-cols-2 gap-3">
                      <div>
                        <Label>Utilizador (opcional)</Label>
                        <Select
                          value={editingLoanId ? loanEditForm.data.borrower_user_id : loanForm.data.borrower_user_id}
                          onValueChange={(v) => editingLoanId ? loanEditForm.setData('borrower_user_id', v) : loanForm.setData('borrower_user_id', v)}
                        >
                          <SelectTrigger className={ws}><SelectValue placeholder="Opcional" /></SelectTrigger>
                          <SelectContent>{users.map((u) => <SelectItem key={u.id} value={u.id}>{u.nome_completo}</SelectItem>)}</SelectContent>
                        </Select>
                      </div>
                      <div>
                        <Label>Nome do requisitante</Label>
                        <Input className={wi}
                          value={editingLoanId ? loanEditForm.data.borrower_name_snapshot : loanForm.data.borrower_name_snapshot}
                          onChange={(e) => editingLoanId ? loanEditForm.setData('borrower_name_snapshot', e.target.value) : loanForm.setData('borrower_name_snapshot', e.target.value)} />
                      </div>
                    </div>
                    <div className="grid md:grid-cols-3 gap-3">
                      <div>
                        <Label>Artigo</Label>
                        <Select
                          value={editingLoanId ? loanEditForm.data.article_id : loanForm.data.article_id}
                          onValueChange={(v) => editingLoanId ? loanEditForm.setData('article_id', v) : loanForm.setData('article_id', v)}
                        >
                          <SelectTrigger className={ws}><SelectValue placeholder="Selecionar" /></SelectTrigger>
                          <SelectContent>{loanableProducts.map((p) => <SelectItem key={p.id} value={p.id}>{p.nome}</SelectItem>)}</SelectContent>
                        </Select>
                      </div>
                      <div>
                        <Label>Quantidade</Label>
                        <Input className={wi} type="number" min={1}
                          value={editingLoanId ? loanEditForm.data.quantity : loanForm.data.quantity}
                          onChange={(e) => editingLoanId ? loanEditForm.setData('quantity', Number(e.target.value || 1)) : loanForm.setData('quantity', Number(e.target.value || 1))} />
                      </div>
                      <div>
                        <Label>Data limite</Label>
                        <Input className={wi} type="date"
                          value={editingLoanId ? loanEditForm.data.due_date : loanForm.data.due_date}
                          onChange={(e) => editingLoanId ? loanEditForm.setData('due_date', e.target.value) : loanForm.setData('due_date', e.target.value)} />
                      </div>
                    </div>
                    <div>
                      <Label>Notas</Label>
                      <Input className={wi}
                        value={editingLoanId ? loanEditForm.data.notes : loanForm.data.notes}
                        onChange={(e) => editingLoanId ? loanEditForm.setData('notes', e.target.value) : loanForm.setData('notes', e.target.value)} />
                    </div>
                    <Button type="submit" disabled={loanForm.processing || loanEditForm.processing}>
                      {editingLoanId ? 'Guardar alterações' : 'Criar Empréstimo'}
                    </Button>
                  </form>
                </DialogContent>
              </Dialog>
            </CardHeader>
            <CardContent>
              <div className="grid md:grid-cols-3 gap-3 mb-4">
                <div>
                  <Label>Pesquisar</Label>
                  <Input className={wi} placeholder="Requisitante ou artigo" value={loanSearch} onChange={(e) => setLoanSearch(e.target.value)} />
                </div>
                <div>
                  <Label>Estado</Label>
                  <Select value={loanStatusFilter} onValueChange={setLoanStatusFilter}>
                    <SelectTrigger className={ws}><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Todos</SelectItem>
                      {Object.entries(statusLoanLabel).map(([k, v]) => <SelectItem key={k} value={k}>{v}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="flex items-end">
                  <Button type="button" variant="secondary" onClick={() => { setLoanSearch(''); setLoanStatusFilter('all'); }}>
                    Limpar filtros
                  </Button>
                </div>
              </div>
              <div className="space-y-3 md:hidden">
                {filteredLoans.map((loan) => (
                  <div key={loan.id} className="rounded-lg border p-3 space-y-3">
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <div className="font-medium truncate">{loan.borrower_name_snapshot}</div>
                        <div className="text-xs text-muted-foreground truncate">{loan.article_name_snapshot}</div>
                      </div>
                      <Badge variant={loan.status === 'overdue' ? 'destructive' : loan.status === 'returned' ? 'secondary' : 'default'}>
                        {statusLoanLabel[loan.status] ?? loan.status}
                      </Badge>
                    </div>

                    <div className="grid grid-cols-2 gap-2 text-sm">
                      <div>
                        <div className="text-xs text-muted-foreground">Quantidade</div>
                        <div>{loan.quantity}</div>
                      </div>
                      <div>
                        <div className="text-xs text-muted-foreground">Data</div>
                        <div>{formatDateYmd(loan.loan_date)}</div>
                      </div>
                      <div>
                        <div className="text-xs text-muted-foreground">Limite</div>
                        <div>{loan.due_date ? formatDateYmd(loan.due_date) : '-'}</div>
                      </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                      {['active', 'overdue'].includes(loan.status) && (
                        <>
                          <Button size="sm" variant="secondary" onClick={() => openEditLoan(loan)}>Editar</Button>
                          <Button size="sm" onClick={() => router.post(route('logistica.emprestimos.return', loan.id))}>Devolver</Button>
                          <Button size="sm" variant="destructive" onClick={() => deleteLoan(loan.id)}>Apagar</Button>
                        </>
                      )}
                    </div>
                  </div>
                ))}
              </div>

              <div className="hidden md:block">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Requisitante</TableHead>
                      <TableHead>Artigo</TableHead>
                      <TableHead>Qtd.</TableHead>
                      <TableHead>Data</TableHead>
                      <TableHead>Limite</TableHead>
                      <TableHead>Estado</TableHead>
                      <TableHead>Ações</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {filteredLoans.map((loan) => (
                      <TableRow key={loan.id}>
                        <TableCell>{loan.borrower_name_snapshot}</TableCell>
                        <TableCell>{loan.article_name_snapshot}</TableCell>
                        <TableCell>{loan.quantity}</TableCell>
                        <TableCell>{formatDateYmd(loan.loan_date)}</TableCell>
                        <TableCell>{loan.due_date ? formatDateYmd(loan.due_date) : '-'}</TableCell>
                        <TableCell>
                          <Badge variant={loan.status === 'overdue' ? 'destructive' : loan.status === 'returned' ? 'secondary' : 'default'}>
                            {statusLoanLabel[loan.status] ?? loan.status}
                          </Badge>
                        </TableCell>
                        <TableCell>
                          <div className="flex flex-wrap gap-1">
                            {['active', 'overdue'].includes(loan.status) && (
                              <>
                                <Button size="sm" variant="secondary" onClick={() => openEditLoan(loan)}>Editar</Button>
                                <Button size="sm" onClick={() => router.post(route('logistica.emprestimos.return', loan.id))}>Devolver</Button>
                                <Button size="sm" variant="destructive" onClick={() => deleteLoan(loan.id)}>Apagar</Button>
                              </>
                            )}
                          </div>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* ── Fornecedores ───────────────────────────────────────────────── */}
        <TabsContent value="fornecedores" className={`${moduleTabbedContentClass} space-y-3`}>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle className="text-sm">Compras Registadas e Referência Financeira</CardTitle>
              <Dialog
                open={purchaseDialogOpen}
                onOpenChange={(open) => {
                  setPurchaseDialogOpen(open);
                  if (!open) { setEditingPurchaseId(null); purchaseForm.reset(); purchaseEditForm.reset(); }
                }}
              >
                <DialogTrigger asChild>
                  <Button onClick={() => setEditingPurchaseId(null)}>Registar compra</Button>
                </DialogTrigger>
                <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
                  <DialogHeader>
                    <DialogTitle>{editingPurchaseId ? 'Editar compra a fornecedor' : 'Registar compra a fornecedor'}</DialogTitle>
                  </DialogHeader>
                  <form onSubmit={editingPurchaseId ? submitPurchaseUpdate : submitPurchase} className="space-y-3">
                    <div className="grid md:grid-cols-3 gap-3">
                      <div>
                        <Label>Fornecedor</Label>
                        <Select
                          value={editingPurchaseId ? purchaseEditForm.data.supplier_id : purchaseForm.data.supplier_id}
                          onValueChange={(v) => editingPurchaseId ? purchaseEditForm.setData('supplier_id', v) : purchaseForm.setData('supplier_id', v)}
                        >
                          <SelectTrigger className={ws}><SelectValue placeholder="Selecionar" /></SelectTrigger>
                          <SelectContent>{suppliers.map((s) => <SelectItem key={s.id} value={s.id}>{s.nome}</SelectItem>)}</SelectContent>
                        </Select>
                      </div>
                      <div>
                        <Label>Ref. Fatura Fornecedor</Label>
                        <Input className={wi}
                          value={editingPurchaseId ? purchaseEditForm.data.invoice_reference : purchaseForm.data.invoice_reference}
                          onChange={(e) => editingPurchaseId ? purchaseEditForm.setData('invoice_reference', e.target.value) : purchaseForm.setData('invoice_reference', e.target.value)}
                          required />
                      </div>
                      <div>
                        <Label>Data da Fatura</Label>
                        <Input className={wi} type="date"
                          value={editingPurchaseId ? purchaseEditForm.data.invoice_date : purchaseForm.data.invoice_date}
                          onChange={(e) => editingPurchaseId ? purchaseEditForm.setData('invoice_date', e.target.value) : purchaseForm.setData('invoice_date', e.target.value)}
                          required />
                      </div>
                    </div>
                    <div className="space-y-2">
                      <Label>Stock de artigos comprados</Label>
                      {purchaseItems(!!editingPurchaseId).map((item, idx) => (
                        <div key={idx} className="grid grid-cols-12 gap-2">
                          <div className="col-span-6">
                            <Select value={item.article_id} onValueChange={(v) => setPurchaseItems(!!editingPurchaseId, purchaseItems(!!editingPurchaseId).map((l, i) => i === idx ? { ...l, article_id: v } : l))}>
                              <SelectTrigger className={ws}><SelectValue placeholder="Artigo" /></SelectTrigger>
                              <SelectContent>{stockManagedProducts.map((p) => <SelectItem key={p.id} value={p.id}>{p.nome}</SelectItem>)}</SelectContent>
                            </Select>
                          </div>
                          <div className="col-span-2">
                            <Input className={wi} type="number" min={1} value={item.quantity}
                              onChange={(e) => setPurchaseItems(!!editingPurchaseId, purchaseItems(!!editingPurchaseId).map((l, i) => i === idx ? { ...l, quantity: Number(e.target.value || 1) } : l))} />
                          </div>
                          <div className="col-span-4">
                            <Input className={wi} type="number" min={0} step="0.01" value={item.unit_cost}
                              onChange={(e) => setPurchaseItems(!!editingPurchaseId, purchaseItems(!!editingPurchaseId).map((l, i) => i === idx ? { ...l, unit_cost: e.target.value } : l))}
                              placeholder="Preço de compra unitário" />
                          </div>
                        </div>
                      ))}
                    </div>
                    <div className="space-x-2">
                      <Button type="button" variant="secondary" onClick={() => setPurchaseItems(!!editingPurchaseId, [...purchaseItems(!!editingPurchaseId), { article_id: '', quantity: 1, unit_cost: '' }])}>
                        Adicionar item
                      </Button>
                      <Button type="submit" disabled={purchaseForm.processing || purchaseEditForm.processing}>
                        {editingPurchaseId ? 'Guardar alterações' : 'Registar compra'}
                      </Button>
                    </div>
                  </form>
                </DialogContent>
              </Dialog>
            </CardHeader>
            <CardContent>
              <div className="grid md:grid-cols-4 gap-3 mb-4">
                <div>
                  <Label>Pesquisar</Label>
                  <Input className={wi} placeholder="Fornecedor ou referência" value={purchaseSearch} onChange={(e) => setPurchaseSearch(e.target.value)} />
                </div>
                <div>
                  <Label>Fornecedor</Label>
                  <Select value={purchaseSupplierFilter} onValueChange={setPurchaseSupplierFilter}>
                    <SelectTrigger className={ws}><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Todos</SelectItem>
                      {suppliers.map((s) => <SelectItem key={s.id} value={s.id}>{s.nome}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label>Data da fatura</Label>
                  <Input className={wi} type="date" value={purchaseDateFilter} onChange={(e) => setPurchaseDateFilter(e.target.value)} />
                </div>
                <div className="flex items-end">
                  <Button type="button" variant="secondary" onClick={() => { setPurchaseSearch(''); setPurchaseSupplierFilter('all'); setPurchaseDateFilter(''); }}>
                    Limpar filtros
                  </Button>
                </div>
              </div>
              <div className="space-y-3 md:hidden">
                {filteredSupplierPurchases.map((p) => (
                  <div key={p.id} className="rounded-lg border p-3 space-y-3">
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <div className="font-medium truncate">{p.supplier_name_snapshot}</div>
                        <div className="text-xs text-muted-foreground truncate">{p.invoice_reference}</div>
                      </div>
                      <div className="text-sm font-medium">{euro(p.total_amount)}</div>
                    </div>

                    <div className="grid grid-cols-2 gap-2 text-sm">
                      <div>
                        <div className="text-xs text-muted-foreground">Data</div>
                        <div>{formatDateYmd(p.invoice_date)}</div>
                      </div>
                      <div>
                        <div className="text-xs text-muted-foreground">Mov. Financeiro</div>
                        <div>{p.financial_movement_id ? p.financial_movement_id.slice(0, 8) : '-'}</div>
                      </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                      <Button size="sm" variant="secondary" onClick={() => openEditPurchase(p)}>Editar</Button>
                      <Button size="sm" variant="destructive" onClick={() => deletePurchase(p.id)}>Apagar</Button>
                    </div>
                  </div>
                ))}
              </div>

              <div className="hidden md:block">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Fornecedor</TableHead>
                      <TableHead>Ref. Fatura</TableHead>
                      <TableHead>Data</TableHead>
                      <TableHead>Total</TableHead>
                      <TableHead>Mov. Financeiro</TableHead>
                      <TableHead>Ações</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {filteredSupplierPurchases.map((p) => (
                      <TableRow key={p.id}>
                        <TableCell>{p.supplier_name_snapshot}</TableCell>
                        <TableCell>{p.invoice_reference}</TableCell>
                        <TableCell>{formatDateYmd(p.invoice_date)}</TableCell>
                        <TableCell>{euro(p.total_amount)}</TableCell>
                        <TableCell>{p.financial_movement_id ? p.financial_movement_id.slice(0, 8) : '-'}</TableCell>
                        <TableCell className="space-x-1">
                          <Button size="sm" variant="secondary" onClick={() => openEditPurchase(p)}>Editar</Button>
                          <Button size="sm" variant="destructive" onClick={() => deletePurchase(p.id)}>Apagar</Button>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </CardContent>
          </Card>
        </TabsContent>
        </Tabs>
      </div>
    </AuthenticatedLayout>
  );
}
