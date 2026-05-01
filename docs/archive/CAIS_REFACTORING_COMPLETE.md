# Refatoração do Tab Cais - Documentação Completa

## 🎯 Objetivo

Refatorar o módulo Tab Cais para permitir múltiplos treinos agendados simultâneos em um layout responsivo com cards interativos, proporcionando uma experiência otimizada para treinadores durante as sessões de treino.

## ✨ Novos Componentes Criados

### 1. **CaisTrainingList.tsx**
**Localização**: `/resources/js/Components/Desportivo/components/cais/CaisTrainingList.tsx`

**Responsabilidade**: Barra lateral com lista de treinos agendados para seleção múltipla.

**Features**:
- ✅ Seleção múltipla com checkboxes
- ✅ Separação de "Hoje" e "Próximos" treinos
- ✅ Ordenação por data e hora
- ✅ Exibição de volume planeado (m)
- ✅ Sticky position para facilitar scrolling
- ✅ Máximo de 4-5 treinos visíveis simultaneamente

**Props**:
```typescript
interface Props {
  trainings: Training[];
  selectedTrainingIds: string[];
  onToggleTraining: (id: string) => void;
}
```

---

### 2. **CaisPresencesGroup.tsx**
**Localização**: `/resources/js/Components/Desportivo/components/cais/CaisPresencesGroup.tsx`

**Responsabilidade**: Card minimizado/expandível com grupo de presenças dos atletas.

**Features**:
- ✅ Expandir/colapsar presencas
- ✅ Contador visual (P/A/D) no header minimizado
- ✅ Ícones coloridos: P (verde), A (vermelho), D (amarelo)
- ✅ Adicionar atletas via Select dropdown
- ✅ Remover atletas com botão Trash
- ✅ Ciclar entre estados: P → A → D → P
- ✅ Scroll interno para muitos atletas
- ✅ Filtro automático de atletas já adicionados

**Props**:
```typescript
interface Props {
  training_id: string;
  presences: PresenceGroup[];
  athletes: User[];
  onAddAthlete: (athlete_id: string) => void;
  onRemoveAthlete: (presence_id: string) => void;
  onUpdatePresence: (user_id: string, status: PresenceState) => void;
}
```

