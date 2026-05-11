<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSponsorRequest;
use App\Http\Requests\UpdateSponsorRequest;
use App\Models\UserType;
use App\Models\AgeGroup;
use App\Models\EventType;
use App\Models\AthleteStatusConfig;
use App\Models\TrainingTypeConfig;
use App\Models\TrainingZoneConfig;
use App\Models\AbsenceReasonConfig;
use App\Models\InjuryReasonConfig;
use App\Models\PoolTypeConfig;
use App\Models\ClubSetting;
use App\Models\CommunicationAlertCategory;
use App\Models\CommunicationDynamicSource;
use App\Models\CostCenter;
use App\Models\InvoiceType;
use App\Models\MonthlyFee;
use App\Models\ItemCategory;
use App\Models\Product;
use App\Models\Sponsor;
use App\Models\Supplier;
use App\Models\UserTypePermission;
use App\Models\NotificationPreference;
use App\Models\ProvaTipo;
use App\Models\User;
use App\Services\AccessControl\UserTypeAccessControlService;
use App\Services\Club\ClubSettingsService;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Support\Communication\AlertCategoryRegistry;

class ConfiguracoesController extends Controller
{
    public function index(Request $request): Response
    {
        $useDefaultCache = ! $this->shouldBypassIndexCache($request);

        $payload = [
            ...$this->buildIndexPayload($useDefaultCache),
            'monthlyFees' => Inertia::lazy(fn () => $this->buildFinanceiroPayload($useDefaultCache)['monthlyFees']),
            'invoiceTypes' => Inertia::lazy(fn () => $this->buildFinanceiroPayload($useDefaultCache)['invoiceTypes']),
            'costCenters' => Inertia::lazy(fn () => $this->buildFinanceiroPayload($useDefaultCache)['costCenters']),
            'products' => Inertia::lazy(fn () => $this->buildLogisticaPayload($useDefaultCache)['products']),
            'sponsors' => Inertia::lazy(fn () => $this->buildLogisticaPayload($useDefaultCache)['sponsors']),
            'suppliers' => Inertia::lazy(fn () => $this->buildLogisticaPayload($useDefaultCache)['suppliers']),
            'itemCategories' => Inertia::lazy(fn () => $this->buildLogisticaPayload($useDefaultCache)['itemCategories']),
            'notificationPrefs' => Inertia::lazy(fn () => $this->buildNotificacoesPayload($useDefaultCache)['notificationPrefs']),
            'communicationDynamicSources' => Inertia::lazy(fn () => $this->buildNotificacoesPayload($useDefaultCache)['communicationDynamicSources']),
            'communicationAlertCategories' => Inertia::lazy(fn () => $this->buildNotificacoesPayload($useDefaultCache)['communicationAlertCategories']),
            'users' => Inertia::lazy(fn () => $this->buildBaseDadosPayload($useDefaultCache)['users']),
            'trainingTypes' => Inertia::lazy(fn () => $this->buildDesportivoPayload($useDefaultCache)['trainingTypes']),
            'trainingZones' => Inertia::lazy(fn () => $this->buildDesportivoPayload($useDefaultCache)['trainingZones']),
            'injuryReasons' => Inertia::lazy(fn () => $this->buildDesportivoPayload($useDefaultCache)['injuryReasons']),
            'poolTypes' => Inertia::lazy(fn () => $this->buildDesportivoPayload($useDefaultCache)['poolTypes']),
            'provaTipos' => Inertia::lazy(fn () => $this->buildDesportivoPayload($useDefaultCache)['provaTipos']),
        ];

        return Inertia::render('Configuracoes/Index', $payload);
    }

