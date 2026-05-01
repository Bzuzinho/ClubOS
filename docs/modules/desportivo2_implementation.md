# Desportivo 2 — Documentação Técnica de Implementação

**Data:** 11 de Março de 2026  
**Status:** Implementação base completa — coexiste com módulo Desportivo clássico  
**Versão:** 1.0

---

## 1. Objetivo

Criar um módulo "Desportivo 2" (`/desportivo-2`) que coexiste com o módulo Desportivo clássico (`/desportivo`), tecnicamente mais sólido, com:
- Presença sempre vinculada a treino agendado
- Modo Cais para operação rápida no deck da piscina
- Planeamento de época/macrociclos/microciclos
- Gestão científica de performance (ACWR, RPE, carga aguda/crónica)
- Reutilização máxima das fontes de dados existentes

---

## 2. Ficheiros Criados

### Componentes — `resources/js/Components/Desportivo2/`

| Ficheiro | Descrição |
|---|---|
| `types.ts` | Tipos TypeScript do módulo (reutilizados e estendidos do módulo clássico) |
| `index.ts` | Barrel export de todos os componentes |
| `Desportivo2DashboardTab.tsx` | Dashboard técnico: stats, alertas, competições próximas |
| `Desportivo2GruposTab.tsx` | Grupos/escalões com contagem de atletas e aptidão médica |
| `Desportivo2TreinosTab.tsx` | Gestão de treinos + agenda semanal + biblioteca de modelos (useKV) |
| `Desportivo2PresencasTab.tsx` | **Presenças vinculadas a treino + Modo Cais** |
| `Desportivo2PlaneamentoTab.tsx` | Planeamento: época, macrociclos, microciclos (misto backend + useKV) |
| `Desportivo2CompeticoesTab.tsx` | Competições: lista + vista por mês |
| `Desportivo2ResultadosTab.tsx` | Resultados por evento + melhor classificação por atleta |
| `Desportivo2PerformanceTab.tsx` | Performance: volume real (presences) + métricas científicas (useKV) |

### Página Principal — `resources/js/Pages/Desportivo2/`

| Ficheiro | Descrição |
|---|---|
| `Index.tsx` | Página principal com 8 tabs, recebe props do `renderSportsPage()` |

---

## 3. Ficheiros Alterados

| Ficheiro | Alteração | Impacto |
|---|---|---|
| `resources/js/Layouts/AuthenticatedLayout.tsx` | Adicionado item "Desportivo 2" na `mainMenuItems` | Navegação visível; módulo clássico **não removido** |

---

## 4. Backend: Rotas e Controller

### Rotas criadas — `routes/web.php`

```php
Route::prefix('desportivo-2')->group(function () {
    Route::get('/', [DesportivoController::class, 'indexV2'])
        ->name('desportivo2.index');
    Route::get('presencas', [DesportivoController::class, 'presencasV2'])
        ->name('desportivo2.presencas');
});
```

### Métodos criados — `app/Http/Controllers/DesportivoController.php`

```php
public function indexV2(): Response
{
    return $this->renderSportsPage('dashboard', 'Desportivo2/Index');
}

public function presencasV2(): Response
{
    return $this->renderSportsPage('presencas', 'Desportivo2/Index');
}
```

Ambos delegam para `renderSportsPage()` existente — reutilizam toda a lógica de dados sem duplicação.

---

## 5. Stores / Tabelas Reutilizadas

O Desportivo 2 **não cria tabelas novas** no backend. Usa exclusivamente as fontes existentes:

| Fonte | Uso |
|---|---|
| `trainings` | Lista de treinos, agenda, seleção de treino para presenças |
| `training_athletes` | Presenças de treinos (fonte de verdade master, pós-refactor) |
| `presences` (legacy) | Compatibilidade retroativa; dual-write mantido |
| `seasons` | Planeamento: lista de épocas |
| `macrocycles` | Planeamento: macrociclos por época |
| `age_groups` | Grupos/escalões, filtragem de atletas elegíveis |
| `events` (tipo='prova') | Competições |
| `event_results` | Resultados de competições |
| `users` | Atletas, filtragem por tipo_membro + estado + escalão |
| `athlete_status_configs` | Opções de status para presenças (dinâmico via Configurações) |

---

## 6. Chaves `useKV` Novas

Criadas apenas para dados que não existem em backend:

| Chave | Tipo | Descrição |
|---|---|---|
| `sports-v2-training-templates` | `TrainingTemplate[]` | Biblioteca local de modelos de treino |
| `sports-v2-seasons` | `V2SeasonPlan[]` | Planos de época avançados (até integração BD) |
| `sports-v2-microcycles` | `V2MicrocyclePlan[]` | Microciclos avançados (até integração BD) |
| `sports-v2-performance-metrics` | `PerformanceMetric[]` | Métricas científicas por atleta (até tabela BD dedicada) |

---

## 7. Integração com Backend Existente

### Presenças (Modo Cais e Normal)

Reutiliza as rotas existentes sem criar novas:

```
PUT  /desportivo/presencas              → desportivo.presencas.update      (batch)
POST /desportivo/presencas/marcar-presentes → desportivo.presencas.mark-all-present
```

**Status válidos** (conforme validação do controller):
`presente | ausente | justificado | atestado_medico | outro | lesionado | limitado | doente`

**Modo Cais mapeia:**
- P → `presente`
- A → `ausente`
- J → `justificado`
- D → `doente`

### Treinos

