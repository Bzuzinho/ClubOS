# Pull Request — ClubOS

## 1. Resumo

Descreve de forma objetiva o que este PR altera.

-
-
-

---

## 2. Módulos afetados

Assinalar os módulos afetados:

- [ ] Base técnica / arquitetura
- [ ] Autenticação / permissões
- [ ] Dashboard
- [ ] Portal atleta / família
- [ ] Membros
- [ ] Desportivo
- [ ] Eventos
- [ ] Financeiro
- [ ] Banco / conciliação
- [ ] Importação de recibos
- [ ] Fiscal / Wintouch / emissão documental
- [ ] Logística / inventário
- [ ] Loja
- [ ] Comunicação
- [ ] Marketing
- [ ] Configurações
- [ ] Patrocínios
- [ ] Relatórios
- [ ] PWA / mobile
- [ ] Testes / qualidade

---

## 3. Impacto funcional

Explica se este PR:

- cria nova funcionalidade;
- altera uma funcionalidade existente;
- corrige um bug;
- remove fluxo legado;
- altera modelo de dados;
- altera permissões;
- altera UI com impacto operacional.

Descrição:

-

---

## 4. Documento vivo

Fonte de verdade:

```txt
docs/ESTADO_VIVO_DESENVOLVIMENTO.md
```

- [ ] Consultei o documento vivo antes de desenvolver.
- [ ] Atualizei o documento vivo porque houve impacto funcional/técnico relevante.
- [ ] Não atualizei o documento vivo porque a alteração não tem impacto funcional/técnico relevante.

Se não foi atualizado, justificar:

-

Se foi atualizado, indicar secções alteradas:

- [ ] Estado global
- [ ] Grelha viva de funcionalidades
- [ ] Riscos técnicos vivos
- [ ] Prioridades recomendadas
- [ ] Histórico vivo de atualizações

---

## 5. Evidência técnica

Ficheiros principais alterados:

-
-
-

Rotas / controllers / services / models / migrations / pages React relevantes:

-

---

## 6. Validações executadas

Assinalar o que foi executado:

- [ ] `composer dump-autoload`
- [ ] `php artisan migrate --pretend`
- [ ] `php artisan test`
- [ ] `npm run build`
- [ ] Validação manual no browser
- [ ] Não executei validações

Notas sobre validações:

-

---

## 7. Riscos e pendências

Indicar riscos, decisões técnicas ou pendências que ficam abertas.

-
-
-

---

## 8. Percentagem de desenvolvimento

Se aplicável, indicar alteração de percentagem estimada:

| Módulo | Antes | Depois | Justificação |
|---|---:|---:|---|
|  |  |  |  |

---

## 9. Checklist final

- [ ] O PR não duplica fluxos de negócio existentes sem justificação.
- [ ] O PR respeita a arquitetura atual do ClubOS.
- [ ] O PR respeita as regras de permissões existentes.
- [ ] O PR não quebra o portal mobile/atleta/família.
- [ ] O PR não cria nova fonte de verdade financeira paralela.
- [ ] O impacto no documento vivo foi tratado.
