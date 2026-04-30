import * as SecureStore from 'expo-secure-store';

const TOKEN_KEY = 'sanctum_token';

function baseUrl(): string {
  const url = process.env.EXPO_PUBLIC_API_URL;
  if (!url) throw new Error('EXPO_PUBLIC_API_URL is not set');
  return url.replace(/\/$/, '');
}

async function blobToDataUrl(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onloadend = () => resolve(reader.result as string);
    reader.onerror = () => reject(reader.error ?? new Error('Failed to read audio'));
    reader.readAsDataURL(blob);
  });
}

/**
 * POSTs to the conversation's /voice/speak endpoint and returns a
 * data: URL that can be fed straight into expo-av Audio.Sound.
 *
 * Errors are deliberately verbose: a failure here is otherwise silent
 * unless we surface the HTTP status / body / blob size to Metro.
 */
export async function fetchSpeechDataUrl(
  conversationId: number,
  text: string,
): Promise<string> {
  const token = await SecureStore.getItemAsync(TOKEN_KEY);
  if (!token) throw new Error('Not authenticated');

  const url = `${baseUrl()}/api/v1/conversations/${conversationId}/voice/speak`;
  console.log('[speak] POST', url, '| chars=', text.length);

  let response: Response;
  try {
    response = await fetch(url, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
        Accept: 'audio/mpeg',
      },
      body: JSON.stringify({ text }),
    });
  } catch (err) {
    console.warn('[speak] fetch threw:', err);
    throw err;
  }

  if (!response.ok) {
    let bodyText = '';
    try { bodyText = await response.text(); } catch { /* ignore */ }
    console.warn('[speak] HTTP', response.status, 'body:', bodyText.slice(0, 400));
    throw new Error(`Speak failed: HTTP ${response.status}${bodyText ? ' — ' + bodyText.slice(0, 160) : ''}`);
  }

  const blob = await response.blob();
  const blobSize = (blob as any).size ?? -1;
  const blobType = (blob as any).type ?? 'unknown';
  console.log('[speak] OK blob size=', blobSize, '| type=', blobType);
  if (blobSize <= 0) {
    throw new Error(`Speak returned empty audio (size=${blobSize}, type=${blobType})`);
  }

  let dataUrl: string;
  try {
    dataUrl = await blobToDataUrl(blob);
  } catch (err) {
    console.warn('[speak] blobToDataUrl failed:', err);
    throw err;
  }
  console.log('[speak] dataUrl ready, prefix=', dataUrl.slice(0, 40), '| length=', dataUrl.length);
  return dataUrl;
}
