import { useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Modal,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { ApiError } from '../../api';
import {
  keepAliveLiveAvatarSession,
  startLiveAvatarSession,
  stopLiveAvatarSession,
  type LiveAvatarSession,
} from '../../api/liveavatar';
import { startLiveAvatarLiteSession } from '../../voice/liveAvatarSession';
import { LiveAvatarSocket } from '../../voice/liveAvatarSocket';
import { colors, spacing, radius, fontSize } from '../../theme';

type Props = {
  visible: boolean;
  avatarSlug: string;
  avatarName: string;
  onClose: () => void;
};

type State =
  | { kind: 'idle' }
  | { kind: 'loading' }
  | { kind: 'ready'; session: LiveAvatarSession }
  | { kind: 'error'; title: string; body: string };

type SocketStatus = 'idle' | 'connecting' | 'live' | 'closed';

const KEEP_ALIVE_INTERVAL_MS = 30_000;

// react-native-webview is a native module; wrap the require so the
// component still compiles in Expo Go (no native linked) with a
// friendly fallback. Dev builds pick up the real component.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let WebViewComponent: any = null;
try {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const mod = require('react-native-webview');
  WebViewComponent = mod?.WebView ?? mod?.default ?? null;
} catch {
  WebViewComponent = null;
}

/**
 * CSS + JS injected into the embed page to hide LiveAvatar's demo
 * UI. That UI drives FULL-mode sessions (they run the LLM), which
 * 400s against our LITE-mode embed. We keep only the avatar video
 * so our own controls can drive it via postMessage in the audio-
 * piping slice.
 *
 * Hiding strategy — layered, because class-based CSS alone is not
 * enough (LiveAvatar's inline `display: flex !important` on the
 * Chat-now button beats a generic `button { display: none }`):
 *
 *   1. Global style: kill buttons, selects, comboboxes via
 *      !important selectors.
 *   2. Text-based scrub: walk the DOM, find any button whose visible
 *      text is "Chat now" / "Start" / contains "chat", and remove
 *      its closest card container entirely. Also wipes nearby
 *      <select>-based language pickers.
 *   3. MutationObserver re-runs the scrub whenever the embed's
 *      React tree mounts new children.
 *   4. 500ms interval as a paranoid fallback for anything the
 *      observer misses (e.g. shadow DOM repaint).
 */
const HIDE_DEMO_UI_CSS = `
  (function() {
    var css = '' +
      'html, body { background: #000 !important; margin: 0 !important; padding: 0 !important; }' +
      'button, select, [role="combobox"], [role="listbox"] { display: none !important; }' +
      '[class*="language" i], [class*="chatNow" i], [class*="chat-now" i], [class*="controls" i], [class*="footer" i] { display: none !important; }';
    var style = document.createElement('style');
    style.type = 'text/css';
    style.innerHTML = css;
    (document.head || document.documentElement).appendChild(style);

    function scrub() {
      // Remove any button whose text matches the demo triggers, plus
      // the closest "card" wrapper so we don't leave a ghost outline.
      var buttons = document.querySelectorAll('button, [role="button"]');
      for (var i = 0; i < buttons.length; i++) {
        var btn = buttons[i];
        var text = ((btn.innerText || btn.textContent || '') + '').trim().toLowerCase();
        if (text === 'chat now' || text === 'start' || text === 'start chat' || text === 'try again') {
          var container = btn.closest('[class*="card" i]') || btn.closest('[class*="control" i]') || btn.parentElement;
          if (container) {
            container.style.setProperty('display', 'none', 'important');
          }
          btn.style.setProperty('display', 'none', 'important');
        }
      }
      // Kill any <select> (language picker) and its label wrapper.
      var selects = document.querySelectorAll('select');
      for (var j = 0; j < selects.length; j++) {
        var wrap = selects[j].closest('[class*="card" i]') || selects[j].parentElement;
        if (wrap) wrap.style.setProperty('display', 'none', 'important');
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', scrub);
    } else {
      scrub();
    }
    var obs = new MutationObserver(scrub);
    obs.observe(document.documentElement, { childList: true, subtree: true });
    // Belt-and-braces — some embed repaints fire outside our
    // observer scope.
    setInterval(scrub, 500);
  })();
  true;
`;

/**
 * Injected before the page loads. Forwards every window.postMessage
 * event back to native via ReactNativeWebView.postMessage so we can
 * learn the embed's protocol (ready-state, transcript, session events)
 * from real traffic before wiring the audio-piping direction.
 */
const LOG_POSTMESSAGE_BRIDGE = `
  (function() {
    if (!window.ReactNativeWebView) return;
    var originalAddEventListener = window.addEventListener;
    window.addEventListener('message', function(event) {
      try {
        var payload = typeof event.data === 'string'
          ? event.data
          : JSON.stringify(event.data);
        window.ReactNativeWebView.postMessage('pm:' + payload);
      } catch (e) {}
    });
  })();
  true;
`;

export function LiveAvatarModal({ visible, avatarSlug, avatarName, onClose }: Props) {
  const insets = useSafeAreaInsets();
  const [state, setState] = useState<State>({ kind: 'idle' });
  const [socketStatus, setSocketStatus] = useState<SocketStatus>('idle');

  // Refs hold transient session/socket state across re-renders without
  // triggering them. Cleanup on close uses these to send the DELETE
  // session call and tear down the WebSocket cleanly.
  const socketRef = useRef<LiveAvatarSocket | null>(null);
  const sessionIdRef = useRef<string | null>(null);
  const sessionTokenRef = useRef<string | null>(null);
  const liveSessionIdRef = useRef<string | null>(null);
  const keepAliveTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  // Tear-down helper used both by useEffect cleanup and by manual close.
  // Best-effort: ignores errors because the modal is going away anyway.
  const teardown = () => {
    if (keepAliveTimerRef.current) {
      clearInterval(keepAliveTimerRef.current);
      keepAliveTimerRef.current = null;
    }
    socketRef.current?.close();
    socketRef.current = null;
    setSocketStatus('idle');

    const sid = liveSessionIdRef.current;
    const tok = sessionTokenRef.current;
    if (sid && tok) {
      // Fire-and-forget — we don't block close on the response.
      void stopLiveAvatarSession(sid, tok);
    }
    liveSessionIdRef.current = null;
    sessionIdRef.current = null;
    sessionTokenRef.current = null;
  };

  useEffect(() => {
    if (!visible) {
      teardown();
      setState({ kind: 'idle' });
      return;
    }

    let cancelled = false;
    setState({ kind: 'loading' });
    setSocketStatus('idle');

    startLiveAvatarSession(avatarSlug)
      .then(async (session) => {
        if (cancelled) return;
        if (!session.session.url) {
          setState({
            kind: 'error',
            title: 'No stream URL returned',
            body: 'LiveAvatar accepted the request but came back without an embed URL. Try again in a moment.',
          });
          return;
        }
        setState({ kind: 'ready', session });

        // Phase 2: kick off the LITE WebSocket bridge in parallel with
        // the WebView loading. If this fails, video still works — users
        // see the avatar but won't be able to interact in voice mode.
        if (session.connect) {
          sessionIdRef.current = session.connect.session_id;
          sessionTokenRef.current = session.connect.session_token;
          await connectLiteSocket(session.connect, cancelled);
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        const mapped = mapErrorToState(err);
        setState(mapped);
      });

    return () => {
      cancelled = true;
      teardown();
    };
    // teardown is stable closure-only; intentional empty dep tail
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [visible, avatarSlug]);

  /**
   * Two-step LITE bring-up:
   *   1. POST /v1/sessions/start directly to LiveAvatar (Bearer auth)
   *      to get the wsUrl + livekit credentials.
   *   2. Open the WebSocket; flip socketStatus to 'live' once the
   *      `{state: "connected"}` ack arrives.
   *   3. Schedule a 30s keep-alive ping against our backend proxy.
   */
  const connectLiteSocket = async (
    connect: NonNullable<LiveAvatarSession['connect']>,
    cancelled: boolean,
  ) => {
    setSocketStatus('connecting');
    let lite;
    try {
      lite = await startLiveAvatarLiteSession(connect.start_url, connect.session_token);
    } catch (err) {
      // eslint-disable-next-line no-console
      console.warn('[LiveAvatar] /v1/sessions/start failed', err);
      setSocketStatus('closed');
      return;
    }
    if (cancelled) return;

    liveSessionIdRef.current = lite.sessionId;
    if (!lite.wsUrl) {
      // eslint-disable-next-line no-console
      console.warn('[LiveAvatar] start response had no ws_url; LITE commands disabled');
      setSocketStatus('closed');
      return;
    }

    const sock = new LiveAvatarSocket(lite.wsUrl, {
      onConnected: () => {
        // eslint-disable-next-line no-console
        console.log('[LiveAvatar] WebSocket connected');
        setSocketStatus('live');
      },
      onMessage: (msg, raw) => {
        // Phase 2 logging — shape we observe here drives Phase 3 design.
        // eslint-disable-next-line no-console
        console.log('[LiveAvatar→ws]', raw.length > 200 ? raw.slice(0, 200) + '…' : raw);
        void msg; // intentionally unused for now
      },
      onClose: (code, reason) => {
        // eslint-disable-next-line no-console
        console.log('[LiveAvatar] WebSocket closed', code, reason);
        setSocketStatus('closed');
      },
      onError: (err) => {
        // eslint-disable-next-line no-console
        console.warn('[LiveAvatar] WebSocket error', err);
      },
    });
    sock.open();
    socketRef.current = sock;

    // Keep-alive — LiveAvatar sessions are credit-leased; we ping our
    // backend proxy every 30s while the modal is open. If the upstream
    // session has ended (410), proxy returns false and we stop pinging.
    keepAliveTimerRef.current = setInterval(async () => {
      const sid = liveSessionIdRef.current;
      const tok = sessionTokenRef.current;
      if (!sid || !tok) return;
      const alive = await keepAliveLiveAvatarSession(sid, tok);
      if (!alive && keepAliveTimerRef.current) {
        clearInterval(keepAliveTimerRef.current);
        keepAliveTimerRef.current = null;
      }
    }, KEEP_ALIVE_INTERVAL_MS);
  };

  const isWebViewAvailable = WebViewComponent !== null;
  // Pulled out of JSX into locals so react-native/no-raw-text stops
  // mis-flagging the string literals inside `state.kind === '...'`
  // guards as bare JSX text.
  const isLoading = state.kind === 'loading';
  const isError = state.kind === 'error';
  const isReady = state.kind === 'ready';
  const readySession = state.kind === 'ready' ? state.session : null;
  const errorView = state.kind === 'error' ? state : null;
  const showSocketPill = socketStatus !== 'idle';
  const isSocketLive = socketStatus === 'live';
  const isSocketConnecting = socketStatus === 'connecting';

  return (
    <Modal
      visible={visible}
      animationType="slide"
      presentationStyle="fullScreen"
      onRequestClose={onClose}
    >
      <View style={[styles.container, { paddingTop: insets.top }]}>
        <View style={styles.topBar}>
          <Pressable
            onPress={onClose}
            accessibilityLabel="End call"
            hitSlop={8}
            style={({ pressed }) => [styles.closeBtn, pressed && { opacity: 0.7 }]}
          >
            <Ionicons name="close" size={24} color={colors.textPrimary} />
          </Pressable>
          <Text style={styles.title} numberOfLines={1}>
            {avatarName}
          </Text>
          <View style={styles.closeBtn} />
        </View>

        <View style={styles.stage}>
          {isLoading && (
            <View style={styles.centered}>
              <ActivityIndicator size="large" color={colors.primary} />
              <Text style={styles.loadingText}>
                Waking up {avatarName}…
              </Text>
            </View>
          )}

          {isError && errorView && (
            <ErrorPanel title={errorView.title} body={errorView.body} onClose={onClose} />
          )}

          {isReady && isWebViewAvailable && readySession?.session.url && (
            <WebViewComponent
              source={{ uri: readySession.session.url }}
              style={styles.webview}
              // Android: let the embed ask for mic permission from the
              // WebView. iOS handles this at the OS level once NSMicrophone
              // UsageDescription is set in Info.plist.
              mediaPlaybackRequiresUserAction={false}
              allowsInlineMediaPlayback
              javaScriptEnabled
              domStorageEnabled
              originWhitelist={['*']}
              // Hide LiveAvatar's built-in "Chat now" / language selector
              // demo UI — that UI calls /v1/sessions/start which is a
              // FULL-mode endpoint and bad-requests against our LITE
              // embed. We drive speech from our side in the next slice;
              // for now we just want the clean avatar surface.
              injectedJavaScript={HIDE_DEMO_UI_CSS}
              injectedJavaScriptBeforeContentLoaded={LOG_POSTMESSAGE_BRIDGE}
              onMessage={(event: { nativeEvent: { data: string } }) => {
                // eslint-disable-next-line no-console
                console.log('[LiveAvatar→native]', event.nativeEvent.data);
              }}
              onPermissionRequest={(event: { nativeEvent: { grant: () => void } }) => {
                event.nativeEvent.grant?.();
              }}
            />
          )}

          {isReady && !isWebViewAvailable && (
            <ErrorPanel
              title="WebView not available"
              body="Live Avatar needs a development build (`eas build --profile development`) — the WebView native module isn't linked in Expo Go. Sign-in and chat still work, just not the video layer."
              onClose={onClose}
            />
          )}
        </View>

        {isReady && showSocketPill && (
          <View style={styles.statusPill}>
            {isSocketLive ? (
              <>
                <View style={styles.liveDot} />
                <Text style={styles.statusText}>Live</Text>
              </>
            ) : isSocketConnecting ? (
              <>
                <ActivityIndicator size="small" color={colors.textPrimary} />
                <Text style={styles.statusText}>Connecting…</Text>
              </>
            ) : (
              <>
                <Ionicons name="cloud-offline-outline" size={12} color={colors.textMuted} />
                <Text style={[styles.statusText, { color: colors.textMuted }]}>Voice offline</Text>
              </>
            )}
          </View>
        )}

        {isReady && readySession?.session.sandbox && (
          <View style={styles.sandboxPill}>
            <Ionicons name="flask-outline" size={12} color={colors.warning} />
            <Text style={styles.sandboxText}>
              Sandbox mode · sessions end after 60s
            </Text>
          </View>
        )}
      </View>
    </Modal>
  );
}

function ErrorPanel({
  title,
  body,
  onClose,
}: {
  title: string;
  body: string;
  onClose: () => void;
}) {
  return (
    <View style={styles.centered}>
      <View style={styles.errorIcon}>
        <Ionicons name="warning-outline" size={28} color={colors.warning} />
      </View>
      <Text style={styles.errorTitle}>{title}</Text>
      <Text style={styles.errorBody}>{body}</Text>
      <Pressable
        onPress={onClose}
        style={({ pressed }) => [styles.errorBtn, pressed && { opacity: 0.85 }]}
      >
        <Text style={styles.errorBtnText}>Close</Text>
      </Pressable>
    </View>
  );
}

function mapErrorToState(err: unknown): Extract<State, { kind: 'error' }> {
  if (err instanceof ApiError) {
    const body = (err.body ?? {}) as { code?: string; error?: string; message?: string };
    switch (body.code) {
      case 'liveavatar_disabled':
        return {
          kind: 'error',
          title: 'Video mode not configured',
          body:
            'The server doesn\'t have a LiveAvatar key yet. Chat still works — just no talking head for now.',
        };
      case 'avatar_not_mapped':
        return {
          kind: 'error',
          title: 'Avatar not linked yet',
          body:
            body.error ??
            'This expert hasn\'t been linked to a LiveAvatar face in the dashboard. Try again after an operator maps it.',
        };
      case 'avatar_not_found':
        return {
          kind: 'error',
          title: 'Avatar not found',
          body:
            'We couldn\'t find this expert on the server. Try signing out and back in, or pick a different avatar.',
        };
      default:
        return {
          kind: 'error',
          title: 'Could not start video mode',
          body: body.message ?? body.error ?? err.message,
        };
    }
  }
  return {
    kind: 'error',
    title: 'Could not start video mode',
    body: (err as Error)?.message ?? 'Unknown error.',
  };
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#000',
  },
  topBar: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
    gap: spacing.md,
  },
  closeBtn: {
    width: 44,
    height: 44,
    borderRadius: radius.pill,
    backgroundColor: 'rgba(255,255,255,0.12)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  title: {
    flex: 1,
    textAlign: 'center',
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
  },
  stage: {
    flex: 1,
    backgroundColor: '#000',
  },
  webview: {
    flex: 1,
    backgroundColor: '#000',
  },
  centered: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.lg,
  },
  loadingText: {
    color: colors.textSecondary,
    fontSize: fontSize.md,
    marginTop: spacing.md,
  },
  errorIcon: {
    width: 56,
    height: 56,
    borderRadius: radius.pill,
    backgroundColor: 'rgba(245,158,11,0.15)',
    borderWidth: 1,
    borderColor: 'rgba(245,158,11,0.4)',
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: spacing.md,
  },
  errorTitle: {
    color: colors.textPrimary,
    fontSize: fontSize.lg,
    fontWeight: '800',
    marginBottom: spacing.sm,
    textAlign: 'center',
  },
  errorBody: {
    color: colors.textSecondary,
    fontSize: fontSize.sm,
    textAlign: 'center',
    lineHeight: 20,
    marginBottom: spacing.lg,
  },
  errorBtn: {
    backgroundColor: colors.primary,
    paddingVertical: spacing.sm + 2,
    paddingHorizontal: spacing.xl,
    borderRadius: radius.pill,
  },
  errorBtnText: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
  },
  sandboxPill: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'center',
    gap: 6,
    backgroundColor: 'rgba(245,158,11,0.12)',
    borderWidth: 1,
    borderColor: 'rgba(245,158,11,0.3)',
    paddingVertical: 4,
    paddingHorizontal: spacing.sm + 2,
    borderRadius: radius.pill,
    position: 'absolute',
    bottom: spacing.lg,
  },
  sandboxText: {
    color: colors.warning,
    fontSize: fontSize.xs,
    fontWeight: '600',
    letterSpacing: 0.2,
  },
  statusPill: {
    position: 'absolute',
    top: 12,
    right: spacing.md,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: spacing.sm + 2,
    paddingVertical: 4,
    borderRadius: radius.pill,
    backgroundColor: 'rgba(11,15,23,0.85)',
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: 'rgba(255,255,255,0.18)',
  },
  liveDot: {
    width: 8,
    height: 8,
    borderRadius: radius.pill,
    backgroundColor: colors.success,
  },
  statusText: {
    color: colors.textPrimary,
    fontSize: fontSize.xs,
    fontWeight: '600',
    letterSpacing: 0.2,
  },
});
