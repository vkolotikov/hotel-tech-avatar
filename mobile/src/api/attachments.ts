import * as SecureStore from 'expo-secure-store';
import type { Attachment } from '../types/models';

const TOKEN_KEY = 'sanctum_token';

function baseUrl(): string {
  const url = process.env.EXPO_PUBLIC_API_URL;
  if (!url) throw new Error('EXPO_PUBLIC_API_URL is not set');
  return url.replace(/\/$/, '');
}

export async function uploadAttachment(
  conversationId: number,
  file: { uri: string; name: string; type: string },
): Promise<Attachment> {
  const token = await SecureStore.getItemAsync(TOKEN_KEY);
  if (!token) throw new Error('Not authenticated');

  const form = new FormData();
  form.append('file', {
    uri: file.uri,
    name: file.name,
    type: file.type,
  } as any);

  const response = await fetch(
    `${baseUrl()}/api/v1/conversations/${conversationId}/attachments`,
    {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
      body: form,
    },
  );

  if (!response.ok) {
    const text = await response.text().catch(() => '');
    throw new Error(`Upload failed (${response.status}): ${text}`);
  }
  return response.json();
}
