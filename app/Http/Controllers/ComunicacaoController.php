<?php

namespace App\Http\Controllers;

use App\Models\CommunicationCampaign;
use App\Models\CommunicationAlertCategory;
use App\Models\CommunicationDynamicSource;
use App\Models\CommunicationDelivery;
use App\Models\CommunicationSegment;
use App\Models\CommunicationTemplate;
use App\Models\InAppAlert;
use App\Models\AgeGroup;
use App\Models\User;
use App\Services\Communication\SocialNetworkAccountService;
use App\Services\Communication\SegmentResolverService;
use App\Services\Members\MemberDataReadService;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use App\Support\Communication\AlertCategoryRegistry;
use App\Support\Communication\TemplateVariableCatalog;

class ComunicacaoController extends Controller
{
    private const TECHNICAL_INDIVIDUAL_SEGMENT_DESCRIPTION = 'Segmento tecnico criado automaticamente para envio individual.';

    public function __construct(
        private readonly SegmentResolverService $segmentResolverService,
        private readonly MemberIdentityDisplayResolver $memberIdentityDisplayResolver,
        private readonly MemberDataReadService $memberDataReadService,
    )
    {
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Comunicacao/Index', $this->indexPayload($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function indexPayload(Request $request): array
    {
        $useDefaultCache = $this->shouldCacheDefaultIndexPayload($request);

        return [
            ...$this->buildDashboardPayload($useDefaultCache),
            'filterOptions' => Inertia::lazy(fn () => $useDefaultCache
                ? Cache::remember('comunicacao:filter-options', now()->addSeconds(60), fn () => $this->buildFilterOptions())
                : $this->buildFilterOptions()),
            'campaigns' => Inertia::lazy(fn () => $this->buildCampaignsPayload($request, $useDefaultCache)),
            'deliveries' => Inertia::lazy(fn () => $this->buildDeliveriesPayload($request, $useDefaultCache)),
            'templates' => Inertia::lazy(fn () => $this->buildTemplatesPayload($useDefaultCache)),
            'segments' => Inertia::lazy(fn () => $this->buildSegmentsPayload($useDefaultCache)),
            'recipientOptions' => Inertia::lazy(fn () => $this->buildRecipientOptions($useDefaultCache)),
            'socialAccounts' => Inertia::lazy(fn () => app(SocialNetworkAccountService::class)->safeConfigurations()),
            'socialCampaigns' => Inertia::lazy(fn () => CommunicationCampaign::query()
                ->where('source_type', 'social_publication')
                ->with(['channels', 'deliveries'])
                ->latest()
                ->limit(20)
                ->get()),
            'filters' => $request->only([
                'search',
                'channel',
                'status',
                'period_start',
                'period_end',
                'author',
                'delivery_channel',
                'delivery_status',
                'delivery_campaign',
            ]),
        ];
    }

    private function shouldCacheDefaultIndexPayload(Request $request): bool
    {
        if ($request->session()->has('success') || $request->session()->has('error') || $request->session()->has('warning')) {
            return false;
        }

        $allowedKeys = [
            'search',
            'channel',
            'status',
            'period_start',
            'period_end',
            'author',
            'delivery_channel',
            'delivery_status',
            'delivery_campaign',
            'page',
            'deliveries_page',
            'templates_page',
                'segments_page',
                'social',
        ];

        $query = collect($request->query())
            ->only($allowedKeys)
            ->filter(fn ($value) => !($value === null || $value === ''));

        return $query->isEmpty();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDashboardPayload(bool $useDefaultCache): array
    {
        if ($useDefaultCache) {
            return Cache::remember('comunicacao:dashboard', now()->addSeconds(60), fn () => $this->buildDashboardPayload(false));
        }

        $alertsSummaryRow = InAppAlert::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_read = false THEN 1 ELSE 0 END) as unread')
            ->selectRaw('SUM(CASE WHEN is_read = true THEN 1 ELSE 0 END) as read')
            ->first();

        $alertsSummary = [
            'total' => (int) ($alertsSummaryRow?->total ?? 0),
            'unread' => (int) ($alertsSummaryRow?->unread ?? 0),
            'read' => (int) ($alertsSummaryRow?->read ?? 0),
        ];

        $deliverySummary = CommunicationDelivery::query()
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_deliveries")
            ->selectRaw("SUM(CASE WHEN status IN ('failed', 'partial') THEN 1 ELSE 0 END) as failed_deliveries")
            ->selectRaw('COALESCE(SUM(success_count), 0) as total_sent')
            ->selectRaw('COALESCE(SUM(failed_count), 0) as total_failed')
            ->selectRaw('COALESCE(SUM(pending_count), 0) as total_pending')
            ->first();

        $stats = [
            'scheduled_campaigns' => CommunicationCampaign::where('status', 'agendada')->count(),
            'active_templates' => CommunicationTemplate::where('status', 'ativo')->count(),
            'completed_deliveries' => (int) ($deliverySummary?->completed_deliveries ?? 0),
            'failed_deliveries' => (int) ($deliverySummary?->failed_deliveries ?? 0),
            'total_sent' => (int) ($deliverySummary?->total_sent ?? 0),
            'total_failed' => (int) ($deliverySummary?->total_failed ?? 0),
            'total_pending' => (int) ($deliverySummary?->total_pending ?? 0),
            'alerts_unread' => $alertsSummary['unread'],
        ];

        $latestCampaigns = CommunicationCampaign::query()
            ->select('id', 'codigo', 'title', 'status', 'created_at', 'sent_at')
            ->latest()
            ->take(6)
            ->get();

        $channelSummary = CommunicationDelivery::query()
            ->selectRaw('channel, count(*) as total, sum(success_count) as success_count, sum(failed_count) as failed_count')
            ->groupBy('channel')
            ->get();

        return [
            'stats' => $stats,
            'latestCampaigns' => $latestCampaigns,
            'channelSummary' => $channelSummary,
            'alertsSummary' => $alertsSummary,
        ];
    }

    private function buildCampaignsPayload(Request $request, bool $useDefaultCache): mixed
    {
        if ($useDefaultCache) {
            return Cache::remember('comunicacao:campaigns:default', now()->addSeconds(60), fn () => $this->buildCampaignsPayload($request, false));
        }

        $campaignsQuery = CommunicationCampaign::query()
            ->with([
                'segment:id,name,rules_json',
                'author:id,name,nome_completo',
                'channels:id,campaign_id,channel,is_enabled,template_id,subject,message_body',
                'deliveries:id,campaign_id,channel,status,success_count,failed_count,error_message,sent_at,created_at',
            ])
            ->where(function ($query): void {
                $query->whereNull('source_type')
                    ->orWhere('source_type', '!=', 'social_publication');
            })
            ->latest();

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $campaignsQuery->where(function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('codigo', 'like', "%{$search}%");
            });
        }