**Estados de Presença**:
- `P` (Presente) - Verde (#10b981)
- `A` (Ausente) - Vermelho (#ef4444)
- `D` (Dispensado) - Amarelo (#eab308)

---

### 3. **CaisTrainingCard.tsx**
**Localização**: `/resources/js/Components/Desportivo/components/cais/CaisTrainingCard.tsx`

**Responsabilidade**: Card principal para um treino agendado com layout em 3 seções internas.

**Estrutura Interna**:

1. **Header** (Fechar + Título)
   - Botão X no canto superior direito
   - Display: `#0001 · Natação`

2. **Card 1: Resumo do Treino**
   - Data (YYYY-MM-DD)
   - Hora início/fim
   - Local
   - Escalões (badges)
   - Volume planeado

3. **Card 2: Séries e Distância**
   - Lista de séries com scroll
   - Descrição, distância, repetições, estilo
   - Total de distância em badge
   - Observações das séries

4. **Card 3: Presencas** (CaisPresencesGroup)
   - Expansível/colapsável
   - Gerenciamento de atletas
   - Status visual

**Props**:
```typescript
interface Props {
  training: Training;
  athletes: User[];
  presences: PresenceGroup[];
  onClose: () => void;
  onAddAthlete: (athlete_id: string) => void;
  onRemoveAthlete: (presence_id: string) => void;
  onUpdatePresence: (user_id: string, status: PresenceState) => void;
}
```

---

## 🔄 Componentes Refatorados

### **CaisTab.tsx** (Refatorado)
**Localização**: `/resources/js/Components/Desportivo/CaisTab.tsx`

**Mudanças**:
- ❌ Removido: Seleção única de treino
- ❌ Removido: `CaisTrainingSelector` (seletor dropdown)
- ❌ Removido: `CaisTrainingSummary` (componente separado)
- ❌ Removido: `CaisAthleteAttendanceList` (componente separado)
- ✅ Adicionado: Seleção múltipla via checkboxes
- ✅ Adicionado: Grid responsivo com cards
- ✅ Refatorado: Lógica de presencas integrada ao Training.presencas_grupo

**Nova Prop**:
```typescript
interface Props {
  trainings: TrainingWithEscaloes[];
  trainingOptions: any[];
  selectedTraining: TrainingWithEscaloes | null;
  users: User[];
  ageGroups: AgeGroup[];
  // Removido: presences (usar training.presencas_grupo agora)
}
```

---

## 📐 Layout Responsivo

### Grid Breakpoints

| Viewport | Colunas | Layout |
|----------|---------|--------|
| Mobile (< 768px) | 1 | Cards empilhados verticamente |
| Tablet (768px - 1024px) | 2 | 2 colunas lado a lado |
| Desktop (> 1024px) | 3 | 3 colunas máximo |

### Estrutura

```
┌─────────────────────────────────────────────────────┐
│  CaisTab (Grid: 4 colunas → 1 col sidebar + 3 cards) │
├──────────────┬──────────────────────────────────────┤
│CaisTraining  │        Cards Grid (Auto-rows-max)    │
│List          │                                       │
│              │  ┌────────────┐  ┌────────────┐      │
│ Hoje:        │  │  Card 1    │  │  Card 2    │      │
│ □ #0001      │  │            │  │            │      │
│ □ #0002      │  │ Resumo     │  │ Resumo     │      │
│              │  │ Séries     │  │ Séries     │      │
│ Próximos:    │  │ Presencas  │  │ Presencas  │      │
│ □ #0003      │  │            │  │            │      │
│              │  └────────────┘  └────────────┘      │
└──────────────┴──────────────────────────────────────┘
```

---

## 🔌 Integração com Backend

### APIs Utilizadas

1. **Atualizar Presença**
   ```
   PUT /desportivo/treinos/{training}/presencas
   ```

2. **Adicionar Atleta**
   ```
   POST /desportivo/treinos/{training}/atletas
   ```

3. **Remover Atleta**
   ```
   DELETE /desportivo/treinos/{training}/atletas/{user}
   ```

### Dados Esperados

**Training.presencas_grupo**:
```typescript
presencas_grupo?: Array<{
  id: string;
  user_id: string;
  nome_atleta: string;
  estado: string;  // 'presente' | 'ausente' | 'justificado' | etc.
}>;
```

---

## 🎨 Estilos e Componentes UI

### Shadcn Components Utilizados

- ✅ `Card` - Containers principais
- ✅ `Button` - Ações (fechar, adicionar)
- ✅ `Select` - Dropdown de atletas
- ✅ `Checkbox` - Seleção múltipla
- ✅ `Badge` - Labels (status, volume)
- ✅ `Collapsible` - Expansão de presencas (via CSS)
- ✅ Ícones `lucide-react` (X, ChevronDown, Plus, Trash2)

### Tailwind Classes

- `grid-cols-1 md:grid-cols-2 lg:grid-cols-3` - Responsividade
- `auto-rows-max` - Cards altura dinâmica
- `bg-green-100 text-green-800` - Status Presente
- `bg-red-100 text-red-800` - Status Ausente
- `bg-yellow-100 text-yellow-800` - Status Dispensado
- `sticky top-0` - Sidebar fixa durante scroll

---

## 📋 Fluxo de Uso

### 1. Seleção de Treinos
```
1. Treinador abre o Tab Cais
2. Vê lista na lateral com treinos de hoje vs próximos
3. Clica nos checkboxes para selecionar 1 a 3 treinos
4. Cards aparecem lado a lado no grid
```

### 2. Gerenciamento de Presencas
```
1. Card exibe resumo do treino (date, time, local, escalão)
2. Mostra séries com distância total em badge
3. Card de presencas começa colapsado com contadores P/A/D
4. Clica para expandir
5. Adiciona atletas via select dropdown
6. Clica nos ícones P/A/D para ciclar status
7. Remove atletas com Trash icon
```

### 3. Fechamento de Card
```
1. Clica no X no canto superior direito do card
2. Card desaparece imediatamente
3. Treino é removido do grid
```

---

## 🔧 Tipos TypeScript

```typescript
type PresenceState = 'presente' | 'ausente' | 'dispensado';

interface PresenceGroup {
  id: string;
  user_id: string;
  nome_atleta: string;
  estado: string;
}

interface TrainingWithEscaloes extends Training {
  escaloes?: string[] | null;
}
```

---

## 📦 Arquivos Modificados

| Arquivo | Mudança | Status |
|---------|---------|--------|
| **CaisTab.tsx** | Refatorado completo | ✅ |
| **CaisTrainingList.tsx** | Novo componente | ✅ |
| **CaisPresencesGroup.tsx** | Novo componente | ✅ |
| **CaisTrainingCard.tsx** | Novo componente | ✅ |
| **Desportivo/Index.tsx** | Props atualizado | ✅ |

---

## 🎯 Funcionalidades Implementadas

### ✅ Implementado
- [x] Seleção múltipla de treinos via checkboxes
- [x] Grid responsivo (1/2/3 colunas)
- [x] 3 cards internos por treino (resumo, séries, presencas)
- [x] Botão X para fechar cards
- [x] Grupo de presencas expandível/colapsável
- [x] Ícones coloridos (P verde, A vermelho, D amarelo)
- [x] Adicionar atletas via dropdown
- [x] Remover atletas com Trash icon
- [x] Ciclar status ao clicar ícone
- [x] Contadores P/A/D no header colapsado
- [x] Ordenação de treinos (hoje primeiro)
- [x] Volume planeado visível na lista
- [x] Distância total exibida nas séries
- [x] TypeScript validation sem erros

### 🚀 Próximas Melhorias (Opcional)
- [ ] Toast notificações ao adicionar/remover/atualizar
- [ ] Animações de entrada/saída de cards
- [ ] Teclado: ESC para fechar, setas para navegar
- [ ] Drag & drop para reordená-los
- [ ] Sincronização em tempo real (WebSockets)
- [ ] Export presencas para PDF/Excel
- [ ] Duplicar treino para próxima sessão

---

## 📝 Notas Importantes

1. **Presencas Agora no Training**: O novo design usa `training.presencas_grupo` diretamente, removendo a necessidade de prop global `presences`.

2. **Máximo de 3 Colunas**: O layout é responsivo mas limita a 3 colunas para não ficar apertado.

3. **Auto-refresh**: Cada ação (adicionar/remover/atualizar) recarrega via `router.put/post/delete` com `preserveState`.

4. **Escalões Filtrados**: Apenas atletas com escalões compatíveis aparecem no dropdown de adicionar.

5. **Estado Persistente**: Grid mantém posição durante updates, melhorando UX.

---

## 🧪 Como Testar

1. Navigate to Desportivo → Cais
2. Verify training list loads on the left
3. Click checkboxes to select trainings
4. Verify cards appear in 1/2/3 column grid based on screen size
5. Click card header to see collapse/expand in presencas
6. Add athlete from dropdown
7. Click P/A/D to cycle status
8. Click trash to remove athlete
9. Click X to close card
10. Verify responsive behavior by resizing browser

---

**Refatoração: 2026-03-17**
**Status: ✅ Completo e Validado**
