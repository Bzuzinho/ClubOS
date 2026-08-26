<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class CompetitionRegistration extends Model
{
    use HasUuids;

    protected $table = 'competition_registrations';

    protected $fillable = [
        'prova_id',
        'user_id',
        'estado',
        'valor_inscricao',
        // Legacy ingestion/fixtures only. F7 finance runtime never writes it.
        'fatura_id',
        'movimento_id',
    ];

    protected $casts = [
        'valor_inscricao' => 'decimal:2',
    ];

    public function prova(): BelongsTo
    {
        return $this->belongsTo(Prova::class, 'prova_id');
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Read-only compatibility alias. Canonical ownership is the aggregate
     * CompetitionFinancialObligation for athlete + competition.
     */
    public function getFaturaIdAttribute(mixed $legacyValue): ?string
    {
        if (Schema::hasTable('competition_financial_obligations') && filled($this->prova_id) && filled($this->user_id)) {
            $competitionId = $this->relationLoaded('prova')
                ? $this->prova?->competicao_id
                : Prova::query()->whereKey($this->prova_id)->value('competicao_id');

            if (filled($competitionId)) {
                $canonical = CompetitionFinancialObligation::query()
                    ->where('competition_id', $competitionId)
                    ->where('user_id', $this->user_id)
                    ->value('invoice_id');

                if (filled($canonical)) {
                    return (string) $canonical;
                }
            }
        }

        return filled($legacyValue) ? (string) $legacyValue : null;
    }

    /** @deprecated Read-only compatibility relation; use financial obligation. */
    public function fatura(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'fatura_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->fatura();
    }

    public function atleta(): BelongsTo
    {
        return $this->athlete();
    }
}
