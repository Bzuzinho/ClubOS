# Âmbito CI/CD — fase 1

## Incluído

- validação automática de Pull Requests para `main`;
- testes Laravel em SQLite em memória;
- build Vite/React;
- deploy automático após validação bem-sucedida de `main`;
- execução serial de deploys de produção;
- chave SSH fornecida por GitHub Secret;
- reutilização do mecanismo de deploy Oracle VM já existente;
- documentação do desenvolvimento local e do rollout.

## Não incluído nesta fase

- migração para Railway;
- mudança de Neon PostgreSQL;
- substituição da Oracle VM;
- containers Docker em produção;
- blue/green deployment;
- rollback automático;
- eliminação de `bin/deploy-vm.sh`;
- alteração da lógica funcional do ClubOS.

Estas exclusões são intencionais para automatizar o processo actual com o menor risco operacional possível.
