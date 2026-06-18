# Runbook M2.4 — Validação, Deploy e Rollback (Consolidação de Dados do Membro)

## Objetivo

Preparar a entrada em produção do cutover de leitura da Sprint M2.3 com validação segura, sem alterar escrita nesta fase.

Este runbook documenta:
- checklist pré-deploy;
- validação de segurança de leitura;
- sequência de deploy (quando autorizada);
- smoke tests pós-deploy;
- rollback funcional e operacional.

Estado desta sprint:
- documentação preparada;
- nenhuma execução em produção foi realizada.

## Regras de segurança

- Não executar mudanças diretas em produção sem janela aprovada.
- Não executar comandos destrutivos.
- Não ativar cutover de escrita nesta fase.
- `users` mantém-se como fonte operacional de escrita.

## Pré-deploy (ambiente local/staging)

Executar antes de qualquer operação em produção:

1. Integridade de branch e diffs
- `git status --short`
- `git diff --stat`

2. Autoload e baseline framework
- `composer dump-autoload`
- `php artisan migrate --pretend`

3. Testes obrigatórios da M2.4
- `php artisan test --filter=MemberDataReadVisualSafetyTest`
- `php artisan test --filter=PortalMemberDataReadSafetyTest`
- `php artisan test --filter="MemberDataReadFallbackTest|PortalProfileFamilyAccessTest|PortalFamilyCurrentAccountTest|MembrosCurrentAccountSurfaceTest|MembrosFamilyContextTabPayloadTest"`

4. Build frontend
- `npm run build`

5. Auditoria de estrutura de dados do membro
- `php artisan members:audit-data-structure`

Critério para avançar:
- sem falhas nos testes obrigatórios;
- sem erro no build;
- sem conflitos inesperados na auditoria.

## Evidência mínima a registar

Guardar no registo da janela:
- hash do commit a promover;
- saída resumida de testes (número de testes/assertions);
- resultado da auditoria `members:audit-data-structure`;
- data/hora da validação.

## Sequência de deploy (quando houver autorização)

Importante: esta secção é preparatória. Não foi executada na M2.4.

1. Deploy normal do projeto
- seguir o fluxo oficial em `docs/deploy/DEPLOY_WORKFLOW.md`.

2. Pós-deploy imediato
- confirmar aplicação online;
- confirmar acesso administrativo e portal;
- validar leitura de dados nas superfícies críticas:
  - ficha de membro (show/edit, tabs Dados Pessoais/Configuração/Família/Financeiro/Desportivo);
  - portal perfil;
  - portal família.

3. Smoke tests funcionais manuais
- cenário A: membro apenas com dados em `users` abre sem erro e sem regressão visual;
- cenário B: membro com `dados_pessoais` usa preferencialmente os novos campos e mantém fallback quando vazio;
- cenário C: membro com `dados_configuracao` mostra campos RGPD/configuração corretamente com fallback quando necessário;
- cenário D: membro sem linhas nas novas tabelas abre sem erro 500/nulos;
- portal perfil: mostra dados corretos e continua editável como antes;
- portal família: nomes e relações estáveis, sem regressões.

4. Verificação de segurança de escrita
- confirmar que leitura não criou/alterou registos em `dados_pessoais`/`dados_configuracao` fora dos fluxos esperados;
- confirmar que `store`/`update` continuam a escrever em `users`.

## Rollback

### Rollback funcional (preferencial)

Se houver regressão de leitura após deploy:

1. Reverter para commit anterior estável da aplicação.
2. Reexecutar deploy padrão.
3. Limpar/recriar caches da aplicação.
4. Revalidar acessos críticos (ficha membro, portal perfil, portal família).

Notas:
- nesta fase não há cutover de escrita; rollback de dados não exige reversão de migração de escrita.
- manter `users` como fonte operacional reduz risco de perda funcional.

### Rollback operacional (fallback rápido)

Se não for possível rollback completo imediato:

1. Restringir uso das superfícies impactadas (janela controlada).
2. Recolher evidência de erro (logs e passos de reprodução).
3. Executar rollback funcional assim que possível.

## Critérios de aceitação para promoção final

- Checklist pré-deploy concluída a 100%.
- Testes M2.4 e regressão focada a verde.
- Build frontend a verde.
- Auditoria de dados sem conflitos inesperados.
- Validação manual dos cenários A-D + portal perfil/família sem regressões.

## Fora de escopo desta sprint

- cutover de escrita para `dados_pessoais`/`dados_configuracao`;
- alterações em migrations;
- alterações em financeiro, desportivo, importação e relações familiares.
