# M3.4 — Decisão Técnica de Fecho da Migração users

## Objetivo
Explicar que este documento fecha a fase M3/M3.4 da migração funcional de dados de membro para tabelas canónicas, sem remoção física de colunas em users.

## Decisão principal
Declaração explícita da decisão técnica atual:
- users deixa de ser fonte primária para dados pessoais/configuração nos fluxos tratados;
- dados_pessoais passa a ser fonte primária para dados pessoais;
- dados_configuracao passa a ser fonte primária para configuração legal/RGPD/afiliação;
- users mantém papel de auth, permissões, estado operacional, compatibilidade e fallback.

## Estado fechado por sprint

### M2
Resumo:
- criação/uso das estruturas dados_pessoais e dados_configuracao;
- backfill controlado;
- auditoria dry-run;
- proteção contra escrita acidental;
- produção validada com missing/conflicts a zero.

### M3.1
Resumo:
- leituras canónicas no módulo Membros;
- index/show/selectors/family context passaram a preferir dados canónicos;
- fallback mantido.

### M3.2
Resumo:
- PortalProfileController@update;
- MembrosController@store;
- MembrosController@update;
- deixaram de gravar payload funcional completo em users;
- MemberDataWriteService mantém persistência canónica.

### M3.3
Resumo:
- mapa cross-module criado;
- AthleteController usa data_nascimento canónica;
- EquipasController usa nomes canónicos de treinadores;
- EventosController usa nomes canónicos na lista de utilizadores;
- dependências de maior risco ficaram documentadas.

### M3.4-F1
Resumo:
- testes de proteção adicionados;
- MemberDataLegacyUserWriteProtectionTest garante que store/update/portal profile não voltam a espelhar payload pessoal completo para users.

## Contrato final atual

### Tabelas canónicas

#### dados_pessoais
- nome_completo
- data_nascimento
- sexo
- nif
- cc
- documento_identificacao
- morada
- codigo_postal
- localidade
- nacionalidade
- contacto
- contacto_alternativo
- email_secundario
- observacoes, quando aplicável

#### dados_configuracao
- rgpd
- consentimento
- declaracao_de_transporte
- afiliacao
- num_federacao / afiliacao_numero, conforme contrato atual
- datas e ficheiros associados à configuração legal

### users
users mantém:
- name
- email
- email_utilizador
- password
- perfil
- estado
- numero_socio
- tipo_membro
- escalao
- ativo_desportivo
- menor
- foto_perfil
- campos operacionais ainda não migrados
- colunas legacy apenas como fallback/compatibilidade temporária

## O que não foi feito
Declaração expressa do âmbito não coberto nesta fase:
- não foram removidas colunas de users;
- não foi removido fallback;
- não foi feita refatoração profunda de Financeiro/Fiscal;
- não foi reescrita a importação de membros;
- não foi alterada a API Users store/update sem testes dedicados;
- não foi alterada a emissão fiscal.

## Dependências toleradas
- Financeiro pode continuar a ler alguns dados fiscais em users até existir adapter fiscal próprio;
- FiscalDocumentRequestService/FiscalEmissionQueueService exigem sprint própria;
- MemberImportService exige plano próprio por criar membros em massa;
- API Users precisa de testes dedicados antes de corte;
- Dashboard/Configurações podem manter leituras de display de baixo risco enquanto houver fallback.

## Regras para trabalho futuro
1. Não voltar a gravar payload pessoal completo em users.
2. Não usar User::create($data) com payload completo de membro.
3. Não usar $user->update($data) com payload completo de membro.
4. Preferir MemberDataReadService para leitura agregada quando o contexto for Membros/Portal.
5. Preferir dadosPessoais/dadosConfiguracao para relações simples.
6. Manter fallback até remoção física ser planeada.
7. Não remover colunas sem sprint M4 própria, testes e plano de rollback.
8. Qualquer alteração em Financeiro/Fiscal deve ter testes dedicados.

## Testes de proteção
Registo da cobertura relevante para evitar regressões:
- MemberDataLegacyUserWriteProtectionTest;
- MemberDataWriteCutoverTest;
- MemberDataReadFallbackTest;
- MemberDataReadVisualSafetyTest;
- MemberDataAuditProtectionTest;
- php artisan test --filter=Membros.

## Critério de conclusão
A migração funcional fica considerada concluída quando:
- os fluxos principais usam fontes canónicas;
- users já não recebe payload completo nos fluxos principais;
- fallback continua disponível;
- testes de proteção existem;
- riscos remanescentes estão documentados;
- produção mantém auditoria limpa.

## Produção
Último estado conhecido:
- total_users: 79;
- users_with_dados_pessoais: 79;
- users_with_dados_configuracao: 79;
- missing_dados_pessoais: 0;
- missing_dados_configuracao: 0;
- conflicts_dados_pessoais: 0;
- conflicts_dados_configuracao: 0;
- possíveis duplicações: 0.

## Próxima fase opcional — M4
Uma futura M4 poderá tratar:
- adapter fiscal;
- importação de membros;
- API Users;
- remoção gradual de fallback;
- eventual remoção física de colunas legacy;
- plano de rollback;
- auditoria pós-produção prolongada.

## Decisão final
A fase M3/M3.4 fecha a migração funcional de dependência de users.
A tabela users permanece fisicamente intacta por segurança.
A aplicação passa a tratar dados_pessoais e dados_configuracao como fontes canónicas nos fluxos principais.
