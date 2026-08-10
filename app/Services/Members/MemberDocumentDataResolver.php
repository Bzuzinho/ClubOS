<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\User;
use DateTimeInterface;
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
        $memberDocumentPayload = $this->memberDocumentPayload($user);
        $documentPaths = $this->documentPaths($user);

        return [
            'is_athlete' => $this->isAthlete($user),
            'documents' => [
                'rgpd' => [
                    'isValidated' => (bool) $memberDocumentPayload['rgpd'],
                    'validatedAt' => $memberDocumentPayload['data_rgpd'],
                    'validUntil' => null,
                    'path' => $memberDocumentPayload['arquivo_rgpd'],
                ],
                'consentimento' => [
                    'isValidated' => (bool) ($memberDocumentPayload['consentimento'] || $memberDocumentPayload['declaracao_de_transporte']),
                    'validatedAt' => $memberDocumentPayload['data_consentimento'],
                    'validUntil' => null,
                    'path' => $memberDocumentPayload['arquivo_consentimento'],
                ],
                'atestado' => [
                    'isValidated' => filled($memberDocumentPayload['data_atestado_medico']),
                    'validatedAt' => $memberDocumentPayload['data_atestado_medico'],
                    'validUntil' => $memberDocumentPayload['data_atestado_medico'],
                    'path' => $documentPaths['atestado'],
                ],
                'cartao_federacao' => [
                    'isValidated' => filled($memberDocumentPayload['cartao_federacao']) || filled($this->memberDataReadService->sportsPayload($user)['num_federacao'] ?? null),
                    'validatedAt' => $memberDocumentPayload['data_afiliacao'],
                    'validUntil' => null,
                    'path' => $memberDocumentPayload['cartao_federacao'],
                ],
                'declaracao_transporte' => [
                    'isValidated' => (bool) $memberDocumentPayload['declaracao_de_transporte'],
                    'validatedAt' => $memberDocumentPayload['data_consentimento'] ?? ($configuration['declaracao_transporte_data'] ?? null),
                    'validUntil' => null,
                    'path' => $memberDocumentPayload['declaracao_transporte'],
                ],
                'afiliacao' => [
                    'isValidated' => (bool) $memberDocumentPayload['afiliacao'],
                    'validatedAt' => $memberDocumentPayload['data_afiliacao'],
                    'validUntil' => null,
                    'path' => $memberDocumentPayload['arquivo_afiliacao'],
                ],
            ],
        ];
    }

    /**
     * @return array{
     *   rgpd: array{is_validated:bool,validated_at:mixed},
     *   consentimento: array{is_validated:bool,validated_at:mixed},
     *   declaracao_transporte: array{is_validated:bool,validated_at:mixed},
     *   atestado: array{is_validated:bool,validated_at:mixed,valid_until:mixed},
     *   federacao: array{is_validated:bool,validated_at:mixed,numero:?string,cartao_path:?string},
     *   afiliacao: array{is_validated:bool,validated_at:mixed,numero:?string,path:?string}
     * }
     */
    public function profileDocuments(User $user): array
    {
        $configuration = $this->memberDataReadService->configurationPayload($user);
        $sports = $this->memberDataReadService->sportsPayload($user);
        $payload = $this->memberDocumentPayload($user);
        $federationNumber = $this->normalizeLegacyString($sports['num_federacao'] ?? null)
            ?: $this->normalizeLegacyString($configuration['afiliacao_numero'] ?? null)
            ?: $this->normalizeLegacyString($this->legacyAttribute($user, 'num_federacao'));

        $federationIsValidated = (bool) $payload['afiliacao']
            || filled($payload['cartao_federacao'])
            || filled($federationNumber);

        return [
            'rgpd' => [
                'is_validated' => (bool) $payload['rgpd'],
                'validated_at' => $payload['data_rgpd'],
            ],
            'consentimento' => [
                'is_validated' => (bool) ($payload['consentimento'] || $payload['declaracao_de_transporte']),
                'validated_at' => $payload['data_consentimento'],
            ],
            'declaracao_transporte' => [
                'is_validated' => (bool) $payload['declaracao_de_transporte'],
                'validated_at' => $payload['data_consentimento'] ?? ($configuration['declaracao_transporte_data'] ?? null),
            ],
            'atestado' => [
                'is_validated' => filled($payload['data_atestado_medico']),
                'validated_at' => $payload['data_atestado_medico'],
                'valid_until' => $payload['data_atestado_medico'],
            ],
            'federacao' => [
                'is_validated' => $federationIsValidated,
                'validated_at' => $payload['data_afiliacao'],
                'numero' => $federationNumber,
                'cartao_path' => $payload['cartao_federacao'],
            ],
            'afiliacao' => [
                'is_validated' => (bool) $payload['afiliacao'],
                'validated_at' => $payload['data_afiliacao'],
                'numero' => $federationNumber,
                'path' => $payload['arquivo_afiliacao'],
            ],
        ];
    }

    /**
     * @return array{num_federacao:?string,data_atestado_medico:?string,informacoes_medicas:?string}
     */
    public function sportsPayload(User $user): array
    {
        $sports = $this->memberDataReadService->sportsPayload($user);
        $configuration = $this->memberDataReadService->configurationPayload($user);
        $configurationExtra = is_array($configuration['configuracao_extra'] ?? null)
            ? $configuration['configuracao_extra']
            : [];

        return [
            'num_federacao' => $this->normalizeLegacyString($sports['num_federacao'] ?? null)
                ?: $this->normalizeLegacyString($configuration['afiliacao_numero'] ?? null)
                ?: $this->normalizeLegacyString($this->legacyAttribute($user, 'num_federacao')),
            'data_atestado_medico' => $this->normalizeLegacyDate($sports['data_atestado_medico'] ?? null)
                ?: $this->normalizeLegacyDate($this->legacyAttribute($user, 'data_atestado_medico')),
            'informacoes_medicas' => $this->normalizeLegacyString($sports['informacoes_medicas'] ?? null)
                ?: $this->normalizeLegacyString($configurationExtra['informacoes_medicas'] ?? null)
                ?: $this->normalizeLegacyString($this->legacyAttribute($user, 'informacoes_medicas')),
        ];
    }

    /**
     * @return array{
     *   rgpd:bool,
     *   data_rgpd:mixed,
     *   arquivo_rgpd:?string,
     *   consentimento:bool,
     *   data_consentimento:mixed,
     *   arquivo_consentimento:?string,
     *   declaracao_de_transporte:bool,
     *   declaracao_transporte:?string,
     *   afiliacao:bool,
     *   data_afiliacao:mixed,
     *   arquivo_afiliacao:?string,
     *   cartao_federacao:?string,
     *   data_atestado_medico:?string
     * }
     */
    public function memberDocumentPayload(User $user): array
    {
        $configuration = $this->memberDataReadService->configurationPayload($user);
        $sports = $this->memberDataReadService->sportsPayload($user);
        $documentPaths = $this->documentPaths($user);

        return [
            'rgpd' => (bool) ($configuration['consentimento_rgpd'] ?? false),
            'data_rgpd' => $configuration['consentimento_rgpd_data'] ?? null,
            'arquivo_rgpd' => $documentPaths['rgpd'],
            'consentimento' => (bool) ($configuration['consentimento_imagem'] ?? false),
            'data_consentimento' => $configuration['consentimento_imagem_data'] ?? null,
            'arquivo_consentimento' => $documentPaths['consentimento'],
            'declaracao_de_transporte' => (bool) ($configuration['declaracao_transporte'] ?? false),
            'declaracao_transporte' => $documentPaths['declaracao_transporte'],
            'afiliacao' => (bool) ($configuration['afiliacao_federativa'] ?? false),
            'data_afiliacao' => $configuration['afiliacao_data'] ?? null,
            'arquivo_afiliacao' => $documentPaths['afiliacao'],
            'cartao_federacao' => $this->normalizeLegacyPath($sports['cartao_federacao'] ?? null)
                ?: $documentPaths['cartao_federacao'],
            'data_atestado_medico' => $this->normalizeLegacyDate($sports['data_atestado_medico'] ?? null),
        ];
    }

    /**
     * @return array{rgpd:?string,consentimento:?string,atestado:?string,cartao_federacao:?string,declaracao_transporte:?string,afiliacao:?string}
     */
    public function documentPaths(User $user): array
    {
        $configuration = $this->memberDataReadService->configurationPayload($user);
        $sports = $this->memberDataReadService->sportsPayload($user);

        return [
            'rgpd' => $this->normalizeLegacyPath($this->legacyAttribute($user, 'arquivo_rgpd')),
            'consentimento' => $this->normalizeLegacyPath($this->legacyAttribute($user, 'arquivo_consentimento'))
                ?: $this->normalizeLegacyPath($configuration['declaracao_transporte_ficheiro'] ?? null)
                ?: $this->normalizeLegacyPath($this->legacyAttribute($user, 'declaracao_transporte')),
            'atestado' => $this->normalizeLegacyPath($sports['arquivo_atestado_medico'] ?? null)
                ?: $this->normalizeLegacyPath($configuration['certificado_medico_ficheiro'] ?? null)
                ?: $this->normalizeLegacyPath($this->legacyAttribute($user, 'arquivo_atestado_medico')),
            'cartao_federacao' => $this->normalizeLegacyPath($sports['cartao_federacao'] ?? null)
                ?: $this->normalizeLegacyPath($this->legacyAttribute($user, 'cartao_federacao'))
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

    private function normalizeLegacyDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function normalizeLegacyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }
}
