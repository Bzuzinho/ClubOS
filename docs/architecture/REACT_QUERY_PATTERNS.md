# React Query Patterns & Best Practices

Este documento explica os padrões e melhores práticas para usar React Query no projeto.

## Índice
1. [Configuração Base](#configuração-base)
2. [Query Patterns](#query-patterns)
3. [Mutation Patterns](#mutation-patterns)
4. [Cache Management](#cache-management)
5. [Error Handling](#error-handling)
6. [Loading States](#loading-states)
7. [Optimistic Updates](#optimistic-updates)
8. [DevTools](#devtools)

---

## Configuração Base

### QueryClient Setup

Já configurado em `resources/js/app.tsx`:

```typescript
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ReactQueryDevtools } from '@tanstack/react-query-devtools';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,  // Não refetch ao focar janela
      retry: 1,                       // Retry 1x em caso de erro
      staleTime: 5 * 60 * 1000,      // Cache válido por 5 minutos
    },
  },
});

// Wrapping app
<QueryClientProvider client={queryClient}>
  <App {...props} />
  <ReactQueryDevtools initialIsOpen={false} />
</QueryClientProvider>
```

---

## Query Patterns

### 1. Lista de Recursos

```typescript
// Hook definition
export function useUsers() {
  return useQuery<User[]>({
    queryKey: ['users'],
    queryFn: async () => {
      const response = await axios.get('/api/users');
      return response.data;
    },
    initialData: [],  // Evita undefined enquanto carrega
  });
}

// Usage
const { data: users = [], isLoading, error, refetch } = useUsers();
```

### 2. Recurso Individual

```typescript
export function useUser(id: string | undefined) {
  return useQuery<User>({
    queryKey: ['users', id],
    queryFn: async () => {
      const response = await axios.get(`/api/users/${id}`);
      return response.data;
    },
    enabled: !!id,  // Só executa se ID existir
  });
}

// Usage
const { data: user, isLoading } = useUser(userId);
```

### 3. Lista com Filtros

```typescript
interface UseEventsFilters {
  type?: string;
  status?: string;
  start_date?: string;
}

export function useEvents(filters?: UseEventsFilters) {
  return useQuery<Event[]>({
    queryKey: ['events', filters],  // Cache separado por filtro
    queryFn: async () => {
      const response = await axios.get('/api/events', { params: filters });
      return response.data;
    },
    initialData: [],
  });
}

// Usage - cada filtro tem cache próprio
const { data: allEvents } = useEvents();
const { data: trainings } = useEvents({ type: 'training' });
const { data: published } = useEvents({ status: 'published' });
```

---

## Mutation Patterns

### 1. Create Resource

```typescript
export function useCreateUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (user: Partial<User>) => {
      const response = await axios.post('/api/users', user);
      return response.data;
    },
    onSuccess: () => {
      // Invalida cache para forçar refetch
      queryClient.invalidateQueries({ queryKey: ['users'] });
    },
  });
}

// Usage
const createUser = useCreateUser();

const handleCreate = async () => {
  try {
    const newUser = await createUser.mutateAsync(formData);
    toast.success('User criado!');
    router.visit(`/membros/${newUser.id}`);
  } catch (error) {
    toast.error('Erro ao criar user');
  }
};
```

### 2. Update Resource

```typescript
export function useUpdateUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, ...data }: Partial<User> & { id: string }) => {
      const response = await axios.put(`/api/users/${id}`, data);
      return response.data;
    },
    onSuccess: (data) => {
      // Invalida lista e item específico
      queryClient.invalidateQueries({ queryKey: ['users'] });
      queryClient.invalidateQueries({ queryKey: ['users', data.id] });
    },
  });
}

// Usage
const updateUser = useUpdateUser();

const handleUpdate = async () => {
  await updateUser.mutateAsync({ id: user.id, nome_completo: newName });
};
```

### 3. Delete Resource

```typescript
export function useDeleteUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (id: string) => {
      await axios.delete(`/api/users/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
      toast.success('User deletado!');
    },
  });
}

// Usage com confirmação
const deleteUser = useDeleteUser();

const handleDelete = async (id: string) => {
  if (!confirm('Tem certeza?')) return;
  await deleteUser.mutateAsync(id);
};
```

---

## Cache Management

### 1. Invalidate Queries

```typescript
import { useQueryClient } from '@tanstack/react-query';

const queryClient = useQueryClient();

// Invalidar query específica
queryClient.invalidateQueries({ queryKey: ['users'] });

// Invalidar múltiplas queries
queryClient.invalidateQueries({ queryKey: ['users'] });
queryClient.invalidateQueries({ queryKey: ['events'] });

// Invalidar todas queries
queryClient.invalidateQueries();
```

### 2. Set Query Data Manualmente

```typescript
// Atualizar cache sem fazer request
queryClient.setQueryData(['users', userId], updatedUser);

// Atualizar lista
queryClient.setQueryData(['users'], (old: User[] = []) => {
  return old.map(u => u.id === userId ? updatedUser : u);
});
```

### 3. Get Query Data

```typescript
// Ler cache atual
const users = queryClient.getQueryData<User[]>(['users']);
const user = queryClient.getQueryData<User>(['users', userId]);

if (users) {
  console.log('Cache contém', users.length, 'users');
}
```

### 4. Prefetch Data

```typescript
// Carregar dados antes de navegar
const prefetchUser = async (id: string) => {
  await queryClient.prefetchQuery({
    queryKey: ['users', id],
    queryFn: () => axios.get(`/api/users/${id}`).then(r => r.data),
  });
  // Agora quando navegar, dados já estarão em cache
  router.visit(`/membros/${id}`);
};
```

---

## Error Handling

### 1. Component Level

```typescript
const { data, error, isError } = useUsers();

if (isError) {
  return (
    <Alert variant="destructive">
      <AlertTitle>Erro ao carregar users</AlertTitle>
      <AlertDescription>{error.message}</AlertDescription>
    </Alert>
  );
}
```

### 2. Mutation Error

```typescript
const createUser = useCreateUser();

const handleCreate = async () => {
  try {
    await createUser.mutateAsync(formData);
    toast.success('User criado!');
  } catch (error) {
    if (axios.isAxiosError(error)) {
      const message = error.response?.data?.message || 'Erro desconhecido';
      toast.error(message);
      
      // Validação errors
      if (error.response?.data?.errors) {
        Object.entries(error.response.data.errors).forEach(([field, messages]) => {
          console.error(`${field}: ${messages}`);
        });
      }
    }
  }
};
```

### 3. Mutation Callbacks

```typescript
const createUser = useCreateUser();

createUser.mutate(formData, {
  onSuccess: (data) => {
    toast.success('User criado!');
    router.visit(`/membros/${data.id}`);
  },
  onError: (error) => {
    toast.error('Erro ao criar user');
  },
});
```

---

## Loading States

### 1. Query Loading

```typescript
const { data: users = [], isLoading, isFetching } = useUsers();

// isLoading: primeira carga (sem cache)
// isFetching: qualquer fetch (incluindo background refetch)

if (isLoading) {
  return <Spinner />;
}

return (
  <div>
    {isFetching && <div className="loading-indicator">Atualizando...</div>}
    <UserList users={users} />
  </div>
);
```

### 2. Mutation Loading

```typescript
const createUser = useCreateUser();

return (
  <Button 
    onClick={handleCreate} 
    disabled={createUser.isPending}
  >
    {createUser.isPending ? 'Salvando...' : 'Salvar'}
  </Button>
);
```

### 3. Multiple Queries

```typescript
const users = useUsers();
const events = useEvents();
const provas = useProvas();

const isLoading = users.isLoading || events.isLoading || provas.isLoading;

if (isLoading) return <Spinner />;
```

---

## Optimistic Updates

### Padrão Completo

```typescript
export function useUpdateUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, ...data }: Partial<User> & { id: string }) => {
      const response = await axios.put(`/api/users/${id}`, data);
      return response.data;
    },
    // ANTES de enviar request
    onMutate: async (newData) => {
      // Cancela queries em andamento
      await queryClient.cancelQueries({ queryKey: ['users'] });
      
      // Salva cache atual
      const previousUsers = queryClient.getQueryData<User[]>(['users']);
      
      // Atualiza cache otimisticamente
      queryClient.setQueryData<User[]>(['users'], (old = []) => {
        return old.map(u => 
          u.id === newData.id ? { ...u, ...newData } : u
        );
      });
      
      // Retorna context para rollback
      return { previousUsers };
    },
    // Se der ERRO
    onError: (err, newData, context) => {
      // Reverte para cache anterior
      if (context?.previousUsers) {
        queryClient.setQueryData(['users'], context.previousUsers);
      }
      toast.error('Erro ao atualizar');
    },
    // SEMPRE executa (sucesso ou erro)
    onSettled: () => {
      // Refetch para garantir sincronização
      queryClient.invalidateQueries({ queryKey: ['users'] });
    },
  });
}
```

---

## DevTools

### Abrir DevTools

- Clique no ícone do React Query (canto inferior esquerdo)
- Ou adicione `?reactQueryDevtools=open` na URL

### Features

1. **Query Inspector**: Ver todas queries ativas
2. **Cache Data**: Inspecionar dados em cache
3. **Query Timeline**: Ver histórico de queries
4. **Actions**: Invalidate/Refetch manualmente
5. **Settings**: Configurar DevTools

### Debug Tips

```typescript
// Ver estado de query específica
const query = queryClient.getQueryState(['users']);
console.log({
  data: query?.data,
  error: query?.error,
  status: query?.status,
  dataUpdatedAt: query?.dataUpdatedAt,
});

