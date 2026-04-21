import { request } from './index';
import type { Avatar } from '../types/models';

export async function listAvatars(vertical = 'wellness'): Promise<{ data: Avatar[] }> {
  return request<{ data: Avatar[] }>(
    `/api/v1/agents?vertical=${encodeURIComponent(vertical)}`,
    { auth: true },
  );
}
