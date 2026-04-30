import { useCallback, useEffect, useRef, useState } from 'react';
import {
  Alert,
  Animated,
  AppState,
  Image,
  Linking,
  Modal,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { useTranslation } from 'react-i18next';
import { Audio } from 'expo-av';
import { transcribeAudio } from '../api/transcribe';
import { fetchAndPlay, useMessagePlayback } from '../hooks/useMessagePlayback';
import { resolveAssetUrl } from '../api';
import { colors, spacing, radius, fontSize } from '../theme';

type Props = {
  visible: boolean;
  conversationId: number;
  avatarName: string;
  avatarImageUrl?: string | null;
  accent: string;
  /**
   * Called when the user has finished saying something. The screen
   * provides the transcribed text + a flag asking the host (chat
   * screen) to send it AND speak the reply. Returns the text of the
   * agent's reply once available, so we can re-arm the mic afterwards.
   */
  onUserSpoke: (transcript: string) => Promise<string | null>;
  onClose: () => void;
};

// Endpoint-detection tuning. Previous values (-40 dB, 1200 ms) cut
// users off mid-sentence: natural speech routinely pauses 1.5–2 s for
// breath/thinking, and soft voices dip below -40 dB even mid-word. The
// values below were validated by re-running real users from the
// 2026-04-28 voice-mode bug report.
//   - threshold -50 dB: catches quiet voices without picking up
//     keyboard typing or distant conversations
//   - hold 1800 ms: lets a complete thought finish ("a high-protein
//     lunch ... [breath] ... that's filling") without losing it
//   - min 800 ms: a quick "yes" or "no" still ends the turn
//   - max 45 s: long enough to dictate a full health concern
const SILENCE_THRESHOLD_DB = -50;
const SILENCE_HOLD_MS = 1800;
const MIN_RECORDING_MS = 800;
const MAX_RECORDING_MS = 45_000;
const METERING_INTERVAL_MS = 120;

type Phase = 'idle' | 'listening' | 'thinking' | 'speaking' | 'paused' | 'error';

const PHASE_KEYS: Record<Phase, string> = {
  idle: 'voiceMode.phaseIdle',
  listening: 'voiceMode.phaseListening',
  thinking: 'voiceMode.phaseThinking',
  speaking: 'voiceMode.phaseSpeaking',
  paused: 'voiceMode.phasePaused',
  error: 'voiceMode.phaseError',
};

export function VoiceModeScreen({
  visible,
  conversationId,
  avatarName,
  avatarImageUrl,
  accent,
  onUserSpoke,
  onClose,
}: Props) {
  const { t } = useTranslation();
  const insets = useSafeAreaInsets();
  const [phase, setPhase] = useState<Phase>('idle');
  // Pulled into locals so the JSX below doesn't carry phase-string
  // comparisons that the react-native/no-raw-text linter trips on.
  const isListening = phase === 'listening';
  const isSpeaking = phase === 'speaking';
  const isThinking = phase === 'thinking';
  const recordingRef = useRef<Audio.Recording | null>(null);
  const lastLoudAtRef = useRef<number>(0);
  const recordingStartedAtRef = useRef<number>(0);
  // Some Android devices (especially emulators) never report a
  // `metering` field. Without this flag the silence-detection logic
  // would interpret the missing meter as "always quiet" and end the
  // recording at MIN_RECORDING_MS — chopping the user off mid-word.
  // We track whether ANY meter sample arrived: if not, we disable the
  // silence-end branch entirely and let MAX_RECORDING_MS or a manual
  // tap close the recording instead.
  const sawMeteringRef = useRef<boolean>(false);
  // Track whether the user has explicitly paused the loop. While paused,
  // the screen stays visible but won't auto-arm the mic; tapping the big
  // central button re-arms it.
  const pausedRef = useRef<boolean>(false);
  const playback = useMessagePlayback();

  // Pulse animation on the avatar — runs whenever we're listening or the
  // agent is speaking, so the user has a visual signal of "the system is
  // doing something." Driven by phase, not isPlaying directly, so the
  // pulse tracks our orchestrator state cleanly.
  const pulse = useRef(new Animated.Value(1)).current;
  useEffect(() => {
    if (phase === 'listening' || phase === 'speaking') {
      const loop = Animated.loop(
        Animated.sequence([
          Animated.timing(pulse, { toValue: 1.08, duration: 700, useNativeDriver: true }),
          Animated.timing(pulse, { toValue: 1, duration: 700, useNativeDriver: true }),
        ]),
      );
      loop.start();
      return () => loop.stop();
    }
    pulse.setValue(1);
    return () => {};
  }, [phase, pulse]);

  // Cleanly tear down the recording if the modal closes (user tapped X,
  // navigated away, or the screen unmounted). Without this the mic stays
  // hot and battery drains.
  const teardownRecording = useCallback(async () => {
    const r = recordingRef.current;
    recordingRef.current = null;
    if (!r) return;
    try { await r.stopAndUnloadAsync(); } catch { /* ignore */ }
  }, []);

  useEffect(() => {
    if (!visible) {
      void teardownRecording();
      void playback.stop();
      pausedRef.current = false;
      setPhase('idle');
    }
  }, [visible, teardownRecording, playback]);

  // If the user backgrounds the app mid-listen we must release the
  // mic immediately. Without this, the recording keeps running for
  // up to MAX_RECORDING_MS while the app is hidden, draining battery
  // and capturing audio the user didn't intend to share. We also
  // pause auto-rearm on foreground so they explicitly tap to resume.
  useEffect(() => {
    if (!visible) return;
    const sub = AppState.addEventListener('change', (next) => {
      if (next === 'background' || next === 'inactive') {
        pausedRef.current = true;
        void teardownRecording();
        void playback.stop();
        setPhase('paused');
      }
    });
    return () => sub.remove();
  }, [visible, teardownRecording, playback]);

  // Watch agent playback finishing so we can re-arm the mic. While the
  // agent is speaking the singleton's activeKey is non-null. The moment
  // it goes back to null AND we're in the speaking phase, switch to
  // listening (assuming user hasn't paused).
  const wasSpeakingRef = useRef<boolean>(false);
  useEffect(() => {
    if (!visible) return;
    const isAnyPlaying = playback.activeKey !== null;
    const justFinished = wasSpeakingRef.current && !isAnyPlaying;
    wasSpeakingRef.current = isAnyPlaying;

    if (justFinished && phase === 'speaking' && !pausedRef.current) {
      void startListening();
    }
  }, [playback.activeKey, phase, visible]);

  const startListening = useCallback(async () => {
    if (recordingRef.current) return;
    pausedRef.current = false;
    try {
      const permission = await Audio.requestPermissionsAsync();
      if (!permission.granted) {
        Alert.alert(
          t('voiceMode.permissionRequired'),
          t('voiceMode.permissionBody'),
          [
            { text: t('common.cancel'), style: 'cancel' },
            { text: t('voiceMode.openSettings'), onPress: () => Linking.openSettings() },
          ],
        );
        setPhase('paused');
        return;
      }

      await Audio.setAudioModeAsync({
        allowsRecordingIOS: true,
        playsInSilentModeIOS: true,
      });

      const { recording } = await Audio.Recording.createAsync(
        {
          ...Audio.RecordingOptionsPresets.HIGH_QUALITY,
          isMeteringEnabled: true,
        },
        undefined,
        METERING_INTERVAL_MS,
      );
      recordingRef.current = recording;
      recordingStartedAtRef.current = Date.now();
      lastLoudAtRef.current = Date.now();
      sawMeteringRef.current = false;
      setPhase('listening');

      recording.setOnRecordingStatusUpdate((status) => {
        if (!status.isRecording || !status.canRecord) return;
        const now = Date.now();
        const elapsed = now - recordingStartedAtRef.current;
        const meter = (status as { metering?: number }).metering;

        if (typeof meter === 'number') {
          sawMeteringRef.current = true;
          if (meter > SILENCE_THRESHOLD_DB) {
            lastLoudAtRef.current = now;
          }
        }

        const silentFor = now - lastLoudAtRef.current;

        // Endpoint detection runs in two modes depending on whether
        // the device actually reports microphone level:
        //
        //   meterring supported → end after MIN reached AND
        //                         SILENCE_HOLD_MS of below-threshold
        //                         audio at the tail.
        //
        //   metering missing → silence detection is unreliable; just
        //                      run until MAX_RECORDING_MS or the user
        //                      taps the mic to finish manually. This
        //                      is what kicks in on Android emulators
        //                      and on devices where the OS chooses
        //                      not to surface meter values.
        const meteringSupported = sawMeteringRef.current;
        if (
          (meteringSupported && elapsed > MIN_RECORDING_MS && silentFor > SILENCE_HOLD_MS) ||
          elapsed > MAX_RECORDING_MS
        ) {
          if (!meteringSupported) {
            console.log('[voice-mode] metering not reported by OS — falling back to MAX_RECORDING_MS endpoint');
          }
          void finishListening();
        }
      });
    } catch (err) {
      console.warn('[voice-mode] failed to start recording', err);
      Alert.alert('Voice mode error', (err as Error).message ?? 'Could not start the recorder.');
      setPhase('error');
    }
  }, []);

  const finishListening = useCallback(async () => {
    const r = recordingRef.current;
    recordingRef.current = null;
    if (!r) return;
    setPhase('thinking');
    try {
      await r.stopAndUnloadAsync();
      const uri = r.getURI();
      console.log('[voice-mode] recording stopped, uri=', uri);
      if (!uri) throw new Error('No recording URI');
      const { transcript } = await transcribeAudio(uri, conversationId);
      const text = (transcript ?? '').trim();
      console.log('[voice-mode] transcript=', JSON.stringify(text.slice(0, 80)), 'len=', text.length);
      if (!text) {
        // Empty transcript — likely the user didn't speak. Re-arm.
        if (!pausedRef.current) {
          void startListening();
        } else {
          setPhase('paused');
        }
        return;
      }

      const replyText = await onUserSpoke(text);
      console.log('[voice-mode] reply received, len=', replyText?.length ?? 0);
      if (replyText && replyText.trim().length > 0) {
        setPhase('speaking');
        try {
          await fetchAndPlay('voice-mode-reply', conversationId, replyText);
        } catch (err) {
          console.warn('[voice-mode] TTS playback failed:', err);
          Alert.alert('Voice playback failed', (err as Error).message ?? 'Unknown error');
          setPhase('paused');
        }
      } else {
        // null reply = send rejected OR the 30-second polling deadline
        // elapsed without an agent message. Either way we owe the user
        // a clear signal that we lost their utterance — silently
        // bouncing to "paused" makes it look like the system stuck.
        setPhase('error');
        Alert.alert(
          t('voiceMode.timedOutTitle'),
          t('voiceMode.timedOutBody'),
          [{ text: t('common.retry') }],
        );
      }
    } catch (err) {
      console.warn('[voice-mode] transcription failed', err);
      Alert.alert('Transcription failed', (err as Error).message ?? 'Unknown error transcribing audio');
      setPhase('error');
    }
  }, [conversationId, onUserSpoke, startListening]);

  const handleCenterTap = useCallback(() => {
    if (phase === 'listening') {
      // User tapped while listening — finish early.
      void finishListening();
      return;
    }
    if (phase === 'speaking') {
      // User tapped while agent talks — interrupt + go straight to listening.
      void playback.stop();
      void startListening();
      return;
    }
    if (phase === 'thinking') {
      // Don't interrupt a network round-trip — let it finish.
      return;
    }
    // idle / paused / error — start a fresh listening cycle.
    void startListening();
  }, [phase, finishListening, startListening, playback]);

  const handleClose = useCallback(async () => {
    pausedRef.current = true;
    await teardownRecording();
    await playback.stop();
    onClose();
  }, [teardownRecording, playback, onClose]);

  const heroUri = resolveAssetUrl(avatarImageUrl);

  return (
    <Modal
      visible={visible}
      animationType="fade"
      onRequestClose={handleClose}
      statusBarTranslucent
    >
      <View style={[styles.root, { paddingTop: insets.top, paddingBottom: insets.bottom }]}>
        <View style={styles.topBar}>
          <Text style={styles.title} numberOfLines={1}>
            {t('voiceMode.title', { name: avatarName })}
          </Text>
          <Pressable
            onPress={handleClose}
            hitSlop={12}
            accessibilityLabel="Close voice mode"
            style={({ pressed }) => [styles.closeBtn, pressed && { opacity: 0.7 }]}
          >
            <Ionicons name="close" size={22} color={colors.textPrimary} />
          </Pressable>
        </View>

        <View style={styles.heroArea}>
          <Animated.View
            style={[
              styles.avatarWrap,
              {
                transform: [{ scale: pulse }],
                shadowColor: accent,
                borderColor: accent,
              },
            ]}
          >
            {heroUri ? (
              <Image source={{ uri: heroUri }} style={styles.avatar} resizeMode="cover" />
            ) : (
              <View style={[styles.avatar, { backgroundColor: accent + '40' }]} />
            )}
          </Animated.View>
          <View style={[styles.statusPill, { borderColor: accent }]}>
            {(isListening || isSpeaking) && (
              <View style={[styles.statusDot, { backgroundColor: accent }]} />
            )}
            <Text style={styles.statusText}>{t(PHASE_KEYS[phase])}</Text>
          </View>
          <Text style={styles.helper}>
            {isListening
              ? t('voiceMode.helperListening')
              : isSpeaking
              ? t('voiceMode.helperSpeaking', { name: avatarName })
              : isThinking
              ? t('voiceMode.helperThinking')
              : t('voiceMode.helperIdle')}
          </Text>
        </View>

        <View style={styles.controls}>
          <Pressable
            onPress={handleCenterTap}
            disabled={phase === 'thinking'}
            accessibilityLabel="Toggle voice"
            style={({ pressed }) => [
              styles.bigMic,
              {
                backgroundColor:
                  phase === 'listening'
                    ? accent
                    : phase === 'speaking'
                    ? 'rgba(20,26,38,0.9)'
                    : accent,
                borderColor: accent,
              },
              pressed && { opacity: 0.85 },
              phase === 'thinking' && { opacity: 0.5 },
            ]}
          >
            <Ionicons
              name={
                phase === 'listening'
                  ? 'stop'
                  : phase === 'speaking'
                  ? 'hand-left'
                  : phase === 'thinking'
                  ? 'ellipsis-horizontal'
                  : 'mic'
              }
              size={36}
              color={colors.textPrimary}
            />
          </Pressable>
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: '#0b0f17',
    paddingHorizontal: spacing.md,
  },
  topBar: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: spacing.sm,
  },
  title: {
    color: colors.textPrimary,
    fontSize: fontSize.md,
    fontWeight: '600',
    flex: 1,
  },
  closeBtn: {
    width: 40,
    height: 40,
    borderRadius: radius.pill,
    backgroundColor: 'rgba(20,26,38,0.55)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.08)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  heroArea: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.lg,
  },
  avatarWrap: {
    width: 220,
    height: 220,
    borderRadius: 999,
    borderWidth: 2,
    overflow: 'hidden',
    alignItems: 'center',
    justifyContent: 'center',
    shadowOffset: { width: 0, height: 0 },
    shadowOpacity: 0.5,
    shadowRadius: 30,
    elevation: 10,
  },
  avatar: {
    width: '100%',
    height: '100%',
  },
  statusPill: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: spacing.xs + 2,
    paddingHorizontal: spacing.md,
    borderRadius: 999,
    borderWidth: 1,
    backgroundColor: 'rgba(20,26,38,0.85)',
    gap: spacing.xs + 2,
  },
  statusDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
  },
  statusText: {
    color: colors.textPrimary,
    fontSize: fontSize.sm,
    fontWeight: '600',
    letterSpacing: 0.3,
  },
  helper: {
    color: colors.textMuted,
    fontSize: fontSize.sm,
    textAlign: 'center',
    paddingHorizontal: spacing.lg,
  },
  controls: {
    alignItems: 'center',
    paddingBottom: spacing.lg,
  },
  bigMic: {
    width: 88,
    height: 88,
    borderRadius: 999,
    borderWidth: 2,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.4,
    shadowRadius: 12,
    elevation: 8,
  },
});
