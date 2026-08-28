# Frontend QA Matrix — H1.17 / H1.18

Estado: baseline automático obrigatório, expandido em 2026-08-28.

## Objetivo

Transformar a qualidade frontend do ClubOS de validação essencialmente por TypeScript/build para uma matriz automática com lint, testes unitários/de componente, browser E2E, acessibilidade e viewports desktop/mobile.

A estratégia é progressiva: o baseline é pequeno mas bloqueante. Novos fluxos e módulos devem ampliar a cobertura sem remover ou enfraquecer os gates existentes.

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
- `no-unreachable`;
- `react-hooks/rules-of-hooks`.

O lint não substitui o TypeScript. Regras adicionais devem ser introduzidas de forma mensurável, sem criar exceções silenciosas para dívida nova.

`no-duplicate-imports` foi medido na introdução do baseline mas não é gate inicial: os duplicados encontrados são dívida de higiene, enquanto Rules of Hooks, código inalcançável e `debugger` são tratados como risco comportamental. A expansão futura pode apertar esta regra depois da limpeza dedicada.

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

Matriz obrigatória:

| Projeto | Motor | Perfil |
|---|---|---|
| `chromium-desktop` | Chromium | Desktop Chrome |
| `firefox-desktop` | Firefox | Desktop Firefox |
| `webkit-desktop` | WebKit | Desktop Safari |
| `chromium-mobile` | Chromium | Pixel 7 |
| `webkit-mobile` | WebKit | iPhone 14 |

Contrato público inicial em `/login`:

- página carrega e expõe os controlos de autenticação;
- não existe overflow horizontal no viewport testado;
- axe não encontra violações `serious` ou `critical` nas regras WCAG A/AA configuradas.

### H1.18 — sessão autenticada e navegação base

O mesmo job Playwright passou a validar uma aplicação Laravel autenticada real com fixtures determinísticas exclusivamente de `testing`:

- cinco utilizadores administrativos isolados, um por projeto Playwright;
- idade, estado, permissões de plataforma e restantes atributos relevantes fixos, evitando falsos alertas provocados por factories aleatórias;
- tentativa de acesso a `/dashboard` sem sessão e retorno ao destino pretendido após login válido;
- credenciais inválidas não criam sessão autenticada;
- logout invalida efetivamente o acesso protegido;
- pedido de recuperação de password para a fixture E2E;
- Dashboard autenticado sem overflow horizontal;
- Dashboard autenticado sem findings axe `serious`/`critical` WCAG A/AA;
- navegação real pelo menu para `Membros`, `Desportivo`, `Eventos`, `Financeiro` e `Configurações`;
- cada destino tem de resolver a URL esperada, renderizar a área principal sem `Server Error` e permanecer sem overflow horizontal;
- o contrato é executado nos cinco projetos desktop/mobile acima.

O primeiro gate autenticado revelou uma dívida real de contraste: branco sobre o azul histórico `#007BFF` não atingia 4,5:1. O token primário/sidebar foi corrigido para `#0066CC`, mantendo a identidade visual azul e cumprindo AA; a regra axe não foi silenciada nem rebaixada.

A matriz corre em ambiente Laravel real com SQLite efémero e assets Vite construídos. Não usa mocks do browser para substituir o fluxo HTTP.

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

A CI #894 validou a expansão H1.18 no head `f083fe74258eec42e443e81fac1169e69269fe9c`: backend, TypeScript 0/0, lint, component tests, build, PostgreSQL concurrency e toda a matriz Playwright autenticada ficaram verdes.

## Próximas expansões

Sem enfraquecer este baseline:

1. Dashboard por perfil e permissões não-admin;
2. Portal atleta/família mobile-first;
3. workspaces/tabs e operações críticas de Membros;
4. Financeiro crítico;
5. Desportivo Planeamento/Treinos/Cais/Live;
6. Eventos e convocatórias;
7. Website público/construtor;
8. acessibilidade de componentes complexos e modais;
9. tablet e viewports intermédios;
10. scroll, drawers e navegação profunda em workspaces mobile.

A cobertura deve crescer por risco operacional e por fluxo de negócio, não por contagem artificial de testes.
