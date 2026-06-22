# Runbook M2.6 - Dual-Write (Rollout e Rollback)

## 1. Pre-condicoes

- Sprint M2.5 aplicada com dual-write ativo em runtime.
- Escrita de ficha de membro e portal perfil a passar por `MemberDataWriteService`.
- Leitura com fallback a passar por `MemberDataReadService`.
- Campos legacy em `users` mantidos (sem remoções).
- Guardrails SEC-1 ativos e validados no ambiente alvo.
- Janela operacional aprovada para rollout controlado.

## 2. Backup obrigatorio antes de producao

- Confirmar snapshot/backup completo da base de dados antes de qualquer operacao.
- Confirmar disponibilidade de restore e ponto temporal (PITR) testado.
- Registar no ticket/janela: timestamp do backup, responsavel e referencia do backup.

Sem backup validado, rollout deve ser abortado.

## 3. Confirmacao de SEC-1 no servidor

Validar no ambiente alvo:

- bloqueio ativo para comandos destrutivos;
- ambiente e assinatura de base remota corretos;
- ausencia de bypass indevido para comandos destrutivos.

Comando recomendado de evidencia:

```bash
php artisan test --filter=DatabaseSafety
```

## 4. Comandos permitidos

```bash
php artisan migrate --force
php artisan members:audit-data-structure
php artisan members:backfill-data-structure
php artisan members:backfill-data-structure --commit --unlock-write --confirm=BACKFILL_MEMBER_DATA
```

Nota: o comando de backfill com `--commit --unlock-write --confirm=BACKFILL_MEMBER_DATA` so pode ser usado apos backup confirmado e apenas no momento operacional definido para backfill controlado.

## 5. Comandos proibidos

```bash
php artisan migrate:fresh
php artisan migrate:refresh
php artisan migrate:reset
php artisan db:wipe
```

## 6. Checklist antes do deploy

1. `git status` limpo no artefacto a promover.
2. `composer dump-autoload` sem erros.
3. `php artisan members:audit-data-structure` com baseline conhecida.
4. Testes M2 (write/read/fallback/portal) verdes.
5. `npm run build` verde.
6. Backup validado e referencia registada.
7. Plano de rollback aprovado.

## 7. Checklist depois do deploy

1. Aplicacao online e login funcional.
2. Ficha de membro abre/guarda sem erro.
3. Portal perfil abre/guarda sem erro.
4. Auditoria `members:audit-data-structure` sem regressao de conflitos.
5. Financeiro/desportivo/familia sem regressao visivel.
6. Registo de evidencias da janela concluido.

## 8. Comandos de auditoria

Execucao recomendada:

```bash
php artisan members:audit-data-structure
php artisan members:audit-data-structure --json
php artisan members:audit-data-structure --limit=50
```

Verificar no minimo:

- `missing_dados_pessoais = 0` (ou valor esperado da janela)
- `missing_dados_configuracao = 0` (ou valor esperado da janela)
- `conflicts_dados_pessoais` sem regressao
- `conflicts_dados_configuracao` sem regressao

## 9. Comandos de backfill controlado (se necessario)

Dry-run primeiro:

```bash
php artisan members:backfill-data-structure
php artisan members:backfill-data-structure --json
```

Commit controlado apenas com backup confirmado:

```bash
php artisan members:backfill-data-structure --commit --unlock-write --confirm=BACKFILL_MEMBER_DATA
```

Apos commit:

```bash
php artisan members:audit-data-structure
```

## 10. Validacao manual da ficha

1. Login com perfil autorizado.
2. Abrir lista de membros.
3. Abrir ficha de um membro.
4. Alterar contacto e guardar.
5. Alterar observacoes e guardar.
6. Alterar RGPD/consentimento e guardar.
7. Confirmar persistencia na UI sem regressao.
8. Confirmar em base de dados (tinker) `dados_pessoais`, `dados_configuracao` e sincronizacao legacy em `users` quando aplicavel.

Comando util de verificacao:

```bash
php artisan tinker --execute="
$u = App\Models\User::with(['dadosPessoais','dadosConfiguracao'])->find(ID_DO_MEMBRO);
dump([
    'user' => $u?->only(['id','name','email','role','estado','numero_socio','contacto','email_secundario']),
    'dados_pessoais' => $u?->dadosPessoais?->toArray(),
    'dados_configuracao' => $u?->dadosConfiguracao?->toArray(),
]);
"
```

## 11. Validacao de portal

1. Abrir Portal Perfil do mesmo membro e editar dado pessoal permitido.
2. Guardar e confirmar persistencia em UI.
3. Confirmar dual-write em `dados_pessoais` e sincronizacao legacy aplicavel.
4. Validar Portal Familia sem regressao de relacoes e visibilidade.

## 12. Rollback logico

Executar rollback logico quando houver regressao funcional sem necessidade de restore integral:

1. Reverter para commit aplicacional anterior estavel.
2. Limpar caches da aplicacao.
3. Revalidar leitura via fallback e operacoes criticas de membro/portal.
4. Manter campos legacy em `users` como plano de seguranca.

## 13. Rollback via Neon PITR/backups

Usar quando houver corrupcao/risco de dados fora do rollback logico:

1. Parar janela e congelar novas alteracoes.
2. Restaurar para o snapshot/PITR registado na fase pre-deploy.
3. Validar integridade minima (login, ficha membro, auditoria).
4. Registar causa raiz e evidencias antes de nova tentativa.

## 14. Criterios de sucesso

- Escrita continua dual-write sem erros.
- Leitura pos-escrita consistente via novas tabelas com fallback funcional.
- `users` legacy mantido e sincronizado onde aplicavel.
- Auditoria sem regressao de conflitos/missing.
- Sem regressao em financeiro, desportivo, familia e portal.
- Build e testes obrigatorios verdes.

## 15. Criterios para abortar rollout

- Falha de backup ou restore nao validado.
- Falha de testes criticos (DatabaseSafety, write/read/fallback/portal).
- Regressao funcional na ficha membro ou portal perfil.
- Regressao de integridade na auditoria (conflitos/missing a aumentar sem explicacao).
- Qualquer evidencia de impacto em financeiro/desportivo/familia fora do escopo.
