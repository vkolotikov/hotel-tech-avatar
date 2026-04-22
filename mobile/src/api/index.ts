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

export async function request<T>(
  path: string,
  init: RequestInit & { auth?: boolean } = {},
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

  const response = await fetch(`${baseUrl()}${path}`, { ...init, headers });
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
    throw new Error(message);
  }
  return body as T;
}

export type AuthUser = {
  id: number;
  name: string;
  email: string;
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

export async function me(): Promise<AuthUser> {
  return request<AuthUser>('/api/v1/me', { auth: true });
}

export async function logout(): Promise<void> {
  try {
    await request('/api/v1/auth/logout', { method: 'POST', auth: true });
  } finally {
    await SecureStore.deleteItemAsync(TOKEN_KEY);
  }
}

export async function storedToken(): Promise<string | null> {
  return SecureStore.getItemAsync(TOKEN_KEY);
}
