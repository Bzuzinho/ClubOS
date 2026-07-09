# Primeira execução do deploy automático

Antes do primeiro merge que active o deploy automático:

1. Confirmar o secret `ORACLE_VM_SSH_KEY`.
2. Confirmar que a chave pública correspondente está autorizada na VM.
3. Confirmar `ORACLE_VM_HOST`, `ORACLE_VM_USER` e `ORACLE_VM_APP_DIR` ou aceitar os defaults temporários documentados.
4. Confirmar que os scripts remotos já usados por `bin/deploy-vm.sh` continuam instalados na VM.
5. Confirmar que a `main` representa exactamente a versão pretendida para produção.

Depois do merge:

1. Abrir GitHub Actions.
2. Abrir `Validate and deploy production`.
3. Acompanhar primeiro `Validate Laravel and frontend`.
4. Confirmar depois `Deploy ClubOS to Oracle VM`.
5. Em caso de falha, não repetir cegamente o deploy: ler o step que falhou e os logs apresentados pelo script.
6. Em caso de sucesso, validar login, dashboard e pelo menos uma operação de leitura no Financeiro e no módulo Desportivo.
