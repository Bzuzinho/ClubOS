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
- CI 34489117738: 149 testes de browser passaram à primeira e um passou na repetição. A falha revelou overflow na tabela de utilizadores das Configurações em WebKit móvel; este lote corrige essa tabela e os restantes catálogos ainda no formato antigo. A CI final 34490322492 passou com 150 testes de browser sem repetições. PR #344 integrada em 84d6029b681af941defac5d532bb4552b524543f.
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

## Continuação: percursos preenchidos

O seeder exclusivo do ambiente testing passa a incluir uma fatura e uma linha bancária por perfil de browser. O percurso abre a conciliação manual, confirma as nove células da fatura, preenche uma alocação parcial e cancela sem gravar pagamentos. A matriz passa também a verificar tabelas e separadores em cada tab visitada, em vez de apenas na última. Validação CI deste incremento pendente. Isto não substitui QA com dados operacionais reais nem fecha os percursos desportivos e Website.

CI 34492565080: conciliação preenchida passou nos cinco perfis; 153 testes passaram e dois detetaram overflow nas tabelas intermédias de Estrutura Desportiva em Chrome/Safari móvel. As dez tabelas de Estrutura passam ao componente adaptável com identificação de todos os campos e ações; nova CI obrigatória.

## Continuação: Planeamento, Cais e Live preenchidos

PR #345 integrada em `4e3e71ae2ba14742e34ee0fa20bfea504a2236d6`; CI da PR 34493511720 verde, com 155 testes sem repetições. O novo fixture exclusivo de testing contém época, macro/meso/microciclo, treino publicado, atleta presente e série 8×50. Os percursos verificam a hierarquia e sessão ligada, alternância Lista/Cards no Cais e seleção atleta→série com START disponível no Live, sem iniciar medições.

A revisão encontrou larguras mínimas no Cais preenchido (grelha de 340+760 px e linha de atleta com mínimos fixos). As grelhas e ações passam a quebrar linha conforme o espaço, preservando o shell operacional e todos os campos. Planeamento quebra as ações dos ciclos; Live adapta cabeçalho e seleção. CI do incremento pendente. Persistência de cronometragem/presenças e editor Website continuam fora desta cobertura de browser.

## Continuação: editor Website

PR #346 integrada em `3c7f35593cbd6c6e935ce0d63f55456d097bc8b0`; CI da PR 34496321359 verde, 165 testes sem repetições. O editor Website deixa de impor uma sobreposição com três colunas fixas e canvas com largura superior ao espaço disponível. Usa o layout administrativo comum, mantém o menu acessível, dispõe os painéis em sequência em ecrãs estreitos e ajusta visualmente a pré-visualização sem alterar a largura simulada do dispositivo. As ferramentas de cabeçalho deixam de estar ocultas por breakpoint e os separadores usam o componente comum.

Fixture de rascunho exclusivo de testing com bloco hero; percurso cobre os seis separadores de propriedades, três dispositivos e largura do editor/inspector. Entradas Website/Páginas incluídas na matriz transversal. TypeScript/lint locais e CI do lote em validação. Gravação, publicação, recuperação de versões e dados dinâmicos não são alterados; a persistência destes percursos em browser continua pendente. Não declarar P1 globalmente fechada.

## Consolidação após o editor Website

PR #347 integrada em `6cf14934ee7bee2aef8ce5a8d3abb5e92fadf8c6`. CI da PR 34500915866: 180 testes de browser, sem falhas nem repetições. A base atual deste inventário é esse merge; o hash no início identifica a base histórica da matriz.

| Componentes revistos | Evidência / decisão |
|---|---|
| Financeiro: Banco, Relatórios, ficha e importação de recibos | 13 tabelas adaptadas, incluindo quatro listas/preview internas do Banco; valores, seleção e alocações preservados |
| Membros: FinancialTab e MemberImportDialog | Quatro tabelas adaptadas; importação e regras financeiras inalteradas |
| Configurações: BankReconciliationManagementTab | Três tabelas adaptadas; aliases e gestão canónica preservados |
| Comunicação e painel administrativo da Loja | Uma tabela em cada componente adaptada, com campos identificados |
| DesportivoPlaneamentoTab, DesportivoTreinosTab, ResultadosCompeticoesForm, CaisAthletePerformanceModal | Permanecem ocorrências de overflow no source; pesquisa de referências não encontrou ligação destes ramos às páginas atuais. Não alterados nem eliminados neste lote |
| EventosTipos e PresencasList | Exportações sem referências encontradas no frontend atual; não confundidas com os componentes de Eventos em uso |
| ReportsTab de Membros | As tabelas HTML restantes pertencem à exportação/impressão, não à interface interativa. Preservadas |
| SportsTab da ficha do membro | Tem consumidores nas páginas de Membros e componentes internos próprios; revisão de tabelas e percursos preenchidos ainda pendente |

O E2E transversal passa a inspecionar também o contentor de tabelas HTML nativas, evitando que escapem ao controlo por não usarem `data-slot`. A CI deste incremento é obrigatória. O percurso financeiro existente tem linhas e faturas de teste; não se assume cobertura preenchida de recibos importados, aliases bancários ou encomendas só porque as páginas entram.

P1 continua aberta para a ficha desportiva do membro, percursos de importação/configuração com dados e inventário residual de diálogos/grelhas. Em P2, rever a coexistência da catalogação bancária e conciliação manual, sem remover fluxos ou alterar fontes financeiras neste lote. P3/P4 (histórico e modalidade/época) mantêm a prioridade previamente definida.
