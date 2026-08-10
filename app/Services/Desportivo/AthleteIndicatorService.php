<?php

namespace App\Services\Desportivo;

use App\Models\AthleteIndicatorDefinition;
use App\Models\AthleteIndicatorRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AthleteIndicatorService
{
    private const DATA_TYPES = ['number', 'text', 'boolean', 'date', 'time', 'json'];

    public function __construct(
        private readonly SportsClubContext $clubContext,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createDefinition(array $data, ?User $actor = null): AthleteIndicatorDefinition
    {
        $clubId = $this->clubContext->id();
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $dataType = (string) ($data['data_type'] ?? 'number');

        $this->validateDefinition($code, $name, $dataType);

        if (AthleteIndicatorDefinition::withTrashed()
            ->where('club_id', $clubId)
            ->where('code', $code)
            ->exists()) {
            throw ValidationException::withMessages([
                'code' => 'Já existe um indicador com este código neste clube.',
            ]);
        }

        return AthleteIndicatorDefinition::query()->create([
            'club_id' => $clubId,
            'code' => $code,
            'name' => $name,
            'description' => $data['description'] ?? null,
            'data_type' => $dataType,
            'unit' => $data['unit'] ?? null,
            'category' => $data['category'] ?? null,
            'version' => 1,
            'active' => $data['active'] ?? true,
            'shareable_by_default' => $data['shareable_by_default'] ?? false,
            'sort_order' => $data['sort_order'] ?? 0,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updateDefinition(
        AthleteIndicatorDefinition $definition,
        array $data,
        ?User $actor = null,
    ): AthleteIndicatorDefinition {
        $dataType = (string) ($data['data_type'] ?? $definition->data_type);
        $name = trim((string) ($data['name'] ?? $definition->name));
        $code = trim((string) ($data['code'] ?? $definition->code));
        $this->validateDefinition($code, $name, $dataType);

        if ($code !== $definition->code
            && AthleteIndicatorDefinition::withTrashed()
                ->where('club_id', $definition->club_id)
                ->where('code', $code)
                ->where('id', '<>', $definition->id)
                ->exists()) {
            throw ValidationException::withMessages([
                'code' => 'Já existe um indicador com este código neste clube.',
            ]);
        }

        $definition->fill([
            'code' => $code,
            'name' => $name,
            'description' => $data['description'] ?? $definition->description,
            'data_type' => $dataType,
            'unit' => array_key_exists('unit', $data) ? $data['unit'] : $definition->unit,
            'category' => array_key_exists('category', $data) ? $data['category'] : $definition->category,
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : $definition->active,
            'shareable_by_default' => array_key_exists('shareable_by_default', $data)
                ? (bool) $data['shareable_by_default']
                : $definition->shareable_by_default,
            'sort_order' => $data['sort_order'] ?? $definition->sort_order,
            'updated_by' => $actor?->id,
        ]);

        if ($definition->isDirty(['code', 'name', 'data_type', 'unit', 'category'])) {
            $definition->version = ((int) $definition->version) + 1;
        }

        $definition->save();

        return $definition->fresh();
    }

    public function archiveDefinition(AthleteIndicatorDefinition $definition, ?User $actor = null): void
    {
        $definition->forceFill([
            'active' => false,
            'updated_by' => $actor?->id,
        ])->save();
        $definition->delete();
    }

    public function record(
        AthleteIndicatorDefinition $definition,
        User $athlete,
        mixed $value,
        mixed $recordedAt = null,
        ?string $notes = null,
        ?bool $shareable = null,
        ?User $actor = null,
    ): AthleteIndicatorRecord {
        if ($definition->trashed() || ! $definition->active) {
            throw ValidationException::withMessages([
                'indicator_definition_id' => 'O indicador está inativo ou arquivado.',
            ]);
        }

        if ((string) $definition->club_id !== $this->clubContext->id()) {
            throw ValidationException::withMessages([
                'indicator_definition_id' => 'O indicador não pertence ao clube ativo.',
            ]);
        }

        $valueColumns = $this->normalizeValue((string) $definition->data_type, $value);

        return DB::transaction(function () use ($definition, $athlete, $recordedAt, $notes, $shareable, $actor, $valueColumns): AthleteIndicatorRecord {
            return AthleteIndicatorRecord::query()->create(array_merge([
                'club_id' => $definition->club_id,
                'indicator_definition_id' => $definition->id,
                'user_id' => $athlete->id,
                'definition_version' => $definition->version,
                'indicator_code' => $definition->code,
                'indicator_name' => $definition->name,
                'indicator_unit' => $definition->unit,
                'indicator_category' => $definition->category,
                'data_type' => $definition->data_type,
                'recorded_at' => $recordedAt === null ? now() : CarbonImmutable::parse($recordedAt),
                'notes' => $notes,
                'shareable' => $shareable ?? (bool) $definition->shareable_by_default,
                'recorded_by' => $actor?->id,
            ], $valueColumns));
        });
    }

    private function validateDefinition(string $code, string $name, string $dataType): void
    {
        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'O código do indicador é obrigatório.']);
        }

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'O nome do indicador é obrigatório.']);
        }

        if (! in_array($dataType, self::DATA_TYPES, true)) {
            throw ValidationException::withMessages(['data_type' => 'Tipo de dado de indicador inválido.']);
        }
    }

    /** @return array<string, mixed> */
    private function normalizeValue(string $dataType, mixed $value): array
    {
        return match ($dataType) {
            'number' => $this->numericValue($value),
            'text' => ['value_text' => (string) $value],
            'boolean' => ['value_boolean' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value],
            'date' => ['value_date' => CarbonImmutable::parse($value)->toDateString()],
            'time' => $this->timeValue($value),
            'json' => ['value_json' => is_array($value) ? $value : ['value' => $value]],
            default => throw ValidationException::withMessages(['value' => 'Tipo de valor não suportado.']),
        };
    }

    /** @return array{value_numeric: float|int|string} */
    private function numericValue(mixed $value): array
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages(['value' => 'O valor do indicador tem de ser numérico.']);
        }

        return ['value_numeric' => $value];
    }

    /** @return array{value_milliseconds:int} */
    private function timeValue(mixed $value): array
    {
        if (! is_numeric($value) || (int) $value < 0) {
            throw ValidationException::withMessages([
                'value' => 'O tempo deve ser fornecido em milissegundos e não pode ser negativo.',
            ]);
        }

        return ['value_milliseconds' => (int) $value];
    }
}
