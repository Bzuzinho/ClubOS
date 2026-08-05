<?php

declare(strict_types=1);

namespace App\Services\Website;

use App\Mail\PublicFormSubmissionReceived;
use App\Models\DadosConfiguracao;
use App\Models\DadosPessoais;
use App\Models\PublicFormSubmission;
use App\Models\PublicRegistrationIdentity;
use App\Models\User;
use App\Services\Communication\InAppAlertService;
use App\Services\Members\MemberDataWriteService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class PublicFormWorkflowService
{
    public function __construct(
        private readonly MemberDataWriteService $memberDataWriteService,
        private readonly InAppAlertService $inAppAlertService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{ip_hash: ?string, user_agent: ?string}  $requestMetadata
     */
    public function submitContact(array $data, array $requestMetadata): PublicFormSubmission
    {
        $submission = DB::transaction(fn (): PublicFormSubmission => $this->createSubmission(
            type: 'contact',
            data: $data,
            requestMetadata: $requestMetadata,
        ));

        $this->dispatchOperationalNotifications($submission);

        return $submission->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{ip_hash: ?string, user_agent: ?string}  $requestMetadata
     */
    public function submitRegistration(array $data, array $requestMetadata): PublicFormSubmission
    {
        $fingerprint = $this->identityFingerprint((string) $data['athleteName'], (string) $data['birthDate']);

        /** @var PublicFormSubmission $submission */
        $submission = Cache::lock('website-registration:'.$fingerprint, 15)->block(5, function () use ($data, $requestMetadata, $fingerprint): PublicFormSubmission {
            return DB::transaction(function () use ($data, $requestMetadata, $fingerprint): PublicFormSubmission {
                $identity = PublicRegistrationIdentity::query()
                    ->where('fingerprint', $fingerprint)
                    ->lockForUpdate()
                    ->first();

                if ($identity === null) {
                    $identity = PublicRegistrationIdentity::query()->create([
                        'fingerprint' => $fingerprint,
                    ]);
                }

                $submission = $this->createSubmission(
                    type: 'registration',
                    data: $data,
                    requestMetadata: $requestMetadata,
                    identityFingerprint: $fingerprint,
                );

                $member = $identity->user;

                if ($member === null) {
                    $member = $this->findExistingCanonicalMember($data)
                        ?? $this->createPreRegistrationMember($data, $submission);

                    $identity->forceFill(['user_id' => $member->id])->save();
                }

                $submission->forceFill(['user_id' => $member->id])->save();

                return $submission;
            });
        });

        $this->dispatchOperationalNotifications($submission);

        return $submission->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{ip_hash: ?string, user_agent: ?string}  $requestMetadata
     */
    private function createSubmission(
        string $type,
        array $data,
        array $requestMetadata,
        ?string $identityFingerprint = null,
    ): PublicFormSubmission {
        return PublicFormSubmission::query()->create([
            'type' => $type,
            'athlete_name' => trim((string) $data['athleteName']),
            'birth_date' => $data['birthDate'],
            'email' => mb_strtolower(trim((string) $data['email'])),
            'phone' => trim((string) $data['phone']),
            'program' => trim((string) $data['program']),
            'experience' => trim((string) $data['experience']),
            'locality' => $this->nullable($data, 'locality'),
            'previous_club' => $this->nullable($data, 'previousClub'),
            'federation_number' => $this->nullable($data, 'federationNumber'),
            'availability' => $this->nullable($data, 'availability'),
            'guardian_name' => $this->nullable($data, 'guardianName'),
            'guardian_relationship' => $this->nullable($data, 'guardianRelationship'),
            'guardian_email' => ($email = $this->nullable($data, 'guardianEmail')) ? mb_strtolower($email) : null,
            'guardian_phone' => $this->nullable($data, 'guardianPhone'),
            'notes' => $this->nullable($data, 'notes'),
            'status' => 'new',
            'identity_fingerprint' => $identityFingerprint,
            'privacy_consent_at' => now(),
            'ip_hash' => $requestMetadata['ip_hash'],
            'user_agent' => $requestMetadata['user_agent'],
            'payload' => collect($data)->except(['company', 'consent', 'accuracy'])->all(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function findExistingCanonicalMember(array $data): ?User
    {
        $normalizedName = $this->normalizeIdentityValue((string) $data['athleteName']);

        $matches = DadosPessoais::query()
            ->with('user')
            ->whereDate('data_nascimento', (string) $data['birthDate'])
            ->get()
            ->filter(fn (DadosPessoais $personal): bool => $this->normalizeIdentityValue((string) $personal->nome_completo) === $normalizedName)
            ->pluck('user')
            ->filter();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @param array<string, mixed> $data */
    private function createPreRegistrationMember(array $data, PublicFormSubmission $submission): User
    {
        $birthDate = Carbon::parse((string) $data['birthDate']);
        $member = User::query()->create([
            'name' => trim((string) $data['athleteName']),
            'email' => 'pre-registration+'.Str::uuid().'@bscn.invalid',
            'password' => Hash::make(Str::random(64)),
            'numero_socio' => null,
            'perfil' => null,
            'tipo_membro' => [],
            'estado' => 'inativo',
            'menor' => $birthDate->age < 18,
            'ativo_desportivo' => false,
            'email_utilizador' => null,
            'data_inscricao' => null,
        ]);

        $this->memberDataWriteService->persistFromMemberRequest($member, [
            'nome_completo' => trim((string) $data['athleteName']),
            'data_nascimento' => $birthDate->toDateString(),
            'localidade' => $this->nullable($data, 'locality'),
            'contacto' => trim((string) $data['phone']),
            'email_secundario' => mb_strtolower(trim((string) $data['email'])),
            'observacoes' => $this->preRegistrationNotes($data),
            'consentimento_rgpd' => true,
            'consentimento_rgpd_data' => now(),
            'acesso_portal_ativo' => false,
            'configuracao_extra' => [
                'origem' => 'public_website_preregistration',
                'origem_label' => 'Pré-inscrição — Website',
                'public_submission_id' => $submission->id,
                'programa_pretendido' => trim((string) $data['program']),
                'experiencia_declarada' => trim((string) $data['experience']),
            ],
        ]);

        DadosConfiguracao::query()
            ->where('user_id', $member->id)
            ->update([
                'platform_access_enabled' => false,
                'acesso_portal_ativo' => false,
            ]);

        return $member->refresh();
    }

    private function dispatchOperationalNotifications(PublicFormSubmission $submission): void
    {
        $recipient = (string) config('public_website.submissions_recipient');

        try {
            Mail::to($recipient)->queue(new PublicFormSubmissionReceived($submission));
            $submission->forceFill(['email_queued_at' => now()])->save();
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            $administrators = User::query()
                ->where(function ($query) {
                    $query->whereIn('perfil', ['admin', 'administrador', 'direcao', 'direção', 'dirigente'])
                        ->orWhereHas('userTypes', fn ($userTypes) => $userTypes->whereIn('codigo', ['administrador', 'direcao']));
                })
                ->get(['id'])
                ->map(fn (User $user): array => ['user_id' => $user->id]);

            $created = $this->inAppAlertService->createAlerts([
                'title' => $submission->type === 'registration' ? 'Nova pré-inscrição no website' : 'Novo pedido de contacto no website',
                'message' => $submission->athlete_name.' · '.$submission->email.' · '.$submission->phone,
                'link' => '/website-redes/pedidos/'.$submission->id,
                'type' => 'info',
            ], $administrators);

            if ($created > 0) {
                $submission->forceFill(['admin_notified_at' => now()])->save();
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @param array<string, mixed> $data */
    private function preRegistrationNotes(array $data): string
    {
        return collect([
            'Origem: Pré-inscrição — Website',
            'Programa: '.trim((string) $data['program']),
            'Experiência: '.trim((string) $data['experience']),
            $this->nullable($data, 'previousClub') ? 'Clube anterior: '.$this->nullable($data, 'previousClub') : null,
            $this->nullable($data, 'availability') ? 'Disponibilidade: '.$this->nullable($data, 'availability') : null,
            $this->nullable($data, 'notes') ? 'Notas: '.$this->nullable($data, 'notes') : null,
        ])->filter()->implode("\n");
    }

    private function identityFingerprint(string $name, string $birthDate): string
    {
        return hash('sha256', $this->normalizeIdentityValue($name).'|'.$birthDate);
    }

    private function normalizeIdentityValue(string $value): string
    {
        return Str::of($value)
            ->trim()
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();
    }

    /** @param array<string, mixed> $data */
    private function nullable(array $data, string $key): ?string
    {
        $value = trim((string) ($data[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
