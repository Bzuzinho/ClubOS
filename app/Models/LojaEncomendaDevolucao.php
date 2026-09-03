<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LojaEncomendaDevolucao extends Model
{
    use HasUuids;

    public const ESTADO_SOLICITADA = 'solicitada';
    public const ESTADO_AGUARDA_NOTA_CREDITO = 'aguarda_nota_credito';
    public const ESTADO_CONCLUIDA = 'concluida';

    protected $table = 'loja_encomenda_devolucoes';

    protected $fillable = [
        'loja_encomenda_id',
        'fatura_id',
        'fiscal_document_request_id',
        'estado',
        'motivo',
        'solicitada_por',
        'solicitada_em',
        'reversao_financeira_por',
        'reversao_financeira_em',
        'stock_reposto_por',
        'stock_reposto_em',
        'concluida_por',
        'concluida_em',
    ];

    protected $casts = [
        'solicitada_em' => 'datetime',
        'reversao_financeira_em' => 'datetime',
        'stock_reposto_em' => 'datetime',
        'concluida_em' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(LojaEncomenda::class, 'loja_encomenda_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'fatura_id');
    }

    public function fiscalDocumentRequest(): BelongsTo
    {
        return $this->belongsTo(FiscalDocumentRequest::class, 'fiscal_document_request_id');
    }
}
