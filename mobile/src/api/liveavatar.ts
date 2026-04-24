import { request } from './index';

export type LiveAvatarSession = {
  session: {
    embed_id: string | null;
    url: string | null;
    script: string | null;
    orientation: 'horizontal' | 'vertical';
    sandbox: boolean;
  };
  avatar: {
    slug: string;
    name: string;
    avatar_id: string;
    context_id: string | null;
  };
};

/**
 * Mint a fresh LiveAvatar embed session for an avatar. Can return:
 *   200 — { session, avatar }
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
