const rawBaseUrl = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? '';
const baseUrl = rawBaseUrl.replace(/\/$/, '');

/** Resolve a backend asset path (e.g. "/assets/avatars/foo.png") to a full URL. */
export function assetUrl(path: string): string {
  if (!path) return '';
  if (path.startsWith('http://') || path.startsWith('https://')) return path;
  return `${baseUrl}${path.startsWith('/') ? path : `/${path}`}`;
}

export class ApiError extends Error {
  status: number;
  path: string;
  details: unknown;

  constructor(message: string, status: number, path: string, details: unknown) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.path = path;
    this.details = details;
  }
}

function buildUrl(path: string): string {
  if (path.startsWith('http://') || path.startsWith('https://')) {
    return path;
  }

  if (!baseUrl) {
    return path;
  }

  return `${baseUrl}${path}`;
}

export async function apiFetch<T>(path: string, options: RequestInit = {}): Promise<T> {
  const url = buildUrl(path);
  const headers = new Headers(options.headers ?? {});
  const hasBody = options.body !== undefined && options.body !== null;
  const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;

  if (hasBody && !isFormData && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }

  const response = await fetch(url, {
    ...options,
    headers,
  });

  const contentType = response.headers.get('content-type') ?? '';
  const isJson = contentType.includes('application/json');
  const payload = isJson ? await response.json() : await response.text();

  if (!response.ok) {
    throw new ApiError(
      `Request failed (${response.status}) for ${path}`,
      response.status,
      path,
      payload
    );
  }

  if (!isJson) {
    throw new ApiError('Expected JSON response', response.status, path, payload);
  }

  return payload as T;
}

export async function apiFetchBlob(path: string, options: RequestInit = {}): Promise<Blob> {
  const url = buildUrl(path);
  const headers = new Headers(options.headers ?? {});
  const hasBody = options.body !== undefined && options.body !== null;
  const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;

  if (hasBody && !isFormData && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }

  const response = await fetch(url, {
    ...options,
    headers,
  });

  if (!response.ok) {
    const contentType = response.headers.get('content-type') ?? '';
    const payload = contentType.includes('application/json')
      ? await response.json()
      : await response.text();
    throw new ApiError(
      `Request failed (${response.status}) for ${path}`,
      response.status,
      path,
      payload
    );
  }

  return response.blob();
}
