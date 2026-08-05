<?php

namespace App\Http\Controllers;

use App\Models\PublicFormSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteRedesController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->renderIndex($request);
    }

    public function show(Request $request, PublicFormSubmission $submission): Response
    {
        return $this->renderIndex($request, $submission);
    }

    public function updateStatus(Request $request, PublicFormSubmission $submission): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['new', 'in_review', 'contacted', 'accepted', 'rejected'])],
        ]);

        $submission->forceFill([
            'status' => $validated['status'],
            'processed_by' => $request->user()->id,
            'processed_at' => now(),
        ])->save();

        return back()->with('success', 'Estado do pedido atualizado.');
    }

    private function renderIndex(Request $request, ?PublicFormSubmission $selected = null): Response
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(['contact', 'registration'])],
            'status' => ['nullable', Rule::in(['new', 'in_review', 'contacted', 'accepted', 'rejected'])],
            'search' => ['nullable', 'string', 'max:140'],
        ]);

        $query = PublicFormSubmission::query()
            ->with([
                'user:id,name,estado,numero_socio',
                'processedBy:id,name',
            ])
            ->when($filters['type'] ?? null, fn ($builder, string $type) => $builder->where('type', $type))
            ->when($filters['status'] ?? null, fn ($builder, string $status) => $builder->where('status', $status))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function ($builder) use ($filters) {
                $search = trim((string) $filters['search']);
                $builder->where(function ($nested) use ($search) {
                    $nested->where('athlete_name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('guardian_name', 'like', '%'.$search.'%');
                });
            })
            ->latest();

        $submissions = $query->paginate(20)->withQueryString()->through(fn (PublicFormSubmission $submission): array => $this->submissionPayload($submission));

        $selectedPayload = null;
        if ($selected !== null) {
            $selected->loadMissing(['user:id,name,estado,numero_socio', 'processedBy:id,name']);
            $selectedPayload = $this->submissionPayload($selected, includePayload: true);
        }

        return Inertia::render('WebsiteRedes/Index', [
            'summary' => [
                'new' => PublicFormSubmission::query()->where('status', 'new')->count(),
                'in_review' => PublicFormSubmission::query()->where('status', 'in_review')->count(),
                'registrations' => PublicFormSubmission::query()->where('type', 'registration')->count(),
                'contacts' => PublicFormSubmission::query()->where('type', 'contact')->count(),
            ],
            'submissions' => $submissions,
            'selectedSubmission' => $selectedPayload,
            'filters' => [
                'type' => $filters['type'] ?? '',
                'status' => $filters['status'] ?? '',
                'search' => $filters['search'] ?? '',
            ],
            'channels' => [
                'website' => 'active',
                'facebook' => 'pending',
                'instagram' => 'pending',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function submissionPayload(PublicFormSubmission $submission, bool $includePayload = false): array
    {
        $payload = [
            'id' => $submission->id,
            'type' => $submission->type,
            'status' => $submission->status,
            'athlete_name' => $submission->athlete_name,
            'birth_date' => $submission->birth_date?->toDateString(),
            'email' => $submission->email,
            'phone' => $submission->phone,
            'program' => $submission->program,
            'experience' => $submission->experience,
            'locality' => $submission->locality,
            'previous_club' => $submission->previous_club,
            'federation_number' => $submission->federation_number,
            'availability' => $submission->availability,
            'guardian_name' => $submission->guardian_name,
            'guardian_relationship' => $submission->guardian_relationship,
            'guardian_email' => $submission->guardian_email,
            'guardian_phone' => $submission->guardian_phone,
            'notes' => $submission->notes,
            'privacy_consent_at' => $submission->privacy_consent_at?->toISOString(),
            'email_queued_at' => $submission->email_queued_at?->toISOString(),
            'admin_notified_at' => $submission->admin_notified_at?->toISOString(),
            'processed_at' => $submission->processed_at?->toISOString(),
            'processed_by' => $submission->processedBy?->name,
            'created_at' => $submission->created_at?->toISOString(),
            'user' => $submission->user ? [
                'id' => $submission->user->id,
                'name' => $submission->user->name,
                'estado' => $submission->user->estado,
                'numero_socio' => $submission->user->numero_socio,
                'url' => route('membros.show', ['member' => $submission->user->id]),
            ] : null,
        ];

        if ($includePayload) {
            $payload['payload'] = $submission->payload;
        }

        return $payload;
    }
}
