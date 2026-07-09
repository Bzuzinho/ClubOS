# Activação do novo workflow

Este rollout deve ser concluído antes do primeiro merge para `main` que pretenda usar deploy automático.

## 1. Configurar secrets GitHub

No repositório `Bzuzinho/ClubOS`, abrir `Settings > Secrets and variables > Actions`.

Criar obrigatoriamente:

- `ORACLE_VM_SSH_KEY`

Criar preferencialmente:

- `ORACLE_VM_HOST`
- `ORACLE_VM_USER`
- `ORACLE_VM_APP_DIR`
- `PRODUCTION_APP_URL`

A chave privada de `ORACLE_VM_SSH_KEY` deve corresponder a uma chave pública já autorizada para o utilizador SSH da Oracle VM.

## 2. Rever a validação do Pull Request

O Pull Request de introdução deste workflow deve executar o job `Validate Laravel and frontend`.

Confirmar que passam:

- instalação Composer;
- `php artisan test`;
- `npm ci`;
- `npm run build`.

## 3. Fazer merge para main

Depois da validação passar e dos secrets estarem configurados, fazer merge para `main`.

O evento `push` na `main` inicia novamente a validação e, em caso de sucesso, executa o job de deploy.

## 4. Confirmar o primeiro deploy

No GitHub Actions, abrir a execução `Validate and deploy production` e confirmar:

- job `Validate Laravel and frontend` verde;
- job `Deploy ClubOS to Oracle VM` verde;
- health check final do `deploy-vm.sh` concluído.

Confirmar depois a aplicação pública manualmente.

## 5. Novo modo de trabalho

Após a activação, deixar de usar o deploy manual como rotina diária.

O fluxo passa a ser:

`Windows/Codex -> branch -> push -> Pull Request -> validação -> merge main -> deploy automático`

O comando `npm run deploy:vm` mantém-se temporariamente para diagnóstico e contingência, não como fluxo principal.
