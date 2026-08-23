import { usePage } from '@inertiajs/react';

export interface AuthUser {
  id: string;
  nome_completo: string;
  email?: string;
  perfil?: 'admin' | 'atleta' | 'encarregado' | 'treinador' | 'socio';
  [key: string]: any;
}

interface PageProps {
  auth: {
    user: AuthUser | null;
  };
}

/**
 * Hook to get the current authenticated user
 * 
 * @example
 * const currentUser = useCurrentUser();
 * if (currentUser) {
 *   console.log(currentUser.nome_completo);
 * }
 */
export function useCurrentUser(): AuthUser | null {
  const { auth } = usePage<PageProps & Record<string, unknown>>().props;
  return auth?.user || null;
}

/**
 * Hook to check if user is authenticated
 */
export function useIsAuthenticated(): boolean {
  const user = useCurrentUser();
  return user !== null;
}

/**
 * Hook to check if user is admin
 */
export function useIsAdmin(): boolean {
  const user = useCurrentUser();
  return user?.perfil === 'admin';
}

/**
 * Hook to check if user has a specific role/perfil
 */
export function useHasRole(role: 'admin' | 'atleta' | 'encarregado' | 'treinador' | 'socio'): boolean {
  const user = useCurrentUser();
  return user?.perfil === role;
}

/**
 * Hook to get user ID
 */
export function useUserId(): string | null {
  const user = useCurrentUser();
  return user?.id || null;
}
