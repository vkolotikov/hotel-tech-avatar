import { useEffect, useState } from 'react';
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
  startLiveAvatarSession,
  type LiveAvatarSession,
} from '../../api/liveavatar';
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

export function LiveAvatarModal({ visible, avatarSlug, avatarName, onClose }: Props) {
  const insets = useSafeAreaInsets();
  const [state, setState] = useState<State>({ kind: 'idle' });

  useEffect(() => {
    if (!visible) {
      setState({ kind: 'idle' });
      return;
    }

    let cancelled = false;
    setState({ kind: 'loading' });

    startLiveAvatarSession(avatarSlug)
      .then((session) => {
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
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        const mapped = mapErrorToState(err);
        setState(mapped);
      });

    return () => {
      cancelled = true;
    };
  }, [visible, avatarSlug]);

  const isWebViewAvailable = WebViewComponent !== null;

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
          {state.kind === 'loading' && (
            <View style={styles.centered}>
              <ActivityIndicator size="large" color={colors.primary} />
              <Text style={styles.loadingText}>
                Waking up {avatarName}…
              </Text>
            </View>
          )}

          {state.kind === 'error' && (
            <ErrorPanel title={state.title} body={state.body} onClose={onClose} />
          )}

          {state.kind === 'ready' && isWebViewAvailable && state.session.session.url && (
            <WebViewComponent
              source={{ uri: state.session.session.url }}
              style={styles.webview}
              // Android: let the embed ask for mic permission from the
              // WebView. iOS handles this at the OS level once NSMicrophone
              // UsageDescription is set in Info.plist (which we add in
              // app.json for the dev build).
              mediaPlaybackRequiresUserAction={false}
              allowsInlineMediaPlayback
              javaScriptEnabled
              domStorageEnabled
              originWhitelist={['*']}
              onPermissionRequest={(event: { nativeEvent: { grant: () => void } }) => {
                // Called on Android — mic / camera / etc. requested by
                // the page. We grant because the user explicitly opened
                // live-avatar mode. If we wanted finer-grained gating
                // we could inspect event.nativeEvent.resources here.
                event.nativeEvent.grant?.();
              }}
            />
          )}

          {state.kind === 'ready' && !isWebViewAvailable && (
            <ErrorPanel
              title="WebView not available"
              body="Live Avatar needs a development build (`eas build --profile development`) — the WebView native module isn't linked in Expo Go. Sign-in and chat still work, just not the video layer."
              onClose={onClose}
            />
          )}
        </View>

        {state.kind === 'ready' && state.session.session.sandbox && (
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
});
