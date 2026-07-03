# MEMBERS LEGACY CLEANUP M5

## Objetivo
Preparar com segurança o cleanup físico futuro de campos legacy de membros em users sem executar remoções destrutivas nesta sprint.

## Resumo da M4 fechada
- M4 está funcionalmente fechada para migração de dados pessoais/configuração de membros.
- 79/79 users com dados_pessoais e 79/79 users com dados_configuracao.
- missing_dados_pessoais = 0.
- missing_dados_configuracao = 0.
- conflitos = 0.
- legacy read audit findings_count = 0.
- legacy write guard violations_count = 0.
- fallback legacy personal/configuration = 0.
- estado_civil migrado 78/78.
- numero_irmaos migrado 2/2.
- data_atestado_medico migrado 19/24.

Referencias:
- Commit de fecho M4: 4f9fb28.
- Issue de isolamento de pendencias desportivas: #64.

## Pendencia isolada no dominio Desportivo
A pendencia de data_atestado_medico permanece isolada no dominio Desportivo (issue #64) e nao faz parte desta sprint M5 de readiness para cleanup de colunas legacy em users.

Classificacao atual dos 5 casos pendentes:
- 3 casos: not_sports_member.
- 2 casos: invalid_legacy_date.

## Comando de auditoria M5
Comando criado:
- php artisan members:audit-legacy-cleanup-readiness

Opcoes:
- --json
- --fail-on-not-ready
- --field=estado_civil
- --field=numero_irmaos
- --field=declaracao_transporte

Criterios validados por campo:
- existe destino canonico;
- todos os valores legacy relevantes estao migrados;
- nao ha divergencias;
- nao ha leituras legacy proibidas;
- nao ha escritas legacy proibidas;
- campo classificado como ready_for_cleanup;
- plano de rollback documentado.

## Campos ready_for_cleanup (M5)
- estado_civil: ready_for_cleanup quando legacy e canonico estao em match, sem divergencias e sem violacoes de read/write guard.
- numero_irmaos: ready_for_cleanup quando legacy e canonico estao em match, sem divergencias e sem violacoes de read/write guard.

## Campos bloqueados (M5)
- declaracao_transporte: bloqueado por default ate auditoria confirmar legacy_values_migrated e ausencia de divergencias no dominio de configuracao.

## Campos needs_manual_review
- declaracao_transporte: quando houver non_scalar_review ou divergencia entre valor legacy e destino canonico em dados_configuracao.

## Plano de rollback
Sem executar remocoes fisicas nesta sprint, o rollback e operacional e documental:
1. Se qualquer campo deixar de ficar ready_for_cleanup, manter coluna legacy em users sem alteracoes destrutivas.
2. Reexecutar auditorias guard rail:
   - members:audit-users-legacy-read --fail-on-finding
   - members:audit-users-legacy-write-guard --json --fail-on-violation
   - members:audit-users-legacy-backfill-validation --json --field=<campo>
3. Reclassificar o campo para blocked ou needs_manual_review no ciclo de auditoria M5.
4. Qualquer proposta de DROP COLUMN exige nova sprint dedicada, com dry-run, plano de rollback tecnico e validacao em ambiente alvo.

## Criterios para futura remocao fisica de colunas
A remocao fisica de colunas legacy em users so pode ser considerada quando todos os itens abaixo estiverem satisfeitos por campo:
1. auditoria M5 marca ready_for_cleanup;
2. zero legacy_only_count;
3. zero divergent_count e zero non_scalar_review_count;
4. zero forbidden_legacy_read_count;
5. zero forbidden_legacy_write_count;
6. validacao operacional no ambiente alvo com evidencias de auditoria;
7. migration destrutiva planeada em sprint propria, com rollback especifico;
8. issue #64 mantida isolada e sem reintroduzir migracao automatica dos 5 casos pendentes de data_atestado_medico.

## Fora de escopo desta sprint
- nao remove colunas de users;
- nao refaz migracao de dados;
- nao altera logica funcional de membros;
- nao migra automaticamente os 5 casos pendentes de data_atestado_medico.

## Atualizacao M6 - remocao fisica conservadora executada
Escopo executado na M6:
- remocao fisica de `users.estado_civil`;
- remocao fisica de `users.numero_irmaos`.

Migration criada:
- `database/migrations/2026_07_03_130000_drop_estado_civil_numero_irmaos_from_users_table.php`.

Rollback:
- a migration `down` recria as colunas com os tipos originais (`string nullable` para `estado_civil`, `integer nullable` para `numero_irmaos`);
- o rollback recompõe apenas o schema e nao repoe automaticamente os valores legacy que ja foram migrados para `dados_pessoais`.
