import { useEffect } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { colors, spacing, fontSize } from '../theme';

/**
 * Renders the LiveAvatar session's remote video track via LiveKit
 * React Native. Replaces the previous WebView approach — the WebView
 * loaded a different LiveAvatar session (from /v2/embeddings) than
 * the one we drove via /v1/sessions/start, so audio sent over our
 * WebSocket reached a "ghost" session and the visible avatar never
 * lip-synced.
 *
 * This component connects to the actual session's livekit_url with
 * livekit_client_token, finds the avatar's published video track,
 * and renders it. Audio plays automatically via LiveKit's room
 * audio output.
 *
 * Native-only: dynamic require so Expo Go (no WebRTC native module)
 * still boots the app — caller falls back to the "development build
 * required" panel when this returns null.
 */

type Props = {
  livekitUrl: string;
  livekitToken: string;
  onConnected?: () => void;
  onError?: (err: unknown) => void;
};

// eslint-disable-next-line @typescript-eslint/no-explicit-any
let lkRn: any = null;
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let lkClient: any = null;
try {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  lkRn = require('@livekit/react-native');
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  lkClient = require('livekit-client');
} catch {
  lkRn = null;
  lkClient = null;
}

export const isLiveKitAvailable = lkRn !== null && lkClient !== null;

export function LiveAvatarLiveKitView({ livekitUrl, livekitToken, onConnected, onError }: Props) {
  if (!lkRn || !lkClient) {
    // Expo Go path — caller renders its own fallback.
    return null;
  }

  const { LiveKitRoom, useTracks, VideoTrack } = lkRn;
  const { Track } = lkClient;

  function StageInner() {
    const tracks = useTracks(
      [{ source: Track.Source.Camera, withPlaceholder: false }],
      { onlySubscribed: true },
    );

    // We expect a single remote video track — the avatar's. Server
    // doesn't always tag it as Camera (LiveAvatar may publish it as
    // a generic source); fall back to the first track that has an
    // actual video publication.
    type TrackRef = {
      participant?: { isLocal?: boolean };
      publication?: { kind?: string; track?: unknown };
    };
    const trackRefs = (tracks as TrackRef[]) ?? [];
    const remoteTracks = trackRefs.filter(
      (t) => !t.participant?.isLocal && t.publication?.kind === 'video',
    );
    const trackRef = remoteTracks[0] ?? null;

    useEffect(() => {
      if (trackRef) {
        onConnected?.();
      }
    }, [trackRef]);

    if (!trackRef) {
      return (
        <View style={styles.placeholder}>
          <ActivityIndicator size="large" color={colors.primary} />
          <Text style={styles.placeholderText}>Waiting for avatar feed…</Text>
        </View>
      );
    }

    return <VideoTrack trackRef={trackRef} style={styles.video} objectFit="cover" />;
  }

  return (
    <LiveKitRoom
      serverUrl={livekitUrl}
      token={livekitToken}
      connect
      audio={false}
      video={false}
      onError={(err: unknown) => onError?.(err)}
      style={styles.room}
    >
      <StageInner />
    </LiveKitRoom>
  );
}

const styles = StyleSheet.create({
  room: { flex: 1, backgroundColor: '#000' },
  video: { flex: 1, backgroundColor: '#000' },
  placeholder: {
    flex: 1,
    backgroundColor: '#000',
    alignItems: 'center',
    justifyContent: 'center',
  },
  placeholderText: {
    color: colors.textSecondary,
    fontSize: fontSize.md,
    marginTop: spacing.md,
  },
});
