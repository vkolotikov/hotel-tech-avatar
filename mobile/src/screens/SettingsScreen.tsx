import { useState } from 'react';
import {
  Alert,
  Linking,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useTranslation } from 'react-i18next';
import { AuthUser, logout } from '../api';
import { updateProfile } from '../api/profile';
import { PaywallScreen } from './PaywallScreen';
import { ProfileSetupScreen } from './ProfileSetupScreen';
import { setLanguage, SUPPORTED_LANGUAGES, type LanguageCode } from '../i18n';
import { colors, spacing, radius, fontSize } from '../theme';
// eslint-disable-next-line @typescript-eslint/no-var-requires
const pkg = require('../../package.json') as { version?: string };

type Props = {
  user: AuthUser | null;
  onRefreshUser: () => Promise<void>;
};

export function SettingsScreen({ user, onRefreshUser }: Props) {
  const version = pkg.version ?? 'dev';
  const { t, i18n } = useTranslation();
  const [paywallOpen, setPaywallOpen] = useState(false);
  const [profileEditOpen, setProfileEditOpen] = useState(false);
  const [langPickerOpen, setLangPickerOpen] = useState(false);

  const currentLang = i18n.language as LanguageCode;
  const currentLangNative =
    SUPPORTED_LANGUAGES.find((l) => l.code === currentLang)?.native ?? 'English';

  /**
   * Apply the new language locally (instant UI re-render via i18next),
   * persist to SecureStore, AND push the choice to the backend so the
   * next AI reply / Whisper STT call uses the same language. Failure
   * to reach the backend is non-fatal — the local change still works.
   */
  const handleLangChange = async (code: LanguageCode) => {
    setLangPickerOpen(false);
    if (code === currentLang) return;
    await setLanguage(code);
    try {
      await updateProfile({ preferred_language: code });
      void onRefreshUser();
    } catch {
      /* server unreachable — try again on next save */
    }
  };

  const plan = user?.subscription?.plan ?? 'free';
  const planName = user?.subscription?.plan_name ?? 'Free';
  const isPremium = plan === 'premium';
  const remaining = user?.subscription?.remaining_today;
  const dailyLimit = user?.subscription?.daily_limit;

  const handleManageSubscription = () => {
    const url =
      Platform.OS === 'ios'
        ? 'https://apps.apple.com/account/subscriptions'
        : 'https://play.google.com/store/account/subscriptions';
    Linking.openURL(url).catch(() =>
      Alert.alert(t('settings.couldNotOpenSubscription'), url),
    );
  };

  const handleSignOut = () => {
    Alert.alert(
      t('auth.signOut'),
      t('auth.signOutConfirm'),
      [
        { text: t('common.cancel'), style: 'cancel' },
        {
          text: t('auth.signOut'),
          style: 'destructive',
          onPress: async () => {
            try {
              await logout();
            } catch (err) {
              Alert.alert(t('auth.signOutFailed'), (err as Error).message);
            }
          },
        },
      ],
    );
  };

  const openLink = (url: string) => {
    Linking.openURL(url).catch(() =>
      Alert.alert(t('settings.couldNotOpenLink'), url),
    );
  };

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <View style={styles.section}>
        <Text style={styles.sectionHeading}>{t('settings.account')}</Text>
        <View style={styles.row}>
          <Ionicons name="person-outline" size={18} color={colors.textMuted} />
          <View style={styles.rowText}>
            <Text style={styles.rowLabel}>{user?.name ?? 'Signed in'}</Text>
            {user?.email && <Text style={styles.rowSub}>{user.email}</Text>}
          </View>
        </View>
        <Pressable
          onPress={() => setProfileEditOpen(true)}
          style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
        >
          <Ionicons name="fitness-outline" size={18} color={colors.textMuted} />
          <Text style={styles.rowLink}>{t('settings.editProfile')}</Text>
          <Ionicons
            name="chevron-forward"
            size={14}
            color={colors.textMuted}
            style={styles.rowChevron}
          />
        </Pressable>
        <Pressable
          onPress={() => setLangPickerOpen(true)}
          style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
        >
          <Ionicons name="language-outline" size={18} color={colors.textMuted} />
          <Text style={styles.rowLink}>{t('settings.language')}</Text>
          <Text style={styles.rowValue}>{currentLangNative}</Text>
          <Ionicons
            name="chevron-forward"
            size={14}
            color={colors.textMuted}
            style={styles.rowChevron}
          />
        </Pressable>
      </View>

      <View style={styles.section}>
        <Text style={styles.sectionHeading}>{t('settings.subscription')}</Text>
        <View style={styles.row}>
          <Ionicons
            name={isPremium ? 'sparkles' : 'flash-outline'}
            size={18}
            color={isPremium ? colors.primary : colors.textMuted}
          />
          <View style={styles.rowText}>
            <View style={styles.planRow}>
              <Text style={styles.rowLabel}>{planName}</Text>
              {isPremium && (
                <View style={styles.premiumPill}>
                  <Text style={styles.premiumPillText}>PREMIUM</Text>
                </View>
              )}
            </View>
            {!isPremium && dailyLimit != null && remaining != null && (
              <Text style={styles.rowSub}>
                {t('settings.messagesLeft', { remaining, limit: dailyLimit })}
              </Text>
            )}
            {isPremium && (
              <Text style={styles.rowSub}>{t('settings.premiumPerks')}</Text>
            )}
          </View>
        </View>
        {isPremium ? (
          <Pressable
            onPress={handleManageSubscription}
            style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
          >
            <Ionicons name="settings-outline" size={18} color={colors.textMuted} />
            <Text style={styles.rowLink}>{t('settings.manageSubscription')}</Text>
            <Ionicons
              name="open-outline"
              size={14}
              color={colors.textMuted}
              style={styles.rowChevron}
            />
          </Pressable>
        ) : (
          <Pressable
            onPress={() => setPaywallOpen(true)}
            style={({ pressed }) => [
              styles.upgradeCta,
              pressed && styles.rowPressed,
            ]}
          >
            <Ionicons name="sparkles" size={16} color={colors.textPrimary} />
            <Text style={styles.upgradeCtaText}>{t('settings.upgradePremium')}</Text>
          </Pressable>
        )}
      </View>

      <View style={styles.section}>
        <Text style={styles.sectionHeading}>{t('settings.support')}</Text>
        <Pressable
          onPress={() => openLink('https://hexalife.app/privacy')}
          style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
        >
          <Ionicons name="shield-checkmark-outline" size={18} color={colors.textMuted} />
          <Text style={styles.rowLink}>{t('settings.privacyPolicy')}</Text>
          <Ionicons
            name="open-outline"
            size={14}
            color={colors.textMuted}
            style={styles.rowChevron}
          />
        </Pressable>
        <Pressable
          onPress={() => openLink('https://hexalife.app/terms')}
          style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
        >
          <Ionicons name="document-text-outline" size={18} color={colors.textMuted} />
          <Text style={styles.rowLink}>{t('settings.termsOfService')}</Text>
          <Ionicons
            name="open-outline"
            size={14}
            color={colors.textMuted}
            style={styles.rowChevron}
          />
        </Pressable>
      </View>

      <View style={styles.section}>
        <Text style={styles.sectionHeading}>{t('settings.about')}</Text>
        <View style={styles.disclaimer}>
          <Text style={styles.disclaimerText}>{t('settings.disclaimer')}</Text>
        </View>
        <View style={styles.row}>
          <Ionicons name="information-circle-outline" size={18} color={colors.textMuted} />
          <Text style={styles.rowLabel}>{t('settings.version')}</Text>
          <Text style={styles.rowValue}>{version}</Text>
        </View>
      </View>

      <Pressable
        onPress={handleSignOut}
        style={({ pressed }) => [styles.signOutBtn, pressed && styles.rowPressed]}
      >
        <Ionicons name="log-out-outline" size={18} color={colors.danger} />
        <Text style={styles.signOutLabel}>{t('auth.signOut')}</Text>
      </Pressable>

      <PaywallScreen
        visible={paywallOpen}
        onClose={() => setPaywallOpen(false)}
        onEntitlementChanged={onRefreshUser}
      />

      <ProfileSetupScreen
        visible={profileEditOpen}
        mode="edit"
        onFinish={() => {
          setProfileEditOpen(false);
          void onRefreshUser();
        }}
        onClose={() => {
          setProfileEditOpen(false);
          void onRefreshUser();
        }}
      />

      <Modal
        visible={langPickerOpen}
        transparent
        animationType="fade"
        onRequestClose={() => setLangPickerOpen(false)}
      >
        <Pressable
          style={langPickerStyles.backdrop}
          onPress={() => setLangPickerOpen(false)}
        >
          <Pressable style={langPickerStyles.sheet} onPress={(e) => e.stopPropagation?.()}>
            <View style={langPickerStyles.head}>
              <Ionicons name="language-outline" size={20} color={colors.primary} />
              <Text style={langPickerStyles.title}>{t('settings.language')}</Text>
              <Pressable onPress={() => setLangPickerOpen(false)} hitSlop={8}>
                <Ionicons name="close" size={20} color={colors.textMuted} />
              </Pressable>
            </View>
            {SUPPORTED_LANGUAGES.map((lang) => {
              const selected = currentLang === lang.code;
              return (
                <Pressable
                  key={lang.code}
                  onPress={() => void handleLangChange(lang.code as LanguageCode)}
                  style={({ pressed }) => [
                    langPickerStyles.row,
                    selected && langPickerStyles.rowSelected,
                    pressed && { opacity: 0.85 },
                  ]}
                >
                  <View style={{ flex: 1 }}>
                    <Text style={langPickerStyles.native}>{lang.native}</Text>
                    <Text style={langPickerStyles.english}>{lang.name}</Text>
                  </View>
                  {selected && (
                    <Ionicons name="checkmark" size={18} color={colors.primary} />
                  )}
                </Pressable>
              );
            })}
          </Pressable>
        </Pressable>
      </Modal>
    </ScrollView>
  );
}

