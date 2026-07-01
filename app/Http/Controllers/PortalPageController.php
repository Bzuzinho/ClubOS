<?php

namespace App\Http\Controllers;

use App\Http\Requests\Communication\StoreInternalMessageRequest;
use App\Models\InternalMessage;
use App\Models\InternalMessageRecipient;
use App\Models\Invoice;
use App\Models\LogisticsRequest;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Result;
use App\Models\User;
use App\Services\Communication\InAppAlertService;
use App\Services\Communication\InternalCommunicationService;
use App\Services\Family\FamilyService;
use App\Services\Financeiro\CurrentAccountService;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PortalPageController extends Controller
{
    public function events(Request $request, FamilyService $familyService): Response
    {
        return $this->renderPage('Portal/Events', $request, $familyService);
    }

    public function payments(Request $request, FamilyService $familyService): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Portal/Payments', array_merge(
            $this->buildPaymentsPayload($user),
            [
                'is_also_admin' => $familyService->userHasAdministratorProfile($user),
                'has_family' => $familyService->userHasFamily($user),
            ],
        ));
    }

    public function results(Request $request, FamilyService $familyService): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Portal/Results', array_merge(
            $this->buildResultsPayload($user),
            [
                'is_also_admin' => $familyService->userHasAdministratorProfile($user),
                'has_family' => $familyService->userHasFamily($user),
            ],
        ));
    }

    public function documents(Request $request, FamilyService $familyService): Response
    {
        return $this->renderPage('Portal/Documents', $request, $familyService);
    }

    public function communications(
        Request $request,
        FamilyService $familyService,
        InternalCommunicationService $internalCommunicationService,
    ): Response
    {
        /** @var User $user */
        $user = $request->user();

        $payload = $this->buildCommunicationsPayload($user, $internalCommunicationService);
        $payload['communicationState'] = [
            'initialFolder' => $request->string('folder')->value() ?: 'received',
            'initialMessageId' => $request->string('message')->value() ?: null,
        ];

        return Inertia::render('Portal/Communications', array_merge(
            $payload,
            [
                'is_also_admin' => $familyService->userHasAdministratorProfile($user),
                'has_family' => $familyService->userHasFamily($user),
            ],
        ));
    }

    public function storeCommunication(
        StoreInternalMessageRequest $request,
        InternalCommunicationService $internalCommunicationService,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $recipientIds = collect($request->validated('recipient_ids', []))
            ->filter(fn ($id) => $id !== $user->id)
            ->values();

        if ($recipientIds->isEmpty()) {
            return back()->withErrors([
                'recipient_ids' => 'Selecione pelo menos um destinatário diferente do utilizador atual.',
            ]);
        }

        $payload = $request->validated();
        $payload['recipient_ids'] = $recipientIds->all();
        $payload['type'] = $payload['type'] ?? 'info';

        $internalCommunicationService->send($user, $payload);

        return back()->with('success', 'Comunicação interna enviada com sucesso.');
    }

    public function markCommunicationRead(
        Request $request,
        InternalCommunicationService $internalCommunicationService,
        InAppAlertService $inAppAlertService,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $source = (string) $request->validate([
            'source' => ['required', 'string', Rule::in(['alert', 'internal'])],
        ])['source'];

        if ($source === 'alert') {
            $alertId = (string) $request->validate([
                'alert_id' => [
                    'required',
                    'string',
                    Rule::exists('in_app_alerts', 'id')->where(fn ($query) => $query->where('user_id', $user->id)),
                ],
            ])['alert_id'];

            $inAppAlertService->markAsRead($alertId, $user->id);

            return back()->with('success', 'Comunicado marcado como lido.');
        }

        $recipientEntryId = (string) $request->validate([
            'recipient_entry_id' => [
                'required',
                'string',
                Rule::exists('internal_message_recipients', 'id')->where(
                    fn ($query) => $query->where('recipient_id', $user->id)->whereNull('deleted_at')
                ),
            ],
        ])['recipient_entry_id'];

        $recipient = InternalMessageRecipient::query()->findOrFail($recipientEntryId);
        $internalCommunicationService->markAsRead($recipient);

        return back()->with('success', 'Comunicado marcado como lido.');
    }

    public function markCommunicationUnread(
        Request $request,
        InternalCommunicationService $internalCommunicationService,
        InAppAlertService $inAppAlertService,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $source = (string) $request->validate([
            'source' => ['required', 'string', Rule::in(['alert', 'internal'])],
        ])['source'];

        if ($source === 'alert') {
            $alertId = (string) $request->validate([
                'alert_id' => [
                    'required',
                    'string',
                    Rule::exists('in_app_alerts', 'id')->where(fn ($query) => $query->where('user_id', $user->id)),
                ],
            ])['alert_id'];

            $inAppAlertService->markAsUnread($alertId, $user->id);

            return back()->with('success', 'Comunicado marcado como não lido.');
        }

        $recipientEntryId = (string) $request->validate([
            'recipient_entry_id' => [
                'required',
                'string',
                Rule::exists('internal_message_recipients', 'id')->where(
                    fn ($query) => $query->where('recipient_id', $user->id)->whereNull('deleted_at')
                ),
            ],
        ])['recipient_entry_id'];

        $recipient = InternalMessageRecipient::query()->findOrFail($recipientEntryId);
        $internalCommunicationService->markAsUnread($recipient);

        return back()->with('success', 'Comunicado marcado como não lido.');
    }

    public function markAllCommunicationsRead(
        Request $request,
        InternalCommunicationService $internalCommunicationService,
        InAppAlertService $inAppAlertService,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $internalCommunicationService->markAllReceivedAsRead($user->id);
        $inAppAlertService->markAllAsRead($user->id);

        return back()->with('success', 'Todos os comunicados foram marcados como lidos.');
    }

    public function destroyReceivedCommunication(
        Request $request,
        InternalCommunicationService $internalCommunicationService,
        InAppAlertService $inAppAlertService,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $source = (string) $request->validate([
            'source' => ['required', 'string', Rule::in(['alert', 'internal'])],
        ])['source'];

        if ($source === 'alert') {
            $alertId = (string) $request->validate([
                'alert_id' => [
                    'required',
                    'string',
                    Rule::exists('in_app_alerts', 'id')->where(fn ($query) => $query->where('user_id', $user->id)),
                ],
            ])['alert_id'];

            $inAppAlertService->deleteForUser($alertId, $user->id);

            return back()->with('success', 'Comunicação removida da caixa de entrada.');
        }

        $recipientEntryId = (string) $request->validate([
            'recipient_entry_id' => [
                'required',
                'string',
                Rule::exists('internal_message_recipients', 'id')->where(
                    fn ($query) => $query->where('recipient_id', $user->id)->whereNull('deleted_at')
                ),
            ],
        ])['recipient_entry_id'];

        $recipient = InternalMessageRecipient::query()->findOrFail($recipientEntryId);
        $internalCommunicationService->deleteReceived($recipient);

        return back()->with('success', 'Comunicação removida da caixa de entrada.');
    }

    public function destroySentCommunication(
        Request $request,
        InternalMessage $message,
        InternalCommunicationService $internalCommunicationService,
    ): RedirectResponse {
        abort_unless($message->sender_id === $request->user()->id, 403);

        $internalCommunicationService->deleteSent($message);

        return back()->with('success', 'Comunicação removida dos enviados.');
    }

    public function shop(Request $request, FamilyService $familyService): Response
    {
        /** @var User $user */
        $user = $request->user();

        $products = Product::query()
            ->active()
            ->visibleInStore()
            ->orderBy('nome')
            ->get()
            ->map(function (Product $product) {
                $availableStock = $product->available_stock;
                $variantOptions = array_values(array_filter((array) ($product->variant_options ?? [])));

                $availability = 'available';
                $availabilityLabel = 'Disponível';

                if ($availableStock <= 0) {
                    $availability = $product->ativo ? 'on_order' : 'unavailable';
                    $availabilityLabel = $product->ativo ? 'Por encomenda' : 'Indisponível';
                } elseif ($product->is_low_stock) {
                    $availability = 'limited';
                    $availabilityLabel = 'Stock limitado';
                }

                return [
                    'id' => $product->id,
                    'name' => $product->nome,
                    'description' => $product->descricao,
                    'price' => $product->preco !== null ? (float) $product->preco : null,
                    'category' => $product->categoria ?: 'Geral',
                    'availability' => $availability,
                    'availability_label' => $availabilityLabel,
                    'stock_available' => $availableStock,
                    'sizes' => $variantOptions,
                    'has_price' => $product->preco !== null && (float) $product->preco > 0,
                ];
            })
            ->values();

        $requests = LogisticsRequest::query()
            ->where(function (Builder $query) use ($user) {
                $query->where('requester_user_id', $user->id)
                    ->orWhere('created_by', $user->id);
            })
            ->with('items:id,logistics_request_id,article_name_snapshot')
            ->latest()
            ->limit(20)
            ->get()
            ->map(function (LogisticsRequest $request) {
                return [
                    'id' => $request->id,
                    'article' => $request->items->first()?->article_name_snapshot ?: 'Pedido sem artigo associado',
                    'requested_at' => $request->created_at?->format('Y-m-d H:i:s'),
                    'status' => $request->status,
                    'status_label' => match ($request->status) {
                        'approved' => 'Aprovado',
                        'delivered' => 'Entregue',
                        'cancelled' => 'Rejeitado',
                        default => 'Pendente',
                    },
                    'has_invoice' => ! empty($request->financial_invoice_id),
                    'total_amount' => (float) $request->total_amount,
                ];
            })
            ->values();

        $categories = $products
            ->pluck('category')
            ->filter()
            ->unique()
            ->values();

        $summary = [
            'available_articles' => $products->count(),
            'pending_requests' => $requests->where('status', 'pending')->count(),
            'delivered_requests' => $requests->where('status', 'delivered')->count(),
            'low_stock_articles' => $products->where('availability', 'limited')->count(),
        ];

        return Inertia::render('Portal/Shop', [
            'is_also_admin' => $familyService->userHasAdministratorProfile($user),
            'has_family' => $familyService->userHasFamily($user),
            'shop' => [
                'summary' => $summary,
                'products' => $products,
                'requests' => $requests,
                'categories' => $categories,
                'notes' => [
                    'Os pedidos dependem sempre de confirmação de stock pelo clube.',
                    'Artigos pagos podem gerar fatura quando a regra financeira estiver ativa.',
                    'Requisições e empréstimos podem exigir aprovação antes da entrega.',
                ],
            ],
        ]);
    }

    private function renderPage(string $component, Request $request, FamilyService $familyService): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render($component, [
            'is_also_admin' => $familyService->userHasAdministratorProfile($user),
            'has_family' => $familyService->userHasFamily($user),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPaymentsPayload(User $user): array
    {
        $today = now()->startOfDay();
        $currentAccountService = app(CurrentAccountService::class);
        $accountSummary = $currentAccountService->summarize([
            'user_id' => $user->id,
        ]);

        $user->loadMissing('dadosFinanceiros.mensalidade');

        $openInvoices = collect($accountSummary['breakdown']['invoices'] ?? []);
        $openFinancialMovements = collect($accountSummary['breakdown']['movements'] ?? []);
        $visibleInvoices = Invoice::query()
            ->whereIn('id', $openInvoices->pluck('id')->filter()->all())
            ->get()
            ->keyBy('id');
        $visibleFinanceMovements = Movement::query()
            ->whereIn('id', $openFinancialMovements->pluck('id')->filter()->all())
            ->get()
            ->keyBy('id');

        $movements = $openInvoices
            ->map(fn (array $invoice) => $this->mapOpenInvoicePaymentMovement(
                $invoice,
                $today,
                $visibleInvoices->get($invoice['id'] ?? null),
            ))
            ->concat($openFinancialMovements->map(fn (array $movement) => $this->mapOpenFinancialMovement(
                $movement,
                $today,
                $visibleFinanceMovements->get($movement['id'] ?? null),
            )))
            ->sortByDesc(fn (array $movement) => sprintf('%s-%s', $movement['date'] ?? '', $movement['id']))
            ->values();

        $nextPayment = $movements
            ->sortBy([
                fn (array $movement) => $movement['due_date'] === null ? 1 : 0,
                'due_date',
                'date',
            ])
            ->first();

        $receipts = Invoice::query()
            ->where('user_id', $user->id)
            ->where('oculta', false)
            ->whereNotNull('numero_recibo')
            ->where(function (Builder $query) use ($today): void {
                $query->whereNull('data_fatura')
                    ->orWhereDate('data_fatura', '<=', $today->toDateString());
            })
            ->orderByDesc('data_emissao')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Invoice $invoice) => [
                'id' => (string) $invoice->id,
                'receipt_number' => (string) $invoice->numero_recibo,
                'date' => optional($invoice->data_emissao ?: $invoice->data_fatura)->toDateString(),
                'amount' => round((float) ($invoice->valor_pago ?: $invoice->valor_total), 2),
                'can_view_receipt' => true,
            ])
            ->concat(
                Movement::query()
                    ->where('user_id', $user->id)
                    ->where('classificacao', 'receita')
                    ->whereNotNull('numero_recibo')
                    ->orderByDesc('data_emissao')
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(fn (Movement $movement) => [
                        'id' => (string) $movement->id,
                        'receipt_number' => (string) $movement->numero_recibo,
                        'date' => optional($movement->data_emissao)->toDateString(),
                        'amount' => round((float) $movement->valor_total, 2),
                        'can_view_receipt' => true,
                    ])
            )
            ->filter(fn (array $movement) => filled($movement['receipt_number']))
            ->sortByDesc('date')
            ->values();

        $receiptsThisYear = $receipts
            ->filter(fn (array $movement) => $movement['date'] !== null && (int) date('Y', strtotime((string) $movement['date'])) === now()->year)
            ->count();

        $grossDebt = round((float) ($accountSummary['gross_debt'] ?? 0), 2);
        $availableCredit = round((float) ($accountSummary['available_credit'] ?? 0), 2);
        $outstandingTotal = round((float) ($accountSummary['net_debt'] ?? 0), 2);
        $overdueTotal = round((float) ($accountSummary['overdue_debt'] ?? 0), 2);
        $plan = $user->dadosFinanceiros?->mensalidade?->designacao
            ?: (is_string($user->tipo_mensalidade) ? $user->tipo_mensalidade : null)
            ?: collect($accountSummary['breakdown']['invoices'] ?? [])->pluck('tipo')->filter()->first()
            ?: 'Sem plano definido';
        $generalStatus = $outstandingTotal > 0
            ? 'Pagamento pendente'
            : ($availableCredit > 0 && $grossDebt > 0
                ? 'Crédito disponível compensa a dívida'
                : ($availableCredit > 0 ? 'Crédito disponível' : 'Tudo em dia'));

        return [
            'user' => [
                'id' => $user->id,
                'name' => $this->displayName($user),
                'email' => $user->email,
            ],
            'secure_payment_enabled' => false,
            'hero' => [
                'title' => 'Pagamentos',
                'status' => $generalStatus,
                'outstanding_value' => $outstandingTotal,
                'next_payment' => $this->compactPaymentSummary($nextPayment),
                'actions' => [
                    'can_view_receipts' => $receipts->isNotEmpty(),
                    'can_view_history' => $movements->isNotEmpty(),
                    'can_pay' => false,
                ],
            ],
            'kpis' => [
                'outstanding_value' => $outstandingTotal,
                'next_payment' => $this->compactPaymentSummary($nextPayment),
                'plan' => $plan,
                'receipts_this_year' => $receiptsThisYear,
            ],
            'account_current' => [
                'outstanding_value' => $outstandingTotal,
                'gross_debt' => $grossDebt,
                'available_credit' => $availableCredit,
                'overdue_invoices' => $openInvoices->where('estado_pagamento', 'vencido')->count(),
                'overdue_value' => $overdueTotal,
                'next_payment' => $this->compactPaymentSummary($nextPayment),
                'plan' => $plan,
                'general_status' => $generalStatus,
                'deprecated' => [
                    'manual_account_balance' => round((float) ($accountSummary['manual_account_balance'] ?? 0), 2),
                ],
            ],
            'movements' => $movements->all(),
            'latest_receipts' => $receipts->take(6)->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPaymentMovement(Invoice $invoice, Carbon $today): array
    {
        return [
            'id' => $invoice->id,
            'description' => $invoice->mes ?: $invoice->tipo ?: 'Fatura',
            'date' => optional($invoice->data_emissao ?: $invoice->data_fatura)->toDateString(),
            'due_date' => optional($invoice->data_vencimento)->toDateString(),
            'amount' => round((float) $invoice->valor_total, 2),
            'status' => $this->normalizeStatus((string) $invoice->estado_pagamento, $invoice->data_vencimento, $today),
            'reference' => $invoice->referencia_pagamento,
            'receipt_number' => $invoice->numero_recibo,
            'payment_method' => null,
            'actions' => [
                'can_view_receipt' => filled($invoice->numero_recibo),
                'can_view_detail' => true,
                'can_pay' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    private function mapOpenInvoicePaymentMovement(array $invoice, Carbon $today, ?Invoice $visibleInvoice = null): array
    {
        return [
            'id' => (string) ($invoice['id'] ?? Str::uuid()),
            'description' => trim((string) (($invoice['mes'] ?? null) ?: ($invoice['tipo'] ?? null) ?: 'Fatura')) ?: 'Fatura',
            'date' => $invoice['data_fatura'] ?? null,
            'due_date' => $invoice['data_vencimento'] ?? null,
            'amount' => round((float) ($invoice['valor_em_aberto'] ?? 0), 2),
            'nominal_amount' => round((float) ($invoice['valor_total'] ?? 0), 2),
            'paid_amount' => round((float) ($invoice['valor_pago'] ?? 0), 2),
            'amount_label' => 'Em aberto',
            'type_label' => 'Fatura',
            'status' => $this->normalizeStatus((string) ($invoice['estado_pagamento'] ?? ''), $invoice['data_vencimento'] ?? null, $today),
            'reference' => $visibleInvoice?->referencia_pagamento,
            'receipt_number' => $visibleInvoice?->numero_recibo,
            'payment_method' => null,
            'actions' => [
                'can_view_receipt' => filled($visibleInvoice?->numero_recibo),
                'can_view_detail' => true,
                'can_pay' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $movement
     * @return array<string, mixed>
     */
    private function mapOpenFinancialMovement(array $movement, Carbon $today, ?Movement $visibleMovement = null): array
    {
        $legacyDescription = $visibleMovement
            ? ($visibleMovement->referencia_pagamento
                ? 'Loja - ' . $visibleMovement->referencia_pagamento
                : 'Movimento material')
            : null;

        return [
            'id' => (string) ($movement['id'] ?? Str::uuid()),
            'description' => trim((string) ($legacyDescription ?: ($movement['description'] ?? 'Movimento'))) ?: 'Movimento',
            'date' => $movement['data_emissao'] ?? null,
            'due_date' => $movement['data_vencimento'] ?? null,
            'amount' => round((float) ($movement['open_amount'] ?? 0), 2),
            'nominal_amount' => null,
            'paid_amount' => null,
            'amount_label' => 'Em aberto',
            'type_label' => 'Movimento',
            'status' => $this->normalizeStatus((string) ($movement['estado_pagamento'] ?? ''), $movement['data_vencimento'] ?? null, $today),
            'reference' => $visibleMovement?->referencia_pagamento,
            'receipt_number' => $visibleMovement?->numero_recibo,
            'payment_method' => $visibleMovement?->metodo_pagamento,
            'actions' => [
                'can_view_receipt' => filled($visibleMovement?->numero_recibo),
                'can_view_detail' => true,
                'can_pay' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapFinancialMovement(Movement $movement, Carbon $today): array
    {
        return [
            'id' => $movement->id,
            'description' => $movement->referencia_pagamento ? 'Loja - ' . $movement->referencia_pagamento : 'Movimento material',
            'date' => optional($movement->data_emissao)->toDateString(),
            'due_date' => optional($movement->data_vencimento)->toDateString(),
            'amount' => round((float) $movement->valor_total, 2),
            'status' => $this->normalizeStatus((string) $movement->estado_pagamento, $movement->data_vencimento, $today),
            'reference' => $movement->referencia_pagamento,
            'receipt_number' => $movement->numero_recibo,
            'payment_method' => $movement->metodo_pagamento,
            'actions' => [
                'can_view_receipt' => filled($movement->numero_recibo),
                'can_view_detail' => true,
                'can_pay' => false,
            ],
        ];
    }

    /**
     * @return array{key:string,label:string}
     */
    private function normalizeStatus(string $rawStatus, mixed $dueDate, Carbon $today): array
    {
        $rawStatus = strtolower(trim($rawStatus));
        $dueDateValue = $dueDate instanceof Carbon
            ? $dueDate
            : ($dueDate ? Carbon::parse((string) $dueDate)->startOfDay() : null);

        if ($rawStatus === 'cancelado') {
            return ['key' => 'cancelled', 'label' => 'Cancelado'];
        }

        if ($rawStatus === 'pago') {
            return ['key' => 'paid', 'label' => 'Pago'];
        }

        if ($rawStatus === 'parcial') {
            return ['key' => 'partial', 'label' => 'Parcial'];
        }

        if ($rawStatus === 'vencido' || ($dueDateValue && $dueDateValue->lt($today))) {
            return ['key' => 'overdue', 'label' => 'Vencido'];
        }

        return ['key' => 'pending', 'label' => 'Pendente'];
    }

    /**
     * @param  array<string, mixed>|null  $movement
     * @return array<string, mixed>|null
     */
    private function compactPaymentSummary(?array $movement): ?array
    {
        if ($movement === null) {
            return null;
        }

        return [
            'id' => $movement['id'],
            'label' => $movement['description'],
            'date' => $movement['due_date'] ?: $movement['date'],
            'amount' => $movement['amount'],
            'status' => $movement['status'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCommunicationsPayload(User $user, InternalCommunicationService $internalCommunicationService): array
    {
        $receivedFeed = $internalCommunicationService->receivedFeed($user->id);
        $sentFeed = $internalCommunicationService->sentFeed($user->id);

        $items = $receivedFeed
            ->map(function (array $item) {
                $category = $this->categorizeCommunication($item);
                $requiresAction = $this->communicationRequiresAction($item, $category['key']);
                $state = $requiresAction
                    ? ['key' => 'action_required', 'label' => 'Ação necessária']
                    : ((bool) ($item['is_read'] ?? false)
                        ? ['key' => 'read', 'label' => 'Lido']
                        : ['key' => 'unread', 'label' => 'Não lido']);
                $message = trim((string) ($item['message'] ?? ''));
                $description = preg_replace('/\s+/', ' ', strip_tags($message) ?: '') ?: '';

                return [
                    'id' => (string) ($item['message_id'] ?? Str::uuid()),
                    'title' => trim((string) ($item['subject'] ?? 'Comunicado')) ?: 'Comunicado',
                    'description' => Str::limit($description, 140),
                    'body' => $message,
                    'date' => $item['created_at'] ?? null,
                    'category' => $category,
                    'state' => $state,
                    'source' => $item['source'] ?? 'internal',
                    'link' => filled($item['link'] ?? null) ? (string) $item['link'] : null,
                    'sender_name' => data_get($item, 'sender.name'),
                    'requires_action' => $requiresAction,
                    'can_mark_read' => ! (bool) ($item['is_read'] ?? false),
                    'mark_read_payload' => [
                        'source' => $item['source'] ?? 'internal',
                        'alert_id' => $item['alert_id'] ?? null,
                        'recipient_entry_id' => $item['recipient_entry_id'] ?? null,
                    ],
                    'actions' => [
                        'can_open' => true,
                        'can_open_detail' => true,
                        'can_respond' => $requiresAction || ($item['source'] ?? null) === 'internal' || filled($item['link'] ?? null),
                        'detail_label' => $requiresAction ? 'Responder / abrir detalhe' : 'Abrir detalhe',
                    ],
                ];
            })
            ->values();

        $currentMonthStart = now()->startOfMonth();
        $unreadCount = $items->where('state.key', 'unread')->count();
        $actionRequiredCount = $items->where('state.key', 'action_required')->count();
        $readCount = $items->where('state.key', 'read')->count();
        $totalThisMonth = $items->filter(function (array $item) use ($currentMonthStart) {
            if (! is_string($item['date'] ?? null) || $item['date'] === '') {
                return false;
            }

            return Carbon::parse($item['date'])->greaterThanOrEqualTo($currentMonthStart);
        })->count();

        $categoryOrder = [
            'treinos' => 'Treinos',
            'eventos' => 'Eventos',
            'financeiro' => 'Financeiro',
            'documentos' => 'Documentos',
            'geral' => 'Geral',
        ];

        $categories = collect($categoryOrder)
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'count' => $items->where('category.key', $key)->count(),
            ])
            ->values();

        return [
            'internalCommunications' => [
                'received' => $receivedFeed->values()->all(),
                'sent' => $sentFeed->values()->all(),
            ],
            'communications' => [
                'hero' => [
                    'title' => 'Comunicações',
                    'unread_label' => $unreadCount === 1 ? '1 comunicação por ler' : sprintf('%d comunicações por ler', $unreadCount),
                    'action_label' => $this->communicationActionHighlight($actionRequiredCount, $items),
                ],
                'kpis' => [
                    'unread' => $unreadCount,
                    'action_required' => $actionRequiredCount,
                    'total_this_month' => $totalThisMonth,
                    'read' => $readCount,
                ],
                'inbox' => [
                    'unread' => $unreadCount,
                    'action_required' => $actionRequiredCount,
                    'total_this_month' => $totalThisMonth,
                ],
                'categories' => $categories,
                'items' => $items,
                'empty_states' => [
                    'unread' => 'Sem comunicações por ler.',
                    'recent' => 'Não existem comunicações recentes.',
                    'pending' => 'Sem ações pendentes.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{key:string,label:string}
     */
    private function categorizeCommunication(array $item): array
    {
        $haystack = Str::lower(implode(' ', array_filter([
            (string) ($item['subject'] ?? ''),
            (string) ($item['message'] ?? ''),
            (string) ($item['link'] ?? ''),
        ])));

        if (Str::contains($haystack, ['pagamento', 'pagamentos', 'mensalidade', 'fatura', 'recibo', 'finance'])) {
            return ['key' => 'financeiro', 'label' => 'Financeiro'];
        }

        if (Str::contains($haystack, ['treino', 'treinos', 'convocat', 'presenca', 'presença', 'atleta'])) {
            return ['key' => 'treinos', 'label' => 'Treinos'];
        }

        if (Str::contains($haystack, ['evento', 'eventos', 'prova', 'reuniao', 'reunião', 'estagio', 'estágio'])) {
            return ['key' => 'eventos', 'label' => 'Eventos'];
        }

        if (Str::contains($haystack, ['document', 'ficheiro', 'anexo', 'licenca', 'licença', 'comprovativo', 'upload'])) {
            return ['key' => 'documentos', 'label' => 'Documentos'];
        }

        return ['key' => 'geral', 'label' => 'Geral'];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function communicationRequiresAction(array $item, string $categoryKey): bool
    {
        if ((bool) ($item['is_read'] ?? false)) {
            return false;
        }

        $haystack = Str::lower(implode(' ', array_filter([
            (string) ($item['subject'] ?? ''),
            (string) ($item['message'] ?? ''),
            (string) ($item['link'] ?? ''),
        ])));

        if (Str::contains($haystack, ['responder', 'resposta', 'confirmar', 'confirmacao', 'confirmação', 'pendente', 'regularizar', 'upload'])) {
            return true;
        }

        if ($categoryKey === 'treinos' && Str::contains($haystack, ['convocat', 'presenca', 'presença'])) {
            return true;
        }

        if ($categoryKey === 'financeiro' && Str::contains($haystack, ['pagamento', 'fatura', 'vencido', 'mensalidade'])) {
            return true;
        }

        return is_string($item['link'] ?? null)
            && Str::contains((string) $item['link'], ['/portal/eventos', '/portal/documentos', '/portal/pagamentos']);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     */
    private function communicationActionHighlight(int $actionRequiredCount, Collection $items): string
    {
        if ($actionRequiredCount === 0) {
            return 'Sem ações pendentes.';
        }

        $convocationPending = $items->first(function (array $item) {
            return ($item['state']['key'] ?? null) === 'action_required'
                && in_array($item['category']['key'] ?? null, ['treinos', 'eventos'], true);
        });

        if ($actionRequiredCount === 1 && $convocationPending) {
            return '1 convocatória exige resposta';
        }

        if ($actionRequiredCount === 1) {
            return '1 comunicação exige ação';
        }

        return sprintf('%d comunicações exigem ação', $actionRequiredCount);
    }

    private function displayName(User $user): string
    {
        return app(MemberIdentityDisplayResolver::class)->displayNameOrFallback($user, 'Utilizador');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResultsPayload(User $user): array
    {
        $profileTypes = $this->resolveProfileLabels($user);
        $isAthlete = $this->hasMemberType($user, 'atleta');

        $basePayload = [
            'user' => [
                'id' => $user->id,
                'name' => $this->displayName($user),
                'email' => $user->email,
            ],
            'perfil_tipos' => $profileTypes,
            'is_athlete' => $isAthlete,
            'hero' => [
                'last_result' => null,
                'best_time' => null,
                'personal_record' => null,
            ],
            'kpis' => [
                'events_this_season' => 0,
                'personal_bests' => 0,
                'latest_time' => null,
                'main_event_evolution' => null,
                'main_event_label' => null,
            ],
            'latest_results' => [],
            'best_times' => [],
            'evolution' => [
                'has_data' => false,
                'prova' => null,
                'improvement_seconds' => null,
                'improvement_label' => 'Sem dados suficientes para evolução.',
                'entries' => [],
            ],
        ];

        if (! $isAthlete) {
            return $basePayload;
        }

        $results = $user->results()
            ->with(['prova.competition'])
            ->get()
            ->filter(fn (Result $result) => $result->prova !== null && $result->prova->competition !== null)
            ->sortByDesc(fn (Result $result) => $this->resolveResultSortKey($result))
            ->values();

        if ($results->isEmpty()) {
            return $basePayload;
        }

        $groupedByProva = $results
            ->groupBy(fn (Result $result) => $this->resolveResultGroupKey($result))
            ->map(fn (Collection $items) => $items
                ->sortBy(fn (Result $result) => $this->resolveResultSortKey($result))
                ->values());

        $seasonStart = $this->currentSeasonStart();
        $resultsThisSeason = $results
            ->filter(fn (Result $result) => $this->resolveCompetitionDate($result)?->greaterThanOrEqualTo($seasonStart))
            ->values();

        $latestResult = $results->first();
        $fastestResult = $results->sortBy(fn (Result $result) => (float) $result->tempo_oficial)->first();
        $latestIsPersonalBest = $latestResult ? $this->isPersonalBest($latestResult, $groupedByProva) : false;
        $mainEvolution = $this->buildMainEvolution($groupedByProva);

        return [
            'user' => $basePayload['user'],
            'perfil_tipos' => $profileTypes,
            'is_athlete' => true,
            'hero' => [
                'last_result' => $latestResult ? $this->mapHeroResult($latestResult, $latestIsPersonalBest) : null,
                'best_time' => $fastestResult ? $this->mapHeroResult($fastestResult, true) : null,
                'personal_record' => $latestResult
                    ? [
                        'label' => $latestIsPersonalBest ? 'Novo recorde pessoal' : 'Recorde pessoal ativo',
                        'context' => $latestIsPersonalBest
                            ? $this->resolveProvaLabel($latestResult)
                            : ($fastestResult ? $this->resolveProvaLabel($fastestResult) : null),
                    ]
                    : null,
            ],
            'kpis' => [
                'events_this_season' => $resultsThisSeason->count(),
                'personal_bests' => $groupedByProva->filter(function (Collection $items): bool {
                    $latest = $items->sortByDesc(fn (Result $result) => $this->resolveResultSortKey($result))->first();

                    return $latest instanceof Result && $this->isPersonalBest($latest, collect([$this->resolveResultGroupKey($latest) => $items]));
                })->count(),
                'latest_time' => $latestResult ? $this->formatRaceTime((float) $latestResult->tempo_oficial) : null,
                'main_event_evolution' => $mainEvolution['summary'],
                'main_event_label' => $mainEvolution['prova'],
            ],
            'latest_results' => $results
                ->take(6)
                ->values()
                ->map(fn (Result $result) => $this->mapResultCard($result, $groupedByProva))
                ->all(),
            'best_times' => $groupedByProva
                ->map(function (Collection $items) {
                    /** @var Result $best */
                    $best = $items->sortBy(fn (Result $result) => (float) $result->tempo_oficial)->first();

                    return [
                        'id' => $best->id,
                        'prova' => $this->resolveProvaLabel($best),
                        'best_time' => $this->formatRaceTime((float) $best->tempo_oficial),
                        'date' => optional($this->resolveCompetitionDate($best))->toDateString(),
                        'date_label' => $this->formatDateLabel($this->resolveCompetitionDate($best)),
                        'event' => $best->prova?->competition?->nome ?: 'Evento sem nome',
                    ];
                })
                ->sortBy('best_time')
                ->take(6)
                ->values()
                ->all(),
            'evolution' => $mainEvolution,
        ];
    }

    /**
     * @param  Collection<string, Collection<int, Result>>  $groupedByProva
     * @return array<string, mixed>
     */
    private function mapResultCard(Result $result, Collection $groupedByProva): array
    {
        $timeline = $groupedByProva->get($this->resolveResultGroupKey($result), collect());
        $currentIndex = $timeline->search(fn (Result $item) => $item->id === $result->id);
        $previousResult = is_int($currentIndex) && $currentIndex > 0 ? $timeline->get($currentIndex - 1) : null;
        $deltaSeconds = $previousResult instanceof Result ? round((float) $previousResult->tempo_oficial - (float) $result->tempo_oficial, 2) : null;
        $isPb = $this->isPersonalBest($result, $groupedByProva);

        $badges = [];

        if ($isPb) {
            $badges[] = ['key' => 'personal_best', 'label' => 'Recorde pessoal', 'tone' => 'blue'];
        }

        if ($this->isSeasonBest($result, $timeline)) {
            $badges[] = ['key' => 'season_best', 'label' => 'Melhor da época', 'tone' => 'emerald'];
        }

        if ($result->posicao !== null && (int) $result->posicao <= 3) {
            $badges[] = ['key' => 'podium', 'label' => 'Pódio', 'tone' => 'amber'];
        }

        if ($deltaSeconds !== null) {
            $badges[] = $deltaSeconds > 0
                ? ['key' => 'positive_evolution', 'label' => 'Evolução positiva', 'tone' => 'emerald']
                : ['key' => 'no_improvement', 'label' => 'Sem melhoria', 'tone' => 'slate'];
        }

        return [
            'id' => $result->id,
            'prova' => $this->resolveProvaLabel($result),
            'distance' => $result->prova?->distancia_m ? $result->prova->distancia_m . ' m' : 'Distância por definir',
            'style' => $result->prova?->estilo ?: 'Estilo por definir',
            'time' => $this->formatRaceTime((float) $result->tempo_oficial),
            'event' => $result->prova?->competition?->nome ?: 'Evento sem nome',
            'location' => $result->prova?->competition?->local ?: 'Local por definir',
            'date' => optional($this->resolveCompetitionDate($result))->toDateString(),
            'date_label' => $this->formatDateLabel($this->resolveCompetitionDate($result)),
            'ranking' => $result->posicao !== null ? '#' . (int) $result->posicao : 'Sem classificação',
            'evolution_label' => $this->formatEvolutionLabel($deltaSeconds),
            'evolution_seconds' => $deltaSeconds,
            'badges' => $badges,
            'details' => [
                'official_time' => $this->formatRaceTime((float) $result->tempo_oficial),
                'previous_time' => $previousResult instanceof Result ? $this->formatRaceTime((float) $previousResult->tempo_oficial) : null,
                'points_fina' => $result->pontos_fina,
                'notes' => $result->observacoes,
            ],
        ];
    }

    /**
     * @param  Collection<string, Collection<int, Result>>  $groupedByProva
     * @return array<string, mixed>
     */
    private function buildMainEvolution(Collection $groupedByProva): array
    {
        /** @var Collection<int, Result>|null $timeline */
        $timeline = $groupedByProva
            ->sortByDesc(fn (Collection $items) => $items->count())
            ->first();

        if (! $timeline instanceof Collection || $timeline->count() < 2) {
            return [
                'has_data' => false,
                'prova' => null,
                'improvement_seconds' => null,
                'improvement_label' => 'Sem dados suficientes para evolução.',
                'summary' => 'Sem dados',
                'entries' => [],
            ];
        }

        /** @var Result $first */
        $first = $timeline->first();
        /** @var Result $latest */
        $latest = $timeline->last();
        $fastestSeconds = (float) $timeline->min(fn (Result $result) => (float) $result->tempo_oficial);
        $improvement = round((float) $first->tempo_oficial - (float) $latest->tempo_oficial, 2);

        return [
            'has_data' => true,
            'prova' => $this->resolveProvaLabel($latest),
            'improvement_seconds' => $improvement,
            'improvement_label' => $improvement > 0
                ? 'Melhorou ' . number_format($improvement, 2, '.', '') . ' segundos desde o início da época.'
                : 'Sem melhoria desde o início da época.',
            'summary' => $improvement > 0
                ? '-' . number_format($improvement, 2, '.', '') . 's'
                : 'Sem melhoria',
            'entries' => $timeline
                ->map(function (Result $result) use ($fastestSeconds) {
                    $time = (float) $result->tempo_oficial;

                    return [
                        'id' => $result->id,
                        'label' => $this->formatDateLabel($this->resolveCompetitionDate($result)),
                        'time' => $this->formatRaceTime($time),
                        'performance_percent' => $time > 0 ? (int) max(25, min(100, round(($fastestSeconds / $time) * 100))) : 25,
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<string, Collection<int, Result>>  $groupedByProva
     */
    private function isPersonalBest(Result $result, Collection $groupedByProva): bool
    {
        /** @var Collection<int, Result> $timeline */
        $timeline = $groupedByProva->get($this->resolveResultGroupKey($result), collect());

        if ($timeline->isEmpty()) {
            return false;
        }

        $best = (float) $timeline->min(fn (Result $item) => (float) $item->tempo_oficial);

        return abs((float) $result->tempo_oficial - $best) < 0.0001;
    }

    /**
     * @param  Collection<int, Result>  $timeline
     */
    private function isSeasonBest(Result $result, Collection $timeline): bool
    {
        $seasonStart = $this->currentSeasonStart();
        $seasonResults = $timeline
            ->filter(fn (Result $item) => $this->resolveCompetitionDate($item)?->greaterThanOrEqualTo($seasonStart))
            ->values();

        if ($seasonResults->isEmpty()) {
            return false;
        }

        $seasonBest = (float) $seasonResults->min(fn (Result $item) => (float) $item->tempo_oficial);

        return abs((float) $result->tempo_oficial - $seasonBest) < 0.0001;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapHeroResult(Result $result, bool $highlightPersonalBest): array
    {
        return [
            'prova' => $this->resolveProvaLabel($result),
            'time' => $this->formatRaceTime((float) $result->tempo_oficial),
            'event' => $result->prova?->competition?->nome ?: 'Evento sem nome',
            'highlight' => $highlightPersonalBest ? 'Novo recorde pessoal' : 'Último resultado oficial',
        ];
    }

    private function resolveProvaLabel(Result $result): string
    {
        $distance = $result->prova?->distancia_m ? $result->prova->distancia_m . 'm' : null;
        $style = $result->prova?->estilo;

        return trim(implode(' ', array_filter([$distance, $style]))) ?: 'Prova sem identificação';
    }

    private function resolveResultGroupKey(Result $result): string
    {
        return implode('|', [
            (string) ($result->prova?->distancia_m ?? '0'),
            strtolower(trim((string) ($result->prova?->estilo ?? ''))),
            strtolower(trim((string) ($result->prova?->genero ?? ''))),
        ]);
    }

    private function resolveCompetitionDate(Result $result): ?Carbon
    {
        return $result->prova?->competition?->data_inicio;
    }

    private function resolveResultSortKey(Result $result): string
    {
        $competitionDate = $this->resolveCompetitionDate($result)?->format('Y-m-d') ?: '0000-00-00';
        $createdAt = $result->created_at?->format('Y-m-d H:i:s.u') ?: '0000-00-00 00:00:00.000000';

        return $competitionDate . '|' . $createdAt;
    }

    private function formatRaceTime(float $seconds): string
    {
        $centiseconds = (int) round($seconds * 100);
        $minutes = intdiv($centiseconds, 6000);
        $remaining = $centiseconds % 6000;
        $secs = intdiv($remaining, 100);
        $hundredths = $remaining % 100;

        return sprintf('%02d:%02d.%02d', $minutes, $secs, $hundredths);
    }

    private function formatDateLabel(?Carbon $date): string
    {
        return $date?->translatedFormat('d M Y') ?: 'Data por definir';
    }

    private function formatEvolutionLabel(?float $deltaSeconds): string
    {
        if ($deltaSeconds === null) {
            return 'Sem resultado anterior para comparar';
        }

        if ($deltaSeconds > 0) {
            return 'Melhorou ' . number_format($deltaSeconds, 2, '.', '') . 's face ao anterior';
        }

        return 'Sem melhoria face ao anterior';
    }

    /**
     * @return array<int, string>
     */
    private function resolveProfileLabels(User $user): array
    {
        return collect([
            $user->perfil,
        ])
            ->merge(is_array($user->tipo_membro) ? $user->tipo_membro : (array) $user->tipo_membro)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function hasMemberType(User $user, string $type): bool
    {
        $types = is_array($user->tipo_membro) ? $user->tipo_membro : (array) $user->tipo_membro;

        return collect($types)
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->contains(strtolower($type));
    }

    private function currentSeasonStart(): Carbon
    {
        $today = now();
        $seasonYear = $today->month >= 9 ? $today->year : $today->year - 1;

        return Carbon::create($seasonYear, 9, 1)->startOfDay();
    }
}