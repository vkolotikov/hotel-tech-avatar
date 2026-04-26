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
  if (!dataUrl) return;
  await Audio.setAudioModeAsync({
    playsInSilentModeIOS: true,
    allowsRecordingIOS: false,
  }).catch(() => { /* device may not allow — best-effort */ });

  const { sound } = await Audio.Sound.createAsync(
    { uri: dataUrl },
    { shouldPlay: true },
  );
  currentSound = sound;
  currentKey = key;
  notify();

  sound.setOnPlaybackStatusUpdate((status) => {
    if (!status.isLoaded) return;
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
