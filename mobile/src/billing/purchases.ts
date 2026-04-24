/**
 * Thin wrapper around react-native-purchases that degrades gracefully
 * when the native module isn't linked (Expo Go, web, SSR tests).
 *
 * Why this layer: `react-native-purchases` registers native iOS/Android
 * modules that don't exist in the Expo Go runtime. Importing it
 * unconditionally from a hook or screen crashes the whole app on
 * Expo Go with "native module cannot be null". Dynamic require + try/
 * catch lets us keep the app bootable in Expo Go, with the paywall
 * showing a "dev-build required" state, while dev-build / standalone
 * builds get real purchase flows.
 */

import { Platform } from 'react-native';

type PurchasesPackage = {
  identifier: string;
  packageType: string;
  product: {
    identifier: string;
    title: string;
    description: string;
    priceString: string;
    price: number;
    currencyCode: string;
    introPrice?: {
      priceString: string;
      periodNumberOfUnits: number;
      periodUnit: string;
    } | null;
  };
};

export type PurchasesOffering = {
  identifier: string;
  serverDescription: string;
  availablePackages: PurchasesPackage[];
  monthly?: PurchasesPackage | null;
  annual?: PurchasesPackage | null;
};

export type PurchasesCustomerInfo = {
  entitlements: {
    active: Record<string, { identifier: string; productIdentifier: string }>;
  };
  originalAppUserId: string;
};

// Dynamic require — only populated in builds that actually include the
// native module. `null` in Expo Go / web / Jest.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let Purchases: any = null;
try {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const mod = require('react-native-purchases');
  Purchases = mod?.default ?? mod;
} catch {
  Purchases = null;
}

export const isPurchasesAvailable = Purchases !== null;

function platformApiKey(): string | null {
  const key =
    Platform.OS === 'ios'
      ? process.env.EXPO_PUBLIC_REVENUECAT_API_KEY_IOS
      : process.env.EXPO_PUBLIC_REVENUECAT_API_KEY_ANDROID;
  return typeof key === 'string' && key.length > 0 ? key : null;
}

/**
 * Idempotent — safe to call on every sign-in. Returns true if
 * RevenueCat is now configured (or was already), false if the native
 * module or API key aren't available.
 */
export function configurePurchases(appUserID: string): boolean {
  if (!Purchases) return false;
  const apiKey = platformApiKey();
  if (!apiKey) return false;
  try {
    Purchases.configure({ apiKey, appUserID });
    return true;
  } catch {
    return false;
  }
}

/**
 * Fetch the "default" offering with the monthly and annual packages
 * we expect. Returns null when Purchases is unavailable OR when the
 * remote offering doesn't exist yet (dashboard not configured).
 */
export async function fetchOfferings(): Promise<PurchasesOffering | null> {
  if (!Purchases) return null;
  try {
    const offerings = await Purchases.getOfferings();
    return offerings?.current ?? null;
  } catch {
    return null;
  }
}

export async function purchasePackage(
  pkg: PurchasesPackage,
): Promise<PurchasesCustomerInfo> {
  if (!Purchases) {
    throw new Error('Purchases are not available in this build.');
  }
  const { customerInfo } = await Purchases.purchasePackage(pkg);
  return customerInfo;
}

export async function restorePurchases(): Promise<PurchasesCustomerInfo> {
  if (!Purchases) {
    throw new Error('Purchases are not available in this build.');
  }
  return Purchases.restorePurchases();
}

/**
 * Convenience: has the user got the `premium` entitlement active right
 * now (from RC's point of view)? Used to reconcile local UI with the
 * SDK after a purchase completes.
 */
export function hasPremiumEntitlement(info: PurchasesCustomerInfo): boolean {
  return Boolean(info?.entitlements?.active?.premium);
}
