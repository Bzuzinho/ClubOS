# P1 — Matriz transversal de interface

Base: `9fa4e91174169e02fc48d0e4aa7b4989f49014d3`. Atualização: 2026-09-10.

A aprovação do mockup é conceptual: conservar componentes, cores e tipografia. Desktop usa a área disponível sem botão de maximização. Não aceitar deslocação horizontal nem ocultar informação para a disfarçar. Cais e Live conservam os seus shells operacionais.

## Cobertura e trabalho aplicado

| Módulo | Ecrãs abrangidos | Situação neste lote | Verificação de percurso |
|---|---|---|---|
| Membros | Lista, criação simples/completa, edição, ficha; relatórios e mensagens no lote anterior | Lista sem colunas ocultas por tamanho; separadores comuns nas fichas | Entrada e separadores do índice na CI; operações de gravação fora desta alteração |
| Financeiro | Faturas, ficha, conciliação bancária | Campos da lista disponíveis; conciliação adaptável com inputs e alocações preservados | Entrada/separadores na CI; conciliação com dados reais ainda por validar visualmente |
| Eventos | Índice e separadores | Lista, relatórios e resultados com tabelas adaptáveis | Percurso de entrada e alternância na CI |
| Comunicação | Índice e separadores | Fundação comum já aplicada | Percurso de entrada e alternância na CI |
| Logística | Índice, stock, requisições, empréstimos, compras | Tabelas e navegação do lote #342 | Entrada/separadores e testes existentes de diálogo/stock na CI |
| Loja | Administração, catálogo, encomendas, histórico público | Lotes #342/#343; filtros públicos agora quebram linha | Entradas e navegação administrativa na CI |
| Patrocínios | Índice e integrações | Lote #342 | Entrada e separadores na CI |
| Configurações | Geral, financeiro, logística, notificações e respetivos separadores | Separadores internos comuns e 14 tabelas adaptáveis, incluindo utilizadores, artigos e catálogos | Entrada/alternância e formulário de artigos na CI |
| Desportivo | Visão geral, atletas, estrutura, configuração, planeamento, treinos, competições, resultados, análise, registos e convocatórias | Retirados limites internos de largura; separadores comuns; filtros de atletas ajustados; linha temporal sem largura mínima de 900 px | Entradas de visão geral, estrutura, planeamento e treinos na CI; operações com dados por expandir |
| Cais / Live | Seletores de sessões e medições | Quebra de linha; cronómetros, presenças e fluxos conservados | Cobertura funcional backend existente; operação visual real pendente |
| Portal | Séries do treino | Tabela adaptável, mantendo todas as métricas | Percurso atleta/família ainda por expandir |
| Website / Marketing / Início | Editor, campanhas, dashboard | Preservados neste lote; dashboard tem QA existente | Editor e campanhas ainda exigem cobertura específica |

## Evidência e limites

- TypeScript, lint e build locais; testes de navegação e tabelas no pipeline.
- CI 34489117738: 149 testes de browser passaram à primeira e um passou na repetição. A falha revelou overflow na tabela de utilizadores das Configurações em WebKit móvel; este lote corrige essa tabela e os restantes catálogos ainda no formato antigo. Exige nova CI sobre a correção, sem considerar a repetição prova suficiente.
- A matriz de percursos verifica entrada autenticada, separador ativo, painel quando existe e overflow da página/tabelas/separadores em cinco perfis Playwright.
- O gate do componente Table cobre 320, 375, 768, 1280 e 1920 px com texto longo. Isto não prova isoladamente todos os ecrãs com todos os dados.
- PHP e browser local indisponíveis neste ambiente; Laravel e percursos reais são executados na CI.
- Não existem alterações de migrations, permissões, dados financeiros, inscrições ou histórico desportivo.

## Critérios ainda necessários para fechar P1

1. CI verde sobre o commit final e revisão dos percursos cobertos.
2. Validar visualmente conciliação preenchida, planeamento com ciclos, Cais/Live em operação e editor Website. Não marcar estes fluxos como concluídos pelo simples teste de uma página vazia.
3. Expandir o mesmo controlo aos componentes internos que ainda impõem overflow horizontal e confirmar quais têm consumidores ativos.
4. Confirmar a altura útil em percursos reais: o shell administrativo passa a distribuir a altura disponível por flex, sem subtrair offsets fixos. Cais/Live preservam o shell operacional.

As redundâncias de propriedade funcional pertencem a P2. O histórico consolidado pertence a P3. O contexto global real de modalidade/época e o assistente de época pertencem a P4. Permanecem no plano; não são substituídos por ajustes de CSS.
