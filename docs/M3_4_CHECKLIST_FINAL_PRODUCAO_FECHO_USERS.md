# M3.4 — Checklist Final de Produção e Fecho users

## Objetivo
Declarar o fecho operacional da migração funcional de dados de membro para tabelas canónicas, mantendo users fisicamente intacta.

## Estado final esperado
- dados_pessoais é fonte primária para dados pessoais;
- dados_configuracao é fonte primária para configuração legal/RGPD/afiliação;
- users mantém autenticação, permissões, estado operacional, compatibilidade e fallback;
- colunas legacy em users não são removidas nesta fase.

## Commits finais relevantes
- 18ce253 Add legacy user write protection tests
- 7a64f77 Document M3.4 users migration closure decision
- 458f7b8 Use canonical user names in events payload
- aa4d705 Use canonical trainer names in teams
- 29ee446 Use canonical athlete birth date in API
- 2ea0d39 Limit member store legacy user writes
- c37c2c5 Limit member update legacy user writes
- 1095b85 Limit portal profile legacy user writes

## Checklist de produção
Registar:
- git pull --ff-only origin main executado em produção;
- git log confirmou commits finais em produção;
- php artisan members:audit-data-structure executado em produção;
- curl -IL http://127.0.0.1/membros validado.

## Auditoria final de produção
Registar os últimos valores conhecidos:
- total_users: 79
- users_with_dados_pessoais: 79
- users_with_dados_configuracao: 79
- missing_dados_pessoais: 0
- missing_dados_configuracao: 0
- conflicts_dados_pessoais: 0
- conflicts_dados_configuracao: 0
- possíveis duplicações: 0
- absent_source_fields: 11
- suspicious_values: 2

absent_source_fields e suspicious_values permanecem como sinais conhecidos e monitorizados, sem bloquear o fecho, porque missing, conflicts e duplicações estão limpos.

## Testes de proteção
Listar:
- MemberDataLegacyUserWriteProtectionTest
- MemberDataWriteCutoverTest
- MemberDataReadFallbackTest
- MemberDataReadVisualSafetyTest
- MemberDataAuditProtectionTest
- php artisan test --filter=Membros

## Decisão operacional
Declarar:
A migração funcional fica fechada.
A aplicação deve continuar a usar dados_pessoais/dados_configuracao como fonte canónica.
users não deve voltar a receber payload pessoal/configuração completo nos fluxos principais.
A remoção física de colunas legacy fica excluída desta fase.

## Pendências toleradas
Listar:
- Financeiro/Fiscal;
- importação de membros;
- API Users;
- matching histórico;
- leituras de display de baixo risco.

Estas pendências não impedem o fecho porque estão documentadas como fallback ou toleradas e exigem sprints próprias se forem atacadas.

## Próxima fase opcional
M4 — Legacy column retirement plan.

Objetivo futuro:
- adapter fiscal;
- importação;
- API Users;
- redução gradual de fallback;
- eventual remoção física de colunas;
- plano de rollback.

## Fecho
M2/M3/M3.4 ficam fechadas.
A base funcional da migração está estabilizada, testada e validada em produção.