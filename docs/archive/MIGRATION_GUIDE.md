# Guia de Migração: useKV → API Laravel

Este guia mostra como migrar componentes que usam `useKV` do Spark para os novos hooks que consomem a API Laravel.

## Índice
1. [Visão Geral](#visão-geral)
2. [Migração Básica](#migração-básica)
3. [Exemplos Específicos](#exemplos-específicos)
4. [Padrões Comuns](#padrões-comuns)
5. [Troubleshooting](#troubleshooting)

---

## Visão Geral

### Antes (Spark useKV)
```typescript
import { useKV } from '@github/spark/hooks';

const [users, setUsers] = useKV<User[]>('club-users', []);
```

### Depois (Laravel API)
```typescript
import { useUsers, useCreateUser } from '@/hooks';

const { data: users = [], isLoading } = useUsers();
const createUser = useCreateUser();
```

### Vantagens da Nova Abordagem
- ✅ **Persistência Real**: Dados salvos no SQLite, não no browser
- ✅ **Multi-user**: Suporta múltiplos usuários simultâneos
- ✅ **Cache Inteligente**: React Query gerencia cache automaticamente
- ✅ **Optimistic Updates**: UI atualiza instantaneamente
- ✅ **Type Safety**: TypeScript completo em todos os hooks
- ✅ **Loading States**: Estados de carregamento automáticos
- ✅ **Error Handling**: Tratamento de erros robusto

---

## Migração Básica

### 1. Key-Value Genérico (Mantém Compatibilidade)

Se você quer manter a API `useKV` mas com backend Laravel:

**ANTES:**
```typescript
import { useKV } from '@github/spark/hooks';

const [settings, setSettings] = useKV<Settings>('app-settings', defaultSettings);
```

**DEPOIS:**
```typescript
import { useKV } from '@/hooks/useKV';

const [settings, setSettings] = useKV<Settings>('app-settings', defaultSettings);
// API idêntica, mas agora salva no Laravel!
```

---

### 2. Recursos com Hooks Dedicados

Para recursos principais (Users, Events, etc), use hooks específicos:

#### Users

**ANTES:**
```typescript
const [users, setUsers] = useKV<User[]>('club-users', []);

// Adicionar user
const handleAddUser = (newUser: User) => {
  setUsers(prev => [...prev, newUser]);
};

// Atualizar user
const handleUpdateUser = (id: string, updates: Partial<User>) => {
  setUsers(prev => prev.map(u => u.id === id ? { ...u, ...updates } : u));
};

// Deletar user
const handleDeleteUser = (id: string) => {
  setUsers(prev => prev.filter(u => u.id !== id));
};
```

**DEPOIS:**
```typescript
import { useUsers, useCreateUser, useUpdateUser, useDeleteUser } from '@/hooks';

const { data: users = [], isLoading } = useUsers();
const createUser = useCreateUser();
const updateUser = useUpdateUser();
const deleteUser = useDeleteUser();

// Adicionar user
const handleAddUser = async (newUser: User) => {
  await createUser.mutateAsync(newUser);
  // UI atualiza automaticamente!
};

// Atualizar user
const handleUpdateUser = async (id: string, updates: Partial<User>) => {
  await updateUser.mutateAsync({ id, ...updates });
};

// Deletar user
const handleDeleteUser = async (id: string) => {
  await deleteUser.mutateAsync(id);
};
```

#### Events

**ANTES:**
```typescript
const [events, setEvents] = useKV<Event[]>('club-events', []);
```

**DEPOIS:**
```typescript
import { useEvents, useCreateEvent } from '@/hooks';

const { data: events = [], isLoading } = useEvents();
const createEvent = useCreateEvent();

// Com filtros
const { data: trainings = [] } = useEvents({ type: 'training', status: 'published' });
```

#### Results (Resultados)

**ANTES:**
```typescript
const [results, setResults] = useKV<ResultProva[]>('club-resultados-provas', []);

// Filtrar manualmente
const userResults = results.filter(r => r.athlete_id === user.id);
```

**DEPOIS:**
```typescript
import { useResults, useCreateResult } from '@/hooks';

// Backend já filtra!
const { data: userResults = [] } = useResults({ athlete_id: user.id });
const createResult = useCreateResult();
```

---

## Exemplos Específicos

### Exemplo 1: ResultadosTab Component

**ANTES:**
```typescript
// resources/js/Components/Members/Tabs/Sports/ResultadosTab.tsx
import { useKV } from '@github/spark/hooks';

export function ResultadosTab({ user }: Props) {
  const [resultadosProvas, setResultadosProvas] = useKV<ResultadoProva[]>(
    'club-resultados-provas',
    []
  );
  const [events] = useKV<Event[]>('club-events', []);
  const [provas] = useKV<Prova[]>('settings-provas', []);

  const atletaResultados = useMemo(() => {
    return resultadosProvas
      .filter(r => r.athlete_id === user.id)
      .sort((a, b) => new Date(b.date).getTime() - new Date(a.date).getTime());
  }, [resultadosProvas, user.id]);

  const handleSaveResult = () => {
    const newResult = { ...formData, id: uuid(), athlete_id: user.id };
    setResultadosProvas(prev => [...prev, newResult]);
  };

  const handleDeleteResult = (id: string) => {
    setResultadosProvas(prev => prev.filter(r => r.id !== id));
  };

  return (
    <div>
      {atletaResultados.map(result => (
        <ResultCard key={result.id} result={result} />
      ))}
    </div>
  );
}
```

**DEPOIS:**
```typescript
// resources/js/Components/Members/Tabs/Sports/ResultadosTab.tsx
import { useResults, useCreateResult, useDeleteResult } from '@/hooks/useResults';
import { useEvents } from '@/hooks/useEvents';
import { useProvas } from '@/hooks/useProvas';

export function ResultadosTab({ user }: Props) {
  // Backend já filtra por athlete_id!
  const { data: atletaResultados = [], isLoading } = useResults({ 
    athlete_id: user.id 
  });
  const { data: events = [] } = useEvents();
  const { data: provas = [] } = useProvas();
  
  const createResult = useCreateResult();
  const deleteResult = useDeleteResult();

  const handleSaveResult = async () => {
    await createResult.mutateAsync({
      ...formData,
      athlete_id: user.id,
    });
    toast.success('Resultado salvo!');
  };

  const handleDeleteResult = async (id: string) => {
    await deleteResult.mutateAsync(id);
    toast.success('Resultado deletado!');
  };

  if (isLoading) return <div>Carregando...</div>;

  return (
    <div>
      {atletaResultados.map(result => (
        <ResultCard 
          key={result.id} 
          result={result}
          onDelete={() => handleDeleteResult(result.id)}
        />
      ))}
    </div>
  );
}
```

### Exemplo 2: RegistoPresencasTab Component

**ANTES:**
```typescript
import { useKV } from '@github/spark/hooks';

const [presencas, setPresencas] = useKV<EventoPresenca[]>('club-presencas', []);
const [events] = useKV<Event[]>('club-events', []);

const handleMarkPresence = () => {
  const newPresenca = {
    id: uuid(),
    event_id: selectedEvent,
    user_id: user.id,
    status: 'present',
  };
  setPresencas(prev => [...prev, newPresenca]);
};
```

**DEPOIS:**
```typescript
import { useEventAttendances, useCreateEventAttendance } from '@/hooks';
import { useEvents } from '@/hooks';

const { data: presencas = [] } = useEventAttendances({ user_id: user.id });
const { data: events = [] } = useEvents();
const createAttendance = useCreateEventAttendance();

const handleMarkPresence = async () => {
  await createAttendance.mutateAsync({
    event_id: selectedEvent,
    user_id: user.id,
    status: 'present',
    registered_at: new Date().toISOString(),
  });
  toast.success('Presença registrada!');
};
```

---

## Padrões Comuns

### 1. Loading States

**Com useKV** (não tem loading state):
```typescript
const [users] = useKV<User[]>('club-users', []);
```

**Com API** (loading state automático):
```typescript
const { data: users = [], isLoading, error } = useUsers();

if (isLoading) return <Spinner />;
if (error) return <ErrorMessage error={error} />;
```

### 2. Optimistic Updates

```typescript
const updateUser = useUpdateUser();

const handleUpdate = async (id: string, data: Partial<User>) => {
  // UI atualiza ANTES da resposta do servidor
  await updateUser.mutateAsync({ id, ...data });
  
  // Se der erro, automaticamente reverte!
};
```

### 3. Invalidação Manual de Cache

```typescript
import { useQueryClient } from '@tanstack/react-query';

const queryClient = useQueryClient();

// Forçar reload de users
queryClient.invalidateQueries({ queryKey: ['users'] });

// Forçar reload de todos os dados
queryClient.invalidateQueries();
```

### 4. Mutations com Callbacks

```typescript
const createEvent = useCreateEvent();

const handleCreate = async () => {
  try {
    const newEvent = await createEvent.mutateAsync(formData);
    toast.success('Evento criado!');
    router.visit(`/eventos/${newEvent.id}`);
  } catch (error) {
    toast.error('Erro ao criar evento');
  }
};
```

---

## Hooks Disponíveis

### Autenticação
```typescript
import {
  useCurrentUser,
  useIsAuthenticated,
  useIsAdmin,
  useHasRole,
  useUserId,
} from '@/hooks';

const currentUser = useCurrentUser();
const isAdmin = useIsAdmin();
const isAtleta = useHasRole('atleta');
```

### Recursos Principais
```typescript
// Users
import { useUsers, useUser, useCreateUser, useUpdateUser, useDeleteUser } from '@/hooks';

// Events
import { useEvents, useEvent, useCreateEvent, useUpdateEvent, useDeleteEvent } from '@/hooks';

// Provas
import { useProvas, useProva, useCreateProva, useUpdateProva, useDeleteProva } from '@/hooks';

// Results
import { useResults, useResult, useCreateResult, useUpdateResult, useDeleteResult } from '@/hooks';

// Attendances
import { 
  useEventAttendances, 
  useEventAttendance,
  useCreateEventAttendance,
  useUpdateEventAttendance,
  useDeleteEventAttendance 
} from '@/hooks';
```

### Key-Value Genérico
```typescript
import { useKV } from '@/hooks';

const [data, setData] = useKV<T>('my-key', defaultValue);
```

---

## Troubleshooting

### Problema: "Data not updating immediately"
**Solução:** React Query tem cache. Use `invalidateQueries`:
```typescript
queryClient.invalidateQueries({ queryKey: ['users'] });
```

### Problema: "Need to refetch after external update"
**Solução:** Configure refetch automático:
```typescript
const { data } = useUsers({
  refetchInterval: 30000, // Refetch a cada 30s
  refetchOnWindowFocus: true,
});
```

### Problema: "Want to access data outside component"
**Solução:** Use QueryClient diretamente:
```typescript
import { useQueryClient } from '@tanstack/react-query';

const queryClient = useQueryClient();
const users = queryClient.getQueryData<User[]>(['users']);
```

---

## Checklist de Migração

Para cada componente:

- [ ] Identificar todos os `useKV` usados
- [ ] Substituir por hook específico ou manter `useKV` local
- [ ] Adicionar tratamento de `isLoading`
- [ ] Adicionar tratamento de `error`
- [ ] Converter `setData(prev => ...)` para `mutation.mutateAsync(...)`
- [ ] Adicionar `toast` notifications
- [ ] Testar CRUD completo
- [ ] Verificar optimistic updates
- [ ] Validar TypeScript sem erros

---

## Próximos Passos

1. Migrar componentes críticos primeiro (Users, Events)
2. Testar em ambiente de desenvolvimento
3. Migrar componentes restantes
4. Remover imports `@github/spark/hooks`
5. Adicionar mais endpoints conforme necessário

---

## Suporte

Para dúvidas ou problemas:
1. Consulte `docs/API_ENDPOINTS.md` para lista completa de endpoints
2. Verifique exemplos em `resources/js/hooks/`
3. Use React Query DevTools para debug (bottom-left icon)
