# Reset financeiro de arranque operacional — 28/09/2026

## Objetivo

Iniciar a operação financeira do ClubOS com saldo e histórico transacional limpos, preservando integralmente os membros/atletas e a estrutura operacional do clube.

Este procedimento é deliberadamente diferente de um reset de base de dados: não usa `migrate:fresh`, `db:wipe` ou qualquer comando destrutivo global.

## Dados preservados

Permanecem na base de dados:

- utilizadores, membros e atletas;
- dados pessoais, familiares e desportivos;
- configurações de mensalidade atribuídas aos membros;
- planos/tipos de mensalidade em `monthly_fees`;
- descontos configurados por membro;
- centros de custo;
- fornecedores;
- categorias financeiras;
- tipos de documento/fatura e métodos de pagamento;
- regras de controlo documental;
- competições, eventos e convocatórias;
- políticas financeiras de competição;
- encomendas/Loja, requisições e compras de Logística enquanto factos operacionais;
- patrocínios e bens associados.

Nas superfícies operacionais que tinham uma ligação para um registo financeiro removido, é eliminada apenas essa referência financeira.

## Dados reiniciados

O reset elimina os factos financeiros existentes de:

- extratos e histórico bancário;
- sugestões, aliases e aprendizagem de reconciliação;
- mapas e alocações de reconciliação;
- pagamentos, alocações, créditos e reversões;
- faturas e linhas;
- movimentos financeiros, despesas e linhas;
- documentos associados aos movimentos na base de dados;
- lançamentos financeiros;
- pedidos fiscais/recibos por emitir;
- lotes e itens de importação de recibos;
- transações/mensalidades legacy;
- movimentos financeiros legacy de convocatórias;
- chaves KV financeiras legacy da ficha do membro.

`dados_financeiros.conta_corrente_manual` é colocado em zero. A atribuição da mensalidade e os descontos do membro não são eliminados.

## Proteção fiscal

A auditoria produtiva imediatamente anterior a este reset reportou:

- 172 pedidos fiscais analisados;
- 162 pedidos pendentes;
- 0 documentos marcados como emitidos;
- 0 documentos externos detetados.

Assim, o reset não está a eliminar evidência de documentos fiscais externos já registados no ClubOS.

## Backup obrigatório antes da execução

O deploy deteta especificamente se a migration one-shot do reset está pendente. Antes de executar qualquer migration:

1. corre um inventário agregado read-only do estado financeiro;
2. cria um dump PostgreSQL 17 dedicado numa diretoria exclusiva do reset;
3. valida SHA256 e o catálogo com `pg_restore --list`;
4. cifra e envia esse snapshot para o DR off-site/R2;
5. só depois executa `migrate --force`.

Se o backup local ou o off-site falhar, o deploy termina antes do reset.

## Validação após o reset

Depois da migration, antes do cutover:

`php artisan finance:audit-go-live-reset --json --fail-on-data`

O deploy só prossegue se:

- todas as tabelas transacionais definidas no contrato estiverem vazias;
- não existirem referências operacionais penduradas para os factos eliminados;
- as chaves KV financeiras legacy tiverem desaparecido;
- todos os saldos manuais estiverem a zero;
- a geração/ativação automática de mensalidades estiver temporariamente desligada.

A própria migration compara contagens das tabelas preservadas antes/depois e aborta a transação se algum conjunto protegido perder registos.

## Arranque financeiro após o reset

A geração automática de mensalidades e a ativação automática ficam deliberadamente desligadas para impedir que o scheduler volte a preencher a área financeira logo após a limpeza.

Depois de validar a nova data/período de arranque financeiro, reativar as opções de mensalidade em Configurações e gerar apenas o novo período pretendido.

## Ficheiros físicos

O reset remove as referências transacionais na base de dados, mas não apaga fisicamente os ficheiros privados antigos de importação/documentos durante o primeiro corte. Esta escolha é deliberada para manter uma camada adicional de recuperação. Sem registos na base de dados, esses ficheiros não pertencem ao runtime financeiro normal.

A eliminação física pode ser feita mais tarde, depois de validado o arranque limpo e expirado o período de segurança acordado.

## Rollback

O `down()` da migration não recria dados apagados.

Rollback de dados = restaurar o snapshot PostgreSQL dedicado criado imediatamente antes do reset. O backup off-site cifrado constitui a segunda cópia de segurança.
