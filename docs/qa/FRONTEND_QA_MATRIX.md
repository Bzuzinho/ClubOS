# Frontend QA Matrix — H1.17

Estado: baseline inicial obrigatório, preparado em 2026-08-28.

## Objetivo

Transformar a qualidade frontend do ClubOS de validação essencialmente por TypeScript/build para uma matriz automática com lint, testes unitários/de componente, browser E2E, acessibilidade e viewports desktop/mobile.

A estratégia é progressiva: o baseline inicial é pequeno mas bloqueante. Novos fluxos e módulos devem ampliar a cobertura sem remover ou enfraquecer os gates existentes.

## Gates obrigatórios

### TypeScript

```bash
npm run typecheck:ratchet
```

Baseline: 0 erros / 0 ficheiros. Qualquer regressão falha a CI.

### ESLint

```bash
npm run lint
```

Escopo: `resources/js/**/*.{ts,tsx}`.

Regras iniciais de alto sinal:

- `no-debugger`;
- `no-duplicate-imports`;
- `no-unreachable`;
- `react-hooks/rules-of-hooks`.

O lint não substitui o TypeScript. Regras adicionais devem ser introduzidas de forma mensurável, sem criar exceções silenciosas para dívida nova.

### Unit/component

```bash
npm run test:unit
```

Stack: Vitest + jsdom + Testing Library + user-event + jest-dom.

Baseline inicial:

- `InputError`: renderização condicional e classes;
- `Checkbox`: forwarding de props, classes e interação real de utilizador.

Regra de expansão: correções de componentes partilhados e novos componentes com lógica devem acrescentar testes no mesmo PR sempre que tecnicamente adequado.

### E2E / acessibilidade / viewport

```bash
npm run test:e2e:install
npm run test:e2e
```

Stack: Playwright + axe-core.

Matriz obrigatória inicial:

| Projeto | Motor | Perfil |
|---|---|---|
| `chromium-desktop` | Chromium | Desktop Chrome |
| `firefox-desktop` | Firefox | Desktop Firefox |
| `webkit-desktop` | WebKit | Desktop Safari |
| `chromium-mobile` | Chromium | Pixel 7 |
| `webkit-mobile` | WebKit | iPhone 14 |

Contrato E2E inicial em `/login`:

- página carrega e expõe os controlos de autenticação;
- não existe overflow horizontal no viewport testado;
- axe não encontra violações `serious` ou `critical` nas regras WCAG A/AA configuradas.

A matriz é executada em ambiente Laravel real com SQLite efémero e assets Vite construídos. Não usa mocks do browser para substituir o fluxo HTTP.

## Integração CI

A CI canónica executa:

1. security/dependency ratchets;
2. TypeScript 0/0;
3. ESLint;
4. Vitest component/unit;
5. Vite build;
6. PHPUnit e legacy guard;
7. Playwright multi-browser/mobile/accessibility num job separado;
8. PostgreSQL concurrency.

O deploy produtivo depende de `validate`, `frontend-browser-qa` e `postgres-concurrency`. Uma falha frontend bloqueia o deploy tal como uma falha backend.

## Próximas expansões

Sem enfraquecer este baseline:

1. autenticação completa e recuperação de password;
2. Dashboard por perfil;
3. Portal atleta/família mobile-first;
4. Membros;
5. Financeiro crítico;
6. Desportivo Cais/Live;
7. Eventos;
8. Website público/construtor;
9. acessibilidade de componentes complexos e modais;
10. testes de scroll/navegação para menus e workspaces em mobile.

A cobertura deve crescer por risco operacional e por fluxo de negócio, não por contagem artificial de testes.
