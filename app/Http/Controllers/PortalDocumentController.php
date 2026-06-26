<?php

namespace App\Http\Controllers;

use App\Models\Season;
use App\Models\User;
use App\Models\UserDocument;
use App\Services\Family\FamilyService;
use App\Services\Members\MemberDocumentDataResolver;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortalDocumentController extends Controller
{
    private const EXPIRING_DAYS = 30;

    public function __construct(
        private readonly MemberDocumentDataResolver $memberDocumentDataResolver,
    ) {
    }

    /**
     * @var array<string, string>
     */
    private const DOCUMENT_LABELS = [
        'rgpd' => 'RGPD',
        'consentimento' => 'Consentimento imagem/transporte',
        'atestado' => 'Atestado médico',
        'cartao_federacao' => 'Cartão federação',
        'declaracao_transporte' => 'Declaração de transporte',
        'afiliacao' => 'Afiliação',
        'outro' => 'Outros documentos',
    ];

    public function index(Request $request, FamilyService $familyService): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('documents');

        $uploadedDocuments = $user->documents
            ->sortByDesc(fn (UserDocument $document) => $document->created_at?->getTimestamp() ?? 0)
            ->values();

        $uploadedByType = $uploadedDocuments
            ->groupBy('type')
            ->map(fn (Collection $documents) => $documents->first());

        $essentialDocuments = $this->buildEssentialDocuments($user, $uploadedByType);
        $otherDocuments = $this->buildOtherDocuments($uploadedDocuments);
        $documents = $essentialDocuments
            ->concat($otherDocuments)
            ->values();

        $alerts = $this->buildAlerts($documents);
        $history = $this->buildHistory($user, $uploadedDocuments, $essentialDocuments);
        $summary = $this->buildSummary($documents);

        return Inertia::render('Portal/Documents', [
            'is_also_admin' => $familyService->userHasAdministratorProfile($user),
            'has_family' => $familyService->userHasFamily($user),
            'documents_overview' => [
                'hero' => $this->buildHero($documents),
                'kpis' => [
                    'valid' => $summary['valid'],
                    'expiring' => $summary['expiring'],
                    'pending' => $summary['pending'],
                    'season' => $this->resolveCurrentSeasonLabel(),
                ],
                'documents' => $documents->all(),
                'alerts' => $alerts,
                'history' => $history,
                'notes' => [
                    'family' => 'Documentos dos educandos continuam na área Família.',
                    'settings' => 'Documentos institucionais e assinaturas dependem das configurações do clube.',
                ],
                'upload' => [
                    'enabled' => true,
                    'route' => route('portal.documents.store'),
                    'accept' => '.pdf,.jpg,.jpeg,.png,.webp',
                    'max_size_mb' => 5,
                ],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys(self::DOCUMENT_LABELS))],
            'name' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        $path = $request->file('file')->store('private/user-documents', 'local');

        $user->documents()->create([
            'type' => $validated['type'],
            'name' => $validated['name'] ?? null,
            'file_path' => $path,
            'expiry_date' => $validated['expiry_date'] ?? null,
        ]);

        return redirect()
            ->route('portal.documents')
            ->with('success', 'Documento carregado com sucesso.');
    }

    public function showLegacy(Request $request, string $documentType): StreamedResponse
    {
        return $this->respondWithLegacyFile($request, $documentType, false);
    }

    public function downloadLegacy(Request $request, string $documentType): StreamedResponse
    {
        return $this->respondWithLegacyFile($request, $documentType, true);
    }

    public function showUpload(Request $request, UserDocument $document): StreamedResponse
    {
        return $this->respondWithUpload($request, $document, false);
    }

    public function downloadUpload(Request $request, UserDocument $document): StreamedResponse
    {
        return $this->respondWithUpload($request, $document, true);
    }

    /**
     * @param  Collection<string, UserDocument>  $uploadedByType
     * @return Collection<int, array<string, mixed>>
     */
    private function buildEssentialDocuments(User $user, Collection $uploadedByType): Collection
    {
        $resolvedDocuments = $this->memberDocumentDataResolver->resolve($user);
        $documentData = $resolvedDocuments['documents'];
        $isAthlete = (bool) ($resolvedDocuments['is_athlete'] ?? false);

        $documents = collect([
            $this->buildEssentialDocument(
                type: 'rgpd',
                label: self::DOCUMENT_LABELS['rgpd'],
                isValidated: (bool) data_get($documentData, 'rgpd.isValidated', false),
                validatedAt: data_get($documentData, 'rgpd.validatedAt'),
                validUntil: null,
                legacyPath: data_get($documentData, 'rgpd.path'),
                upload: $uploadedByType->get('rgpd'),
                description: 'Consentimento RGPD associado à sua ficha.',
            ),
            $this->buildEssentialDocument(
                type: 'consentimento',
                label: self::DOCUMENT_LABELS['consentimento'],
                isValidated: (bool) data_get($documentData, 'consentimento.isValidated', false),
                validatedAt: data_get($documentData, 'consentimento.validatedAt'),
                validUntil: null,
                legacyPath: data_get($documentData, 'consentimento.path'),
                upload: $uploadedByType->get('consentimento'),
                description: 'Autorização de imagem e transporte disponível no Portal.',
            ),
            $this->buildEssentialDocument(
                type: 'atestado',
                label: self::DOCUMENT_LABELS['atestado'],
                isValidated: (bool) data_get($documentData, 'atestado.isValidated', false),
                validatedAt: data_get($documentData, 'atestado.validatedAt'),
                validUntil: data_get($documentData, 'atestado.validUntil'),
                legacyPath: data_get($documentData, 'atestado.path'),
                upload: $uploadedByType->get('atestado'),
                description: 'Documento com prioridade máxima quando está em falta ou a caducar.',
            ),
            $isAthlete ? $this->buildEssentialDocument(
                type: 'cartao_federacao',
                label: self::DOCUMENT_LABELS['cartao_federacao'],
                isValidated: (bool) data_get($documentData, 'cartao_federacao.isValidated', false),
                validatedAt: data_get($documentData, 'cartao_federacao.validatedAt'),
                validUntil: null,
                legacyPath: data_get($documentData, 'cartao_federacao.path'),
                upload: $uploadedByType->get('cartao_federacao'),
                description: 'Mostrado apenas para perfis com tipologia de atleta.',
            ) : null,
            $this->buildEssentialDocument(
                type: 'declaracao_transporte',
                label: self::DOCUMENT_LABELS['declaracao_transporte'],
                isValidated: (bool) data_get($documentData, 'declaracao_transporte.isValidated', false),
                validatedAt: data_get($documentData, 'declaracao_transporte.validatedAt'),
                validUntil: null,
                legacyPath: data_get($documentData, 'declaracao_transporte.path'),
                upload: $uploadedByType->get('declaracao_transporte'),
                description: 'Declaração específica para transporte quando exigida pelo clube.',
            ),
            $this->buildEssentialDocument(
                type: 'afiliacao',
                label: self::DOCUMENT_LABELS['afiliacao'],
                isValidated: (bool) data_get($documentData, 'afiliacao.isValidated', false),
                validatedAt: data_get($documentData, 'afiliacao.validatedAt'),
                validUntil: null,
                legacyPath: data_get($documentData, 'afiliacao.path'),
                upload: $uploadedByType->get('afiliacao'),
                description: 'Comprovativo de afiliação associado à época ativa.',
            ),
        ])->filter();

        $otherPlaceholder = $this->buildOtherPlaceholder($uploadedByType->get('outro'));

        return $documents->push($otherPlaceholder)->values();
    }

    private function buildEssentialDocument(
        string $type,
        string $label,
        bool $isValidated,
        Carbon|string|null $validatedAt,
        Carbon|string|null $validUntil,
        ?string $legacyPath,
        ?UserDocument $upload,
        string $description,
    ): array {
        $validatedDate = $this->normalizeDate($validatedAt);
        $validUntilDate = $this->normalizeDate($validUntil);
        $uploadDate = $upload?->created_at;
        $hasNewerUploadPendingReview = $uploadDate && (!$validatedDate || $uploadDate->gt($validatedDate));

        if ($hasNewerUploadPendingReview) {
            $statusKey = 'in_review';
            $statusLabel = 'Em validação';
            $highlight = 'Último ficheiro aguarda validação administrativa.';
            $preferredSource = 'upload';
        } elseif ($isValidated) {
            [$statusKey, $statusLabel, $highlight] = $this->resolveValidatedStatus($label, $validUntilDate);
            $preferredSource = 'legacy';
        } else {
            $statusKey = 'pending';
            $statusLabel = 'Pendente';
            $highlight = 'Documento ainda não submetido.';
            $preferredSource = 'upload';
        }

        $actionSource = $preferredSource === 'legacy' ? 'legacy' : ($upload ? 'upload' : ($legacyPath ? 'legacy' : null));
        $viewUrl = $actionSource === 'legacy'
            ? route('portal.documents.legacy.view', ['documentType' => $type])
            : ($upload ? route('portal.documents.uploads.view', ['document' => $upload->id]) : null);
        $downloadUrl = $actionSource === 'legacy'
            ? route('portal.documents.legacy.download', ['documentType' => $type])
            : ($upload ? route('portal.documents.uploads.download', ['document' => $upload->id]) : null);

        return [
            'id' => $type,
            'type' => $type,
            'name' => $label,
            'group' => 'essential',
            'description' => $description,
            'status' => [
                'key' => $statusKey,
                'label' => $statusLabel,
                'tone' => $this->statusTone($statusKey),
            ],
            'signed_at' => $this->formatDate($uploadDate ?: $validatedDate),
            'valid_until' => $this->formatDate($validUntilDate ?: $upload?->expiry_date),
            'highlight' => $highlight,
            'priority' => $type === 'atestado' ? 'high' : 'normal',
            'actions' => [
                'view_url' => $viewUrl,
                'download_url' => $downloadUrl,
                'upload_type' => $type,
                'can_upload' => true,
                'primary_upload_label' => $actionSource ? 'Substituir' : 'Carregar novo',
            ],
        ];
    }

    private function buildOtherPlaceholder(?UserDocument $upload): array
    {
        $statusKey = $upload ? $this->resolveUploadStatusKey($upload) : 'pending';

        return [
            'id' => 'outro',
            'type' => 'outro',
            'name' => self::DOCUMENT_LABELS['outro'],
            'group' => 'essential',
            'description' => 'Documentos adicionais configurados pelo clube e disponibilizados no Portal.',
            'status' => [
                'key' => $statusKey,
                'label' => $this->statusLabel($statusKey),
                'tone' => $this->statusTone($statusKey),
            ],
            'signed_at' => $this->formatDate($upload?->created_at),
            'valid_until' => $this->formatDate($upload?->expiry_date),
            'highlight' => $upload
                ? 'Existe pelo menos um documento adicional registado.'
                : 'Sem documentos adicionais carregados no Portal.',
            'priority' => 'normal',
            'actions' => [
                'view_url' => $upload ? route('portal.documents.uploads.view', ['document' => $upload->id]) : null,
                'download_url' => $upload ? route('portal.documents.uploads.download', ['document' => $upload->id]) : null,
                'upload_type' => 'outro',
                'can_upload' => true,
                'primary_upload_label' => $upload ? 'Substituir' : 'Carregar novo',
            ],
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildOtherDocuments(Collection $uploadedDocuments): Collection
    {
        return $uploadedDocuments
            ->filter(fn (UserDocument $document) => $document->type === 'outro')
            ->take(4)
            ->values()
            ->map(function (UserDocument $document, int $index): array {
                $statusKey = $this->resolveUploadStatusKey($document);

                return [
                    'id' => 'outro-upload-' . $document->id,
                    'type' => 'outro',
                    'name' => $document->name ?: 'Documento adicional ' . ($index + 1),
                    'group' => 'other',
                    'description' => 'Ficheiro carregado a partir do Portal.',
                    'status' => [
                        'key' => $statusKey,
                        'label' => $this->statusLabel($statusKey),
                        'tone' => $this->statusTone($statusKey),
                    ],
                    'signed_at' => $this->formatDate($document->created_at),
                    'valid_until' => $this->formatDate($document->expiry_date),
                    'highlight' => $document->expiry_date
                        ? 'Validade registada para controlo automático.'
                        : 'Sem validade associada ao ficheiro.',
                    'priority' => 'normal',
                    'actions' => [
                        'view_url' => route('portal.documents.uploads.view', ['document' => $document->id]),
                        'download_url' => route('portal.documents.uploads.download', ['document' => $document->id]),
                        'upload_type' => 'outro',
                        'can_upload' => true,
                        'primary_upload_label' => 'Substituir',
                    ],
                ];
            });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $documents
     * @return array{valid:int,expiring:int,pending:int}
     */
    private function buildSummary(Collection $documents): array
    {
        return [
            'valid' => $documents->where('status.key', 'valid')->count(),
            'expiring' => $documents->where('status.key', 'expiring')->count(),
            'pending' => $documents->whereIn('status.key', ['pending', 'expired', 'in_review'])->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $documents
     * @return array<string, mixed>
     */
    private function buildHero(Collection $documents): array
    {
        $priorityDocument = $documents
            ->sortBy(fn (array $document) => $this->heroPriorityScore((string) data_get($document, 'status.key'), (string) ($document['type'] ?? '')))
            ->first();

        if (!$priorityDocument) {
            return [
                'title' => 'Documentos',
                'headline' => 'Todos os documentos estão válidos.',
                'subheadline' => 'Sem pendências no Portal.',
                'tone' => 'success',
                'primary_upload_type' => 'outro',
            ];
        }

        $statusKey = (string) data_get($priorityDocument, 'status.key');
        $headline = match ($statusKey) {
            'expired' => $priorityDocument['name'] . ' expirado',
            'expiring' => $priorityDocument['name'] . ' a caducar',
            'in_review' => $priorityDocument['name'] . ' em validação',
            'pending' => $priorityDocument['name'] . ' em falta',
            default => 'Tudo válido',
        };

        $subheadline = match ($statusKey) {
            'expiring' => $priorityDocument['valid_until']
                ? 'Validade termina em ' . $this->daysUntilLabel((string) $priorityDocument['valid_until'])
                : 'Documento com validade próxima.',
            'expired' => 'Precisa de substituição para manter a ficha regularizada.',
            'in_review' => 'O último ficheiro foi recebido e aguarda validação.',
            'pending' => 'Carregue o documento para completar a sua ficha.',
            default => 'Todos os documentos apresentados estão regulares.',
        };

        return [
            'title' => 'Documentos',
            'headline' => $headline,
            'subheadline' => $subheadline,
            'tone' => $this->statusTone($statusKey),
            'primary_upload_type' => $priorityDocument['actions']['upload_type'] ?? 'outro',
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $documents
     * @return array<string, mixed>
     */
    private function buildAlerts(Collection $documents): array
    {
        $items = $documents
            ->filter(fn (array $document) => in_array(data_get($document, 'status.key'), ['pending', 'expired', 'expiring'], true))
            ->sortBy(fn (array $document) => $this->alertPriorityScore((string) ($document['type'] ?? ''), (string) data_get($document, 'status.key')))
            ->values()
            ->map(fn (array $document) => [
                'id' => $document['id'],
                'name' => $document['name'],
                'status' => data_get($document, 'status.label'),
                'message' => $document['highlight'],
                'valid_until' => $document['valid_until'],
                'is_medical' => $document['type'] === 'atestado',
            ])
            ->all();

        return [
            'items' => $items,
            'empty_message' => empty($items)
                ? 'Todos os documentos estão válidos.'
                : null,
        ];
    }

    /**
     * @param  Collection<int, UserDocument>  $uploadedDocuments
     * @param  Collection<int, array<string, mixed>>  $essentialDocuments
     * @return array<int, array<string, mixed>>
     */
    private function buildHistory(User $user, Collection $uploadedDocuments, Collection $essentialDocuments): array
    {
        $legacyEntries = collect($this->memberDocumentDataResolver->history($user, $essentialDocuments->all()))
            ->map(fn (array $entry): array => [
                'id' => (string) ($entry['id'] ?? ''),
                'name' => (string) ($entry['name'] ?? 'Documento'),
                'date' => $this->formatDate($entry['date'] ?? null),
                'status' => (string) ($entry['status'] ?? 'Válido'),
            ])
            ->filter(fn (array $entry): bool => filled($entry['date']));

        $uploadEntries = $uploadedDocuments->map(function (UserDocument $document): array {
            $statusKey = $this->resolveUploadStatusKey($document);

            return [
                'id' => (string) $document->id,
                'name' => $document->name ?: (self::DOCUMENT_LABELS[$document->type] ?? 'Documento'),
                'date' => $this->formatDate($document->created_at),
                'status' => $this->statusLabel($statusKey),
            ];
        });

        return $uploadEntries
            ->concat($legacyEntries)
            ->filter(fn (array $item) => filled($item['date']))
            ->sortByDesc(function (array $item): int {
                $parsed = Carbon::createFromFormat('d/m/Y', (string) $item['date']);

                return $parsed?->getTimestamp() ?? 0;
            })
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $essentialDocuments
     * @return array<string, mixed>|null
     */
    private function legacyHistoryEntry(string $name, Carbon|string|null $date, bool $exists, Collection $essentialDocuments, string $type): ?array
    {
        if (!$exists) {
            return null;
        }

        $document = $essentialDocuments->firstWhere('type', $type);

        return [
            'id' => 'legacy-' . $type,
            'name' => $name,
            'date' => $this->formatDate($date),
            'status' => data_get($document, 'status.label', 'Válido'),
        ];
    }

    private function respondWithLegacyFile(Request $request, string $documentType, bool $download): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $path = $this->legacyDocumentPath($user, $documentType);

        abort_unless($path && Storage::disk('public')->exists($path), 404);

        $filename = $this->downloadFilename($documentType, $path);

        return $download
            ? Storage::disk('public')->download($path, $filename)
            : Storage::disk('public')->response($path, $filename, ['Content-Disposition' => 'inline; filename="' . $filename . '"']);
    }

    private function respondWithUpload(Request $request, UserDocument $document, bool $download): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $disk = $this->userDocumentDisk($document);

        abort_unless((string) $document->user_id === (string) $user->id, 404);
        abort_unless($disk->exists($document->file_path), 404);

        $filename = $this->downloadFilename($document->type, $document->file_path, $document->name);

        return $download
            ? $disk->download($document->file_path, $filename)
            : $disk->response($document->file_path, $filename, ['Content-Disposition' => 'inline; filename="' . $filename . '"']);
    }

    private function userDocumentDisk(UserDocument $document): FilesystemAdapter
    {
        if (Storage::disk('local')->exists($document->file_path)) {
            return Storage::disk('local');
        }

        return Storage::disk('public');
    }

    private function legacyDocumentPath(User $user, string $documentType): ?string
    {
        $paths = $this->memberDocumentDataResolver->documentPaths($user);

        return $paths[$documentType] ?? null;
    }

    private function resolveCurrentSeasonLabel(): string
    {
        $season = Season::query()
            ->where('estado', 'Em curso')
            ->orderByDesc('data_inicio')
            ->first(['nome', 'ano_temporada']);

        if (!$season) {
            $season = Season::query()->orderByDesc('data_inicio')->first(['nome', 'ano_temporada']);
        }

        return $season?->nome ?: $season?->ano_temporada ?: 'Sem época ativa';
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function resolveValidatedStatus(string $label, ?Carbon $validUntil): array
    {
        if (!$validUntil) {
            return ['valid', 'Válido', 'Documento regularizado.'];
        }

        $today = now()->startOfDay();

        if ($validUntil->lt($today)) {
            return ['expired', 'Expirado', $label . ' expirado.'];
        }

        $daysUntilExpiry = $today->diffInDays($validUntil, false);

        if ($daysUntilExpiry <= self::EXPIRING_DAYS) {
            return ['expiring', 'A caducar', 'Validade termina em ' . $daysUntilExpiry . ' dias.'];
        }

        return ['valid', 'Válido', 'Válido até ' . $validUntil->format('d/m/Y') . '.'];
    }

    private function resolveUploadStatusKey(UserDocument $document): string
    {
        return $this->resolveValidatedStatus($document->name ?: 'Documento', $this->normalizeDate($document->expiry_date))[0];
    }

    private function statusLabel(string $statusKey): string
    {
        return match ($statusKey) {
            'valid' => 'Válido',
            'expiring' => 'A caducar',
            'expired' => 'Expirado',
            'in_review' => 'Em validação',
            default => 'Pendente',
        };
    }

    private function statusTone(string $statusKey): string
    {
        return match ($statusKey) {
            'valid' => 'success',
            'expiring' => 'warning',
            'expired' => 'danger',
            'in_review' => 'info',
            default => 'muted',
        };
    }

    private function heroPriorityScore(string $statusKey, string $type): int
    {
        $base = match ($statusKey) {
            'expired' => 0,
            'pending' => 1,
            'expiring' => 2,
            'in_review' => 3,
            default => 4,
        };

        if ($type === 'atestado') {
            return $base * 10;
        }

        return $base * 10 + 5;
    }

    private function alertPriorityScore(string $type, string $statusKey): int
    {
        $medicalBoost = $type === 'atestado' ? 0 : 10;
        $statusBoost = $statusKey === 'expired' ? 0 : 1;

        return $medicalBoost + $statusBoost;
    }

    private function daysUntilLabel(string $formattedDate): string
    {
        $date = Carbon::createFromFormat('d/m/Y', $formattedDate);
        $days = max(0, now()->startOfDay()->diffInDays($date, false));

        return $days . ' dias';
    }

    private function formatDate(Carbon|string|null $date): ?string
    {
        $normalized = $this->normalizeDate($date);

        return $normalized?->format('d/m/Y');
    }

    private function normalizeDate(Carbon|string|null $date): ?Carbon
    {
        if ($date instanceof Carbon) {
            return $date->copy()->startOfDay();
        }

        if (is_string($date) && trim($date) !== '') {
            return Carbon::parse($date)->startOfDay();
        }

        return null;
    }

    private function normalizeLegacyPath(mixed $value): ?string
    {
        if (is_array($value)) {
            $first = collect($value)->first(fn (mixed $item) => is_string($item) && $item !== '');

            return is_string($first) ? $first : null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function downloadFilename(string $documentType, string $path, ?string $name = null): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $baseName = $name ?: (self::DOCUMENT_LABELS[$documentType] ?? 'documento');

        return Str::slug($baseName, '_') . ($extension ? '.' . $extension : '');
    }
}