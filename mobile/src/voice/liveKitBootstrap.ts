/**
 * Calls @livekit/react-native's registerGlobals() once at app startup.
 *
 * registerGlobals polyfills RTCPeerConnection / MediaStream / etc onto
 * global so livekit-client can import them as if running in a browser.
 * Must run BEFORE any other livekit-client code touches those globals.
 *
 * Wrapped in a try/catch so the app still boots in Expo Go (no native
 * WebRTC linked) — LiveAvatar features fall back to "dev build
 * required" UI in that case.
 */

let initialised = false;

export function registerLiveKitGlobals(): void {
  if (initialised) return;
  initialised = true;
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const mod = require('@livekit/react-native');
    if (typeof mod?.registerGlobals === 'function') {
      mod.registerGlobals();
    }
  } catch {
    // Swallowed — Expo Go path. LiveAvatarModal will detect the absence
    // of a working LiveKit module and render the existing
    // "Development build required" panel.
  }
}
