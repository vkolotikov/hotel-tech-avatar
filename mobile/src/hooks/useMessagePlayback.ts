import { useEffect, useState } from 'react';
import { Audio } from 'expo-av';
import { fetchSpeechDataUrl } from '../api/voice';

/**
 * Module-level singleton — there is only ever one TTS clip playing in the
 * app at a time. This is what lets a tap on bubble #2's ▶ button stop
 * bubble #1's playback automatically, and what keeps the voice-mode
 * auto-speak from racing with a manual play. Everything goes through
 * `play()` / `stop()` — no other component should construct its own
 * Audio.Sound for TTS.
 */
type ActiveKey = number | string | null;

let currentSound: Audio.Sound | null = null;
let currentKey: ActiveKey = null;
const listeners = new Set<(active: ActiveKey) => void>();

function notify() {
  listeners.forEach((cb) => cb(currentKey));
}

async function stopInternal(): Promise<void> {
  const s = currentSound;
  currentSound = null;
  const wasActive = currentKey !== null;
  currentKey = null;
  if (s) {
    try { await s.stopAsync(); } catch { /* ignore — may already be unloaded */ }
    try { await s.unloadAsync(); } catch { /* ignore */ }
  }
  if (wasActive) notify();
}

async function playInternal(key: ActiveKey, dataUrl: string): Promise<void> {
  await stopInternal();
  if (!dataUrl) {
    throw new Error('Empty audio URL');
  }

  // Force the audio session into pure-playback mode. Critical when the
  // user just used the dictation mic or voice mode — that left the
  // session in record-and-playback, and on some Android devices
  // playback stays muted until we explicitly clear allowsRecordingIOS
  // (the option name is misleading; expo-av reads it on Android too).
  await Audio.setAudioModeAsync({
    playsInSilentModeIOS: true,
    allowsRecordingIOS: false,
    staysActiveInBackground: false,
    shouldDuckAndroid: true,
    playThroughEarpieceAndroid: false,
  }).catch((err) => {
    console.warn('useMessagePlayback: setAudioModeAsync failed', err);
  });

  let sound: Audio.Sound;
  try {
    const result = await Audio.Sound.createAsync(
      { uri: dataUrl },
      { shouldPlay: true, volume: 1.0 },
    );
    sound = result.sound;
  } catch (err) {
    console.warn('useMessagePlayback: createAsync failed', err);
    throw err instanceof Error ? err : new Error(String(err));
  }
  currentSound = sound;
  currentKey = key;
  notify();

  sound.setOnPlaybackStatusUpdate((status) => {
    if (!status.isLoaded) {
      // Status carries an `error` field when load fails. Surface it so
      // a silent failure becomes a noisy one in dev.
      const errStatus = status as { error?: string };
      if (errStatus.error) {
        console.warn('useMessagePlayback: playback status error', errStatus.error);
        if (currentSound === sound) {
          currentSound = null;
          currentKey = null;
          notify();
        }
      }
      return;
    }
    if (status.didJustFinish) {
      // Race-free check: only clear if THIS sound is still the active one.
      if (currentSound === sound) {
        currentSound = null;
        currentKey = null;
        sound.unloadAsync().catch(() => {});
        notify();
      }
    }
  });
}

/**
 * Subscribe to the singleton and get a stable `play` / `stop` API.
 * `activeKey` is whatever caller-supplied identifier is currently
 * playing — typically the message id, or `'agent-reply'` for the
 * voice-mode auto-speak path.
 */
export function useMessagePlayback() {
  const [activeKey, setActiveKey] = useState<ActiveKey>(currentKey);

  useEffect(() => {
    listeners.add(setActiveKey);
    return () => {
      listeners.delete(setActiveKey);
    };
  }, []);

  return {
    activeKey,
    isPlayingAny: activeKey !== null,
    isPlaying: (key: ActiveKey) => activeKey !== null && activeKey === key,
    play: playInternal,
    stop: stopInternal,
  };
}

/**
 * Convenience wrapper: fetch the TTS clip from the backend, then play.
 * Used by MessageBubble's ▶ button so each bubble doesn't have to
 * duplicate the fetch+play sequence.
 */
export async function fetchAndPlay(
  key: ActiveKey,
  conversationId: number,
  text: string,
): Promise<void> {
  const dataUrl = await fetchSpeechDataUrl(conversationId, text);
  await playInternal(key, dataUrl);
}
