/**
 * Sentry bootstrap for the mobile app.
 *
 * Call initSentry() once as the first thing in App.tsx. It is env-gated —
 * if EXPO_PUBLIC_SENTRY_DSN is not set, the function is a no-op and the
 * app runs fine without Sentry installed. This keeps local development
 * and pre-provisioning builds working without the package.
 */

let initialised = false;

export function initSentry(): void {
  if (initialised) return;
  initialised = true;

  const dsn = process.env.EXPO_PUBLIC_SENTRY_DSN;
  if (!dsn) return;

  try {
    // Require at runtime so the app still boots if the package isn't
    // installed yet (e.g. before the next native rebuild).
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const Sentry = require('@sentry/react-native');
    Sentry.init({
      dsn,
      // Keep this low on mobile — perf traces are expensive on cold start.
      tracesSampleRate: Number(process.env.EXPO_PUBLIC_SENTRY_TRACES_SAMPLE_RATE ?? 0.05),
      // Don't capture PII by default; the wellness vertical is health-adjacent.
      sendDefaultPii: false,
      enableNative: true,
    });
  } catch {
    // Module not installed yet; silently skip.
  }
}
