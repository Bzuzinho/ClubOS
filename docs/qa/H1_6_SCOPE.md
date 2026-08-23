# H1.6 — Paydown TypeScript por concentração

Objetivo: reduzir o baseline TypeScript de 101 erros / 51 ficheiros por um lote de baixo risco escolhido a partir da concentração real de diagnósticos do `tsc`.

Critério:

1. medir os ficheiros e códigos de erro mais frequentes no CI;
2. selecionar o domínio com maior retorno e menor risco funcional;
3. corrigir tipagem sem alterar comportamento de runtime salvo se a causa exigir correção explícita;
4. executar build, PHPUnit, PostgreSQL concurrency e guard rails existentes;
5. baixar `qa/baselines/typescript.json` no mesmo PR;
6. atualizar o estado vivo antes do merge.
