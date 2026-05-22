import axios from 'axios';

export const FINANCEIRO_SESSION_EXPIRED_MESSAGE = 'Sessão expirada. Atualize a página e tente novamente.';

export const getCsrfToken = () => {
  const token = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null;
  return token?.content || '';
};

export const getFinanceiroJsonHeaders = ({ includeContentType = true }: { includeContentType?: boolean } = {}) => {
  const csrfToken = getCsrfToken();

  return {
    Accept: 'application/json',
    ...(includeContentType ? { 'Content-Type': 'application/json' } : {}),
    'X-Requested-With': 'XMLHttpRequest',
    ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
  };
};

export const getFinanceiroAxiosJsonConfig = () => ({
  headers: getFinanceiroJsonHeaders(),
  withCredentials: true,
});

export const getFinanceiroRequestErrorMessage = (error: unknown, fallbackMessage: string) => {
  if (axios.isAxiosError(error)) {
    if (error.response?.status === 419) {
      return FINANCEIRO_SESSION_EXPIRED_MESSAGE;
    }

    const responseMessage = error.response?.data?.message;
    const validationErrors = Object.values(error.response?.data?.errors || {}).flat().join(' ');

    return responseMessage || validationErrors || fallbackMessage;
  }

  if (error instanceof Error) {
    return error.message;
  }

  return fallbackMessage;
};

const isFormDataPayload = (payload: unknown): payload is FormData => typeof FormData !== 'undefined' && payload instanceof FormData;

export const getFinanceiroFetchErrorMessage = async (response: Response, fallbackMessage: string) => {
  if (response.status === 419) {
    return FINANCEIRO_SESSION_EXPIRED_MESSAGE;
  }

  const contentType = response.headers.get('content-type') || '';

  if (contentType.includes('application/json')) {
    const data = await response.json();
    const validationErrors = Object.values(data?.errors || {}).flat().join(' ');

    return data?.message || validationErrors || fallbackMessage;
  }

  const fallback = await response.text();
  return fallback || fallbackMessage;
};

export async function fetchFinanceiro<T>(
  url: string,
  options: {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    body?: Record<string, unknown> | FormData;
    fallbackMessage: string;
  },
): Promise<T> {
  const method = options.method || 'GET';
  const hasBody = options.body !== undefined;
  const isFormData = isFormDataPayload(options.body);

  const response = await fetch(url, {
    method,
    headers: getFinanceiroJsonHeaders({ includeContentType: hasBody && !isFormData }),
    credentials: 'same-origin',
    body: !hasBody ? undefined : isFormData ? options.body : JSON.stringify(options.body),
  });

  if (!response.ok) {
    throw new Error(await getFinanceiroFetchErrorMessage(response, options.fallbackMessage));
  }

  if (response.status === 204) {
    return undefined as T;
  }

  const contentType = response.headers.get('content-type') || '';
  if (!contentType.includes('application/json')) {
    return undefined as T;
  }

  return response.json() as Promise<T>;
}