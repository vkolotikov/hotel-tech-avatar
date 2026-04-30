import * as SecureStore from 'expo-secure-store';
import i18n from 'i18next';

const TOKEN_KEY = 'sanctum_token';

function baseUrl(): string {
  const url = process.env.EXPO_PUBLIC_API_URL;
  if (!url) throw new Error('EXPO_PUBLIC_API_URL is not set');
  return url.replace(/\/$/, '');
}

/**
 * Normalise i18n.language into a 2-letter ISO-639-1 code Whisper
 * accepts, or null when the locale isn't resolvable (i18n hasn't
 * initialised yet, weird locale id like "cimode"). Sending null
 * lets the backend fall through to the user's profile preference.
 */
function ifValidLanguage(raw: string | undefined | null): string | null {
  if (!raw) return null;
  const head = String(raw).slice(0, 2).toLowerCase();
  return /^[a-z]{2}$/.test(head) ? head : null;
}

/**
 * The `language` field is a hint to Whisper / gpt-4o-transcribe so it
 * expects that language. Without it Whisper auto-detects, which
 * routinely flips between languages mid-conversation for bilingual
 * users (e.g. a Russian L1 speaking soft English). The backend layers
 * its own fallback chain (form → profile → Accept-Language → null)
 * so omitting this field still works for users with a saved
 * preferred_language.
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
  const lang = ifValidLanguage(i18n.language);
  if (lang) form.append('language', lang);

  const url = `${baseUrl()}/api/v1/conversations/${conversationId}/voice/transcribe`;
  // Visible in Metro so the user can confirm what's being uploaded.
  console.log('[transcribe] POST', url, '| lang=', lang ?? 'auto', '| uri=', uri);

  let response: Response;
  try {
    response = await fetch(url, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
        // Layer-2 fallback hint for the backend.
        ...(lang ? { 'Accept-Language': lang } : {}),
      },
      body: form,
    });
  } catch (err) {
    console.warn('[transcribe] fetch threw:', err);
    throw err;
  }

  if (!response.ok) {
    let bodyText = '';
    try { bodyText = await response.text(); } catch { /* ignore */ }
    console.warn('[transcribe] HTTP', response.status, 'body:', bodyText.slice(0, 400));
    throw new Error(`Transcribe failed: HTTP ${response.status}${bodyText ? ' — ' + bodyText.slice(0, 160) : ''}`);
  }

  const body = await response.json();
  const transcript: string = body.text ?? body.transcript ?? '';
  console.log('[transcribe] OK len=', transcript.length, '| serverLang=', body.language ?? '?');
  return { transcript };
}
