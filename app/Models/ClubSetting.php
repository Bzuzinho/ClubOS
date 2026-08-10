<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubSetting extends Model
{
    protected $fillable = [
        'nome_clube',
        'sigla',
        'morada',
        'codigo_postal',
        'localidade',
        'telefone',
        'email',
        'website',
        'nif',
        'logo_url',
        'horario_funcionamento',
        'redes_sociais',
        'iban',
        'monthly_fee_generation_enabled',
        'monthly_fee_start_month',
        'monthly_fee_end_month',
        'monthly_fee_due_day',
        'monthly_fee_hide_future',
        'monthly_fee_auto_activate_due',
        'monthly_fee_respect_registration_date',
        'monthly_fee_generate_months_ahead',
        'monthly_fee_default_period_mode',
        'sports_lane_overlap_policy',
        'sports_athlete_overlap_policy',
        'sports_capacity_policy',
    ];

    protected $casts = [
        'horario_funcionamento' => 'array',
        'redes_sociais' => 'array',
        'monthly_fee_generation_enabled' => 'boolean',
        'monthly_fee_start_month' => 'integer',
        'monthly_fee_end_month' => 'integer',
        'monthly_fee_due_day' => 'integer',
        'monthly_fee_hide_future' => 'boolean',
        'monthly_fee_auto_activate_due' => 'boolean',
        'monthly_fee_respect_registration_date' => 'boolean',
        'monthly_fee_generate_months_ahead' => 'integer',
    ];
}
