import { useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import * as ImagePicker from 'expo-image-picker';
import * as DocumentPicker from 'expo-document-picker';
import { useVoiceRecorder } from '../../hooks/useVoiceRecorder';
import { VoiceRecordButton } from './VoiceRecordButton';
import { AttachmentPickerSheet } from './AttachmentPickerSheet';
import { uploadAttachment } from '../../api/attachments';
import { colors, spacing, radius, fontSize } from '../../theme';
import type { Attachment } from '../../types/models';

type Props = {
  conversationId: number;
  accent?: string;
  onSend: (text: string, opts?: { voice?: boolean; attachmentIds?: number[] }) => void;
  disabled: boolean;
};

function deriveFilename(uri: string, fallbackExt = 'jpg'): string {
  const base = uri.split('/').pop() ?? `upload.${fallbackExt}`;
  return base.includes('.') ? base : `${base}.${fallbackExt}`;
}

function deriveMime(name: string, explicit: string | null | undefined): string {
  if (explicit) return explicit;
  const ext = name.split('.').pop()?.toLowerCase();
  switch (ext) {
    case 'jpg':
    case 'jpeg':
      return 'image/jpeg';
    case 'png':
      return 'image/png';
    case 'gif':
      return 'image/gif';
    case 'webp':
      return 'image/webp';
    case 'pdf':
      return 'application/pdf';
    case 'mp4':
      return 'video/mp4';
    case 'mov':
      return 'video/quicktime';
    default:
      return 'application/octet-stream';
  }
}

export function MessageInput({
  conversationId,
  accent = colors.primary,
  onSend,
  disabled,
}: Props) {
  const [text, setText] = useState('');
  // Dictate mode (default): transcript fills the input, user reviews + sends.
  // Voice mode: transcript auto-sends and the agent reply plays through TTS.
  const [voiceMode, setVoiceMode] = useState(false);
  const [pickerVisible, setPickerVisible] = useState(false);
  const [attachments, setAttachments] = useState<Attachment[]>([]);
  const [uploading, setUploading] = useState(false);
  const insets = useSafeAreaInsets();
  const recorder = useVoiceRecorder(conversationId, (transcript) => {
    const trimmed = transcript.trim();
    if (!trimmed) return;
    if (voiceMode) {
      onSend(trimmed, { voice: true });
    } else {
      // Dictate — append to whatever the user had typed.
      setText((prev) => (prev ? prev + ' ' + trimmed : trimmed));
    }
  });

  const canSend =
    !disabled &&
    !recorder.isTranscribing &&
    !uploading &&
    (text.trim().length > 0 || attachments.length > 0);

  const handleSend = () => {
    if (!canSend) return;
    const trimmed = text.trim();
    const ids = attachments.map((a) => a.id);
    onSend(trimmed, ids.length > 0 ? { attachmentIds: ids } : undefined);
    setText('');
    setAttachments([]);
  };

  const runUpload = async (asset: { uri: string; name: string; mimeType: string | null }) => {
    setUploading(true);
    try {
      const file = {
        uri: asset.uri,
        name: asset.name,
        type: deriveMime(asset.name, asset.mimeType),
      };
      const attachment = await uploadAttachment(conversationId, file);
      setAttachments((prev) => [...prev, attachment]);
    } catch (err) {
      Alert.alert('Attachment failed', (err as Error).message);
    } finally {
      setUploading(false);
    }
  };

  const handleCamera = async () => {
    setPickerVisible(false);
    const perm = await ImagePicker.requestCameraPermissionsAsync();
    if (!perm.granted) {
      Alert.alert('Camera permission needed', 'Enable camera access in Settings to attach a photo.');
      return;
    }
    const result = await ImagePicker.launchCameraAsync({
      mediaTypes: ImagePicker.MediaTypeOptions.Images,
      quality: 0.8,
    });
    if (result.canceled || result.assets.length === 0) return;
    const a = result.assets[0];
    await runUpload({
      uri: a.uri,
      name: a.fileName ?? deriveFilename(a.uri, 'jpg'),
      mimeType: a.mimeType ?? 'image/jpeg',
    });
  };

  const handlePhotoLibrary = async () => {
    setPickerVisible(false);
    const perm = await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!perm.granted) {
      Alert.alert('Photos permission needed', 'Enable photo-library access in Settings to attach an image.');
      return;
    }
    const result = await ImagePicker.launchImageLibraryAsync({
      mediaTypes: ImagePicker.MediaTypeOptions.Images,
      quality: 0.9,
    });
    if (result.canceled || result.assets.length === 0) return;
    const a = result.assets[0];
    await runUpload({
      uri: a.uri,
      name: a.fileName ?? deriveFilename(a.uri, 'jpg'),
      mimeType: a.mimeType ?? 'image/jpeg',
    });
  };

  const handleFile = async () => {
    setPickerVisible(false);
    const result = await DocumentPicker.getDocumentAsync({
      copyToCacheDirectory: true,
      multiple: false,
    });
    if (result.canceled || result.assets.length === 0) return;
    const a = result.assets[0];
    await runUpload({
      uri: a.uri,
      name: a.name,
      mimeType: a.mimeType ?? null,
    });
  };

  const removeAttachment = (id: number) => {
    setAttachments((prev) => prev.filter((a) => a.id !== id));
  };

  const voiceActive = recorder.isRecording || recorder.isTranscribing;
  const hint = recorder.isRecording
    ? voiceMode
      ? 'Voice mode · tap mic to send'
      : 'Listening… tap mic to finish'
    : recorder.isTranscribing
    ? 'Transcribing…'
    : uploading
    ? 'Uploading attachment…'
    : null;

  return (
    <View style={styles.wrapper}>
      {hint && (
        <View style={[styles.hintPill, { borderColor: accent }]}>
          {(recorder.isRecording || uploading) && (
            <View style={[styles.hintDot, { backgroundColor: accent }]} />
          )}
          <Text style={styles.hintText}>{hint}</Text>
        </View>
      )}

      {attachments.length > 0 && (
        <ScrollView
          horizontal
          showsHorizontalScrollIndicator={false}
          contentContainerStyle={styles.chipStrip}
        >
          {attachments.map((a) => (
            <View key={a.id} style={[styles.chip, { borderColor: accent + '55' }]}>
              <Ionicons
                name={a.mime_type?.startsWith('image/') ? 'image-outline' : 'document-outline'}
                size={14}
                color={colors.textPrimary}
              />
              <Text style={styles.chipText} numberOfLines={1}>
                {a.file_name}
              </Text>
              <Pressable
                onPress={() => removeAttachment(a.id)}
                hitSlop={8}
                accessibilityLabel={`Remove ${a.file_name}`}
              >
                <Ionicons name="close" size={16} color={colors.textMuted} />
              </Pressable>
            </View>
          ))}
        </ScrollView>
      )}

      <View style={styles.modeRow} pointerEvents={voiceActive ? 'none' : 'auto'}>
        <Pressable
          onPress={() => setVoiceMode((v) => !v)}
          style={[
            styles.modeChip,
            voiceMode && { borderColor: accent, backgroundColor: accent + '22' },
          ]}
          accessibilityRole="switch"
          accessibilityState={{ checked: voiceMode }}
          accessibilityLabel="Toggle voice mode"
        >
          <View
            style={[
              styles.modeDot,
              { backgroundColor: voiceMode ? accent : 'rgba(255,255,255,0.3)' },
            ]}
          />
          <Text style={[styles.modeChipText, voiceMode && { color: colors.textPrimary }]}>
            {voiceMode ? 'Voice mode on' : 'Dictate to text'}
          </Text>
        </Pressable>
      </View>

      <View style={[styles.container, { paddingBottom: spacing.sm + insets.bottom }]}>
        <Pressable
          onPress={() => setPickerVisible(true)}
          disabled={disabled || uploading || recorder.isTranscribing}
          style={({ pressed }) => [
            styles.attachButton,
            (disabled || uploading || recorder.isTranscribing) && styles.attachDisabled,
            pressed && { opacity: 0.7 },
          ]}
          accessibilityLabel="Attach file or photo"
        >
          {uploading ? (
            <ActivityIndicator size="small" color={colors.textPrimary} />
          ) : (
            <Ionicons name="add" size={22} color={colors.textPrimary} />
          )}
        </Pressable>
        <VoiceRecordButton
          isRecording={recorder.isRecording}
          isTranscribing={recorder.isTranscribing}
          accent={accent}
          onToggle={recorder.toggle}
        />
        <TextInput
          style={[
            styles.input,
            recorder.isTranscribing && { opacity: 0.5 },
          ]}
          value={text}
          onChangeText={setText}
          placeholder={
            recorder.isTranscribing
              ? ''
              : voiceMode
              ? 'Voice mode — tap mic to speak'
              : 'Message… or tap mic to dictate'
          }
          placeholderTextColor={colors.textMuted}
          multiline
          editable={!disabled && !recorder.isTranscribing}
        />
        <Pressable
          testID="send-button"
          style={[
            styles.sendButton,
            { backgroundColor: accent },
            !canSend && styles.sendDisabled,
          ]}
          onPress={handleSend}
          disabled={!canSend}
          accessibilityLabel="Send message"
        >
          <Ionicons name="send" size={18} color={colors.textPrimary} />
        </Pressable>
      </View>

      <AttachmentPickerSheet
        visible={pickerVisible}
        onCamera={handleCamera}
        onPhotoLibrary={handlePhotoLibrary}
        onFile={handleFile}
        onClose={() => setPickerVisible(false)}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  wrapper: {
    backgroundColor: 'rgba(11,15,23,0.92)',
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: 'rgba(255,255,255,0.08)',
  },
  hintPill: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'center',
    marginTop: spacing.sm,
    marginBottom: 2,
    paddingVertical: spacing.xs + 2,
    paddingHorizontal: spacing.md,
    borderRadius: 999,
    borderWidth: 1,
    backgroundColor: 'rgba(20,26,38,0.9)',
  },
  hintDot: {
    width: 7,
    height: 7,
    borderRadius: 4,
    marginRight: spacing.xs + 2,
  },
  hintText: {
    color: colors.textPrimary,
    fontSize: fontSize.sm - 1,
    fontWeight: '500',
    letterSpacing: 0.2,
  },
  chipStrip: {
    paddingHorizontal: spacing.sm,
    paddingTop: spacing.sm,
    gap: spacing.xs + 2,
  },
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 6,
    paddingHorizontal: spacing.sm,
    borderRadius: radius.md,
    borderWidth: 1,
    backgroundColor: 'rgba(20,26,38,0.85)',
    gap: spacing.xs + 2,
    maxWidth: 200,
  },
  chipText: {
    color: colors.textPrimary,
    fontSize: fontSize.sm - 1,
    flexShrink: 1,
  },
  modeRow: {
    flexDirection: 'row',
    justifyContent: 'center',
    paddingTop: spacing.xs,
  },
  modeChip: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 4,
    paddingHorizontal: 10,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.12)',
    backgroundColor: 'rgba(20,26,38,0.6)',
  },
  modeDot: {
    width: 6,
    height: 6,
    borderRadius: 3,
    marginRight: 6,
  },
  modeChipText: {
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: '600',
    letterSpacing: 0.3,
  },
  container: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    padding: spacing.sm,
    gap: spacing.xs,
  },
  attachButton: {
    width: 44,
    height: 44,
    borderRadius: radius.pill,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(255,255,255,0.1)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.15)',
  },
  attachDisabled: { opacity: 0.4 },
  input: {
    flex: 1,
    marginHorizontal: spacing.xs,
    backgroundColor: 'rgba(31,41,55,0.7)',
    color: colors.textPrimary,
    borderRadius: radius.pill,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm + 2,
    fontSize: fontSize.md,
    maxHeight: 100,
    minHeight: 44,
  },
  sendButton: {
    width: 44,
    height: 44,
    borderRadius: radius.pill,
    alignItems: 'center',
    justifyContent: 'center',
  },
  sendDisabled: { opacity: 0.35 },
});
