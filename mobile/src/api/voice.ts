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
 */
export async function fetchSpeechDataUrl(
  conversationId: number,
  text: string,
): Promise<string> {
  const token = await SecureStore.getItemAsync(TOKEN_KEY);
  if (!token) throw new Error('Not authenticated');

  const response = await fetch(
    `${baseUrl()}/api/v1/conversations/${conversationId}/voice/speak`,
    {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
        Accept: 'audio/mpeg',
      },
      body: JSON.stringify({ text }),
    },
  );

  if (!response.ok) {
    throw new Error(`Speak failed: ${response.status}`);
  }

  const blob = await response.blob();
  return blobToDataUrl(blob);
}
