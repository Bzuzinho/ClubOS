# Mapeamento de Normalização - Português

Este documento mapeia todas as mudanças de nomenclatura de Inglês → Português.

**STATUS**: ✅ **COMPLETO** - Todas as mudanças implementadas

---

## Controllers

| Actual (Inglês) | Normalizado (Português) | Status |
|-----------------|-------------------------|--------|
| MembersController | MembrosController | ✅ COMPLETO |
| MemberDocumentController | DocumentosMembrosController | ✅ COMPLETO |
| MemberRelationshipController | RelacoesMembroController | ✅ COMPLETO |
| EventsController | EventosController | ✅ COMPLETO |
| SportsController | DesportivoController | ✅ COMPLETO |
| FinancialController | FinanceiroController | ✅ COMPLETO |
| TransactionController | TransacoesController | ✅ COMPLETO |
| MembershipFeeController | TaxasController | ✅ COMPLETO |
| FinancialCategoryController | CategoriasFinanceirasController | ✅ COMPLETO |
| FinancialReportController | RelatoriosFinanceirosController | ✅ COMPLETO |
| ShopController | LojaController | ✅ COMPLETO |
| SponsorshipsController | PatrocinosController | ✅ COMPLETO |
| ComunicacaoController | ComunicacaoController | ✅ JÁ ESTAVA FEITO |
| MarketingCampaignController | CampanhasMarketingController | ✅ COMPLETO |
| TeamController | EquipasController | ✅ COMPLETO |
| TeamMemberController | MembrosEquipaController | ✅ COMPLETO |
| TrainingSessionController | SessoesFormacaoController | ✅ COMPLETO |
| CallUpController | ConvocatoriasController | ✅ COMPLETO |
| SettingsController | ConfiguracoesController | ✅ COMPLETO |

## Routes

| Actual | Normalizado | Status |
|--------|-------------|--------|
| /members | /membros | ✅ COMPLETO (+ Redirect 301) |
| /members/{id}/documents | /membros/{id}/documentos | ✅ COMPLETO |
| /members/{id}/relationships | /membros/{id}/relacoes | ✅ COMPLETO |
| /events | /eventos | ✅ COMPLETO (+ Redirect 301) |
| /sports | /desportivo | ✅ COMPLETO (+ Redirect 301) |
| /financial | /financeiro | ✅ COMPLETO (+ Redirect 301) |
| /financial/transactions | /financeiro/transacoes | ✅ COMPLETO |
| /financial/membership-fees | /financeiro/taxas | ✅ COMPLETO |
| /financial/categories | /financeiro/categorias | ✅ COMPLETO |
| /financial/reports | /financeiro/relatorios | ✅ COMPLETO |
| /shop | /loja | ✅ COMPLETO (+ Redirect 301) |
| /sponsorships | /patrocinios | ✅ COMPLETO (+ Redirect 301) |
| /communication | /comunicacao | ✅ COMPLETO (+ Redirect 301) |
| /marketing | /campanhas-marketing | ✅ COMPLETO (+ Redirect 301) |
| /teams | /equipas | ✅ COMPLETO (+ Redirect 301) |
| /team-members | /membros-equipa | ✅ COMPLETO (+ Redirect 301) |
| /training-sessions | /sessoes-formacao | ✅ COMPLETO (+ Redirect 301) |
| /call-ups | /convocatorias | ✅ COMPLETO (+ Redirect 301) |
| /settings | /configuracoes | ✅ COMPLETO (+ Redirect 301) |

## Pages (Inertia)

| Actual | Normalizado | Status |
|--------|-------------|--------|
| Pages/Members/ | Pages/Membros/ | ✅ COMPLETO |
| Pages/Events/ | Pages/Eventos/ | ✅ COMPLETO |
| Pages/Sports/ | Pages/Desportivo/ | ✅ COMPLETO |
| Pages/Financial/ | Pages/Financeiro/ | ✅ COMPLETO |
| Pages/Shop/ | Pages/Loja/ | ✅ COMPLETO |
| Pages/Sponsorships/ | Pages/Patrocinios/ | ✅ COMPLETO |
| Pages/Communication/ | Pages/Comunicacao/ | ✅ COMPLETO |
| Pages/Marketing/ | Pages/CampanhasMarketing/ | ✅ COMPLETO |
| Pages/Settings/ | Pages/Configuracoes/ | ✅ COMPLETO |

## Models - Campos Duplicados (User)

⚠️ **CRÍTICO**: Model User tinha campos em INGLÊS e PORTUGUÊS simultaneamente - CORRIGIDO!

