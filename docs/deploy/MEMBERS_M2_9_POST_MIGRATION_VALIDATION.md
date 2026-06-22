# M2.9 — Validação pós-migração da estrutura de membros

## Objetivo

Fechar operacionalmente a fase M2 da consolidação de dados de membros, confirmando que a produção já se encontra migrada para a nova estrutura:

- `dados_pessoais`
- `dados_configuracao`

A tabela `users` mantém-se como fonte legacy e conta de autenticação, mas os dados funcionais da ficha de membro passam a estar suportados pelas tabelas dedicadas.

## Estado confirmado em produção

Validação executada na VM de produção:

```bash
cd /var/www/clubmanager
sudo -u www-data -H php artisan members:audit-data-structure
```