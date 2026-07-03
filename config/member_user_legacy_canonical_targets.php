<?php

declare(strict_types=1);

return [
    'version' => 'M4.17',
    'fields' => [
        'data_atestado_medico' => [
            'legacy_field' => 'data_atestado_medico',
            'target_area' => 'athlete_sports_data',
            'target_field' => 'data_atestado_medico',
            'target_status' => 'canonical_domain_target_defined',
            'decision' => 'backfill_to_sports_domain',
            'write_allowed' => true,
            'requires_migration' => false,
            'requires_domain_mapping' => false,
            'owner_area' => 'desportivo',
            'reason' => 'Destino canonico definido em athlete_sports_data.data_atestado_medico com escrita permitida apenas em correspondencia segura e unica.',
            'next_action' => 'Executar backfill controlado com dry-run por defeito e commit confirmado, saltando casos sem alvo seguro.',
        ],
        'estado_civil' => [
            'legacy_field' => 'estado_civil',
            'target_area' => 'dados_pessoais',
            'target_field' => 'estado_civil',
            'target_status' => 'canonical_payload_key_defined',
            'decision' => 'backfill_to_personal_payload',
            'write_allowed' => true,
            'requires_migration' => false,
            'requires_domain_mapping' => false,
            'owner_area' => 'membros',
            'reason' => 'Campo canonical payload key definido em users.dados_pessoais[estado_civil], com preservacao integral do payload e sem tocar em users.estado_civil.',
            'next_action' => 'Executar backfill controlado preenchendo apenas destino vazio e reportando divergencias sem sobrescrever.',
        ],
        'numero_irmaos' => [
            'legacy_field' => 'numero_irmaos',
            'target_area' => 'dados_pessoais',
            'target_field' => 'numero_irmaos',
            'target_status' => 'canonical_payload_key_defined',
            'decision' => 'backfill_to_personal_payload',
            'write_allowed' => true,
            'requires_migration' => false,
            'requires_domain_mapping' => false,
            'owner_area' => 'membros',
            'reason' => 'Campo canonical payload key definido em users.dados_pessoais[numero_irmaos], com normalizacao numerica segura e sem alterar users.numero_irmaos.',
            'next_action' => 'Executar backfill controlado preenchendo apenas destino vazio e mantendo idempotencia na segunda execucao.',
        ],
    ],
];