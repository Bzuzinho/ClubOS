<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    use HasUuids;

    protected $fillable = [
        'email_notificacoes',
        'alertas_aplicacao',
        'alertas_pagamento',
        'alertas_atividade',
        'automacoes_financeiro',
        'automacoes_eventos',
        'automacoes_logistica',
        'automacoes_faturas_financeiras',
        'automacoes_movimentos_financeiros',
        'automacoes_convocatorias_eventos',
        'automacoes_requisicoes_logistica',
        'automacoes_alertas_operacionais',
    ];

    protected $casts = [
        'email_notificacoes' => 'boolean',
        'alertas_aplicacao' => 'boolean',
        'alertas_pagamento' => 'boolean',
        'alertas_atividade' => 'boolean',
        'automacoes_financeiro' => 'boolean',
        'automacoes_eventos' => 'boolean',
        'automacoes_logistica' => 'boolean',
        'automacoes_faturas_financeiras' => 'boolean',
        'automacoes_movimentos_financeiros' => 'boolean',
        'automacoes_convocatorias_eventos' => 'boolean',
        'automacoes_requisicoes_logistica' => 'boolean',
        'automacoes_alertas_operacionais' => 'boolean',
    ];
}
