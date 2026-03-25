import type { Agent } from '../api/endpoints';

export type ExpertKey =
  | 'marketing-expert'
  | 'social-media-manager'
  | 'acountant'
  | 'copywriter'
  | 'e-mail-manager'
  | 'business-coach';

export type ExpertProfile = {
  key: ExpertKey;
  name: string;
  role: string;
  avatar: string;
  background: string;
  avatarScale: number;
};

const EXPERTS: Record<ExpertKey, ExpertProfile> = {
  'marketing-expert': {
    key: 'marketing-expert',
    name: 'Marketing Expert',
    role: 'Marketing',
    avatar: '/assets/avatars/marketing-expert.png',
    background: '/assets/backgrounds/marketing-office-hd.png',
    avatarScale: 1,
  },
  'social-media-manager': {
    key: 'social-media-manager',
    name: 'Social Media Manager',
    role: 'Social Media',
    avatar: '/assets/avatars/social-media-manager.png',
    background: '/assets/backgrounds/social-media-manager-office.png',
    avatarScale: 1.42,
  },
  acountant: {
    key: 'acountant',
    name: 'Accountant',
    role: 'Finance',
    avatar: '/assets/avatars/acountant.png',
    background: '/assets/backgrounds/accountant-office.png',
    avatarScale: 1.38,
  },
  copywriter: {
    key: 'copywriter',
    name: 'Copywriter',
    role: 'Copywriting',
    avatar: '/assets/avatars/copywriter.png',
    background: '/assets/backgrounds/copywright-office.png',
    avatarScale: 1.34,
  },
  'e-mail-manager': {
    key: 'e-mail-manager',
    name: 'E-Mail Manager',
    role: 'Email Marketing',
    avatar: '/assets/avatars/e-mail-manager.png',
    background: '/assets/backgrounds/email-marketing-office.png',
    avatarScale: 1.34,
  },
  'business-coach': {
    key: 'business-coach',
    name: 'Business Coach',
    role: 'Business Strategy',
    avatar: '/assets/avatars/business-coach.png',
    background: '/assets/backgrounds/business-coach-office.png',
    avatarScale: 1.34,
  },
};

export const EXPERT_ORDER: ExpertKey[] = [
  'marketing-expert',
  'social-media-manager',
  'acountant',
  'copywriter',
  'e-mail-manager',
  'business-coach',
];

export const EXPERT_PROFILES: ExpertProfile[] = EXPERT_ORDER.map((key) => EXPERTS[key]);

function normalize(value: string | null): string {
  return (value ?? '')
    .toLowerCase()
    .replace(/[_\s]+/g, '-')
    .replace(/[^a-z0-9-]/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '');
}

export function resolveExpertKey(agent: Agent): ExpertKey | null {
  const slug = normalize(agent.slug);
  const role = normalize(agent.role);

  if (slug.includes('business') || slug.includes('coach') || role.includes('business') || role.includes('coach')) {
    return 'business-coach';
  }

  if (slug.includes('email') || slug.includes('e-mail') || role.includes('email')) {
    return 'e-mail-manager';
  }

  if (slug.includes('copy') || role.includes('copy')) {
    return 'copywriter';
  }

  if (slug.includes('account') || role.includes('account') || role.includes('finance')) {
    return 'acountant';
  }

  if (slug.includes('social') || role.includes('social')) {
    return 'social-media-manager';
  }

  if (slug.includes('marketing') || role.includes('marketing')) {
    return 'marketing-expert';
  }

  return null;
}

export function getAgentExpertProfile(agent: Agent): ExpertProfile | null {
  const key = resolveExpertKey(agent);
  return key ? EXPERTS[key] : null;
}

export function isSupportedAgent(agent: Agent): boolean {
  return resolveExpertKey(agent) !== null;
}
