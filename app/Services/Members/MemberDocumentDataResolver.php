<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\User;
use Illuminate\Support\Collection;

final class MemberDocumentDataResolver
{
    public function __construct(
        private readonly MemberDataReadService $memberDataReadService,
    ) {
    }

    /**
     * @return array{
     *   is_athlete: bool,
     *   documents: array<string, array{isValidated:bool,validatedAt:mixed,validUntil:mixed,path:?string}>
     * }
     */
    public function resolve(User $user): array
    {
        $configuration = $this->memberDataReadService->configurationPayload($user);
        $documentPaths = $this->documentPaths($user);

        return [
            'is_athlete' => $this->isAthlete($user),
            'documents' => [
                'rgpd' => [
                    'isValidated' => (bool) ($configuration['consentimento_rgpd'] ?? false),
                    'validatedAt' => $configuration['consentimento_rgpd_data'] ?? null,
                    'validUntil' => null,
                    'path' => $documentPaths['rgpd'],
                ],
                'consentimento' => [
                    'isValidated' => (bool) (($configuration['consentimento_imagem'] ?? false) || ($configuration['declaracao_transporte'] ?? false)),
                    'validatedAt' => $configuration['consentimento_imagem_data'] ?? null,
                    'validUntil' => null,
                    'path' => $documentPaths['consentimento'],
                ],
                'atestado' => [
                    'isValidated' => filled($this->legacyAttribute($user, 'data_atestado_medico')),
                    'validatedAt' => $this->legacyAttribute($user, 'data_atestado_medico'),
                    'validUntil' => $this->legacyAttribute($user, 'data_atestado_medico'),
                    'path' => $documentPaths['atestado'],
                ],
                'cartao_federacao' => [
                    'isValidated' => filled($this->legacyAttribute($user, 'cartao_federacao')) || filled($configuration['afiliacao_numero'] ?? null),
                    'validatedAt' => $configuration['afiliacao_data'] ?? null,
                    'validUntil' => null,
                    'path' => $documentPaths['cartao_federacao'],
                ],
                'declaracao_transporte' => [
                    'isValidated' => (bool) ($configuration['declaracao_transporte'] ?? false),
                    'validatedAt' => $configuration['consentimento_imagem_data'] ?? ($configuration['declaracao_transporte_data'] ?? null),
                    'validUntil' => null,
                    'path' => $documentPaths['declaracao_transporte'],
                ],
                'afiliacao' => [
                    'isValidated' => (bool) ($configuration['afiliacao_federativa'] ?? false),
                    'validatedAt' => $configuration['afiliacao_data'] ?? null,
                    'validUntil' => null,
                    'path' => $documentPaths['afiliacao'],
                ],
            ],
        ];
    }

    /**
     * @return array{rgpd:?string,consentimento:?string,atestado:?string,cartao_federacao:?string,declaracao_transporte:?string,afiliacao:?string}
     */
    public function documentPaths(User $user): array
    {
        $configuration = $this->memberDataReadService->configurationPayload($user);

        return [
            'rgpd' => $this->normalizeLegacyPath($this->legacyAttribute($user, 'arquivo_rgpd')),
            'consentimento' => $this->normalizeLegacyPath($this->legacyAttribute($user, 'arquivo_consentimento'))
                ?: $this->normalizeLegacyPath($configuration['declaracao_transporte_ficheiro'] ?? null)
                ?: $this->normalizeLegacyPath($this->legacyAttribute($user, 'declaracao_transporte')),
            'atestado' => $this->normalizeLegacyPath($configuration['certificado_medico_ficheiro'] ?? null)
                ?: $this->normalizeLegacyPath($this->legacyAttribute($user, 'arquivo_atestado_medico')),
            'cartao_federacao' => $this->normalizeLegacyPath($this->legacyAttribute($user, 'cartao_federacao'))
                ?: $this->normalizeLegacyPath($configuration['afiliacao_ficheiro'] ?? null),
            'declaracao_transporte' => $this->normalizeLegacyPath($configuration['declaracao_transporte_ficheiro'] ?? null)
                ?: $this->normalizeLegacyPath($this->legacyAttribute($user, 'declaracao_transporte')),
            'afiliacao' => $this->normalizeLegacyPath($configuration['afiliacao_ficheiro'] ?? null)
                ?: $this->normalizeLegacyPath($this->legacyAttribute($user, 'arquivo_afiliacao')),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $essentialDocuments
     * @return array<int, array{id:string,name:string,date:?string,status:string}>
     */
    public function history(User $user, array $essentialDocuments = []): array
    {
        $resolved = $this->resolve($user);
        $documents = collect($resolved['documents']);
        $essentialByType = collect($essentialDocuments)->keyBy('type');

        $historyMap = [
            'rgpd' => 'RGPD',
            'consentimento' => 'Consentimento imagem/transporte',
            'atestado' => 'Atestado medico',
            'afiliacao' => 'Afiliacao',
        ];

        return collect($historyMap)
            ->map(function (string $label, string $type) use ($documents, $essentialByType): ?array {
                $document = $documents->get($type);

                if (!is_array($document) || !($document['isValidated'] ?? false)) {
                    return null;
                }

                return [
                    'id' => 'legacy-' . $type,
                    'name' => $label,
                    'date' => $document['validatedAt'] ?? null,
                    'status' => (string) ($essentialByType->get($type)['status']['label'] ?? 'Valido'),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function isAthlete(User $user): bool
    {
        return collect($user->getAttribute('tipo_membro') ?? [])->contains(
            fn (mixed $type): bool => $type === 'atleta'
        );
    }

    private function legacyAttribute(User $user, string $field): mixed
    {
        // transitional legacy document path fallback
        return $user->getAttribute($field);
    }

    private function normalizeLegacyPath(mixed $value): ?string
    {
        if (is_array($value)) {
            $first = Collection::make($value)->first(fn (mixed $item) => is_string($item) && $item !== '');

            return is_string($first) ? $first : null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