Reutiliza rotas existentes:
```
POST /desportivo/treinos            → desportivo.treino.store
POST /desportivo/treinos/{id}/duplicar → desportivo.treino.duplicate
```

### Competições

Linka para módulo de Eventos:
```
GET /eventos        → eventos.index
GET /eventos/{id}   → eventos.show
```

---

## 8. Regra Crítica de Presença

```
1. Utilizador seleciona treino agendado (dropdown)
2. Sistema filtra atletas por escalão do treino
3. Sistema carrega training_athletes do treino selecionado
4. Atleta que não tem training_athlete aparece como "pendente" (desabilitado)
5. No Modo Cais: 4 botões ultracompactos P/A/J/D por atleta
6. Treinos simultâneos: chips de alternância rápida (treinos do dia de hoje)
7. Mudança de treino: router.get('desportivo2.presencas', { training_id })
```

---

## 9. Modo Cais — UX

```
┌─ Selector de treino ──────────────────────┐
│ [2026-03-11 · Treino 45    ▼]  [⚓ Modo Cais] │
└───────────────────────────────────────────┘

Treinos hoje: [Treino 45] [Treino 46]

╔═ Info do treino ══════════════════════════╗
║ Treino 45 · Técnico                       ║
║ 2026-03-11 18:00 · Piscina 25m · Juv/Sen  ║
║         [P 12] [A 3] [J 1] [D 0]  15/16  ║
╚═══════════════════════════════════════════╝

╔═ Modo Cais — registro rápido ═════════════╗
║ Todos: [P] [A] [J] [D]                    ║
║ ─────────────────────────────────────     ║
║ João Silva        [ P ][ A ][ J ][ D ]    ║
║ Maria Costa       [ P ][ A ][ J ][ D ]    ║
║ Ana Ferreira      [ P ][ A ][ J ][ D ]    ║
╚═══════════════════════════════════════════╝
```

---

## 10. Pontos de Integração com Outros Módulos

| Módulo | Integração |
|---|---|
| **Membros** | Atletas via `users` + `dados-desportivos`; filtro por `tipo_membro`, `estado`, `escalao` |
| **Eventos** | Competições = events(tipo='prova'); resultados = event_results; link direto para Eventos |
| **Configurações** | `statusOptions` do catálogo `athlete_status_configs` (Configurações > Desportivo) |
| **Dashboard global** | Stats passados via `renderSportsPage` — prontos para consumo futuro |

---

## 11. Passos Futuros para Substituição Segura do Módulo Antigo

### Fase A — Validação (fazer antes de remover o antigo)
1. Testar Modo Cais em tablet e mobile real no deck da piscina
2. Testar todos os fluxos de presença com dados reais (treino selecionado → atletas carregados → marcar → guardar)
3. Validar que `statusOptions` do catálogo aparecem corretamente
4. Testar mudança rápida entre treinos simultâneos

### Fase B — Migrar dados useKV para BD
- `sports-v2-training-templates` → tabela `training_templates`
- `sports-v2-seasons` → integrar em tabela `seasons` com campos extra
- `sports-v2-microcycles` → integrar em tabela `microcycles`
- `sports-v2-performance-metrics` → nova tabela `performance_metrics`

### Fase C — Remover módulo antigo (só após validação completa)
O que pode ser removido:
```
resources/js/Pages/Desportivo/Index.tsx          ← substituído por Desportivo2/Index.tsx
resources/js/Components/Desportivo/*.tsx          ← substituídos por Components/Desportivo2/*
routes/web.php: Route::prefix('desportivo')...   ← manter até migração completa
Desportivo/Index na navegação (AuthenticatedLayout) ← remover entry "Desportivo" clássico
```

O que NUNCA remover (infraestrutura partilhada):
- `app/Http/Controllers/DesportivoController.php` — `renderSportsPage()` é usado por ambos
- `app/Services/Desportivo/*` — lógica de domínio partilhada
- Migrations e Models (trainings, training_athletes, etc.)
- Rotas CRUD de treinos/épocas (usadas por Desportivo 2 também)

---

## 12. Arquitetura de Componentes

```
Pages/Desportivo2/Index.tsx
│
├── Props (de renderSportsPage via Inertia)
│   ├── stats, alerts, upcomingCompetitions
│   ├── seasons, macrocycles, ageGroups
│   ├── trainings, trainingOptions, selectedTraining
│   ├── presences, users, statusOptions
│   ├── competitions, results
│   └── volumeByAthlete
│
└── Components/Desportivo2/
    ├── Desportivo2DashboardTab    ← stats + alertas + competições próximas
    ├── Desportivo2GruposTab       ← escalões + atletas + aptidão médica
    ├── Desportivo2TreinosTab      ← agenda + criar treino + modelos (useKV)
    ├── Desportivo2PresencasTab    ← MODO CAIS + modo normal
    │   ├── Selector de treino
    │   ├── Alternância treinos simultâneos
    │   ├── Modo Cais (P/A/J/D ultracompacto)
    │   └── Modo Normal (botões + volume + notas)
    ├── Desportivo2PlaneamentoTab  ← época + macrociclos + microciclos
    ├── Desportivo2CompeticoesTab  ← lista + vista mês
    ├── Desportivo2ResultadosTab   ← resultados por evento + pódio
    └── Desportivo2PerformanceTab  ← volume real + métricas científicas (useKV)
```

---

**Módulo clássico:** `/desportivo` — **intacto, sem alterações destrutivas**  
**Módulo novo:** `/desportivo-2` — **funcional e pronto para validação**
