import { colors, spacing, avatarColors } from '../index';

describe('theme', () => {
  test('exports base colors', () => {
    expect(colors.background).toBe('#0b0f17');
    expect(colors.primary).toBe('#7c5cff');
    expect(colors.textPrimary).toBe('#ffffff');
  });

  test('exports spacing scale', () => {
    expect(spacing.sm).toBe(8);
    expect(spacing.md).toBe(16);
    expect(spacing.lg).toBe(24);
  });

  test('exports avatar accent colors', () => {
    expect(avatarColors.nora).toBe('#4ade80');
    expect(avatarColors.luna).toBe('#818cf8');
    expect(avatarColors.zen).toBe('#2dd4bf');
    expect(avatarColors.integra).toBe('#3b82f6');
    expect(avatarColors.axel).toBe('#f87171');
    expect(avatarColors.aura).toBe('#f472b6');
  });
});
