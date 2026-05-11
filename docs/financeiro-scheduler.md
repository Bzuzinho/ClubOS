# Scheduler financeiro

O scheduler do Laravel para mensalidades usa apenas recursos normais do servidor. Nao existe custo adicional, nao depende de servicos externos, filas pagas ou cron pago.

Por defeito, o scheduler das mensalidades fica desligado. Em producao, so deve ser ativado com configuracao explicita.

## Cron de producao

No servidor Linux, adicionar ao `crontab`:

```bash
* * * * * cd /var/www/clubmanager && php artisan schedule:run >> /dev/null 2>&1
```

E ativar explicitamente no ambiente:

```bash
CLUBOS_MONTHLY_FEE_SCHEDULER=true
```

## Como funciona

- O cron chama `php artisan schedule:run` a cada minuto.
- O Laravel verifica internamente quais tarefas estao marcadas para esse minuto.
- Se `CLUBOS_MONTHLY_FEE_SCHEDULER=false` ou ausente, os comandos de mensalidades nem sequer sao registados no scheduler.
- `finance:activate-due-monthly-fees` corre diariamente as `00:10` para tornar visiveis mensalidades ocultas cujo vencimento chegou, mas so atua quando `monthly_fee_auto_activate_due` estiver explicitamente ligado.
- `finance:generate-monthly-fees` corre diariamente as `00:20` para gerar mensalidades do ciclo financeiro configurado, mas so atua quando `monthly_fee_generation_enabled` estiver explicitamente ligado.
- Os flags `monthly_fee_hide_future` e `monthly_fee_respect_registration_date` continuam a controlar o comportamento da geracao, mas nao ativam automacoes por si.

## Porque foi escolhida execucao diaria

- A geracao e idempotente, por isso nao duplica mensalidades existentes.
- A execucao diaria cobre novos utilizadores, alteracoes de plano e ajustes administrativos sem esperar pelo primeiro dia do mes.
- A ativacao fica separada da geracao para manter o comportamento previsivel e simples de auditar.

## Garantias operacionais

- Nao duplica mensalidades.
- Sem opt-in explicito, nao gera nem ativa mensalidades automaticamente.
- Nao altera faturas pagas, parciais ou fiscalizadas; apenas evita duplicar periodos que ja existem.
- Mensalidades futuras podem ser criadas ocultas e so passam a visiveis quando se tornam devidas.
- A geracao automatica nao envia emails nem cria `CommunicationCampaign`, `CommunicationDelivery` ou `InAppAlert`.
- A conciliacao bancaria considera apenas mensalidades visiveis e em aberto.