        if ($request->filled('channel')) {
            $channel = $request->string('channel')->toString();
            $campaignsQuery->whereHas('channels', fn ($query) => $query->where('channel', $channel));
        }

        if ($request->filled('status')) {
            $campaignsQuery->where('status', $request->string('status')->toString());
        }

        if ($request->filled('author')) {
            $campaignsQuery->where('author_id', $request->string('author')->toString());
        }

        if ($request->filled('period_start')) {
            $campaignsQuery->whereDate('created_at', '>=', $request->string('period_start')->toString());
        }

        if ($request->filled('period_end')) {
            $campaignsQuery->whereDate('created_at', '<=', $request->string('period_end')->toString());
        }

        return $campaignsQuery->paginate(12)->withQueryString();
    }

    private function buildDeliveriesPayload(Request $request, bool $useDefaultCache): mixed
    {
        if ($useDefaultCache) {
            return Cache::remember('comunicacao:deliveries:default', now()->addSeconds(60), fn () => $this->buildDeliveriesPayload($request, false));
        }

        $deliveriesQuery = CommunicationDelivery::query()
            ->with(['campaign:id,codigo,title', 'segment:id,name'])
            ->withCount([
                'attempts',
                'recipients as retryable_count' => fn ($builder) => $builder
                    ->where('status', 'failed')
                    ->whereColumn('attempt_count', '<', 'max_attempts')
                    ->whereNotNull('next_attempt_at'),
                'recipients as exhausted_count' => fn ($builder) => $builder
                    ->where('status', 'failed')
                    ->whereColumn('attempt_count', '>=', 'max_attempts'),
            ])
            ->latest();

        if ($request->filled('delivery_channel')) {
            $deliveriesQuery->where('channel', $request->string('delivery_channel')->toString());
        }

        if ($request->filled('delivery_status')) {
            $deliveriesQuery->where('status', $request->string('delivery_status')->toString());
        }

        if ($request->filled('delivery_campaign')) {
            $deliveriesQuery->where('campaign_id', $request->string('delivery_campaign')->toString());
        }

        return $deliveriesQuery->paginate(12, ['*'], 'deliveries_page')->withQueryString();
    }

    private function buildTemplatesPayload(bool $useDefaultCache): mixed
    {
        if ($useDefaultCache) {
            return Cache::remember('comunicacao:templates:default', now()->addSeconds(60), fn () => $this->buildTemplatesPayload(false));
        }

        return CommunicationTemplate::query()
            ->latest()
            ->paginate(12, ['*'], 'templates_page')
            ->withQueryString();
    }

    private function buildSegmentsPayload(bool $useDefaultCache): mixed
    {
        if ($useDefaultCache) {
            return Cache::remember('comunicacao:segments:default', now()->addSeconds(60), fn () => $this->buildSegmentsPayload(false));
        }

        $segments = CommunicationSegment::query()
            ->where(function ($query) {
                $query->where('type', '!=', 'system')
                    ->orWhereNull('type');
            })
            ->where(function ($query) {
                $query->where('description', '!=', self::TECHNICAL_INDIVIDUAL_SEGMENT_DESCRIPTION)
                    ->orWhereNull('description');
            })
            ->latest()
            ->paginate(12, ['*'], 'segments_page')
            ->withQueryString();

        $segments->getCollection()->transform(function (CommunicationSegment $segment) {
            $rules = $segment->rules_json ?? [];

            return [
                ...$segment->toArray(),
                'estimated_recipients' => $this->segmentResolverService->estimateRecipients($segment),
                'resolved_age_group_labels' => $this->segmentResolverService->resolveAgeGroupLabels($rules['age_group_ids'] ?? []),
                'resolved_user_types' => array_values(array_filter($rules['user_types'] ?? [])),
                'resolved_user_ids' => array_values(array_filter($rules['user_ids'] ?? [])),
            ];
        });

        return $segments;
    }

    private function buildFilterOptions(): array
    {
        return [
            'authors' => User::query()
                ->with(['dadosPessoais:user_id,nome_completo'])
                ->orderBy('name')
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'nome_completo' => $this->memberIdentityDisplayResolver->displayName($user),
                ])
                ->values()
                ->all(),
            'segments' => CommunicationSegment::select('id', 'name')
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->where('type', '!=', 'system')
                        ->orWhereNull('type');
                })
                ->where(function ($query) {
                    $query->where('description', '!=', self::TECHNICAL_INDIVIDUAL_SEGMENT_DESCRIPTION)
                        ->orWhereNull('description');
                })
                ->orderBy('name')
                ->get(),
            'dynamicSources' => $this->dynamicSourcesForCommunication(),
            'alertCategories' => AlertCategoryRegistry::all(true)->values()->all(),
            'templates' => CommunicationTemplate::select('id', 'name', 'channel', 'category')->where('status', 'ativo')->orderBy('name')->get(),
            'ageGroups' => AgeGroup::select('id', 'nome')->where('ativo', true)->orderBy('nome')->get(),
            'userTypes' => $this->communicationUserTypes(),
            'templateVariables' => TemplateVariableCatalog::definitions(),
        ];
    }

    private function buildRecipientOptions(bool $useDefaultCache): mixed
    {
        if ($useDefaultCache) {
            return Cache::remember('comunicacao:recipients', now()->addSeconds(60), fn () => $this->buildRecipientOptions(false));
        }

        return User::query()
            ->with(['dadosPessoais:user_id,nome_completo,contacto'])
            ->where(function ($query) {
                $query->where('estado', 'ativo')->orWhereNull('estado');
            })
            ->orderByRaw('COALESCE(nome_completo, name) asc')
            ->get()
            ->map(fn (User $user): array => $this->mapRecipientOption($user))
            ->values()
            ->all();
    }

    private function dynamicSourcesForCommunication(): array
    {
        if (Schema::hasTable('communication_dynamic_sources')) {
            return CommunicationDynamicSource::query()
                ->select('id', 'name', 'description', 'strategy', 'sort_order', 'is_active')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->toArray();
        }

        return [
            ['id' => '', 'name' => 'Todos os membros', 'description' => 'Inclui todos os membros ativos do sistema.', 'strategy' => 'all_members', 'sort_order' => 1, 'is_active' => true],
            ['id' => '', 'name' => 'Atletas por escalão', 'description' => 'Seleciona utilizadores com perfil de atleta.', 'strategy' => 'athletes', 'sort_order' => 2, 'is_active' => true],
            ['id' => '', 'name' => 'Pais/Encarregados', 'description' => 'Seleciona encarregados de educação ativos.', 'strategy' => 'guardians', 'sort_order' => 3, 'is_active' => true],
            ['id' => '', 'name' => 'Treinadores', 'description' => 'Seleciona utilizadores com perfil de treinador.', 'strategy' => 'coaches', 'sort_order' => 4, 'is_active' => true],
            ['id' => '', 'name' => 'Pagamentos em atraso', 'description' => 'Membros com faturas vencidas por regularizar.', 'strategy' => 'overdue_payments', 'sort_order' => 5, 'is_active' => true],
            ['id' => '', 'name' => 'Participantes de evento', 'description' => 'Membros ligados a participações em eventos.', 'strategy' => 'event_participants', 'sort_order' => 6, 'is_active' => true],
            ['id' => '', 'name' => 'Utilizadores com alertas por ler', 'description' => 'Utilizadores com alertas internos pendentes de leitura.', 'strategy' => 'users_with_unread_alerts', 'sort_order' => 7, 'is_active' => true],
        ];
    }

    private function communicationUserTypes(): array
    {
        return User::query()
            ->pluck('tipo_membro')
            ->filter(fn ($value) => is_array($value) && $value !== [])
            ->flatten()
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $value) => ['value' => $value, 'label' => str_replace('_', ' ', ucfirst($value))])
            ->all();
    }

    private function mapRecipientOption(User $user): array
    {
        $personal = $this->memberDataReadService->personalPayload($user);
        $contact = $this->normalizedCommunicationContact($personal['contacto'] ?? null);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'nome_completo' => $this->memberIdentityDisplayResolver->displayName($user),
            'email' => $user->email,
            'telemovel' => $contact,
            'contacto_telefonico' => $contact,
            'contacto' => $contact,
            'tipo_membro' => $user->tipo_membro,
            'escalao' => $user->escalao,
            'estado' => $user->estado,
            'numero_socio' => $user->numero_socio,
            'age_group_id' => $user->age_group_id,
        ];
    }

    private function normalizedCommunicationContact(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
