import { useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Modal,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { ResizeMode, Video, AVPlaybackStatus } from 'expo-av';
import { resolveAssetUrl } from '../../api';
import { colors, radius, spacing, fontSize, avatarColors, AvatarSlug } from '../../theme';

type Props = {
  visible: boolean;
  videoUrl: string | null | undefined;
  avatarName: string;
  avatarSlug: string;
  /** Called when the user taps the "Start chat" CTA. */
  onStartChat: () => void;
  /** Called when the user dismisses without starting a chat. */
  onClose: () => void;
};

/**
 * Full-screen intro video player.
 *
 * Autoplays on open, pauses on last frame when it finishes — the last
 * frame is the avatar "landing" on screen, so we never swap to the
 * static image; the video's own end-frame IS the avatar. At any point
 * the user can tap Start chat (immediate) or Close.
 */
export function IntroVideoModal({
  visible,
  videoUrl,
  avatarName,
  avatarSlug,
  onStartChat,
  onClose,
}: Props) {
  const insets = useSafeAreaInsets();
  const videoRef = useRef<Video | null>(null);
  const [finished, setFinished] = useState(false);
  const [loading, setLoading] = useState(true);

  const uri = resolveAssetUrl(videoUrl);
  const slug = avatarSlug as AvatarSlug;
  const accent =
    slug in avatarColors ? avatarColors[slug] : colors.primary;

  useEffect(() => {
    if (!visible) {
      setFinished(false);
      setLoading(true);
      videoRef.current?.unloadAsync().catch(() => {});
    }
  }, [visible]);

  const onStatus = (status: AVPlaybackStatus) => {
    if (!status.isLoaded) return;
    if (status.isPlaying && loading) setLoading(false);
    if (status.didJustFinish) setFinished(true);
  };

  if (!uri) return null;

  return (
    <Modal
      visible={visible}
      animationType="fade"
      transparent={false}
      onRequestClose={onClose}
      supportedOrientations={['portrait', 'landscape']}
      statusBarTranslucent
    >
      <View style={styles.root}>
        <Video
          ref={videoRef}
          source={{ uri }}
          style={StyleSheet.absoluteFill}
          resizeMode={ResizeMode.COVER}
          shouldPlay
          useNativeControls={false}
          onPlaybackStatusUpdate={onStatus}
          onError={() => setLoading(false)}
        />

        {loading && (
          <View style={styles.loadingOverlay} pointerEvents="none">
            <ActivityIndicator color={colors.textPrimary} size="large" />
          </View>
        )}

        {/* Close button, top-right, always available */}
        <Pressable
          onPress={onClose}
          style={[styles.closeButton, { top: insets.top + spacing.sm }]}
          accessibilityLabel="Close intro video"
          hitSlop={10}
        >
          <Text style={styles.closeGlyph}>×</Text>
        </Pressable>

        {/* Bottom CTA — always show Start chat, label shifts to Continue
            to [name] after the video ends for a clearer hand-off. */}
        <View
          style={[styles.footer, { paddingBottom: insets.bottom + spacing.md }]}
          pointerEvents="box-none"
        >
          <Pressable
            onPress={onStartChat}
            style={({ pressed }) => [
              styles.cta,
              { backgroundColor: accent },
              pressed && { opacity: 0.88, transform: [{ scale: 0.98 }] },
            ]}
          >
            <Text style={styles.ctaText}>
              {finished ? `Start chat with ${avatarName}` : `Skip & chat with ${avatarName}`}
            </Text>
          </Pressable>
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: '#000',
  },
  loadingOverlay: {
    ...StyleSheet.absoluteFillObject,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(0,0,0,0.4)',
  },
  closeButton: {
    position: 'absolute',
    right: spacing.md,
    width: 40,
    height: 40,
    borderRadius: radius.pill,
    backgroundColor: 'rgba(0,0,0,0.55)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.15)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  closeGlyph: {
    color: colors.textPrimary,
    fontSize: 24,
    lineHeight: 24,
    marginTop: -2,
  },
  footer: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    paddingHorizontal: spacing.lg,
    alignItems: 'center',
  },
  cta: {
    paddingVertical: spacing.md,
    paddingHorizontal: spacing.xl,
    borderRadius: radius.pill,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.35,
    shadowRadius: 12,
    elevation: 6,
  },
  ctaText: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '700',
    letterSpacing: 0.3,
  },
});
