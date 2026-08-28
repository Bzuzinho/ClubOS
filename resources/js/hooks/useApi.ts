import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';

/**
 * Generic API hook to replace Spark's useKV
 *
 * @example
 * const { data: users = [], isLoading } = useApi('users');
 */
export function useApi<T>(endpoint: string, queryKey?: string[]) {
    const key = queryKey || [endpoint];

    return useQuery<T>({
        queryKey: key,
        queryFn: async () => {
            const response = await axios.get(`/api/${endpoint}`);
            return response.data;
        },
    });
}

/**
 * API mutation hook for POST/PUT/DELETE operations
 *
 * @example
 * const createUser = useApiMutation('users', 'POST');
 * createUser.mutate({ name: 'John' });
 */
export function useApiMutation<TData = any, TVariables = any>(
    endpoint: string,
    method: 'POST' | 'PUT' | 'PATCH' | 'DELETE' = 'POST',
    invalidateKeys?: string[],
) {
    const queryClient = useQueryClient();

    return useMutation<TData, Error, TVariables>({
        mutationFn: async (data: TVariables) => {
            const response = await axios({
                method,
                url: `/api/${endpoint}`,
                data,
            });
            return response.data;
        },
        onSuccess: () => {
            if (invalidateKeys) {
                invalidateKeys.forEach((key) => {
                    queryClient.invalidateQueries({ queryKey: [key] });
                });
            }
        },
    });
}

/**
 * Hook for specific resource operations.
 *
 * The returned list/get callbacks are custom hooks internally, so consumers
 * must invoke them following the normal Rules of Hooks.
 */
export function useResource<T = any>(resource: string) {
    const queryClient = useQueryClient();

    const useList = () => useApi<T[]>(resource);

    const useGet = (id: number | string) =>
        useQuery<T>({
            queryKey: [resource, id],
            queryFn: async () => {
                const response = await axios.get(`/api/${resource}/${id}`);
                return response.data;
            },
        });

    const create = useMutation<T, Error, Partial<T>>({
        mutationFn: async (data) => {
            const response = await axios.post(`/api/${resource}`, data);
            return response.data;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: [resource] });
        },
    });

    const update = useMutation<T, Error, { id: number | string; data: Partial<T> }>({
        mutationFn: async ({ id, data }) => {
            const response = await axios.put(`/api/${resource}/${id}`, data);
            return response.data;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: [resource] });
        },
    });

    const remove = useMutation<void, Error, number | string>({
        mutationFn: async (id) => {
            await axios.delete(`/api/${resource}/${id}`);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: [resource] });
        },
    });

    return { list: useList, get: useGet, create, update, remove };
}

// Specific hooks for common resources
export const useUserTypes = () => useApi<any[]>('user-types');
export const useAgeGroups = () => useApi<any[]>('age-groups');
export const useEventTypes = () => useApi<any[]>('event-types');
export const useCostCenters = () => useApi<any[]>('cost-centers');
export const useClubSettingsApi = () => useApi<any>('club-settings');