// Ver todas queries
const allQueries = queryClient.getQueryCache().getAll();
console.log('Total queries:', allQueries.length);
```

---

## Best Practices

### 1. Query Keys
```typescript
// ✅ Good: Estruturado e descritivo
['users']
['users', userId]
['events', { type: 'training', status: 'published' }]

// ❌ Bad: Pouco específico
['data']
['list']
```

### 2. Initial Data
```typescript
// ✅ Good: Sempre forneça initialData
const { data: users = [] } = useUsers();

// ❌ Bad: Pode ser undefined
const { data: users } = useUsers();
```

### 3. Enabled Option
```typescript
// ✅ Good: Só executa quando necessário
const { data: user } = useUser(userId, {
  enabled: !!userId && isAdmin,
});

// ❌ Bad: Executa sempre
const { data: user } = useUser(undefined);
```

### 4. StaleTime vs CacheTime
```typescript
staleTime: 5 * 60 * 1000,  // Dados "frescos" por 5min
cacheTime: 30 * 60 * 1000, // Mantém em cache por 30min após unused
```

### 5. Refetch Strategy
```typescript
// Dados que mudam raramente
const { data } = useUserTypes({
  staleTime: Infinity,  // Nunca fica stale
});

// Dados que mudam frequentemente
const { data } = useEventAttendances({
  refetchInterval: 30000,      // Refetch a cada 30s
  refetchOnWindowFocus: true,  // Refetch ao focar janela
});
```

---

## Conclusão

React Query oferece:
- ✅ Cache automático e inteligente
- ✅ Loading/Error states built-in
- ✅ Optimistic updates com rollback
- ✅ DevTools poderosas
- ✅ TypeScript support completo

Use esses padrões para manter o código consistente e robusto!
