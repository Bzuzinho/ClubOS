# Decisão de infraestrutura — ClubOS

## Decisão

Manter, nesta fase:

- Oracle VM como runtime de produção;
- Neon PostgreSQL como base de dados;
- GitHub como fonte de verdade do código.

Adoptar:

- desenvolvimento local no Windows;
- Codex como agente de programação local;
- branches curtas por tarefa;
- Pull Requests para `main`;
- GitHub Actions para validação e deploy automático.

## Justificação

O principal problema identificado é a fricção do processo de desenvolvimento e deploy, não uma limitação comprovada da infraestrutura de produção.

Migrar simultaneamente o runtime para Railway aumentaria custo e risco de mudança sem resolver uma necessidade de capacidade actualmente demonstrada.

A fase 1 automatiza o processo existente antes de qualquer decisão posterior de plataforma.

## Critérios para reavaliar Railway ou outra PaaS

Reavaliar quando existir pelo menos um dos seguintes sinais:

- custo operacional de manutenção da VM superior ao custo da PaaS;
- indisponibilidade recorrente relacionada com administração da VM;
- necessidade de escalar horizontalmente a aplicação ou workers;
- necessidade de ambientes preview por Pull Request;
- crescimento da equipa que torne a administração SSH um bloqueio;
- requisitos de observabilidade e rollback que justifiquem uma plataforma gerida.
