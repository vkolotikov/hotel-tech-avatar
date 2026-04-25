import { useState } from 'react';
import {
  Alert,
  Linking,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { AuthUser, logout } from '../api';
import { PaywallScreen } from './PaywallScreen';
import { ProfileSetupScreen } from './ProfileSetupScreen';
import { colors, spacing, radius, fontSize } from '../theme';
// eslint-disable-next-line @typescript-eslint/no-var-requires
const pkg = require('../../package.json') as { version?: string };

type Props = {
  user: AuthUser | null;
  onRefreshUser: () => Promise<void>;
};

export function SettingsScreen({ user, onRefreshUser }: Props) {
  const version = pkg.version ?? 'dev';
  const [paywallOpen, setPaywallOpen] = useState(false);
  const [profileEditOpen, setProfileEditOpen] = useState(false);
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
      Alert.alert("Couldn't open subscription settings", url),
    );
  };

  const handleSignOut = () => {
    Alert.alert(
      'Sign out',
      'You will be signed out of WellnessAI on this device.',
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Sign out',
          style: 'destructive',
          onPress: async () => {
            try {
              await logout();
            } catch (err) {
              Alert.alert('Sign out failed', (err as Error).message);
            }
          },
        },
      ],
    );
  };

  const openLink = (url: string) => {
    Linking.openURL(url).catch(() =>
      Alert.alert("Couldn't open link", url),
    );
  };

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <View style={styles.section}>
        <Text style={styles.sectionHeading}>Account</Text>
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
          <Text style={styles.rowLink}>Edit profile</Text>
          <Ionicons
            name="chevron-forward"
            size={14}
            color={colors.textMuted}
            style={styles.rowChevron}
          />
        </Pressable>
      </View>

      <View style={styles.section}>
        <Text style={styles.sectionHeading}>Subscription</Text>
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
                {remaining} of {dailyLimit} messages left today
              </Text>
            )}
            {isPremium && (
              <Text style={styles.rowSub}>Unlimited messages, all features</Text>
            )}
          </View>
        </View>
        {isPremium ? (
          <Pressable
            onPress={handleManageSubscription}
            style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
          >
            <Ionicons name="settings-outline" size={18} color={colors.textMuted} />
            <Text style={styles.rowLink}>Manage subscription</Text>
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
            <Text style={styles.upgradeCtaText}>Upgrade to Premium</Text>
          </Pressable>
        )}
      </View>

      <View style={styles.section}>
        <Text style={styles.sectionHeading}>Support</Text>
        <Pressable
          onPress={() => openLink('https://wellnessai.app/privacy')}
          style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
        >
          <Ionicons name="shield-checkmark-outline" size={18} color={colors.textMuted} />
          <Text style={styles.rowLink}>Privacy policy</Text>
          <Ionicons
            name="open-outline"
            size={14}
            color={colors.textMuted}
            style={styles.rowChevron}
          />
        </Pressable>
        <Pressable
          onPress={() => openLink('https://wellnessai.app/terms')}
          style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
        >
          <Ionicons name="document-text-outline" size={18} color={colors.textMuted} />
          <Text style={styles.rowLink}>Terms of service</Text>
          <Ionicons
            name="open-outline"
            size={14}
            color={colors.textMuted}
            style={styles.rowChevron}
          />
        </Pressable>
      </View>

      <View style={styles.section}>
        <Text style={styles.sectionHeading}>About</Text>
        <View style={styles.disclaimer}>
          <Text style={styles.disclaimerText}>
            WellnessAI offers wellness education only. It is not a substitute for
            professional medical advice, diagnosis, or treatment. Always seek the
            advice of a qualified clinician with any questions about a medical
            condition.
          </Text>
        </View>
        <View style={styles.row}>
          <Ionicons name="information-circle-outline" size={18} color={colors.textMuted} />
          <Text style={styles.rowLabel}>Version</Text>
          <Text style={styles.rowValue}>{version}</Text>
        </View>
      </View>

      <Pressable
        onPress={handleSignOut}
        style={({ pressed }) => [styles.signOutBtn, pressed && styles.rowPressed]}
      >
        <Ionicons name="log-out-outline" size={18} color={colors.danger} />
        <Text style={styles.signOutLabel}>Sign out</Text>
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
    </ScrollView>
  );
}

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
