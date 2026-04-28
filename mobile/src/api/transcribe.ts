import * as SecureStore from 'expo-secure-store';
import i18n from 'i18next';

const TOKEN_KEY = 'sanctum_token';

function baseUrl(): string {
  const url = process.env.EXPO_PUBLIC_API_URL;
  if (!url) throw new Error('EXPO_PUBLIC_API_URL is not set');
  return url.replace(/\/$/, '');
}

/**
 * The `language` field in the form body is a hint to Whisper /
 * gpt-4o-transcribe so it expects that language. The backend prefers
 * the user's profile.preferred_language when set; this client-side
 * value is the fallback for anonymous sessions and a sanity-check
 * against profile drift. Without it Whisper auto-detects, which
 * routinely flips between languages mid-conversation for bilingual
 * users (e.g. a Russian L1 speaking soft English).
 */
export async function transcribeAudio(
  uri: string,
  conversationId: number,
): Promise<{ transcript: string }> {
  const token = await SecureStore.getItemAsync(TOKEN_KEY);
  if (!token) throw new Error('Not authenticated');

  const form = new FormData();
  form.append('file', {
    uri,
    name: 'recording.m4a',
    type: 'audio/m4a',
  } as any);
  // ISO-639-1 — i18n.language is one of our 9 supported codes.
  form.append('language', (i18n.language || 'en').slice(0, 2));

  const response = await fetch(
    `${baseUrl()}/api/v1/conversations/${conversationId}/voice/transcribe`,
    {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
        // Sent for backend language fallback chains that don't read
        // form fields directly (e.g. middleware-level locale lookup).
        'Accept-Language': i18n.language || 'en',
      },
      body: form,
    },
  );

  if (!response.ok) {
    throw new Error(`Transcribe failed: ${response.status}`);
  }
  const body = await response.json();
  return { transcript: body.text ?? body.transcript ?? '' };
}
