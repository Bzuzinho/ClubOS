<?php

declare(strict_types=1);

return [
    'version' => 'M4.16',
    'fields' => [
        'data_atestado_medico' => [
            'legacy_field' => 'data_atestado_medico',
            'target_area' => 'athlete_sports_data',
            'target_field' => 'data_atestado_medico',
            'target_status' => 'architecture_decision_required',
            'decision' => 'route_to_sports_domain',
            'write_allowed' => false,
            'requires_migration' => false,
            'requires_domain_mapping' => true,
            'owner_area' => 'desportivo',
            'reason' => 'A data do atestado medico pertence ao dominio desportivo e deve ser reconciliada com athlete_sports_data antes de qualquer backfill.',
            'next_action' => 'Auditar athlete_sports_data por atleta/user e definir chave de correspondencia segura.',
        ],
        'estado_civil' => [
            'legacy_field' => 'estado_civil',
            'target_area' => 'dados_pessoais',
            'target_field' => 'estado_civil',
            'target_status' => 'canonical_payload_key_defined',
            'decision' => 'add_to_personal_payload_contract',
            'write_allowed' => false,
            'requires_migration' => false,
            'requires_domain_mapping' => false,
            'owner_area' => 'membros',
            'reason' => 'Contrato canonico de dados_pessoais atualizado para reconhecer estado_civil, mantendo fase read-only.',
            'next_action' => 'Validar backfill controlado apenas de estado_civil numa sprint propria, sem ativar escrita automatica nesta fase.',
        ],
        'numero_irmaos' => [
            'legacy_field' => 'numero_irmaos',
            'target_area' => 'dados_pessoais',
            'target_field' => 'numero_irmaos',
            'target_status' => 'canonical_payload_key_pending',
            'decision' => 'add_to_personal_payload_contract_or_discard_as_historical',
            'write_allowed' => false,
            'requires_migration' => false,
            'requires_domain_mapping' => false,
            'owner_area' => 'membros',
            'reason' => 'Campo pessoal/familiar com apenas 2 valores legacy_only; precisa decisao se deve ser preservado ou tratado como historico.',
            'next_action' => 'Validar utilidade funcional antes de criar contrato canonico.',
        ],
    ],
];