<?php

declare(strict_types=1);

namespace App\Http\Controllers\Membros;

use App\Http\Controllers\Controller;
use App\Models\DadosConfiguracao;
use App\Models\User;
use App\Services\AccessControl\UserTypeAccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MemberMedicalDocumentController extends Controller
{
    public function __construct(
        private readonly UserTypeAccessControlService $accessControlService,
    ) {
    }

    public function update(Request $request, User $member): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor !== null, 401);
        abort_unless($this->accessControlService->canAccessPermission($actor, 'membros.ficha', 'edit'), 403);

        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'file' => ['nullable', 'string'],
        ]);

        $record = DadosConfiguracao::query()->firstOrNew(['user_id' => $member->id]);
        $currentPath = trim((string) ($record->certificado_medico_ficheiro ?? '')) ?: null;
        $nextPath = $currentPath;

        if (array_key_exists('file', $data)) {
            $file = trim((string) ($data['file'] ?? ''));

            if ($file === '') {
                $this->deleteStoredFile($currentPath);
                $nextPath = null;
            } elseif (str_starts_with($file, 'data:')) {
                $nextPath = $this->storeBase64File($file);
                if ($currentPath && $currentPath !== $nextPath) {
                    $this->deleteStoredFile($currentPath);
                }
            } elseif ($file !== $currentPath) {
                throw ValidationException::withMessages([
                    'file' => 'O ficheiro deve ser um novo upload ou o documento atualmente guardado.',
                ]);
            }
        }

        $extra = is_array($record->configuracao_extra) ? $record->configuracao_extra : [];
        $extra['medical_certificate'] = [
            'date' => $data['date'] ?? null,
            'updated_at' => now()->toIso8601String(),
            'updated_by' => (string) $actor->id,
        ];

        $record->certificado_medico_ficheiro = $nextPath;
        $record->configuracao_extra = $extra;
        if (! $record->exists && ! $record->migrated_from_users_at) {
            $record->migrated_from_users_at = now();
        }
        $record->save();

        return response()->json([
            'message' => 'Atestado médico atualizado em Membros.',
            'data' => [
                'date' => $extra['medical_certificate']['date'],
                'file' => $nextPath,
            ],
        ]);
    }

    private function storeBase64File(string $payload): string
    {
        if (! preg_match('/^data:([^;]+);base64,/', $payload, $match)) {
            throw ValidationException::withMessages(['file' => 'Formato de ficheiro inválido.']);
        }

        $mime = $match[1];
        $extensions = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ];

        if (! isset($extensions[$mime])) {
            throw ValidationException::withMessages(['file' => 'Tipo de ficheiro não suportado.']);
        }

        $encoded = substr($payload, strpos($payload, ',') + 1);
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw ValidationException::withMessages(['file' => 'Não foi possível descodificar o ficheiro.']);
        }

        if (strlen($decoded) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'O atestado médico não pode exceder 5 MB.']);
        }

        $path = 'members/medical-certificates/'.Str::uuid().'.'.$extensions[$mime];
        Storage::disk('public')->put($path, $decoded);

        return $path;
    }

    private function deleteStoredFile(?string $path): void
    {
        if (! $path || str_starts_with($path, 'data:')) {
            return;
        }

        $normalized = $path;
        if (str_starts_with($normalized, 'http')) {
            $parsed = parse_url($normalized, PHP_URL_PATH);
            $normalized = is_string($parsed) ? $parsed : $normalized;
        }
        if (str_starts_with($normalized, '/storage/')) {
            $normalized = substr($normalized, strlen('/storage/'));
        }

        if (Storage::disk('public')->exists($normalized)) {
            Storage::disk('public')->delete($normalized);
        }
    }
}
