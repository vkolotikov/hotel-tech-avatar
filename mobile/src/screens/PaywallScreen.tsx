import { useEffect, useMemo, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import {
  fetchOfferings,
  isPurchasesAvailable,
  purchasePackage,
  restorePurchases,
  hasPremiumEntitlement,
  type PurchasesOffering,
} from '../billing/purchases';
import { colors, spacing, radius, fontSize } from '../theme';

type Props = {
  visible: boolean;
  onClose: () => void;
  /**
   * Fires when the user's entitlement definitely changed — either from
   * a successful purchase or a successful restore. Parent should
   * re-fetch /me so the UI picks up the new premium state.
   */
  onEntitlementChanged?: () => void;
  /**
   * Optional extra context shown above the CTA — used when the paywall
   * opens because the user hit the free-tier daily limit.
   */
  reason?: string | null;
};

type FallbackPlan = {
  id: 'monthly' | 'annual';
  title: string;
  priceLabel: string;
  subtitle: string;
  badge?: string;
};

// Used when RC offerings are unavailable (Expo Go / dashboard not
// configured yet / network error). Copy is authoritative locally so
// the paywall is never a blank card — actual pricing on real devices
// comes from RC's localised prices.
const FALLBACK_PLANS: FallbackPlan[] = [
  {
    id: 'annual',
    title: 'Annual',
    priceLabel: '€278.40 / year',
    subtitle: '20% off vs monthly',
    badge: 'BEST VALUE',
  },
  {
    id: 'monthly',
    title: 'Monthly',
    priceLabel: '€29 / month',
    subtitle: '5-day free trial, cancel anytime',
  },
];

const FEATURES: Array<{ icon: keyof typeof Ionicons.glyphMap; label: string }> = [
  { icon: 'people-outline',      label: 'Talk to all six experts' },
  { icon: 'infinite-outline',    label: 'Unlimited day-to-day messages' },
  { icon: 'mic-outline',         label: 'Voice mode + natural replies' },
  { icon: 'attach-outline',      label: 'Upload photos and documents' },
  { icon: 'shield-checkmark-outline', label: 'Every claim grounded in research' },
  { icon: 'time-outline',        label: '90-day conversation memory' },
];

export function PaywallScreen({ visible, onClose, onEntitlementChanged, reason }: Props) {
  const insets = useSafeAreaInsets();
  const [offering, setOffering] = useState<PurchasesOffering | null>(null);
  const [selectedId, setSelectedId] = useState<'monthly' | 'annual'>('annual');
  const [loadingOfferings, setLoadingOfferings] = useState(false);
  const [purchasing, setPurchasing] = useState(false);
  const [restoring, setRestoring] = useState(false);

  useEffect(() => {
    if (!visible || !isPurchasesAvailable) return;
    setLoadingOfferings(true);
    fetchOfferings()
      .then((o) => setOffering(o))
      .finally(() => setLoadingOfferings(false));
  }, [visible]);

  const realPackages = useMemo(() => {
    if (!offering) return null;
    return {
      monthly: offering.monthly ?? null,
      annual: offering.annual ?? null,
    };
  }, [offering]);

  const handlePurchase = async () => {
    if (!isPurchasesAvailable) {
      Alert.alert(
        'Development build required',
        "In-app purchases don't run in Expo Go. Build the app with `eas build --profile development` and try again — nothing in the paywall changes.",
      );
      return;
    }
    const pkg = realPackages?.[selectedId];
    if (!pkg) {
      Alert.alert(
        "Plan isn't available yet",
        'This plan hasn\'t been published to the store. Check the RevenueCat dashboard and try again.',
      );
      return;
    }
    setPurchasing(true);
    try {
      const info = await purchasePackage(pkg);
      if (hasPremiumEntitlement(info)) {
        onEntitlementChanged?.();
        Alert.alert('Welcome to Premium', "You're all set — enjoy the full experience.");
        onClose();
      } else {
        Alert.alert(
          'Purchase pending',
          "We haven't seen the entitlement activate yet. It should appear within a minute.",
        );
      }
    } catch (err) {
      const message = (err as Error).message;
      // RC surfaces userCancelled as an error — suppress silently.
      if (!/user.?cancel/i.test(message)) {
        Alert.alert('Purchase failed', message);
      }
    } finally {
      setPurchasing(false);
    }
  };

  const handleRestore = async () => {
    if (!isPurchasesAvailable) {
      Alert.alert(
        'Development build required',
        'Restore works on a native build (dev or production), not in Expo Go.',
      );
      return;
    }
    setRestoring(true);
    try {
      const info = await restorePurchases();
      if (hasPremiumEntitlement(info)) {
        onEntitlementChanged?.();
        Alert.alert('Restored', 'Premium is active on this device.');
        onClose();
      } else {
        Alert.alert(
          'No active subscription found',
          'We couldn\'t find a Premium subscription on this Apple ID / Google account.',
        );
      }
    } catch (err) {
      Alert.alert('Restore failed', (err as Error).message);
    } finally {
      setRestoring(false);
    }
  };

  const plans: Array<FallbackPlan & { realPriceLabel?: string }> =
    realPackages
      ? [
          {
            ...FALLBACK_PLANS[0],
            realPriceLabel: realPackages.annual?.product.priceString,
          },
          {
            ...FALLBACK_PLANS[1],
            realPriceLabel: realPackages.monthly?.product.priceString,
          },
        ]
      : FALLBACK_PLANS;

  const isDevPreview = !isPurchasesAvailable;

  return (
    <Modal
      visible={visible}
      transparent
      animationType="slide"
      onRequestClose={onClose}
    >
      <View style={[styles.backdrop, { paddingTop: insets.top }]}>
        <Pressable
          onPress={onClose}
          accessibilityLabel="Close paywall"
          style={styles.closeBtn}
          hitSlop={8}
        >
          <Ionicons name="close" size={24} color={colors.textPrimary} />
        </Pressable>

        <ScrollView
          contentContainerStyle={[styles.content, { paddingBottom: insets.bottom + spacing.xl }]}
          showsVerticalScrollIndicator={false}
        >
          <View style={styles.heroIconWrap}>
            <Ionicons name="sparkles" size={32} color={colors.primary} />
          </View>
          <Text style={styles.heading}>WellnessAI Premium</Text>
          <Text style={styles.subheading}>
            Six expert avatars, citation-backed, without the daily limit.
          </Text>

          {reason && (
            <View style={styles.reasonCard}>
              <Ionicons name="information-circle-outline" size={16} color={colors.warning} />
              <Text style={styles.reasonText}>{reason}</Text>
            </View>
          )}

          <View style={styles.featureList}>
            {FEATURES.map((f) => (
              <View key={f.label} style={styles.featureRow}>
                <Ionicons name={f.icon} size={18} color={colors.primary} />
                <Text style={styles.featureLabel}>{f.label}</Text>
              </View>
            ))}
          </View>

          <View style={styles.planList}>
            {loadingOfferings && (
              <View style={styles.loadingRow}>
                <ActivityIndicator color={colors.textMuted} size="small" />
                <Text style={styles.loadingLabel}>Loading plans…</Text>
              </View>
            )}
            {plans.map((plan) => {
              const selected = selectedId === plan.id;
              return (
                <Pressable
                  key={plan.id}
                  onPress={() => setSelectedId(plan.id)}
                  style={({ pressed }) => [
                    styles.planCard,
                    selected && styles.planCardSelected,
                    pressed && { opacity: 0.88 },
                  ]}
                >
                  <View style={styles.planCardLeft}>
                    <View style={styles.planCardTitleRow}>
                      <Text style={styles.planTitle}>{plan.title}</Text>
                      {plan.badge && (
                        <View style={styles.planBadge}>
                          <Text style={styles.planBadgeText}>{plan.badge}</Text>
                        </View>
                      )}
                    </View>
                    <Text style={styles.planPrice}>
                      {plan.realPriceLabel ?? plan.priceLabel}
                    </Text>
                    <Text style={styles.planSubtitle}>{plan.subtitle}</Text>
                  </View>
                  <View style={[styles.radio, selected && styles.radioSelected]}>
                    {selected && <View style={styles.radioDot} />}
                  </View>
                </Pressable>
              );
            })}
          </View>

          <Pressable
            onPress={handlePurchase}
            disabled={purchasing || restoring}
            style={({ pressed }) => [
              styles.cta,
              (pressed || purchasing) && { opacity: 0.85 },
              (purchasing || restoring) && { opacity: 0.6 },
            ]}
          >
            {purchasing ? (
              <ActivityIndicator color={colors.textPrimary} />
            ) : (
              <Text style={styles.ctaText}>
                {selectedId === 'monthly' ? 'Start 5-day free trial' : 'Subscribe'}
              </Text>
            )}
          </Pressable>

          <Pressable
            onPress={handleRestore}
            disabled={purchasing || restoring}
            style={({ pressed }) => [styles.restore, pressed && { opacity: 0.7 }]}
          >
            <Text style={styles.restoreText}>
              {restoring ? 'Restoring…' : 'Restore purchases'}
            </Text>
          </Pressable>

          {isDevPreview && (
            <View style={styles.devBanner}>
              <Ionicons name="construct-outline" size={14} color={colors.warning} />
              <Text style={styles.devBannerText}>
                Preview mode — purchases require a development build
                (`eas build --profile development`) or the production app.
              </Text>
            </View>
          )}

          <Text style={styles.fineprint}>
            {Platform.OS === 'ios'
              ? 'Subscription auto-renews each period until cancelled in the App Store. Cancel at least 24 hours before the next billing date to avoid being charged.'
              : 'Subscription auto-renews each period until cancelled in Google Play. Cancel at least 24 hours before the next billing date to avoid being charged.'}
          </Text>
        </ScrollView>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: {
    flex: 1,
    backgroundColor: colors.background,
  },
  closeBtn: {
    alignSelf: 'flex-end',
    margin: spacing.md,
    width: 36,
    height: 36,
    borderRadius: radius.pill,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  content: {
    paddingHorizontal: spacing.lg,
    paddingTop: spacing.md,
  },
  heroIconWrap: {
    alignSelf: 'flex-start',
    width: 56,
    height: 56,
    borderRadius: radius.pill,
    backgroundColor: colors.primary + '22',
    borderWidth: 1,
    borderColor: colors.primary + '55',
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.md,
  },
  heading: {
    color: colors.textPrimary,
    fontSize: 28,
    fontWeight: '800',
    letterSpacing: -0.5,
    marginBottom: spacing.xs,
  },
  subheading: {
    color: colors.textSecondary,
    fontSize: fontSize.md,
    lineHeight: 22,
    marginBottom: spacing.lg,
  },
  reasonCard: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.sm,
    backgroundColor: 'rgba(245,158,11,0.12)',
    borderWidth: 1,
    borderColor: 'rgba(245,158,11,0.3)',
    borderRadius: radius.md,
    padding: spacing.sm + 2,
    marginBottom: spacing.md,
  },
  reasonText: {
    color: colors.textPrimary,
    fontSize: fontSize.sm,
    flex: 1,
    lineHeight: 20,
  },
  featureList: {
    gap: spacing.sm,
    marginBottom: spacing.lg,
  },
  featureRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm + 2,
  },
  featureLabel: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    flex: 1,
  },
  planList: {
    gap: spacing.sm,
    marginBottom: spacing.md,
  },
  loadingRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    paddingVertical: spacing.sm,
  },
  loadingLabel: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
  },
  planCard: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    padding: spacing.md,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.08)',
  },
  planCardSelected: {
    borderColor: colors.primary,
    backgroundColor: colors.primary + '11',
  },
  planCardLeft: { flex: 1 },
  planCardTitleRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginBottom: 4,
  },
  planTitle: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
  },
  planBadge: {
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: radius.sm,
    backgroundColor: colors.primary,
  },
  planBadgeText: {
    color: colors.textPrimary,
    fontSize: 10,
    fontWeight: '800',
    letterSpacing: 0.6,
  },
  planPrice: {
    color: colors.textPrimary,
    fontSize: fontSize.lg,
    fontWeight: '700',
    marginBottom: 2,
  },
  planSubtitle: {
    color: colors.textMuted,
    fontSize: fontSize.xs,
  },
  radio: {
    width: 22,
    height: 22,
    borderRadius: radius.pill,
    borderWidth: 2,
    borderColor: colors.textMuted,
    alignItems: 'center',
    justifyContent: 'center',
  },
  radioSelected: {
    borderColor: colors.primary,
  },
  radioDot: {
    width: 10,
    height: 10,
    borderRadius: radius.pill,
    backgroundColor: colors.primary,
  },
  cta: {
    backgroundColor: colors.primary,
    paddingVertical: spacing.md + 2,
    borderRadius: radius.pill,
    alignItems: 'center',
    marginTop: spacing.md,
  },
  ctaText: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '800',
    letterSpacing: 0.3,
  },
  restore: {
    alignItems: 'center',
    paddingVertical: spacing.sm,
    marginTop: spacing.sm,
  },
  restoreText: {
    color: colors.textSecondary,
    fontSize: fontSize.sm,
    fontWeight: '600',
  },
  devBanner: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.xs + 2,
    padding: spacing.sm,
    marginTop: spacing.md,
    borderRadius: radius.md,
    backgroundColor: 'rgba(245,158,11,0.1)',
    borderWidth: 1,
    borderColor: 'rgba(245,158,11,0.25)',
  },
  devBannerText: {
    color: colors.textSecondary,
    fontSize: fontSize.xs,
    flex: 1,
    lineHeight: 16,
  },
  fineprint: {
    color: colors.textMuted,
    fontSize: 11,
    lineHeight: 15,
    marginTop: spacing.md,
  },
});
