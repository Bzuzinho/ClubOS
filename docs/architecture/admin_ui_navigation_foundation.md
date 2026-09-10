# ClubOS — Fundação transversal de interface administrativa

Data: 2026-09-10  
Estado: contrato aprovado para implementação incremental

## Objetivo

A área administrativa deve comportar-se como uma única aplicação. Portal, Cais e Live mantêm experiências próprias por terem utilizadores e condições operacionais diferentes.

## Regras obrigatórias

1. Cada módulo usa um cabeçalho compacto, navegação principal visível e uma única área de scroll.
2. O separador ativo e os filtros relevantes ficam no URL.
3. Um diálogo serve confirmações e formulários curtos. Processos longos usam painel lateral, página ou assistente.
4. Todas as páginas apresentam estados coerentes de carregamento, vazio, erro e sucesso.
5. Registos criados por outro módulo mostram a origem e uma ligação para a abrir.
6. Uma entidade tem um único módulo proprietário; outros módulos apenas a consultam ou editam através do respetivo contrato.
7. Portal, Cais e Live não recebem a navegação administrativa normal.

## Propriedade funcional

| Domínio | Proprietário |
|---|---|
| Identidade, família, contactos e documentos | Membros |
| Modalidades, escalões, épocas, grupos e perfil técnico | Desportivo |
| Calendário e logística pública dos eventos | Eventos |
| Obrigações, pagamentos, banco e fiscalidade | Financeiro |
| Catálogo, fornecedores, stock e empréstimos | Logística |
| Venda e apresentação comercial do catálogo | Loja |
| Patrocinadores e contratos | Patrocínios |
| Templates, segmentos, campanhas e entregas | Comunicação |
| Páginas, media e pedidos públicos | Website |
| Organização, acesso, sistema e credenciais | Configurações |

Operações como conciliação bancária e importação de recibos pertencem ao Financeiro. Produtos, fornecedores e patrocinadores não devem ter ecrãs concorrentes em Configurações.

## Navegação global alvo

- Operação: Início, Membros, Desportivo, Eventos
- Gestão: Financeiro, Logística, Loja, Patrocínios
- Comunicação: Comunicação, Website
- Administração: Configurações

Marketing deve convergir com as campanhas de Comunicação, evitando dois modelos de campanha sem ligação operacional.

## Desportivo

Navegação administrativa alvo:

`Visão geral · Organização · Atletas · Planeamento · Treinos · Competições · Análise`

Modalidade e época são contexto persistente no cabeçalho e no URL. A criação de época usa três passos: identificação e datas; reutilização opcional da época anterior; revisão da estrutura transportada. Macrociclos, mesociclos e microciclos pertencem ao Planeamento.

Biblioteca integra Treinos. Convocatórias, Resultados e Recordes integram Competições. Cais e Live são iniciados a partir de um treino.

## Histórico do atleta

A ficha deve apresentar os dados canónicos de participação, modalidade, época, escalão oficial/calculado, alterações manuais, grupos e resultados. Mudanças encerram o período anterior e criam um novo registo temporal; nunca substituem o histórico.

## Sequência

1. P0 — resiliência global, URL dos separadores, navegação ativa e diálogos seguros.
2. P1 — `AppShell`/`ModuleShell`, scroll único, cabeçalhos, tabelas e formulários.
3. P2 — propriedade funcional e retirada de operações de Configurações.
4. P3 — Membros/Website e histórico consolidado do atleta.
5. P4 — reorganização funcional do Desportivo.
6. P5 — ligações de origem entre módulos e fila global de pendências.
7. P6 — reporting transversal.

Cada fase deve manter contratos canónicos, permissões e histórico existentes.
