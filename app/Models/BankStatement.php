<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BankStatement extends Model
{
    use HasUuids;

    public const DUPLICATE_MESSAGE = 'Ja existe uma linha de extrato com a mesma assinatura (conta, data, valor e referencia/descricao).';

    protected $table = 'bank_statements';

    protected $fillable = [
        'conta',
        'data_movimento',
        'descricao',
        'valor',
        'saldo',
        'referencia',
        'ficheiro_id',
        'centro_custo_id',
        'conciliado',
        'valor_conciliado',
        'valor_por_conciliar',
        'conciliacao_status',
        'suggestions_analyzed_at',
        'lancamento_id',
    ];

    protected $casts = [
        'data_movimento' => 'date',
        'valor' => 'decimal:2',
        'saldo' => 'decimal:2',
        'conciliado' => 'boolean',
        'valor_conciliado' => 'decimal:2',
        'valor_por_conciliar' => 'decimal:2',
        'suggestions_analyzed_at' => 'datetime',
    ];

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'centro_custo_id');
    }

    public function financialEntry(): BelongsTo
    {
        return $this->belongsTo(FinancialEntry::class, 'lancamento_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'bank_statement_id');
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(BankReconciliationSuggestion::class, 'bank_statement_id');
    }

    public function reconciliationMaps(): HasMany
    {
        return $this->hasMany(MapaConciliacao::class, 'extrato_id');
    }

    public function bankTransactionAllocations(): HasMany
    {
        return $this->hasMany(BankTransactionAllocation::class, 'bank_statement_id');
    }

    /**
     * @param  array<string, mixed>|self  $source
     * @return array{conta:string,data_movimento:string,valor:string,referencia:?string,descricao:string,signature:string}
     */
    public static function duplicateSignatureFrom(array|self $source): array
    {
        $conta = self::normalizeDuplicateText(data_get($source, 'conta')) ?? '';
        $dataMovimento = self::normalizeDuplicateDate(data_get($source, 'data_movimento'));
        $valor = number_format(round((float) data_get($source, 'valor', 0), 2), 2, '.', '');
        $referencia = self::normalizeDuplicateText(data_get($source, 'referencia'));
        $descricao = self::normalizeDuplicateText(data_get($source, 'descricao')) ?? '';
        $descriptor = $referencia !== null && $referencia !== ''
            ? 'ref:' . $referencia
            : 'desc:' . $descricao;

        return [
            'conta' => $conta,
            'data_movimento' => $dataMovimento,
            'valor' => $valor,
            'referencia' => $referencia,
            'descricao' => $descricao,
            'signature' => implode('|', [$conta, $dataMovimento, $valor, $descriptor]),
        ];
    }

    /**
     * @param  array<string, mixed>|self  $attributes
     */
    public static function findDuplicateFor(array|self $attributes, ?string $ignoreId = null): ?self
    {
        $signature = self::duplicateSignatureFrom($attributes);

        return self::query()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->whereDate('data_movimento', $signature['data_movimento'])
            ->where('valor', $signature['valor'])
            ->where(function ($query) use ($signature): void {
                if ($signature['conta'] === '') {
                    $query->where(function ($nestedQuery): void {
                        $nestedQuery->whereNull('conta')->orWhere('conta', '');
                    });

                    return;
                }

                $query->where('conta', $signature['conta']);
            })
            ->get()
            ->first(function (self $candidate) use ($signature): bool {
                return self::duplicateSignatureFrom($candidate)['signature'] === $signature['signature'];
            });
    }

    /**
     * @param  iterable<array<string, mixed>|self>  $items
     * @return Collection<int, string>
     */
    public static function collectDuplicateSignatures(iterable $items): Collection
    {
        return collect($items)
            ->map(fn (array|self $item) => self::duplicateSignatureFrom($item)['signature'])
            ->duplicates()
            ->values();
    }

    private static function normalizeDuplicateText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = (string) Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->trim();

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizeDuplicateDate(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        return Carbon::parse((string) $value)->toDateString();
    }
}
