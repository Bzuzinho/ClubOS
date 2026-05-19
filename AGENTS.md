# AGENTS.md — Regras Permanentes para IA e Developers no ClubOS

Este ficheiro define as regras obrigatórias para qualquer agente de IA, developer ou ferramenta automatizada que trabalhe neste repositório.

O objetivo é impedir perda de contexto, duplicação de lógica e alterações desalinhadas com o estado real do projeto.

---

## 1. Regra principal

Antes de qualquer tarefa de desenvolvimento, análise, refatoração, bugfix, sprint, auditoria ou criação de prompt técnico relacionada com o ClubOS, consultar primeiro:

```txt
docs/ESTADO_VIVO_DESENVOLVIMENTO.md
```

Este ficheiro é a fonte de verdade funcional e técnica do projeto.

Não iniciar trabalho relevante sem verificar:

- estado global do projeto;
- percentagens por módulo;
- riscos técnicos vivos;
- prioridades recomendadas;
- histórico de atualizações.

---

## 2. Quando atualizar o documento vivo

Atualizar `docs/ESTADO_VIVO_DESENVOLVIMENTO.md` sempre que a tarefa alterar qualquer uma destas dimensões:

- nova funcionalidade;
- alteração funcional relevante;
- correção de bug com impacto em fluxo de negócio;
- alteração de modelo de dados;
- nova migration;
- nova integração;
- remoção de fluxo legado;
- alteração de permissões;
- alteração de rotas;
- alteração de UI com impacto operacional;
- melhoria relevante de performance;
- criação ou alteração de testes relevantes;
- alteração de percentagem estimada de um módulo;
- nova pendência ou risco técnico identificado.

Se a alteração for apenas cosmética, textual, pequena limpeza sem impacto funcional ou correção isolada de estilo, indicar no resumo que o documento vivo não foi atualizado por não haver impacto funcional.

---

## 3. Como atualizar o documento vivo

Sempre que houver impacto, atualizar pelo menos uma destas secções:

1. `## 4. Grelha viva de funcionalidades`
2. `## 5. Principais riscos técnicos vivos`
3. `## 6. Prioridades recomendadas`
4. `## 7. Histórico vivo de atualizações`

No histórico, usar o formato:

```md
| AAAA-MM-DD | Módulo | Desenvolvimento / análise | Evidência | Percentagem antes | Percentagem depois | Pendências |
```

A evidência deve apontar para ficheiros, serviços, controllers, migrations, páginas React, testes ou PRs/commits relevantes.

---

## 4. Regras de análise do repositório

Quando a tarefa exigir avaliação do estado atual do código:

1. Analisar o repositório GitHub online, ramo `main`, salvo indicação contrária.
2. Não usar ZIPs locais como fonte principal se o pedido indicar repositório online.
3. Confirmar rotas, controllers, services, models, migrations, pages React/Inertia e testes antes de atualizar percentagens.
4. Distinguir entre:
   - funcionalidade com UI apenas;
   - funcionalidade com backend apenas;
   - funcionalidade com persistência real;
   - funcionalidade com testes;
   - funcionalidade validada em runtime.
5. Não assumir que uma rota declarada significa funcionalidade completa.
6. Não assumir que uma página React significa fluxo funcional fechado.

---

## 5. Critérios para percentagens

As percentagens devem refletir maturidade real, não apenas existência de ficheiros.

Critérios recomendados:

- 0% — inexistente;
- 10%–25% — conceito, estrutura ou placeholder;
- 30%–50% — migrations/models/rotas parciais, sem fluxo completo;
- 55%–70% — fluxo funcional principal existe, mas falta validação, testes ou consolidação;
- 75%–85% — funcionalidade avançada, com backend/frontend/persistência, faltando endurecimento;
- 90%–100% — funcionalidade fechada, testada, validada e integrada operacionalmente.

Nunca subir percentagens apenas porque existe código novo. A percentagem só deve subir se o fluxo de negócio ficou mais completo.

---

## 6. Regras específicas do ClubOS

### 6.1. Financeiro

O módulo financeiro é crítico. Evitar múltiplas fontes de verdade.

Regra preferencial:

- pagamentos, liquidações, alocações, conciliações bancárias, importação de recibos e criação de pedidos fiscais devem convergir para um fluxo canónico.
- O candidato atual a fluxo canónico é `App\Services\Financeiro\PaymentAllocationService`.

Sempre que mexer em financeiro, verificar impacto em:

- `Invoice`;
- `Payment`;
- `PaymentAllocation`;
- `BankStatement`;
- `MapaConciliacao`;
- `FinancialEntry`;
- `Movement`;
- `FiscalDocumentRequest`;
- `ReceiptImport*`.

### 6.2. Membros

Manter separação entre:

- identidade/autenticação em `users`;
- dados financeiros em tabelas financeiras;
- dados desportivos em tabelas desportivas;
- documentos em tabelas próprias;
- permissões/tipos em tabelas de configuração.

Evitar voltar a concentrar tudo em `users`.

### 6.3. Desportivo

Validar sempre impacto em:

- épocas;
- macrociclos;
- mesociclos;
- microciclos;
- treinos;
- atletas em treino;
- presenças;
- eventos;
- resultados;
- portal do atleta.

### 6.4. Portal mobile / atleta / família

O utilizador comum deve entrar preferencialmente numa visualização simples, mobile-first e sem ruído administrativo.

Apenas perfis autorizados devem aceder à administração.

---

## 7. Validações recomendadas

Sempre que possível, depois de alterações relevantes executar:

```bash
composer dump-autoload
php artisan migrate --pretend
php artisan test
npm run build
```

Se alguma validação não for executada, indicar explicitamente no resumo.

---

## 8. Resumo obrigatório no fim de cada tarefa relevante

No fim de cada desenvolvimento relevante, devolver sempre:

- o que foi alterado;
- ficheiros principais alterados;
- impacto funcional;
- impacto no documento vivo;
- validações executadas;
- pendências.

Se o documento vivo foi atualizado, indicar a secção alterada.

---

## 9. Princípio final

O ClubOS deve evoluir com memória técnica.

A regra é simples:

```txt
Implementar sem atualizar contexto cria dívida.
Atualizar contexto sem validar código cria ilusão.
O correto é implementar, validar e registar.
```
