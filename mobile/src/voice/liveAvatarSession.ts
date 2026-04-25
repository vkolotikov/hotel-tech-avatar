/**
 * Calls LiveAvatar's POST /v1/sessions/start directly from the mobile
 * client using the JWT session token our backend minted on
 * /api/v1/liveavatar/session.
 *
 * Going direct to LiveAvatar (rather than proxying through our
 * backend) keeps WebRTC negotiation latency tight — LiveKit
 * credentials are useless after seconds of staleness, so an extra
 * round-trip through our server would risk the credentials timing
 * out before the WebView/socket actually opens. The session token
 * itself is short-lived and scoped, so direct exposure is fine.
 */

export type LiveAvatarLiteSessionConfig = {
  sessionId: string;
  livekitUrl: string;
  livekitClientToken: string;
  livekitAgentToken: string | null;
  wsUrl: string | null;
  maxSessionDurationSeconds: number;
};

export async function startLiveAvatarLiteSession(
  startUrl: string,
  sessionToken: string,
): Promise<LiveAvatarLiteSessionConfig> {
  const response = await fetch(startUrl, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${sessionToken}`,
      Accept: 'application/json',
    },
  });
  if (!response.ok) {
    const body = await response.text().catch(() => '');
    throw new Error(`LiveAvatar /v1/sessions/start ${response.status}: ${body || 'no body'}`);
  }

  const body = await response.json();
  const data = (body?.data ?? {}) as Record<string, unknown>;

  if (!data.session_id || !data.livekit_url || !data.livekit_client_token) {
    throw new Error(
      `LiveAvatar /v1/sessions/start returned unexpected payload: ${JSON.stringify(body)}`,
    );
  }

  return {
    sessionId:                String(data.session_id),
    livekitUrl:               String(data.livekit_url),
    livekitClientToken:       String(data.livekit_client_token),
    livekitAgentToken:        data.livekit_agent_token ? String(data.livekit_agent_token) : null,
    wsUrl:                    data.ws_url ? String(data.ws_url) : null,
    maxSessionDurationSeconds: Number(data.max_session_duration ?? 60),
  };
}
