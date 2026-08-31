# H2.5u — POST financeiro de compatibilidade

Estado provisório da implementação antes do gate de CI.

- `POST financeiro/{financeiro}/apagar` saiu de `routes/web.php`;
- o shim passou para `routes/compat/web_finance_delete.php` exatamente na mesma posição runtime, entre Logística e Loja;
- preservados `FinanceiroController@destroy`, `auth`, `verified`, `module.access:financeiro`, `permission.access:financeiro.dashboard,delete` e o nome `financeiro.destroy.post`;
- a suite topológica histórica permanece intacta;
- foi acrescentado regression test específico para origem e contrato runtime;
- sem migrations ou alterações de dados.

Nota: o shim de compatibilidade fica deliberadamente em `routes/compat/`, fora do inventário de módulos top-level do audit H2.5a, para não reclassificar um endpoint legacy como novo módulo funcional. O contrato runtime continua a ser o gate decisivo.