| Campo Inglês | Campo Português | Ação Tomada |
|--------------|-----------------|-------------|
| member_type | tipo_membro | ✅ REMOVIDO inglês, mantido português |
| member_number | numero_socio | ✅ REMOVIDO inglês |
| full_name | nome_completo | ✅ REMOVIDO inglês |
| profile | perfil | ✅ REMOVIDO inglês |
| status | estado | ✅ REMOVIDO inglês |
| birth_date | data_nascimento | ✅ REMOVIDO inglês |
| is_minor | menor | ✅ REMOVIDO inglês |
| gender | sexo | ✅ REMOVIDO inglês |
| age_groups | escalao | ✅ REMOVIDO inglês |
| gdpr_consent | rgpd | ✅ REMOVIDO inglês |
| consent | consentimento | ✅ REMOVIDO inglês |
| affiliation | afiliacao | ✅ REMOVIDO inglês |
| transport_declaration | declaracao_de_transporte | ✅ REMOVIDO inglês |
| sports_active | ativo_desportivo | ✅ REMOVIDO inglês |
| address | morada | ✅ REMOVIDO inglês |
| postal_code | codigo_postal | ✅ REMOVIDO inglês |
| city | localidade | ✅ REMOVIDO inglês |
| phone | contacto | ✅ REMOVIDO inglês |
| mobile | telemovel | ✅ REMOVIDO inglês |

**Total de campos duplicados removidos**: 60+

## API Controllers

| Actual | Normalizado | Status |
|--------|-------------|--------|
| Api/UserTypeController | Api/TiposUtilizadorController | ✅ COMPLETO |
| Api/AgeGroupController | Api/EscaloesController | ✅ COMPLETO |
| Api/CostCenterController | Api/CentrosCustoController | ✅ COMPLETO |
| Api/EventTypeController | Api/TiposEventoController | ✅ COMPLETO |

## Route Names (utilizados em route() helper)

| Actual | Normalizado | Status |
|--------|-------------|--------|
| members.* | membros.* | ✅ COMPLETO |
| events.* | eventos.* | ✅ COMPLETO |
| sports.* | desportivo.* | ✅ COMPLETO |
| financial.* | financeiro.* | ✅ COMPLETO |
| shop.* | loja.* | ✅ COMPLETO |
| sponsorships.* | patrocinios.* | ✅ COMPLETO |
| communication.* | comunicacao.* | ✅ COMPLETO |
| marketing.* | campanhas-marketing.* | ✅ COMPLETO |
| teams.* | equipas.* | ✅ COMPLETO |
| call-ups.* | convocatorias.* | ✅ COMPLETO |
| settings.* | configuracoes.* | ✅ COMPLETO |

## Frontend Components

| Componente | Ação | Status |
|------------|------|--------|
| Sidebar.tsx | Menu em português + URLs atualizados | ✅ COMPLETO |
| Membros/*.tsx | Route names atualizados | ✅ COMPLETO |
| Eventos/*.tsx | Route names atualizados | ✅ COMPLETO |
| Comunicacao/*.tsx | Route names atualizados | ✅ COMPLETO |
| Configuracoes/*.tsx | Route names atualizados | ✅ COMPLETO |

---

## Notas Importantes

### Breaking Changes Implementados ✅
- URLs mudaram (ex: `/members` → `/membros`)
- Route names mudaram em todo código frontend
- Campos User agora apenas em português

### Mitigação Implementada ✅
- **Redirects 301**: Adicionados para todas as rotas antigas → novas
- **Documentação completa**: 3 documentos criados
- **Backward compatibility**: Links antigos funcionam via redirect

### Ordem de Implementação ✅
1. ✅ Criar documentos de mapeamento (este ficheiro)
2. ✅ Renomear Controllers + atualizar namespaces
3. ✅ Atualizar routes/web.php
4. ✅ Renomear diretórios Pages/
5. ✅ Limpar campos duplicados do User model
6. ✅ Atualizar todas referências route() no código frontend
7. ✅ Adicionar redirects para retrocompatibilidade
8. ✅ Criar documento de conclusão (PORTUGUESE_NORMALIZATION_COMPLETE.md)

---

## 📊 Estatísticas Finais

- **Controllers renomeados**: 22 ficheiros
- **Rotas atualizadas**: 30+ rotas
- **Pages renomeadas**: 9 diretórios
- **Campos User eliminados**: 60+ duplicados
- **Ficheiros frontend atualizados**: 6+ .tsx
- **Redirects 301 adicionados**: 13 redirects
- **Commits realizados**: 6 commits incrementais
- **Documentos criados**: 3 documentos completos

---

## ✅ STATUS FINAL: COMPLETO

**Data**: 2026-02-03  
**Branch**: copilot/normalize-naming-to-portuguese  
**Resultado**: ✅ Sucesso Total
