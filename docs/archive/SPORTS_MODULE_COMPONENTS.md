# Sports Module Components - Implementation Complete

## Overview
Successfully migrated all 7 Sports module components from Spark to the Laravel Inertia implementation.

## Components Created

### 1. **SportsTab** (Main Container)
**Path:** `/resources/js/Components/Members/Tabs/SportsTab.tsx`

Main container with nested tabs system featuring:
- 6 sub-tabs: Dados Desportivos, Convocatórias, Registo Presenças, Resultados, Treinos, Planeamento
- Athlete type validation (only shows for tipo_membro = 'atleta')
- Responsive horizontal scrolling tabs
- Navigation context support for cross-module navigation

**Props:**
```typescript
{
  user: User;
  onChange: (field: keyof User, value: any) => void;
  isAdmin: boolean;
  onNavigate?: (view: string, context?: NavigationContext) => void;
}
```

---

### 2. **DadosDesportivosTab**
**Path:** `/resources/js/Components/Members/Tabs/Sports/DadosDesportivosTab.tsx`

Comprehensive sports data form with:

**Federation Data:**
- `ativo_desportivo` - Switch for active status
- `num_federacao` - Federation number input
- `numero_pmb` - PMB number input
- `escalao` - Age group dropdown (from settings-age-groups)

**Documents:**
- `cartao_federacao` - Federation card image upload (image only, 5MB max)
  - Preview thumbnail (click to enlarge)
  - Print functionality
- `data_inscricao` - Date picker for inscription date
- `inscricao` - File upload for inscription document

**Medical Certificate:**
- `data_atestado_medico` - Date picker
- `arquivo_atestado_medico` - Multi-file upload (PDF, DOC, DOCX, images)
- `informacoes_medicas` - Textarea for medical notes

**Features:**
- Card preview dialog with full-size image
- Print card functionality (opens in new window)
- Compact responsive grid layout
- Admin-only edit permissions

---

### 3. **ConvocatoriasTab**
**Path:** `/resources/js/Components/Members/Tabs/Sports/ConvocatoriasTab.tsx`

Read-only table of athlete's event convocations:

**Data Sources:**
- `club-convocatorias-atleta` - Athlete convocations
- `club-convocatorias-grupo` - Group convocations
- `club-events` - Events data
- `settings-provas` - Available events/provas

**Columns:**
- Evento - Event title
- Data - Event date (dd/MM/yyyy)
- Estado - Status badge (color-coded)
- Provas - Event types (max 2 shown, +N for more)

**Status Badges:**
- Concluído: Green (bg-green-50 text-green-700)
- Em Curso: Blue (bg-blue-50 text-blue-700)
- Agendado: Orange (bg-orange-50 text-orange-700)
- Cancelado: Red (bg-red-50 text-red-700)
- Rascunho: Gray (outline)

**Features:**
- Click row to navigate to event (if onNavigate provided)
- Sorted by date (newest first)
- Filtered by athlete ID
- ScrollArea with 400px height

---

### 4. **RegistoPresencasTab**
**Path:** `/resources/js/Components/Members/Tabs/Sports/RegistoPresencasTab.tsx`

Read-only attendance records table:

**Data Sources:**
- `club-presencas` - Attendance records
- `club-events` - Events data

**Columns:**
- Evento - Event title
- Data - Event date (dd/MM/yyyy)
- Estado - Attendance status badge
- Hora Chegada - Arrival time
- Observações - Notes

**Status Badges:**
- Presente: Green (bg-green-50 text-green-700)
- Justificado: Amber (bg-amber-50 text-amber-700)
- Ausente: Red (bg-red-50 text-red-700)

**Features:**
- Sorted by date (newest first)
- Filtered by athlete ID
- Empty state message when no records

---

### 5. **ResultadosTab**
**Path:** `/resources/js/Components/Members/Tabs/Sports/ResultadosTab.tsx`

Competition results management:

**Data Sources:**
- `club-resultados-provas` - Results data (read/write)
- `club-events` - Events for selection
- `settings-provas` - Event types

**Table Columns:**
- Evento - Event name
- Local - Location
- Data - Date (dd/MM/yyyy)
- Prova - Event type
- Piscina - Pool type badge (25m/50m/Águas Abertas)
- Tempo - Final time
- Ações - Edit/Delete buttons (admin only)

**Add/Edit Dialog Fields:**
- Nome do Evento - Text input with datalist autocomplete
- Ou selecione da lista - Dropdown of existing events
  - Auto-fills: local, data, piscina from selected event
- Prova * - Required dropdown (from settings-provas)
- Local * - Required text input
- Data * - Required date input
- Tipo de Piscina * - Required dropdown (25m/50m/Águas Abertas)
- Tempo Final * - Required text input (format: 01:23.45)

**Features:**
- Admin: Add new results
- Admin: Edit existing results
- Admin: Delete results (with confirmation)
- Manual entry or event selection
- Empty state with Trophy icon
- Sorted by date (newest first)

