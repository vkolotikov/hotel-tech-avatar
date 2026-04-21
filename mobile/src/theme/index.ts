export const colors = {
  background: '#0b0f17',
  surface: '#141a26',
  surfaceElevated: '#1f2937',
  primary: '#7c5cff',
  textPrimary: '#ffffff',
  textSecondary: '#d1d5db',
  textMuted: '#9ca3af',
  border: '#374151',
  danger: '#ef4444',
  warning: '#f59e0b',
  success: '#10b981',
} as const;

export const spacing = {
  xs: 4,
  sm: 8,
  md: 16,
  lg: 24,
  xl: 32,
  xxl: 48,
} as const;

export const radius = {
  sm: 8,
  md: 12,
  lg: 16,
  pill: 9999,
} as const;

export const fontSize = {
  xs: 12,
  sm: 14,
  md: 16,
  lg: 20,
  xl: 28,
} as const;

export const avatarColors = {
  nora: '#4ade80',
  luna: '#818cf8',
  zen: '#2dd4bf',
  integra: '#3b82f6',
  axel: '#f87171',
  aura: '#f472b6',
} as const;

export type AvatarSlug = keyof typeof avatarColors;
