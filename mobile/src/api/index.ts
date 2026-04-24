import * as SecureStore from 'expo-secure-store';

const TOKEN_KEY = 'sanctum_token';

type SessionListener = () => void;
const sessionListeners = new Set<SessionListener>();

export function onSessionExpired(listener: SessionListener): () => void {
  sessionListeners.add(listener);
  return () => {
    sessionListeners.delete(listener);
  };
}

function notifySessionExpired() {
  sessionListeners.forEach((fn) => fn());
}

const baseUrl = (): string => {
  const url = process.env.EXPO_PUBLIC_API_URL;
  if (!url) {
    throw new Error('EXPO_PUBLIC_API_URL is not set — copy .env.example to .env');
  }
  return url.replace(/\/$/, '');
};

export function resolveAssetUrl(path: string | null | undefined): string | null {
  if (!path) return null;
  if (/^https?:\/\//i.test(path)) return path;
  return `${baseUrl()}${path.startsWith('/') ? '' : '/'}${path}`;
}

// Hard ceiling for every non-streaming request. Fetch has no built-in
// timeout on React Native, so a backend that hangs leaves the UI stuck
// on a spinner with no way out. 20s is generous enough to cover slow
// cold starts on Laravel Cloud + embedding roundtrips, tight enough
// that a dead network surfaces as a retryable error within a reasonable
// window. Long-lived paths (SSE streaming, voice transcribe) don't use
// this helper, so they're unaffected.
const DEFAULT_REQUEST_TIMEOUT_MS = 20_000;

export async function request<T>(
  path: string,
  init: RequestInit & { auth?: boolean; timeoutMs?: number } = {},
): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  if (init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }
  if (init.auth) {
    const token = await SecureStore.getItemAsync(TOKEN_KEY);
    if (!token) throw new Error('Not authenticated');
    headers.set('Authorization', `Bearer ${token}`);
  }

  const controller = new AbortController();
  const timeoutMs = init.timeoutMs ?? DEFAULT_REQUEST_TIMEOUT_MS;
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

  let response: Response;
  try {
    response = await fetch(`${baseUrl()}${path}`, {
      ...init,
      headers,
      signal: controller.signal,
    });
  } catch (err) {
    clearTimeout(timeoutId);
    // AbortError from our timeout vs a real network error — surface a
    // clearer message for the former so the user knows a retry is
    // meaningful.
    if ((err as { name?: string }).name === 'AbortError') {
      throw new Error(`Request timed out after ${Math.round(timeoutMs / 1000)}s — check your connection and try again.`);
    }
    throw err;
  }
  clearTimeout(timeoutId);

  const text = await response.text();
  const body = text ? JSON.parse(text) : null;

  if (!response.ok) {
    if (response.status === 401 && init.auth) {
      await SecureStore.deleteItemAsync(TOKEN_KEY);
      notifySessionExpired();
    }
    const message =
      body?.message ??
      body?.errors?.email?.[0] ??
      body?.error ??
      `Request failed: ${response.status}`;
    throw new ApiError(message, response.status, body);
  }
  return body as T;
}

/**
 * Error thrown by `request()` for any non-2xx response. Carries the
 * HTTP status and the decoded JSON body so callers can handle specific
 * statuses structurally — e.g. the chat screen opens the paywall on
 * 402 and reads `body.plan` + `body.used_today` to tailor the CTA.
 */
export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly body: unknown,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

export type Subscription = {
  plan: string | null;
  plan_name: string | null;
  daily_limit: number | null;
  used_today: number;
  remaining_today: number | null;
  features: Record<string, boolean | number | string>;
};

export type AuthUser = {
  id: number;
  name: string;
  email: string;
  subscription?: Subscription;
};

export async function login(
  email: string,
  password: string,
  deviceName: string,
): Promise<AuthUser> {
  const body = await request<{ token: string; user: AuthUser }>('/api/v1/auth/login', {
    method: 'POST',
    body: JSON.stringify({ email, password, device_name: deviceName }),
  });
  await SecureStore.setItemAsync(TOKEN_KEY, body.token);
  return body.user;
}

export async function register(
  name: string,
  email: string,
  password: string,
  deviceName: string,
): Promise<AuthUser> {
  const body = await request<{ token: string; user: AuthUser }>('/api/v1/auth/register', {
    method: 'POST',
    body: JSON.stringify({ name, email, password, device_name: deviceName }),
  });
  await SecureStore.setItemAsync(TOKEN_KEY, body.token);
  return body.user;
}

export async function me(): Promise<AuthUser> {
  return request<AuthUser>('/api/v1/me', { auth: true });
}

export async function logout(): Promise<void> {
  try {
    await request('/api/v1/auth/logout', { method: 'POST', auth: true });
  } finally {
    await SecureStore.deleteItemAsync(TOKEN_KEY);
    notifySessionExpired();
  }
}

export async function storedToken(): Promise<string | null> {
  return SecureStore.getItemAsync(TOKEN_KEY);
}
