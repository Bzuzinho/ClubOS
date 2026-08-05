import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';

interface UseKVOptions {
  scope?: 'global' | 'user';
}

/**
 * Hook compatível com Spark useKV, mas usando backend Laravel
 * 
 * @example
 * const [users, setUsers] = useKV<User[]>('club-users', []);
 * setUsers(prev => [...prev, newUser]); // Salva no Laravel
 */
export function useKV<T>(
  key: string,
  defaultValue: T,
  options: UseKVOptions = {}
): [T, (value: T | ((prev: T) => T)) => Promise<void>] {
  const { scope = 'global' } = options;
  const queryClient = useQueryClient();

  // Fetch data from Laravel API
  const { data, refetch } = useQuery<T>({
    queryKey: ['kv', key, scope],
    queryFn: async () => {
      console.log(`🔄 useKV.get('${key}')`);
      const response = await axios.get(`/api/kv/${key}`, {
        params: { scope },
      });
      console.log(`✅ useKV.get('${key}') retornou:`, response.data.value);
      return response.data.value ?? defaultValue;
    },
    initialData: defaultValue,
    staleTime: 0, // ✅ Sempre considerar como stale para refetch rápido
    gcTime: 1000 * 60 * 5, // 5 minutos
  });

  // Mutation to save data
  const mutation = useMutation({
    mutationFn: async (newValue: T) => {
      await axios.put(`/api/kv/${key}`, {
        value: newValue,
        scope,
      });
    },
    onMutate: async (newValue) => {
      // Optimistic update
      await queryClient.cancelQueries({ queryKey: ['kv', key, scope] });
      const previousValue = queryClient.getQueryData<T>(['kv', key, scope]);
      queryClient.setQueryData(['kv', key, scope], newValue);
      return { previousValue };
    },
    onError: (err, newValue, context) => {
      // Rollback on error
      if (context?.previousValue) {
        queryClient.setQueryData(['kv', key, scope], context.previousValue);
      }
    },
    onSettled: async () => {
      // ✅ Invalidar e imediatamente refetch para garantir dados frescos
      await queryClient.invalidateQueries({ queryKey: ['kv', key, scope] });
      // Forçar refetch imediato
      await new Promise(resolve => setTimeout(resolve, 100));
      refetch();
    },
  });

  // Setter function (compatible with Spark API)
  const setValue = (value: T | ((prev: T) => T)) => {
    const currentValue = queryClient.getQueryData<T>(['kv', key, scope]) ?? defaultValue;
    const newValue = typeof value === 'function' 
      ? (value as (prev: T) => T)(currentValue)
      : value;
    
    // ✅ Return promise so caller can await
    return mutation.mutateAsync(newValue);
  };

  return [data ?? defaultValue, setValue];
}

export default useKV;
