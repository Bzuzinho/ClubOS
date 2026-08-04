<?php

namespace App\Http\Controllers;

use App\Models\PublicFormSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;

class PublicFormSubmissionController extends Controller
{
    public function contact(Request $request): RedirectResponse
    {
        if ($this->isSpam($request)) {
            return back()->with('success', 'Pedido recebido.');
        }

        $validator = Validator::make($request->all(), [
            'athleteName' => ['required', 'string', 'max:140'],
            'birthDate' => ['required', 'date', 'before_or_equal:today'],
            'email' => ['required', 'email:rfc', 'max:180'],
            'phone' => ['required', 'string', 'max:40'],
            'program' => ['required', 'string', 'max:100'],
            'experience' => ['required', 'string', 'max:120'],
            'guardianName' => ['nullable', 'string', 'max:140'],
            'guardianEmail' => ['nullable', 'email:rfc', 'max:180'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
            'company' => ['nullable', 'string', 'max:100'],
        ]);

        $this->requireGuardianForMinor($validator, $request, ['guardianName', 'guardianEmail']);
        $data = $validator->validate();

        $this->store('contact', $data, $request);

        return back()->with('success', 'Pedido de contacto enviado. A equipa do BSCN entrará em contacto contigo.');
    }

    public function registration(Request $request): RedirectResponse
    {
        if ($this->isSpam($request)) {
            return back()->with('success', 'Registo recebido.');
        }

        $validator = Validator::make($request->all(), [
            'athleteName' => ['required', 'string', 'max:140'],
            'birthDate' => ['required', 'date', 'before_or_equal:today'],
            'locality' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:180'],
            'phone' => ['required', 'string', 'max:40'],
            'program' => ['required', 'string', 'max:100'],
            'experience' => ['required', 'string', 'max:120'],
            'previousClub' => ['nullable', 'string', 'max:140'],
            'federationNumber' => ['nullable', 'string', 'max:40'],
            'availability' => ['nullable', 'string', 'max:1000'],
            'guardianName' => ['nullable', 'string', 'max:140'],
            'guardianRelationship' => ['nullable', 'string', 'max:80'],
            'guardianEmail' => ['nullable', 'email:rfc', 'max:180'],
            'guardianPhone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
            'accuracy' => ['accepted'],
            'company' => ['nullable', 'string', 'max:100'],
        ]);

        $this->requireGuardianForMinor($validator, $request, [
            'guardianName',
            'guardianRelationship',
            'guardianEmail',
            'guardianPhone',
        ]);
        $data = $validator->validate();

        $this->store('registration', $data, $request);

        return back()->with('success', 'Registo submetido. A equipa do BSCN irá validar os dados e indicar os próximos passos.');
    }

    /** @param array<int, string> $fields */
    private function requireGuardianForMinor(LaravelValidator $validator, Request $request, array $fields): void
    {
        $validator->after(function (LaravelValidator $validator) use ($request, $fields) {
            $birthDate = $request->string('birthDate')->trim()->toString();

            if ($birthDate === '' || ! Carbon::hasFormat($birthDate, 'Y-m-d')) {
                return;
            }

            if (Carbon::createFromFormat('Y-m-d', $birthDate)->age >= 18) {
                return;
            }

            foreach ($fields as $field) {
                if ($request->string($field)->trim()->isEmpty()) {
                    $validator->errors()->add($field, 'Este campo é obrigatório para atletas menores.');
                }
            }
        });
    }

    /** @param array<string, mixed> $data */
    private function store(string $type, array $data, Request $request): void
    {
        PublicFormSubmission::create([
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
            'privacy_consent_at' => now(),
            'ip_hash' => $request->ip() ? hash_hmac('sha256', $request->ip(), (string) config('app.key')) : null,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
            'payload' => collect($data)->except(['company', 'consent', 'accuracy'])->all(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function nullable(array $data, string $key): ?string
    {
        $value = trim((string) ($data[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    private function isSpam(Request $request): bool
    {
        return $request->filled('company');
    }
}
