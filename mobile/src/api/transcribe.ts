import * as SecureStore from 'expo-secure-store';

const TOKEN_KEY = 'sanctum_token';

function baseUrl(): string {
  const url = process.env.EXPO_PUBLIC_API_URL;
  if (!url) throw new Error('EXPO_PUBLIC_API_URL is not set');
  return url.replace(/\/$/, '');
}

export async function transcribeAudio(uri: string): Promise<{ transcript: string }> {
  const token = await SecureStore.getItemAsync(TOKEN_KEY);
  if (!token) throw new Error('Not authenticated');

  const form = new FormData();
  form.append('audio', {
    uri,
    name: 'recording.m4a',
    type: 'audio/m4a',
  } as any);

  const response = await fetch(`${baseUrl()}/api/v1/transcribe`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    },
    body: form,
  });

  if (!response.ok) {
    throw new Error(`Transcribe failed: ${response.status}`);
  }
  return response.json();
}