    private function shouldBypassIndexCache(Request $request): bool
    {
        return $request->session()->has('success')
            || $request->session()->has('error')
            || $request->session()->has('warning');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildIndexPayload(bool $useDefaultCache): array
    {
        if ($useDefaultCache) {
            return Cache::remember(
                'configuracoes:index:eager',
                now()->addMinutes(5),
                fn () => $this->buildIndexPayload(false)
            );
        }

        $userTypes = UserType::query()->orderBy('nome')->get();

        return [
            'userTypes' => $userTypes,
            'ageGroups' => AgeGroup::all(),
            'eventTypes' => EventType::all(),
            'athleteStatuses' => AthleteStatusConfig::query()->ordenado()->get(),
            'absenceReasons' => AbsenceReasonConfig::query()->ordenado()->get(),
            'clubSettings' => app(ClubSettingsService::class)->model(),
            'permissions' => UserTypePermission::all(),
            'accessControlBootstrap' => app(UserTypeAccessControlService::class)->getBootstrap($userTypes->first()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFinanceiroPayload(bool $useDefaultCache): array
    {
        if ($useDefaultCache) {
            return Cache::remember('configuracoes:financeiro', now()->addMinutes(5), fn () => $this->buildFinanceiroPayload(false));
        }

        return [
            'monthlyFees' => MonthlyFee::all(),
            'invoiceTypes' => InvoiceType::orderBy('nome')->get(),
            'costCenters' => CostCenter::all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLogisticaPayload(bool $useDefaultCache): array
    {
        if ($useDefaultCache) {
            return Cache::remember('configuracoes:logistica', now()->addMinutes(5), fn () => $this->buildLogisticaPayload(false));
        }

        return [
            'products' => Product::all(),
            'sponsors' => Sponsor::orderBy('nome')->get(),
            'suppliers' => Supplier::all(),
            'itemCategories' => ItemCategory::orderBy('nome')->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildNotificacoesPayload(bool $useDefaultCache): array
    {
        if ($useDefaultCache) {
            return Cache::remember('configuracoes:notificacoes', now()->addMinutes(5), fn () => $this->buildNotificacoesPayload(false));
        }

        return [
            'notificationPrefs' => NotificationPreference::first(),
            'communicationDynamicSources' => $this->communicationDynamicSources(),
            'communicationAlertCategories' => AlertCategoryRegistry::all(false)->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBaseDadosPayload(bool $useDefaultCache): array
    {
        if ($useDefaultCache) {
            return Cache::remember('configuracoes:base-dados', now()->addMinutes(5), fn () => $this->buildBaseDadosPayload(false));
        }

        return [
            'users' => User::select('id', 'numero_socio', 'nome_completo', 'email_utilizador', 'perfil', 'estado')->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDesportivoPayload(bool $useDefaultCache): array
    {
        if ($useDefaultCache) {
            return Cache::remember('configuracoes:desportivo', now()->addMinutes(5), fn () => $this->buildDesportivoPayload(false));
        }

        return [
            'trainingTypes' => TrainingTypeConfig::query()->ordenado()->get(),
            'trainingZones' => TrainingZoneConfig::query()->ordenado()->get(),
            'injuryReasons' => InjuryReasonConfig::query()->ordenado()->get(),
            'poolTypes' => PoolTypeConfig::query()->ordenado()->get(),
            'provaTipos' => ProvaTipo::all(),
        ];
    }

    public function storeUserType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nome' => 'required|string|max:255',
            'descricao' => 'nullable|string',
            'ativo' => 'boolean',
        ]);

        UserType::create($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Tipo de utilizador criado com sucesso!');
    }

    public function updateUserType(Request $request, UserType $userType): RedirectResponse
    {
        $data = $request->validate([
            'nome' => 'required|string|max:255',
            'descricao' => 'nullable|string',
            'ativo' => 'boolean',
        ]);

        $userType->update($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Tipo de utilizador atualizado com sucesso!');
    }

    public function destroyUserType(UserType $userType): RedirectResponse
    {
        $userType->delete();

        return redirect()->route('configuracoes')
            ->with('success', 'Tipo de utilizador eliminado com sucesso!');
    }

    public function storeAgeGroup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nome' => 'required|string|max:255',
            'idade_minima' => 'required|integer|min:0',
            'idade_maxima' => 'required|integer|min:0',
            'descricao' => 'nullable|string',
        ]);

        AgeGroup::create($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Escalão etário criado com sucesso!');
    }

    public function updateAgeGroup(Request $request, AgeGroup $ageGroup): RedirectResponse
    {
        $data = $request->validate([
            'nome' => 'required|string|max:255',
            'idade_minima' => 'required|integer|min:0',
            'idade_maxima' => 'required|integer|min:0',
            'descricao' => 'nullable|string',
        ]);

        $ageGroup->update($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Escalão etário atualizado com sucesso!');
    }

    public function destroyAgeGroup(AgeGroup $ageGroup): RedirectResponse
    {
        $ageGroup->delete();

        return redirect()->route('configuracoes')
            ->with('success', 'Escalão etário eliminado com sucesso!');
    }

    public function storeEventType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nome' => 'required|string|max:255',
            'descricao' => 'nullable|string',
            'categoria' => 'nullable|string',
            'cor' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'visibilidade_default' => 'nullable|string|in:publico,restrito,privado',
            'gera_taxa' => 'boolean',
            'permite_convocatoria' => 'boolean',
            'gera_presencas' => 'boolean',
            'requer_transporte' => 'boolean',
            'ativo' => 'boolean',
        ]);

        EventType::create($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Tipo de evento criado com sucesso!');
    }

    public function updateEventType(Request $request, EventType $eventType): RedirectResponse
    {
        $data = $request->validate([
            'nome' => 'required|string|max:255',
            'descricao' => 'nullable|string',
            'categoria' => 'nullable|string',
            'cor' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'visibilidade_default' => 'nullable|string|in:publico,restrito,privado',
            'gera_taxa' => 'boolean',
            'permite_convocatoria' => 'boolean',
            'gera_presencas' => 'boolean',
            'requer_transporte' => 'boolean',
            'ativo' => 'boolean',
        ]);

        $eventType->update($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Tipo de evento atualizado com sucesso!');
    }

    public function destroyEventType(EventType $eventType): RedirectResponse
    {
        $eventType->delete();

        return redirect()->route('configuracoes')
            ->with('success', 'Tipo de evento eliminado com sucesso!');
    }

    public function updateClubSettings(Request $request): RedirectResponse
    {
        $clubSettingsService = app(ClubSettingsService::class);

        $data = $request->validate([
            'nome_clube' => 'nullable|string|max:255',
            'sigla' => 'nullable|string|max:10',
            'morada' => 'nullable|string',
            'codigo_postal' => 'nullable|string|max:20',
            'localidade' => 'nullable|string|max:100',
            'telefone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:255',
            'nif' => 'nullable|string|max:20',
            'iban' => 'nullable|string|max:34',
            'logo_url' => 'nullable|string',
            'logo' => 'nullable|image|max:2048',
            'monthly_fee_generation_enabled' => 'nullable|boolean',
            'monthly_fee_start_month' => 'nullable|integer|min:1|max:12',
            'monthly_fee_end_month' => 'nullable|integer|min:1|max:12',
            'monthly_fee_due_day' => 'nullable|integer|min:1|max:28',
            'monthly_fee_hide_future' => 'nullable|boolean',
            'monthly_fee_auto_activate_due' => 'nullable|boolean',
            'monthly_fee_respect_registration_date' => 'nullable|boolean',
            'monthly_fee_generate_months_ahead' => 'nullable|integer|min:1|max:24',
            'monthly_fee_default_period_mode' => 'nullable|string|in:financial_cycle,custom',
        ]);

        $data['nome_clube'] = trim((string) ($data['nome_clube'] ?? '')) ?: $clubSettingsService->name();

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('club-logos', 'public');
            $data['logo_url'] = Storage::url($path);
        }

        unset($data['logo']);

        $clubSettings = $clubSettingsService->model();
        
        if ($clubSettings) {
            $clubSettings->update($data);
        } else {
            ClubSetting::create($data);
        }

        $clubSettingsService->clearCache();
        Cache::forget('club_settings_shared');
        Cache::forget('configuracoes:index:eager');

        return redirect()->route('configuracoes')
            ->with('success', 'Configurações do clube atualizadas com sucesso!');
    }

    public function storePermission(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_type_id' => 'required|exists:user_types,id',
            'modulo' => 'required|string|max:100',
            'submodulo' => 'nullable|string|max:100',
            'separador' => 'nullable|string|max:100',
            'campo' => 'nullable|string|max:100',
            'pode_ver' => 'boolean',
            'pode_criar' => 'boolean',
            'pode_editar' => 'boolean',
            'pode_eliminar' => 'boolean',
        ]);

        UserTypePermission::create($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Permissão criada com sucesso!');
    }

    public function updatePermission(Request $request, UserTypePermission $permission): RedirectResponse
    {
        $data = $request->validate([
            'user_type_id' => 'required|exists:user_types,id',
            'modulo' => 'required|string|max:100',
            'submodulo' => 'nullable|string|max:100',
            'separador' => 'nullable|string|max:100',
            'campo' => 'nullable|string|max:100',
            'pode_ver' => 'boolean',
            'pode_criar' => 'boolean',
            'pode_editar' => 'boolean',
            'pode_eliminar' => 'boolean',
        ]);

        $permission->update($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Permissão atualizada com sucesso!');
    }

    public function destroyPermission(UserTypePermission $permission): RedirectResponse
    {
        $permission->delete();

        return redirect()->route('configuracoes')
            ->with('success', 'Permissão eliminada com sucesso!');
    }

    public function storeCostCenter(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'codigo' => 'nullable|string|max:50|unique:cost_centers,codigo',
            'nome' => 'required|string|max:255',
            'tipo' => 'nullable|string|max:100',
            'descricao' => 'nullable|string',
            'orcamento' => 'nullable|numeric|min:0',
            'ativo' => 'boolean',
        ]);

        if (empty($data['codigo'])) {
            $data['codigo'] = 'CC-' . strtoupper(Str::random(6));
        }

        CostCenter::create($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Centro de custos criado com sucesso!');
    }

    public function storeInvoiceType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'codigo' => 'nullable|string|max:50|unique:invoice_types,codigo',
            'nome' => 'required|string|max:255',
            'descricao' => 'nullable|string',
            'ativo' => 'boolean',
        ]);

        if (empty($data['codigo'])) {
            $data['codigo'] = Str::slug($data['nome']);
        }

        InvoiceType::create($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Tipo de fatura criado com sucesso!');
    }

    public function updateInvoiceType(Request $request, InvoiceType $invoiceType): RedirectResponse
    {
        $data = $request->validate([
            'codigo' => 'nullable|string|max:50|unique:invoice_types,codigo,' . $invoiceType->id,
            'nome' => 'required|string|max:255',
            'descricao' => 'nullable|string',
            'ativo' => 'boolean',
        ]);

        if (empty($data['codigo'])) {
            $data['codigo'] = Str::slug($data['nome']);
        }

        $invoiceType->update($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Tipo de fatura atualizado com sucesso!');
    }

    public function destroyInvoiceType(InvoiceType $invoiceType): RedirectResponse
    {
        $invoiceType->delete();

        return redirect()->route('configuracoes')
            ->with('success', 'Tipo de fatura eliminado com sucesso!');
    }

    public function updateCostCenter(Request $request, CostCenter $costCenter): RedirectResponse
    {
        $data = $request->validate([
            'codigo' => 'nullable|string|max:50|unique:cost_centers,codigo,' . $costCenter->id,
            'nome' => 'required|string|max:255',
            'tipo' => 'nullable|string|max:100',
            'descricao' => 'nullable|string',
            'orcamento' => 'nullable|numeric|min:0',
            'ativo' => 'boolean',
        ]);

        if (empty($data['codigo'])) {
            $data['codigo'] = $costCenter->codigo ?: 'CC-' . strtoupper(Str::random(6));
        }

        $costCenter->update($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Centro de custos atualizado com sucesso!');
    }

    public function destroyCostCenter(CostCenter $costCenter): RedirectResponse
    {
        $costCenter->delete();

        return redirect()->route('configuracoes')
            ->with('success', 'Centro de custos eliminado com sucesso!');
    }

    public function storeMonthlyFee(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'designacao' => 'required|string|max:255',
            'valor' => 'required|numeric|min:0',
            'age_group_id' => 'nullable|exists:age_groups,id',
            'ativo' => 'boolean',
        ]);

        MonthlyFee::create($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Mensalidade criada com sucesso!');
    }

    public function updateMonthlyFee(Request $request, MonthlyFee $monthlyFee): RedirectResponse
    {
        $data = $request->validate([
            'designacao' => 'required|string|max:255',
            'valor' => 'required|numeric|min:0',
            'age_group_id' => 'nullable|exists:age_groups,id',
            'ativo' => 'boolean',
        ]);

        $monthlyFee->update($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Mensalidade atualizada com sucesso!');
    }

    public function destroyMonthlyFee(MonthlyFee $monthlyFee): RedirectResponse
    {
        $monthlyFee->delete();

        return redirect()->route('configuracoes')
            ->with('success', 'Mensalidade eliminada com sucesso!');
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'codigo' => 'required|string|max:50|unique:products,codigo',
            'nome' => 'required|string|max:255',
            'categoria' => 'nullable|string|max:100',
            'preco' => 'required|numeric|min:0',
            'stock_minimo' => 'nullable|integer|min:0',
            'area_armazenamento' => 'nullable|string|max:120',
            'descricao' => 'nullable|string',
            'ativo' => 'boolean',
            'visible_in_store' => 'boolean',
        ]);

        if ($request->hasFile('imagem_file')) {
            $path = $request->file('imagem_file')->store('products', 'public');
            $data['imagem'] = Storage::url($path);
        }
        unset($data['imagem_file']);

        $data['stock'] = $data['stock'] ?? 0;
        $data['stock_minimo'] = $data['stock_minimo'] ?? 0;

        Product::create($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Artigo criado com sucesso!');
    }

    public function updateProduct(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'codigo' => 'required|string|max:50|unique:products,codigo,' . $product->id,
            'nome' => 'required|string|max:255',
            'categoria' => 'nullable|string|max:100',
            'preco' => 'required|numeric|min:0',
            'stock_minimo' => 'nullable|integer|min:0',
            'area_armazenamento' => 'nullable|string|max:120',
            'descricao' => 'nullable|string',
            'ativo' => 'boolean',
            'visible_in_store' => 'boolean',
        ]);

        if ($request->hasFile('imagem_file')) {
            $path = $request->file('imagem_file')->store('products', 'public');
            $data['imagem'] = Storage::url($path);
        }
        unset($data['imagem_file']);

        $product->update($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Artigo atualizado com sucesso!');
    }

    public function destroyProduct(Product $product): RedirectResponse
    {
        $product->delete();

        return redirect()->route('configuracoes')
            ->with('success', 'Artigo eliminado com sucesso!');
    }

    public function storeSponsor(StoreSponsorRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('sponsors', 'public');
            $data['logo'] = Storage::url($path);
        }

        Sponsor::create($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Patrocinador criado com sucesso!');
    }

    public function updateSponsor(UpdateSponsorRequest $request, Sponsor $sponsor): RedirectResponse
    {
        $data = $request->validated();

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('sponsors', 'public');
            $data['logo'] = Storage::url($path);
        }

        $sponsor->update($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Patrocinador atualizado com sucesso!');
    }

    public function destroySponsor(Sponsor $sponsor): RedirectResponse
    {
        $sponsor->delete();

        return redirect()->route('configuracoes')
            ->with('success', 'Patrocinador eliminado com sucesso!');
    }

    public function storeSupplier(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nome' => 'required|string|max:255',
            'nif' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'telefone' => 'nullable|string|max:30',
            'morada' => 'nullable|string',
            'categoria' => 'nullable|string|max:100',
            'ativo' => 'boolean',
        ]);

        Supplier::create($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Fornecedor criado com sucesso!');
    }

    public function updateSupplier(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $request->validate([
            'nome' => 'required|string|max:255',
            'nif' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'telefone' => 'nullable|string|max:30',
            'morada' => 'nullable|string',
            'categoria' => 'nullable|string|max:100',
            'ativo' => 'boolean',
        ]);

        $supplier->update($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Fornecedor atualizado com sucesso!');
    }

    public function destroySupplier(Supplier $supplier): RedirectResponse
    {
        $supplier->delete();

        return redirect()->route('configuracoes')
            ->with('success', 'Fornecedor eliminado com sucesso!');
    }

    public function storeProvaTipo(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nome' => 'required|string|max:255',
            'distancia' => 'required|integer|min:0',
            'unidade' => 'required|string|max:20',
            'modalidade' => 'required|string|max:100',
            'ativo' => 'boolean',
        ]);

        ProvaTipo::create($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Prova criada com sucesso!');
    }

    public function updateProvaTipo(Request $request, ProvaTipo $provaTipo): RedirectResponse
    {
        $data = $request->validate([
            'nome' => 'required|string|max:255',
            'distancia' => 'required|integer|min:0',
            'unidade' => 'required|string|max:20',
            'modalidade' => 'required|string|max:100',
            'ativo' => 'boolean',
        ]);

        $provaTipo->update($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Prova atualizada com sucesso!');
    }

    public function destroyProvaTipo(ProvaTipo $provaTipo): RedirectResponse
    {
        $provaTipo->delete();

        return redirect()->route('configuracoes')
            ->with('success', 'Prova eliminada com sucesso!');
    }

    public function storeItemCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'codigo' => 'required|string|max:50|unique:item_categories,codigo',
            'nome' => 'required|string|max:255',
            'ativo' => 'boolean',
        ]);

        ItemCategory::create($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Categoria criada com sucesso!');
    }

    public function updateItemCategory(Request $request, ItemCategory $itemCategory): RedirectResponse
    {
        $data = $request->validate([
            'codigo' => 'required|string|max:50|unique:item_categories,codigo,' . $itemCategory->id,
            'nome' => 'required|string|max:255',
            'ativo' => 'boolean',
        ]);

        $itemCategory->update($data);

        return redirect()->route('configuracoes')
            ->with('success', 'Categoria atualizada com sucesso!');
    }

    public function destroyItemCategory(ItemCategory $itemCategory): RedirectResponse
    {
        $itemCategory->delete();

        return redirect()->route('configuracoes')
            ->with('success', 'Categoria eliminada com sucesso!');
    }

    public function updateNotificationPreferences(Request $request): RedirectResponse
    {
        $fields = [
            'email_notificacoes',
            'alertas_pagamento',
            'alertas_atividade',
            'automacoes_financeiro',
            'automacoes_eventos',
            'automacoes_logistica',
            'automacoes_faturas_financeiras',
            'automacoes_movimentos_financeiros',
            'automacoes_convocatorias_eventos',
            'automacoes_requisicoes_logistica',
            'automacoes_alertas_operacionais',
        ];

        $request->validate([
            'email_notificacoes' => 'boolean',
            'alertas_pagamento' => 'boolean',
            'alertas_atividade' => 'boolean',
            'automacoes_financeiro' => 'boolean',
            'automacoes_eventos' => 'boolean',
            'automacoes_logistica' => 'boolean',
            'automacoes_faturas_financeiras' => 'boolean',
            'automacoes_movimentos_financeiros' => 'boolean',
            'automacoes_convocatorias_eventos' => 'boolean',
            'automacoes_requisicoes_logistica' => 'boolean',
            'automacoes_alertas_operacionais' => 'boolean',
        ]);

        $data = collect($fields)
            ->mapWithKeys(fn (string $field) => [$field => $request->boolean($field)])
            ->all();

        $prefs = NotificationPreference::first();

        if ($prefs) {
            $prefs->update($data);
        } else {
            NotificationPreference::create($data);
        }

        Cache::forget('configuracoes:notificacoes');
        Cache::forget('configuracoes:index:eager');

        return redirect()->route('configuracoes')
            ->with('success', 'Preferências atualizadas com sucesso!');
    }

    public function storeCommunicationDynamicSource(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'strategy' => 'required|string|in:' . implode(',', $this->communicationDynamicSourceStrategies()),
            'sort_order' => 'nullable|integer|min:0|max:999',
            'is_active' => 'boolean',
        ]);

        CommunicationDynamicSource::create([
            ...$data,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return redirect()->route('configuracoes')
            ->with('success', 'Fonte dinâmica criada com sucesso!');
    }

    public function updateCommunicationDynamicSource(Request $request, CommunicationDynamicSource $dynamicSource): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'strategy' => 'required|string|in:' . implode(',', $this->communicationDynamicSourceStrategies()),
            'sort_order' => 'nullable|integer|min:0|max:999',
            'is_active' => 'boolean',
        ]);

        $dynamicSource->update([
            ...$data,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return redirect()->route('configuracoes')
            ->with('success', 'Fonte dinâmica atualizada com sucesso!');
    }

    public function destroyCommunicationDynamicSource(CommunicationDynamicSource $dynamicSource): RedirectResponse
    {
        $dynamicSource->delete();

        return redirect()->route('configuracoes')
            ->with('success', 'Fonte dinâmica eliminada com sucesso!');
    }

    public function storeCommunicationAlertCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:80|alpha_dash|unique:communication_alert_categories,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'channels' => 'required|array|min:1',
            'channels.*' => 'required|string|in:email,sms,alert_app',
            'sort_order' => 'nullable|integer|min:0|max:999',
            'is_active' => 'boolean',
        ]);

