import { request } from './index';
import type { Avatar } from '../types/models';

export async function listAvatars(vertical = 'wellness'): Promise<Avatar[]> {
  return request<Avatar[]>(
    `/api/v1/agents?vertical=${encodeURIComponent(vertical)}`,
    { auth: true },
  );
}
