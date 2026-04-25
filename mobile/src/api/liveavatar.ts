import { request } from './index';

export type LiveAvatarSession = {
  session: {
    embed_id: string | null;
    url: string | null;
    script: string | null;
    orientation: 'horizontal' | 'vertical';
    sandbox: boolean;
  };
  /**
   * LITE-mode session bootstrap — the JWT the mobile client uses to
   * call /v1/sessions/start directly against LiveAvatar. Null when
   * server-side token minting failed (rare); callers should treat
   * the embed URL as video-only in that case and re-fetch later.
   */
  connect: {
    session_id: string;
    session_token: string;
    start_url: string;
  } | null;
  avatar: {
    slug: string;
    name: string;
    avatar_id: string;
    context_id: string | null;
  };
};

/**
 * Mint a fresh LiveAvatar embed session for an avatar. Can return:
 *   200 — { session, connect, avatar }
 *   422 — backend response with code 'avatar_not_mapped' (this avatar
 *         has no liveavatar_avatar_id yet — ops needs to pick one in
 *         the LiveAvatar dashboard)
 *   503 — code 'liveavatar_disabled' (LIVEAVATAR_API_KEY not set)
 *
 * Both 4xx/5xx surface as ApiError with the backend body attached,
 * so the component can render a tailored fallback state.
 */
export async function startLiveAvatarSession(
  avatarSlug: string,
): Promise<LiveAvatarSession> {
  return request<LiveAvatarSession>('/api/v1/liveavatar/session', {
    method: 'POST',
    auth: true,
    body: JSON.stringify({ avatar_slug: avatarSlug }),
  });
}

/**
 * Tell LiveAvatar (via our proxy) that the session is still in use.
 * Should be called every ~30s while a live session is open. Returns
 * false when the upstream session has already ended — caller should
 * stop pinging in that case.
 */
export async function keepAliveLiveAvatarSession(
  sessionId: string,
  sessionToken: string,
): Promise<boolean> {
  try {
    await request<{ alive: boolean }>(
      `/api/v1/liveavatar/session/${encodeURIComponent(sessionId)}/keep-alive`,
      {
        method: 'POST',
        auth: true,
        body: JSON.stringify({ session_token: sessionToken }),
      },
    );
    return true;
  } catch {
    return false;
  }
}

/**
 * Tell LiveAvatar to terminate the session — releases the credit
 * lease. Best-effort: if it fails, the session times out naturally
 * after max_session_duration anyway.
 */
export async function stopLiveAvatarSession(
  sessionId: string,
  sessionToken: string,
): Promise<void> {
  try {
    await request<{ stopped: boolean }>(
      `/api/v1/liveavatar/session/${encodeURIComponent(sessionId)}`,
      {
        method: 'DELETE',
        auth: true,
        body: JSON.stringify({ session_token: sessionToken }),
      },
    );
  } catch {
    // Swallow — see docblock.
  }
}

export type SpeakPcmResponse = {
  sample_rate: number;
  format: string;
  chunk_count: number;
  chunks: string[];
};

/**
 * Generate avatar speech audio for a snippet of text. Returns
 * base64-encoded PCM 16-bit-LE / 24kHz / mono chunks (~1 second per
 * chunk) ready to feed straight into a LiveAvatar agent.speak event.
 */
export async function fetchPcmAudio(
  conversationId: number,
  text: string,
): Promise<SpeakPcmResponse> {
  return request<SpeakPcmResponse>(
    `/api/v1/conversations/${conversationId}/voice/speak-pcm`,
    {
      method: 'POST',
      auth: true,
      body: JSON.stringify({ text }),
    },
  );
}
