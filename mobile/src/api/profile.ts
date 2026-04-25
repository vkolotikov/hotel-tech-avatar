import { request } from './index';

export type SexAtBirth = 'F' | 'M' | 'I' | null;

export type ActivityLevel =
  | 'sedentary'
  | 'light'
  | 'moderate'
  | 'active'
  | 'athlete'
  | null;

export type UserProfile = {
  display_name: string | null;
  pronouns: string | null;
  sex_at_birth: SexAtBirth;
  height_cm: number | null;
  weight_kg: number | null;
  activity_level: ActivityLevel;
  sleep_hours_target: number | null;
  goals: string[];
  conditions: string[];
  medications: string[];
  dietary_flags: string[];
  allergies: string[];
};

export type UpdateProfilePayload = Partial<UserProfile>;

export async function fetchProfile(): Promise<UserProfile> {
  const body = await request<{ profile: UserProfile }>('/api/v1/me/profile', {
    auth: true,
  });
  return body.profile;
}

export async function updateProfile(
  patch: UpdateProfilePayload,
): Promise<UserProfile> {
  const body = await request<{ profile: UserProfile }>('/api/v1/me/profile', {
    method: 'PATCH',
    auth: true,
    body: JSON.stringify(patch),
  });
  return body.profile;
}