        CommunicationAlertCategory::create([
            ...$data,
            'channels' => array_values(array_unique($data['channels'])),
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return redirect()->route('configuracoes')
            ->with('success', 'Categoria do alerta criada com sucesso!');
    }

    public function updateCommunicationAlertCategory(Request $request, CommunicationAlertCategory $alertCategory): RedirectResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:80|alpha_dash|unique:communication_alert_categories,code,' . $alertCategory->id,
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'channels' => 'required|array|min:1',
            'channels.*' => 'required|string|in:email,sms,alert_app',
            'sort_order' => 'nullable|integer|min:0|max:999',
            'is_active' => 'boolean',
        ]);

        $alertCategory->update([
            ...$data,
            'channels' => array_values(array_unique($data['channels'])),
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return redirect()->route('configuracoes')
            ->with('success', 'Categoria do alerta atualizada com sucesso!');
    }

    public function destroyCommunicationAlertCategory(CommunicationAlertCategory $alertCategory): RedirectResponse
    {
        $alertCategory->delete();

        return redirect()->route('configuracoes')
            ->with('success', 'Categoria do alerta eliminada com sucesso!');
    }

    private function communicationDynamicSourceStrategies(): array
    {
        return [
            'all_members',
            'athletes',
            'guardians',
            'coaches',
            'team_members',
            'age_group_members',
            'overdue_payments',
            'event_participants',
            'users_with_unread_alerts',
        ];
    }

    private function communicationDynamicSources(): array
    {
        if (Schema::hasTable('communication_dynamic_sources')) {
            return CommunicationDynamicSource::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->toArray();
        }

        return [];
    }
}