---

### 6. **TreinosTab**
**Path:** `/resources/js/Components/Members/Tabs/Sports/TreinosTab.tsx`

Placeholder component:
- Construction icon
- "Em desenvolvimento" message
- Simple centered layout

---

### 7. **PlaneamentoTab**
**Path:** `/resources/js/Components/Members/Tabs/Sports/PlaneamentoTab.tsx`

Placeholder component:
- Construction icon
- "Em desenvolvimento" message
- Simple centered layout

---

## Technical Implementation

### Imports & Dependencies
```typescript
// UI Components
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Switch } from '@/Components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Calendar } from '@/Components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';

// Custom Components
import { FileUpload } from '@/Components/FileUpload';

// Hooks & Utilities
import { useKV } from '@/hooks/useKV';
import { format } from 'date-fns';
import { pt } from 'date-fns/locale';
import { toast } from 'sonner';

// Icons
import { Construction, Plus, Pencil, Trash, Trophy, Printer } from 'lucide-react';

// Types
import { User, Event, ConvocatoriaAtleta, ConvocatoriaGrupo, EventoPresenca, ResultadoProva } from '@/types';
```

### Styling Convention (Preserved from Spark)

**Compact Sizing:**
- `text-xs` - All labels and regular text
- `h-7` - Buttons and smaller inputs
- `h-8` - Standard inputs
- `h-9` - Tab list height

**Spacing:**
- `space-y-2` - Between major sections
- `space-y-3` - Within forms
- `gap-2` - Grid gaps
- `p-2` - Small padding

**Tables:**
- `ScrollArea` with `h-[400px]` for consistent height
- `text-xs` for all table content
- `font-medium` for primary column

**Colors (Status Badges):**
```css
/* Success/Active */
bg-green-50 text-green-700 border-green-200

/* Info/In Progress */
bg-blue-50 text-blue-700 border-blue-200

/* Warning/Scheduled */
bg-orange-50 text-orange-700 border-orange-200

/* Danger/Cancelled */
bg-red-50 text-red-700 border-red-200

/* Justified */
bg-amber-50 text-amber-700 border-amber-200
```

---

## KV Storage Keys Used

### Settings:
- `settings-age-groups` - Age groups/escalões configuration
- `settings-provas` - Event types/provas list

### Club Data:
- `club-events` - All events
- `club-convocatorias-atleta` - Individual athlete convocations
- `club-convocatorias-grupo` - Group convocations
- `club-presencas` - Attendance records
- `club-resultados-provas` - Competition results

---

## Integration Notes

### User Type Fields Required:
```typescript
interface User {
  // Personal
  id: string;
  nome_completo: string;
  tipo_membro: string[];
  
  // Sports Data
  ativo_desportivo?: boolean;
  num_federacao?: string;
  numero_pmb?: string;
  escalao?: string[];
  cartao_federacao?: string;
  data_inscricao?: string;
  inscricao?: string;
  data_atestado_medico?: string;
  arquivo_atestado_medico?: string[];
  informacoes_medicas?: string;
}
```

### onChange Callback:
```typescript
onChange: (field: keyof User, value: any) => void
```
Called whenever a field is updated in DadosDesportivosTab.

### onNavigate Callback:
```typescript
onNavigate?: (view: string, context?: NavigationContext) => void

interface NavigationContext {
  eventId?: string;
  convocatoriaId?: string;
  tab?: string;
}
```
Called when clicking on convocations to navigate to event details.

---

## File Structure
```
resources/js/Components/Members/Tabs/
├── SportsTab.tsx                    # Main container (86 lines)
└── Sports/
    ├── DadosDesportivosTab.tsx      # Sports data form (257 lines)
    ├── ConvocatoriasTab.tsx         # Convocations table (124 lines)
    ├── RegistoPresencasTab.tsx      # Attendance records (86 lines)
    ├── ResultadosTab.tsx            # Results management (381 lines)
    ├── TreinosTab.tsx               # Training placeholder (18 lines)
    └── PlaneamentoTab.tsx           # Planning placeholder (18 lines)
```

**Total:** 970 lines across 7 components

---

## Features Summary

✓ Nested tabs system with 6 sub-tabs
✓ Athlete type validation
✓ Federation data management
✓ Image upload with preview and print
✓ Multi-file upload support
✓ Date pickers with Portuguese locale
✓ Read-only tables for convocations and attendance
✓ Full CRUD for competition results (admin)
✓ Color-coded status badges
✓ Responsive layouts with ScrollArea
✓ Empty state messages
✓ Toast notifications
✓ Navigation support
✓ Compact Spark UI/UX preserved

---

## Status
✅ All 7 components created
✅ Exact Spark UI/UX preserved
✅ All imports updated for Laravel structure
✅ Props and callbacks defined
✅ KV storage integration
✅ Ready for integration testing
