# Desportivo 2 - TODO Próximos Passos

Data: 2026-03-11
Estado: plano operacional pós-refatoração

Atualização: 2026-03-12
- Concluído: toggle de mock por ambiente (`VITE_SPORTS_USE_MOCK`) no cliente de API.
- Concluído: fallback controlado por ambiente em erro de API (`VITE_SPORTS_FALLBACK_ON_ERROR`).
- Concluído: hardening de payloads no container e hooks do Desportivo2 para evitar crash com dados inválidos.

## Fase 1 - Estabilização (alta prioridade)

1. Validar fluxo Presenças E2E em staging
- Abrir por treino selecionado
- Alternar treinos simultâneos
- Testar Modo Cais (P/A/J/D)
- Confirmar batch update e marcar todos
- Confirmar comportamento sem treino selecionado

Critério de saída:
- Sem erros de UI/API no fluxo completo
- Sem regressão no módulo Desportivo clássico

2. Fechar fallbacks da tab Atletas
- Substituir PB N/D por campo real (se existir em backend)
- Substituir estado disciplinar N/D por fonte real
- Substituir assiduidade estimada por métrica real de presença

Critério de saída:
- 0 placeholders N/D para dados que já existam no backend

3. Hardening de defensividade de dados
- Rever null/undefined em todas as tabs
- Garantir render estável com arrays vazios e datas inválidas
- Validar estado inicial sem selectedTraining

Critério de saída:
- Nenhum crash de render por dados incompletos

## Fase 2 - Integração backend (prioridade média)

4. Endpoint de duplicação semanal/microciclo (Em desenvolvimento - Standby)
- Criar rota dedicada para duplicação por bloco
- Executar em transação
- Aplicar offset de datas
- Preservar relação com época/microciclo

Critério de saída:
- Deixar de usar duplicação em loop no frontend

5. Persistir métricas de Performance em BD
- Criar migration da tabela performance_metrics
- Criar model + validações
- Expor endpoints CRUD mínimos
- Migrar leitura/escrita do useKV para API

Critério de saída:
- Métricas ACWR/RPE/prontidão persistidas no backend

6. Persistir Planeamento V2 em BD
- Criar/alinhar entidades para microciclos e plano de época avançado
- Ligar tab Planeamento ao backend
- Remover dependência de useKV para dados críticos

Critério de saída:
- Planeamento V2 100% baseado em dados reais

## Fase 3 - Qualidade e operação (prioridade média/alta)

7. Testes automatizados do Desportivo 2
- Feature test: Dashboard -> Presenças com treino pré-selecionado
- Feature test: bloqueio de presenças sem treino selecionado
- Feature test: Modo Cais marcação rápida
- Feature test: duplicação semanal (quando endpoint existir)

Critério de saída:
- Cobertura dos fluxos operacionais críticos

8. Observabilidade e performance
- Monitorar queries de presenças/sincronização
- Revisar necessidade de índices compostos
- Criar painel básico de falhas de sincronização

Critério de saída:
- Sem gargalos claros em staging sob carga normal

9. Checklist de release
- Build e testes verdes
- Validação manual em tablet/mobile (contexto cais)
- Verificação de rotas desportivo2
- Plano de rollback validado

Critério de saída:
- Go-live aprovado para produção

## Ordem recomendada de execução

1) Fase 1 inteira
2) Item 4 da Fase 2 (duplicação)
3) Itens 5 e 6 da Fase 2 (persistência)
4) Fase 3 inteira

## Riscos se não executar

- Permanência de dados mock/local (useKV) em áreas críticas
- Duplicação de treinos com risco de inconsistência temporal
- Falta de rastreabilidade de métricas científicas
- Regressões não detectadas sem suite E2E
