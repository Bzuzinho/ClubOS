# Desportivo — Resultados

## Fronteira funcional

Resultados é o registo factual do que aconteceu numa participação competitiva.

Fluxo canónico:

`Competition → Prova → CompetitionRegistration → Result → ResultSplit`

- **Competition** define o contexto competitivo.
- **Prova** define a prova do programa.
- **CompetitionRegistration** define a participação esperada do atleta nessa prova.
- **Result** regista o desfecho factual.
- **ResultSplit** regista os parciais do resultado.
- **TeamResult** mantém a classificação coletiva separada.

Resultados não cria Competições, Eventos ou Provas implicitamente.

## Cutover legacy

O workspace novo não usa:

- matching de Convocatória por título + data;
- `events.id` como substituto de `competitions.id`;
- criação de Competition a partir de Event durante o registo de um resultado;
- criação automática de Prova a partir de `prova_tipo_id`.

A matriz de "Por registar" é derivada exclusivamente de `competition_registrations` nas provas da competição selecionada.

## Estados competitivos

`results.status` distingue:

- `ok` — resultado válido, com tempo oficial obrigatório;
- `dsq` — desclassificado;
- `dns` — não iniciou;
- `dnf` — não terminou.

`desclassificado` permanece preenchido para compatibilidade e é verdadeiro apenas para `dsq`.

## Splits

Os splits pertencem sempre a um `Result` e são persistidos em `result_splits`.

Na UI de registo em massa:

- nenhuma área de splits fica aberta por defeito;
- clicar na linha `atleta + prova` torna-a ativa;
- os splits aparecem imediatamente abaixo dessa linha;
- selecionar outra linha fecha o detalhe anterior e abre o da nova prova;
- as distâncias dos splits são limitadas à distância da prova.

## Workspace

Vista transversal:

`Resultados → Por registar → Por atleta → Coletivos`

Dentro da competição:

`Resumo → Por prova → Todos os resultados → Coletivo`

O módulo seguinte, **Análise**, é responsável pela interpretação longitudinal, comparação de desempenho, tendências e métricas derivadas. Resultados mantém-se factual.
