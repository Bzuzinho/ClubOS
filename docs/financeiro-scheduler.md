# Scheduler financeiro

O scheduler do Laravel para mensalidades usa apenas recursos normais do servidor. Nao existe custo adicional, nao depende de servicos externos, filas pagas ou cron pago.

## Cron de producao

No servidor Linux, adicionar ao `crontab`:

```bash
* * * * * cd /var/www/clubmanager && php artisan schedule:run >> /dev/null 2>&1
```

## Como funciona

- O cron chama `php artisan schedule:run` a cada minuto.
- O Laravel verifica internamente quais tarefas estao marcadas para esse minuto.
- `finance:activate-due-monthly-fees` corre diariamente as `00:10` para tornar visiveis mensalidades ocultas cujo vencimento chegou.
- `finance:generate-monthly-fees` corre diariamente as `00:20` para gerar mensalidades do ciclo financeiro configurado.

## Porque foi escolhida execucao diaria

- A geracao e idempotente, por isso nao duplica mensalidades existentes.
- A execucao diaria cobre novos utilizadores, alteracoes de plano e ajustes administrativos sem esperar pelo primeiro dia do mes.
- A ativacao fica separada da geracao para manter o comportamento previsivel e simples de auditar.

## Garantias operacionais

- Nao duplica mensalidades.
- Nao altera faturas pagas, parciais ou fiscalizadas; apenas evita duplicar periodos que ja existem.
- Mensalidades futuras podem ser criadas ocultas e so passam a visiveis quando se tornam devidas.
- A conciliacao bancaria considera apenas mensalidades visiveis e em aberto.