const langPickerStyles = StyleSheet.create({
  backdrop: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.55)',
    justifyContent: 'flex-end',
  },
  sheet: {
    backgroundColor: colors.background,
    borderTopLeftRadius: radius.lg,
    borderTopRightRadius: radius.lg,
    padding: spacing.md,
    paddingBottom: spacing.xl,
    gap: spacing.xs,
  },
  head: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginBottom: spacing.md,
  },
  title: {
    flex: 1,
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: spacing.sm + 2,
    paddingHorizontal: spacing.sm + 2,
    borderRadius: radius.md,
    backgroundColor: colors.surface,
    marginBottom: 4,
  },
  rowSelected: {
    backgroundColor: 'rgba(124,92,255,0.18)',
  },
  native: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '600',
  },
  english: {
    color: colors.textMuted,
    fontSize: fontSize.xs,
  },
});

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: colors.background },
  content: { padding: spacing.md, paddingBottom: spacing.xl },
  section: {
    marginBottom: spacing.lg,
  },
  sectionHeading: {
    color: colors.textMuted,
    fontSize: fontSize.xs,
    fontWeight: '700',
    textTransform: 'uppercase',
    letterSpacing: 1.2,
    marginBottom: spacing.sm,
    paddingHorizontal: spacing.sm,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: spacing.md,
    paddingHorizontal: spacing.md,
    backgroundColor: colors.surface,
    borderRadius: radius.md,
    marginBottom: 2,
    gap: spacing.md,
  },
  rowPressed: { opacity: 0.85 },
  rowText: { flex: 1 },
  rowLabel: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '500',
    flex: 1,
  },
  rowValue: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
    fontWeight: '600',
  },
  rowSub: {
    color: colors.textMuted,
    fontSize: fontSize.xs,
    marginTop: 2,
  },
  rowLink: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '500',
    flex: 1,
  },
  rowChevron: { opacity: 0.6 },
  disclaimer: {
    backgroundColor: 'rgba(124,92,255,0.08)',
    borderWidth: 1,
    borderColor: 'rgba(124,92,255,0.22)',
    borderRadius: radius.md,
    padding: spacing.md,
    marginBottom: spacing.sm,
  },
  disclaimerText: {
    color: colors.textSecondary,
    fontSize: fontSize.sm,
    lineHeight: 20,
  },
  signOutBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: spacing.md,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.danger + '55',
    backgroundColor: 'rgba(239,68,68,0.08)',
    gap: spacing.sm,
    marginTop: spacing.md,
  },
  signOutLabel: {
    color: colors.danger,
    fontSize: fontSize.md,
    fontWeight: '700',
  },
  planRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  premiumPill: {
    backgroundColor: colors.primary,
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: radius.sm,
  },
  premiumPillText: {
    color: colors.textPrimary,
    fontSize: 10,
    fontWeight: '800',
    letterSpacing: 0.6,
  },
  upgradeCta: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.sm,
    backgroundColor: colors.primary,
    paddingVertical: spacing.md - 2,
    borderRadius: radius.md,
    marginTop: spacing.sm,
  },
  upgradeCtaText: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
  },
});
