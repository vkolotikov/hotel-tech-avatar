import { useCallback, useRef, useState } from 'react';
import { Alert, Linking } from 'react-native';
import { Audio } from 'expo-av';
import { transcribeAudio } from '../api/transcribe';

type State = {
  isRecording: boolean;
  isTranscribing: boolean;
  error: Error | null;
};

export function useVoiceRecorder(onTranscript: (text: string) => void) {
  const [state, setState] = useState<State>({
    isRecording: false,
    isTranscribing: false,
    error: null,
  });
  const recordingRef = useRef<Audio.Recording | null>(null);

  const start = useCallback(async () => {
    try {
      const permission = await Audio.requestPermissionsAsync();
      if (!permission.granted) {
        Alert.alert(
          'Microphone access required',
          'Enable microphone access in Settings to record voice messages.',
          [
            { text: 'Cancel', style: 'cancel' },
            { text: 'Open Settings', onPress: () => Linking.openSettings() },
          ],
        );
        return;
      }

      await Audio.setAudioModeAsync({
        allowsRecordingIOS: true,
        playsInSilentModeIOS: true,
      });

      const { recording } = await Audio.Recording.createAsync(
        Audio.RecordingOptionsPresets.HIGH_QUALITY,
      );
      recordingRef.current = recording;
      setState({ isRecording: true, isTranscribing: false, error: null });
    } catch (error) {
      setState({ isRecording: false, isTranscribing: false, error: error as Error });
    }
  }, []);

  const stop = useCallback(async () => {
    const recording = recordingRef.current;
    recordingRef.current = null;
    if (!recording) return;
    try {
      setState((s) => ({ ...s, isRecording: false, isTranscribing: true }));
      await recording.stopAndUnloadAsync();
      const uri = recording.getURI();
      if (!uri) throw new Error('No recording URI');
      const { transcript } = await transcribeAudio(uri);
      onTranscript(transcript);
      setState({ isRecording: false, isTranscribing: false, error: null });
    } catch (error) {
      setState({ isRecording: false, isTranscribing: false, error: error as Error });
      Alert.alert('Transcription failed', (error as Error).message);
    }
  }, [onTranscript]);

  return { ...state, start, stop };
}
