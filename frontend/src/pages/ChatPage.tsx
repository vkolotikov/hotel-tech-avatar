import { useEffect, useMemo, useRef, useState, type CSSProperties, type FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ApiError, assetUrl } from '../api/client';
import MessageMarkdown from '../components/MessageMarkdown';
import { useMediaQuery } from '../hooks/useMediaQuery';
import { useOnlineStatus } from '../hooks/useOnlineStatus';
import {
  createAgentReply,
  createConversationForAgent,
  createMessage,
  deleteConversation,
  getAgent,
  getAgentAttachments,
  getConversationAttachments,
  getConversationForAgent,
  getConversationsForAgent,
  getMessages,
  renameConversation,
  speakConversationMessage,
  transcribeConversationAudio,
  uploadConversationAttachments,
  type Agent,
  type AgentAttachment,
  type ConversationAttachment,
  type Conversation,
  type Message,
} from '../api/endpoints';
import { getAgentExpertProfile, type ExpertProfile } from '../utils/agentAssets';
import '../styles/chat.css';

const STARTER_PROMPTS: Record<ExpertProfile['key'], string[]> = {
  'marketing-expert': [
    'Help me create a launch plan for my new product.',
    'Give me 3 high-converting offer ideas for this month.',
    'How should I position my service against competitors?',
  ],
  'social-media-manager': [
    'Create a 7-day social content plan for my brand.',
    'Give me 10 post ideas to increase engagement fast.',
    'What should I post this week for Instagram and LinkedIn?',
  ],
  acountant: [
    'Help me organize my monthly business budget.',
    'What financial reports should I review every month?',
    'How can I reduce business expenses safely?',
  ],
  copywriter: [
    'Write a landing page headline for my offer.',
    'Give me 5 ad copy hooks for my target audience.',
    'Improve this CTA to increase conversions.',
  ],
  'e-mail-manager': [
    'Build a simple email welcome sequence for new leads.',
    'Suggest subject lines to improve open rates.',
    'What emails should I send this week to drive sales?',
  ],
  'business-coach': [
    'Help me set priorities for the next 30 days.',
    'How do I improve operations for a small team?',
    'Give me a weekly growth checklist for my business.',
  ],
};

const DEFAULT_STARTER_PROMPTS = [
  'Help me with the next best action for my business.',
  'Review my idea and give me a practical plan.',
  'Ask me 3 questions to clarify my goal, then suggest a strategy.',
];

function isAbortError(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError';
}

function dedupeConversations(items: Conversation[]): Conversation[] {
  const seen = new Set<number>();
  const result: Conversation[] = [];

  for (const item of items) {
    if (seen.has(item.id)) {
      continue;
    }

    seen.add(item.id);
    result.push(item);
  }

  return result;
}

function formatConversationTime(value: string): string {
  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return 'Now';
  }

  return date.toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

function parseAgentMessageContent(
  content: string,
  ui?: Message['ui'] | null
): {
  displayText: string;
  quickReplies: string[];
  followUpQuestion: string | null;
  sources: Array<{ label: string; path?: string }>;
} {
  const uiQuickReplies = Array.isArray(ui?.quick_replies)
    ? ui.quick_replies.map((item) => String(item).trim()).filter((item) => item.length > 0).slice(0, 5)
    : [];
  const uiFollowUpQuestion = typeof ui?.follow_up_question === 'string' && ui.follow_up_question.trim() !== ''
    ? ui.follow_up_question.trim()
    : null;
  const uiSources = Array.isArray(ui?.sources)
    ? ui.sources
      .filter((item): item is { label: string; path?: string } => Boolean(item && typeof item.label === 'string'))
      .map((item) => ({
        label: item.label.trim(),
        path: typeof item.path === 'string' && item.path.trim() !== '' ? item.path.trim() : undefined,
      }))
      .filter((item) => item.label.length > 0)
    : [];

  const blockPattern = /\[\[quick_replies\]\]([\s\S]*?)\[\[\/quick_replies\]\]/i;
  const match = blockPattern.exec(content);

  if (!match) {
    return {
      displayText: content,
      quickReplies: uiQuickReplies,
      followUpQuestion: uiFollowUpQuestion,
      sources: uiSources,
    };
  }

  const lines = match[1]
    .split(/\r?\n/)
    .map((line) => line.trim().replace(/^[-*]\s*/, ''))
    .filter((line) => line.length > 0);

  const quickReplies = lines.slice(0, 5);
  const displayText = content
    .replace(match[0], '')
    .replace(/\n{3,}/g, '\n\n')
    .trim();

  return {
    displayText: displayText || 'Please choose one option below.',
    quickReplies: uiQuickReplies.length > 0 ? uiQuickReplies : quickReplies,
    followUpQuestion: uiFollowUpQuestion,
    sources: uiSources,
  };
}

type RenderMessage = Message & {
  streaming?: boolean;
};

function splitForStreaming(content: string): string[] {
  const parts = content.match(/\S+\s*/g);

  if (!parts || parts.length === 0) {
    return [content];
  }

  return parts;
}

function getAudioExtension(mimeType: string): string {
  const normalized = mimeType.toLowerCase();

  if (normalized.includes('webm')) {
    return 'webm';
  }

  if (normalized.includes('mp4')) {
    return 'mp4';
  }

  if (normalized.includes('mpeg') || normalized.includes('mp3')) {
    return 'mp3';
  }

  if (normalized.includes('wav')) {
    return 'wav';
  }

  if (normalized.includes('ogg')) {
    return 'ogg';
  }

  if (normalized.includes('flac')) {
    return 'flac';
  }

  if (normalized.includes('aac')) {
    return 'aac';
  }

  if (normalized.includes('opus')) {
    return 'opus';
  }

  return 'webm';
}

function mergeAudioChunks(chunks: Float32Array[]): Float32Array {
  const totalLength = chunks.reduce((sum, chunk) => sum + chunk.length, 0);
  const merged = new Float32Array(totalLength);
  let offset = 0;

  chunks.forEach((chunk) => {
    merged.set(chunk, offset);
    offset += chunk.length;
  });

  return merged;
}

function encodeWavFile(samples: Float32Array, sampleRate: number): Blob {
  const buffer = new ArrayBuffer(44 + samples.length * 2);
  const view = new DataView(buffer);

  const writeString = (offset: number, value: string) => {
    for (let i = 0; i < value.length; i += 1) {
      view.setUint8(offset + i, value.charCodeAt(i));
    }
  };

  writeString(0, 'RIFF');
  view.setUint32(4, 36 + samples.length * 2, true);
  writeString(8, 'WAVE');
  writeString(12, 'fmt ');
  view.setUint32(16, 16, true);
  view.setUint16(20, 1, true);
  view.setUint16(22, 1, true);
  view.setUint32(24, sampleRate, true);
  view.setUint32(28, sampleRate * 2, true);
  view.setUint16(32, 2, true);
  view.setUint16(34, 16, true);
  writeString(36, 'data');
  view.setUint32(40, samples.length * 2, true);

  let offset = 44;
  for (let i = 0; i < samples.length; i += 1) {
    const sample = Math.max(-1, Math.min(1, samples[i]));
    view.setInt16(offset, sample < 0 ? sample * 0x8000 : sample * 0x7fff, true);
    offset += 2;
  }

  return new Blob([buffer], { type: 'audio/wav' });
}

export default function ChatPage() {
  const { id } = useParams();
  const isMobileViewport = useMediaQuery('(max-width: 960px)');
  const isOnline = useOnlineStatus();
  const [agent, setAgent] = useState<Agent | null>(null);
  const [profile, setProfile] = useState<ExpertProfile | null>(null);
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [activeConversationId, setActiveConversationId] = useState<number | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [loading, setLoading] = useState(true);
  const [messagesLoading, setMessagesLoading] = useState(false);
  const [creatingChat, setCreatingChat] = useState(false);
  const [deletingConversationId, setDeletingConversationId] = useState<number | null>(null);
  const [renamingConversationId, setRenamingConversationId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [input, setInput] = useState('');
  const [sending, setSending] = useState(false);
  const [voiceRecording, setVoiceRecording] = useState(false);
  const [voiceTranscribing, setVoiceTranscribing] = useState(false);
  const [voicePlayingId, setVoicePlayingId] = useState<number | null>(null);
  const [voiceLoadingId, setVoiceLoadingId] = useState<number | null>(null);
  const [pendingUserMessage, setPendingUserMessage] = useState<string | null>(null);
  const [waitingForAgent, setWaitingForAgent] = useState(false);
  const [streamingAgentMessage, setStreamingAgentMessage] = useState<RenderMessage | null>(null);
  const [copiedMessageId, setCopiedMessageId] = useState<number | null>(null);
  const [historyCollapsed, setHistoryCollapsed] = useState(false);
  const [mobileHistoryOpen, setMobileHistoryOpen] = useState(false);
  const [mobileConversationFilesOpen, setMobileConversationFilesOpen] = useState(false);
  const [attachmentsOpen, setAttachmentsOpen] = useState(false);
  const [attachmentsLoading, setAttachmentsLoading] = useState(false);
  const [attachmentsLoaded, setAttachmentsLoaded] = useState(false);
  const [attachmentsError, setAttachmentsError] = useState<string | null>(null);
  const [attachments, setAttachments] = useState<AgentAttachment[]>([]);
  const [conversationAttachments, setConversationAttachments] = useState<ConversationAttachment[]>([]);
  const [conversationAttachmentsLoading, setConversationAttachmentsLoading] = useState(false);
  const [conversationAttachmentsUploading, setConversationAttachmentsUploading] = useState(false);
  const [conversationAttachmentsError, setConversationAttachmentsError] = useState<string | null>(null);
  const listRef = useRef<HTMLDivElement | null>(null);
  const messageAbortRef = useRef<AbortController | null>(null);
  const attachmentAbortRef = useRef<AbortController | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const copyTimeoutRef = useRef<number | null>(null);
  const streamIntervalRef = useRef<number | null>(null);
  const activeConversationIdRef = useRef<number | null>(null);
  const voiceStreamRef = useRef<MediaStream | null>(null);
  const voiceAudioContextRef = useRef<AudioContext | null>(null);
  const voiceSourceNodeRef = useRef<MediaStreamAudioSourceNode | null>(null);
  const voiceProcessorNodeRef = useRef<ScriptProcessorNode | null>(null);
  const voiceSampleRateRef = useRef<number>(16000);
  const voiceChunksRef = useRef<Float32Array[]>([]);
  const voiceAudioRef = useRef<HTMLAudioElement | null>(null);
  const voiceAudioCacheRef = useRef<Map<number, string>>(new Map());

  const loadMessagesForConversation = async (conversationId: number) => {
    messageAbortRef.current?.abort();
    const controller = new AbortController();
    messageAbortRef.current = controller;
    setMessagesLoading(true);
    stopStreaming();

    try {
      const conversationMessages = await getMessages(conversationId, controller.signal);
      setMessages(conversationMessages);
      setError(null);
      return conversationMessages;
    } catch (err) {
      if (!isAbortError(err)) {
        setError(err instanceof Error ? err.message : 'Failed to load messages');
      }
      return [] as Message[];
    } finally {
      if (!controller.signal.aborted) {
        setMessagesLoading(false);
      }
    }
  };

  const loadConversationAttachments = async (conversationId: number) => {
    attachmentAbortRef.current?.abort();
    const controller = new AbortController();
    attachmentAbortRef.current = controller;
    setConversationAttachmentsLoading(true);
    setConversationAttachmentsError(null);

    try {
      const items = await getConversationAttachments(conversationId, controller.signal);
      setConversationAttachments(items);
    } catch (err) {
      if (!isAbortError(err)) {
        setConversationAttachmentsError(err instanceof Error ? err.message : 'Failed to load attachments');
      }
    } finally {
      if (!controller.signal.aborted) {
        setConversationAttachmentsLoading(false);
      }
    }
  };

  const stopStreaming = () => {
    if (streamIntervalRef.current !== null) {
      window.clearInterval(streamIntervalRef.current);
      streamIntervalRef.current = null;
    }

    setStreamingAgentMessage(null);
  };

  const stopVoicePlayback = () => {
    if (voiceAudioRef.current) {
      voiceAudioRef.current.pause();
      voiceAudioRef.current.currentTime = 0;
    }
    setVoicePlayingId(null);
  };

  const cleanupVoiceRecorder = async (skipState = false) => {
    if (voiceProcessorNodeRef.current) {
      voiceProcessorNodeRef.current.disconnect();
      voiceProcessorNodeRef.current.onaudioprocess = null;
      voiceProcessorNodeRef.current = null;
    }

    if (voiceSourceNodeRef.current) {
      voiceSourceNodeRef.current.disconnect();
      voiceSourceNodeRef.current = null;
    }

    if (voiceStreamRef.current) {
      voiceStreamRef.current.getTracks().forEach((track) => track.stop());
      voiceStreamRef.current = null;
    }

    if (voiceAudioContextRef.current) {
      const context = voiceAudioContextRef.current;
      voiceAudioContextRef.current = null;
      await context.close().catch(() => undefined);
    }

    if (!skipState) {
      setVoiceRecording(false);
    }
  };

  const streamAgentMessage = (conversationId: number, message: Message) => {
    stopStreaming();

    const tokens = splitForStreaming(message.content);
    let cursor = 0;

    setStreamingAgentMessage({
      ...message,
      content: '',
      streaming: true,
    });

    streamIntervalRef.current = window.setInterval(() => {
      if (activeConversationIdRef.current !== conversationId) {
        stopStreaming();
        return;
      }

      cursor += 1;
      const partialContent = tokens.slice(0, cursor).join('');

      setStreamingAgentMessage((current) => {
        if (!current || current.id !== message.id) {
          return current;
        }

        return {
          ...current,
          content: partialContent,
          streaming: cursor < tokens.length,
        };
      });

      if (cursor >= tokens.length) {
        stopStreaming();
        setMessages((prev) => {
          if (prev.some((item) => item.id === message.id)) {
            return prev;
          }

          return [...prev, message];
        });
      }
    }, 26);
  };

  const hasPendingUserMessage = (conversationMessages: Message[]) => {
    if (conversationMessages.length === 0) {
      return false;
    }

    return conversationMessages[conversationMessages.length - 1].role === 'user';
  };

  const triggerAgentReply = async (conversationId: number) => {
    if (waitingForAgent || streamingAgentMessage !== null) {
      return;
    }

    setWaitingForAgent(true);

    try {
      const result = await createAgentReply(conversationId);

      if (result.status === 'created' && result.message) {
        streamAgentMessage(conversationId, result.message);
        return;
      }

      await loadMessagesForConversation(conversationId);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to generate reply');
    } finally {
      setWaitingForAgent(false);
    }
  };

  const transcribeVoiceBlob = async (blob: Blob) => {
    if (!activeConversationId) {
      setError('Select a chat before using voice input.');
      return;
    }

    if (!isOnline) {
      setError('Offline mode: reconnect to use voice input.');
      return;
    }

    if (voiceTranscribing) {
      return;
    }

    setVoiceTranscribing(true);

    try {
      const extension = getAudioExtension(blob.type || 'audio/wav');
      const file = new File([blob], `voice-input.${extension}`, {
        type: blob.type || 'audio/wav',
      });
      const result = await transcribeConversationAudio(activeConversationId, file);
      const text = result.text?.trim() ?? '';

      if (text !== '') {
        setInput(text);
      } else {
        setError('No speech detected. Please try again.');
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Voice transcription failed');
    } finally {
      setVoiceTranscribing(false);
    }
  };

  const startVoiceRecording = async () => {
    if (voiceRecording || voiceTranscribing) {
      return;
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setError('Voice input is not supported in this browser.');
      return;
    }

    const AudioContextCtor =
      window.AudioContext
      || (window as Window & { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;

    if (!AudioContextCtor) {
      setError('Voice input is not supported in this browser.');
      return;
    }

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const audioContext = new AudioContextCtor();
      await audioContext.resume();
      const source = audioContext.createMediaStreamSource(stream);
      const processor = audioContext.createScriptProcessor(4096, 1, 1);

      voiceChunksRef.current = [];
      voiceSampleRateRef.current = audioContext.sampleRate;
      processor.onaudioprocess = (event) => {
        const inputData = event.inputBuffer.getChannelData(0);
        voiceChunksRef.current.push(new Float32Array(inputData));

        const outputData = event.outputBuffer.getChannelData(0);
        outputData.fill(0);
      };

      source.connect(processor);
      processor.connect(audioContext.destination);

      voiceAudioContextRef.current = audioContext;
      voiceStreamRef.current = stream;
      voiceSourceNodeRef.current = source;
      voiceProcessorNodeRef.current = processor;
      setVoiceRecording(true);
      setError(null);
    } catch (err) {
      await cleanupVoiceRecorder(true);
      setError('Microphone permission denied.');
    }
  };

  const stopVoiceRecording = () => {
    if (!voiceRecording) {
      return;
    }

    const samples = mergeAudioChunks(voiceChunksRef.current);
    voiceChunksRef.current = [];
    setVoiceRecording(false);

    void cleanupVoiceRecorder(true).then(() => {
      if (samples.length === 0) {
        setError('No speech detected. Please try again.');
        return;
      }

      const wavBlob = encodeWavFile(samples, voiceSampleRateRef.current);
      void transcribeVoiceBlob(wavBlob);
    });
  };

  const handleVoiceToggle = () => {
    if (voiceRecording) {
      stopVoiceRecording();
      return;
    }

    void startVoiceRecording();
  };

  const handleSpeakMessage = async (messageId: number, text: string) => {
    if (!activeConversationId || voiceLoadingId !== null) {
      return;
    }

    if (!isOnline) {
      setError('Offline mode: reconnect to play audio.');
      return;
    }

    if (voicePlayingId === messageId) {
      stopVoicePlayback();
      return;
    }

    const cachedUrl = voiceAudioCacheRef.current.get(messageId);
    if (cachedUrl) {
      playVoiceUrl(cachedUrl, messageId);
      return;
    }

    setVoiceLoadingId(messageId);

    try {
      const audioBlob = await speakConversationMessage(activeConversationId, { text });
      const url = URL.createObjectURL(audioBlob);
      const existingUrl = voiceAudioCacheRef.current.get(messageId);
      if (existingUrl) {
        URL.revokeObjectURL(existingUrl);
      }
      voiceAudioCacheRef.current.set(messageId, url);
      playVoiceUrl(url, messageId);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Voice playback failed');
    } finally {
      setVoiceLoadingId(null);
    }
  };

  const playVoiceUrl = (url: string, messageId: number) => {
    stopVoicePlayback();

    let audio = voiceAudioRef.current;
    if (!audio) {
      audio = new Audio();
      voiceAudioRef.current = audio;
    }

    audio.src = url;
    audio.onended = () => setVoicePlayingId(null);
    audio.onerror = () => setVoicePlayingId(null);

    audio.play().then(() => {
      setVoicePlayingId(messageId);
    }).catch(() => {
      setError('Audio playback was blocked by the browser.');
      setVoicePlayingId(null);
    });
  };

  useEffect(() => {
    if (id === undefined) {
      setError('Missing agent id');
      setLoading(false);
      return undefined;
    }

    const controller = new AbortController();
    let active = true;

    const loadChat = async () => {
      try {
        setLoading(true);
        setMessages([]);
        setPendingUserMessage(null);
        setWaitingForAgent(false);
        setConversations([]);
        setActiveConversationId(null);
        setMobileHistoryOpen(false);
        setMobileConversationFilesOpen(false);
        setAttachmentsOpen(false);
        setVoiceRecording(false);
        setVoiceTranscribing(false);
        setVoicePlayingId(null);
        setVoiceLoadingId(null);
        setAttachments([]);
        setAttachmentsError(null);
        setAttachmentsLoading(false);
        setAttachmentsLoaded(false);
        setConversationAttachments([]);
        setConversationAttachmentsError(null);
        setConversationAttachmentsLoading(false);
        setConversationAttachmentsUploading(false);

        const agentData = await getAgent(id, controller.signal);
        if (!active) {
          return;
        }
        setAgent(agentData);

        setProfile(getAgentExpertProfile(agentData));

        let history: Conversation[] = [];

        try {
          history = await getConversationsForAgent(id, controller.signal);
        } catch (err) {
          if (!(err instanceof ApiError)) {
            throw err;
          }
        }

        if (!active) {
          return;
        }

        let selectedConversation: Conversation | null = history[0] ?? null;

        if (selectedConversation === null) {
          try {
            selectedConversation = await createConversationForAgent(id, controller.signal);
          } catch (err) {
            if (!(err instanceof ApiError) || err.status !== 500) {
              throw err;
            }

            selectedConversation = await getConversationForAgent(id, controller.signal);
          }
        }

        if (!active || selectedConversation === null) {
          return;
        }

        const ordered = dedupeConversations([selectedConversation, ...history]);
        setConversations(ordered);
        setActiveConversationId(selectedConversation.id);
        setError(null);
        const initialMessages = await loadMessagesForConversation(selectedConversation.id);
        await loadConversationAttachments(selectedConversation.id);

        if (hasPendingUserMessage(initialMessages)) {
          void triggerAgentReply(selectedConversation.id);
        }
      } catch (err) {
        if (!active || isAbortError(err)) {
          return;
        }

        if (err instanceof ApiError) {
          setError(err.message);
        } else {
          setError(err instanceof Error ? err.message : 'Failed to load chat');
        }
      } finally {
        if (active) {
          setLoading(false);
        }
      }
    };

    void loadChat();

    return () => {
      active = false;
      controller.abort();
      messageAbortRef.current?.abort();
      attachmentAbortRef.current?.abort();
      stopStreaming();
    };
  }, [id]);

  useEffect(() => {
    activeConversationIdRef.current = activeConversationId;
    stopVoicePlayback();
  }, [activeConversationId]);

  useEffect(() => {
    if (!isMobileViewport) {
      setMobileHistoryOpen(false);
      return;
    }

    setHistoryCollapsed(false);
    setAttachmentsOpen(false);
    setMobileConversationFilesOpen(false);
  }, [isMobileViewport]);

  useEffect(() => {
    if (!listRef.current) {
      return;
    }

    listRef.current.scrollTop = listRef.current.scrollHeight;
  }, [messages, streamingAgentMessage?.content, waitingForAgent, pendingUserMessage]);

  useEffect(() => {
    return () => {
      if (copyTimeoutRef.current !== null) {
        window.clearTimeout(copyTimeoutRef.current);
      }

      stopStreaming();
      stopVoicePlayback();
      cleanupVoiceRecorder();
      voiceAudioCacheRef.current.forEach((url) => URL.revokeObjectURL(url));
      voiceAudioCacheRef.current.clear();
    };
  }, []);

  const backgroundStyle = useMemo(() => {
    if (!agent) {
      return undefined;
    }

    const background = assetUrl(agent.chat_background_url || profile?.background || '/assets/backgrounds/lobby-hd.png');

    return { backgroundImage: `url('${background}')` };
  }, [agent, profile]);

  const agentStyle = useMemo<CSSProperties>(() => {
    return {
      ['--avatar-scale' as string]: String(profile?.avatarScale ?? 1),
    };
  }, [profile]);

  const starterPrompts = useMemo(() => {
    if (!profile) {
      return DEFAULT_STARTER_PROMPTS;
    }

    return STARTER_PROMPTS[profile.key];
  }, [profile]);

  const displayMessages = useMemo<RenderMessage[]>(() => {
    if (streamingAgentMessage === null) {
      return messages;
    }

    if (messages.some((item) => item.id === streamingAgentMessage.id)) {
      return messages;
    }

    return [...messages, streamingAgentMessage];
  }, [messages, streamingAgentMessage]);

  const isAssistantBusy = waitingForAgent || streamingAgentMessage !== null;

  const activeConversation = useMemo(() => {
    if (activeConversationId === null) {
      return null;
    }

    return conversations.find((conversation) => conversation.id === activeConversationId) ?? null;
  }, [activeConversationId, conversations]);

  const displayAgent = useMemo(() => {
    if (!agent) {
      return null;
    }

    return {
      name: agent.name || profile?.name || 'Avatar',
      role: agent.role || profile?.role || 'Assistant',
      avatar: assetUrl(agent.avatar_image_url || profile?.avatar || '/assets/avatars/marketing-expert.png'),
    };
  }, [agent, profile]);

  const sendMessage = async (content: string) => {
    if (!activeConversationId || sending || isAssistantBusy) {
      return;
    }

    if (!isOnline) {
      setError('Offline mode: reconnect to send messages.');
      return;
    }

    const trimmed = content.trim();

    if (!trimmed) {
      return;
    }

    setSending(true);
    setPendingUserMessage(trimmed);

    try {
      const createdUserMessage = await createMessage(activeConversationId, 'user', trimmed, false);
      setMessages((prev) => [...prev, createdUserMessage]);
      setInput('');
      setPendingUserMessage(null);

      if (id) {
        const history = await getConversationsForAgent(id);
        setConversations(dedupeConversations(history));
      }

      setError(null);
      void triggerAgentReply(activeConversationId);
    } catch (err) {
      if (!isAbortError(err)) {
        setError(err instanceof Error ? err.message : 'Failed to send message');
      }
    } finally {
      setPendingUserMessage(null);
      setSending(false);
    }
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    await sendMessage(input);
  };

  const handleSelectConversation = async (conversationId: number) => {
    if (conversationId === activeConversationId || messagesLoading) {
      return;
    }

    cleanupVoiceRecorder();
    stopVoicePlayback();

    if (isMobileViewport) {
      setMobileHistoryOpen(false);
      setAttachmentsOpen(false);
      setMobileConversationFilesOpen(false);
    }

    setActiveConversationId(conversationId);
    const conversationMessages = await loadMessagesForConversation(conversationId);
    await loadConversationAttachments(conversationId);

    if (hasPendingUserMessage(conversationMessages)) {
      void triggerAgentReply(conversationId);
    }
  };

  const handleCreateChat = async () => {
    if (!id || creatingChat) {
      return;
    }

    cleanupVoiceRecorder();
    stopVoicePlayback();
    setCreatingChat(true);

    try {
      const conversation = await createConversationForAgent(id);
      const history = await getConversationsForAgent(id);
      const ordered = dedupeConversations([conversation, ...history]);

      setConversations(ordered);
      setActiveConversationId(conversation.id);
      setMessages([]);
      setConversationAttachments([]);
      setConversationAttachmentsError(null);
      if (isMobileViewport) {
        setMobileHistoryOpen(false);
        setAttachmentsOpen(false);
        setMobileConversationFilesOpen(false);
      }
      setError(null);
      await loadMessagesForConversation(conversation.id);
      await loadConversationAttachments(conversation.id);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create chat');
    } finally {
      setCreatingChat(false);
    }
  };

  const handleDeleteConversation = async (conversationId: number) => {
    if (deletingConversationId !== null || creatingChat || renamingConversationId !== null) {
      return;
    }

    setDeletingConversationId(conversationId);

    try {
      await deleteConversation(conversationId);
      const remaining = conversations.filter((conversation) => conversation.id !== conversationId);
      setConversations(remaining);

      if (activeConversationId !== conversationId) {
        if (isMobileViewport) {
          setMobileHistoryOpen(false);
        }
        setError(null);
        return;
      }

      if (remaining.length > 0) {
        const nextConversation = remaining[0];
        setActiveConversationId(nextConversation.id);
        if (isMobileViewport) {
          setMobileHistoryOpen(false);
          setAttachmentsOpen(false);
          setMobileConversationFilesOpen(false);
        }
        await loadMessagesForConversation(nextConversation.id);
        await loadConversationAttachments(nextConversation.id);
      } else {
        setActiveConversationId(null);
        setMessages([]);
        setConversationAttachments([]);
        setConversationAttachmentsError(null);
        if (isMobileViewport) {
          setMobileHistoryOpen(false);
          setAttachmentsOpen(false);
          setMobileConversationFilesOpen(false);
        }
        await handleCreateChat();
      }

      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to delete chat');
    } finally {
      setDeletingConversationId(null);
    }
  };

  const handleRenameConversation = async (conversation: Conversation) => {
    if (renamingConversationId !== null || creatingChat || deletingConversationId !== null) {
      return;
    }

    const fallbackTitle = `Chat #${conversation.id}`;
    const initialTitle = (conversation.title ?? '').trim() || fallbackTitle;
    const inputTitle = window.prompt('Rename chat', initialTitle);

    if (inputTitle === null) {
      return;
    }

    const nextTitle = inputTitle.trim();

    if (nextTitle === '') {
      setError('Chat title cannot be empty');
      return;
    }

    setRenamingConversationId(conversation.id);

    try {
      const updated = await renameConversation(conversation.id, nextTitle);
      setConversations((prev) =>
        prev.map((item) => (item.id === conversation.id ? updated : item))
      );
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to rename chat');
    } finally {
      setRenamingConversationId(null);
    }
  };

  const handleCopyMessage = async (messageId: number, content: string) => {
    const text = content.trim();
    if (text === '') {
      return;
    }

    try {
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        await navigator.clipboard.writeText(text);
      } else {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
      }

      setCopiedMessageId(messageId);

      if (copyTimeoutRef.current !== null) {
        window.clearTimeout(copyTimeoutRef.current);
      }

      copyTimeoutRef.current = window.setTimeout(() => {
        setCopiedMessageId(null);
      }, 1200);
    } catch {
      // No-op: keep chat flow uninterrupted if clipboard is blocked.
    }
  };

  const handleToggleAttachments = async () => {
    if (!id) {
      return;
    }

    if (attachmentsOpen) {
      setAttachmentsOpen(false);
      return;
    }

    if (attachmentsLoading) {
      return;
    }

    setAttachmentsOpen(true);

    if (attachmentsLoaded) {
      return;
    }

    setAttachmentsLoading(true);
    setAttachmentsError(null);

    try {
      const payload = await getAgentAttachments(id);
      setAttachments(payload.attachments ?? []);
      setAttachmentsError(null);
      setAttachmentsLoaded(true);
    } catch (err) {
      setAttachmentsError(err instanceof Error ? err.message : 'Failed to load attachments');
    } finally {
      setAttachmentsLoading(false);
    }
  };

  const handleConversationAttachmentUpload = async (files: FileList | null) => {
    if (!activeConversationId || !files || files.length === 0 || conversationAttachmentsUploading) {
      return;
    }

    if (!isOnline) {
      setConversationAttachmentsError('Offline mode: reconnect to upload attachments.');
      if (fileInputRef.current) {
        fileInputRef.current.value = '';
      }
      return;
    }

    const selectedFiles = Array.from(files);
    setConversationAttachmentsUploading(true);
    setConversationAttachmentsError(null);

    try {
      await uploadConversationAttachments(activeConversationId, selectedFiles);
      await loadConversationAttachments(activeConversationId);
    } catch (err) {
      setConversationAttachmentsError(err instanceof Error ? err.message : 'Failed to upload attachments');
    } finally {
      setConversationAttachmentsUploading(false);
      if (fileInputRef.current) {
        fileInputRef.current.value = '';
      }
    }
  };

  const handleToggleMobileHistory = () => {
    if (!isMobileViewport) {
      setHistoryCollapsed((current) => !current);
      return;
    }

    setMobileHistoryOpen((current) => !current);
  };

  const panelBodyClassName = [
    'chat-panel__body',
    historyCollapsed ? 'chat-panel__body--history-collapsed' : '',
    isMobileViewport ? 'chat-panel__body--mobile' : '',
  ]
    .filter(Boolean)
    .join(' ');

  const dialogsClassName = [
    'chat-dialogs',
    historyCollapsed ? 'chat-dialogs--collapsed' : '',
  ]
    .filter(Boolean)
    .join(' ');

  const showStarterPrompts =
    messages.length === 0 &&
    !messagesLoading;
  const showConversationAttachments =
    !isMobileViewport ||
    mobileConversationFilesOpen ||
    conversationAttachmentsLoading ||
    conversationAttachmentsError !== null;
  const showAvatarAttachments = attachmentsOpen && !isMobileViewport;
  const mobileConversationAttachmentCount = conversationAttachments.length;

  const conversationList = (
    <div className="chat-dialogs__list">
      {conversations.map((conversation, index) => (
        <div
          key={conversation.id}
          className={`chat-dialogs__row ${conversation.id === activeConversationId ? 'chat-dialogs__row--active' : ''}`}
        >
          <button
            type="button"
            className={`chat-dialogs__item ${conversation.id === activeConversationId ? 'chat-dialogs__item--active' : ''}`}
            onClick={() => void handleSelectConversation(conversation.id)}
          >
            <span className="chat-dialogs__item-title">
              {(conversation.title ?? '').trim() || `Chat #${conversation.id}`}
            </span>
            <span className="chat-dialogs__item-meta">
              {index === 0 ? 'Latest' : formatConversationTime(conversation.updated_at)}
            </span>
          </button>
        </div>
      ))}
    </div>
  );

  if (loading) {
    return <div className="chat-page__state">Loading chat...</div>;
  }

  if (!agent || !displayAgent) {
    return <div className="chat-page__state chat-page__state--error">Unable to load chat.</div>;
  }

  return (
    <div className="chat-page" style={backgroundStyle}>
      <div className="chat-page__overlay">
        <aside className="chat-agent" style={agentStyle}>
          <img
            className="chat-agent__avatar"
            src={displayAgent.avatar}
            alt={`${displayAgent.name} avatar`}
          />
          <h1>{displayAgent.name}</h1>
          <p>{displayAgent.role}</p>
        </aside>

        <div className={`chat-panel ${isMobileViewport ? 'chat-panel--mobile' : ''}`}>
          <header className="chat-panel__header">
            <div className="chat-panel__heading">
              <Link to="/" className="chat-panel__back">
                {'< Back to Hub'}
              </Link>
              {isMobileViewport && (
                <button
                  type="button"
                  className="chat-panel__mobile-history-btn"
                  onClick={handleToggleMobileHistory}
                >
                  {mobileHistoryOpen ? 'Close chats' : 'Chats'}
                </button>
              )}
              <h2>Chat with {displayAgent.name}</h2>
              <p>Start a new chat or continue one from your history.</p>
            </div>

            <div className="chat-panel__actions">
              <button
                type="button"
                className={`chat-panel__action chat-panel__action--attachments ${attachmentsOpen ? 'chat-panel__action--active' : ''}`}
                onClick={() => void handleToggleAttachments()}
                aria-label={attachmentsOpen ? 'Hide attachments' : 'Show attachments'}
                title={attachmentsOpen ? 'Hide attachments' : 'Show attachments'}
              >
                <span className="chat-panel__paperclip" aria-hidden="true" />
              </button>

              <button
                type="button"
                className="chat-panel__action chat-panel__action--print"
                onClick={() => window.print()}
                aria-label="Print chat"
                title="Print chat"
              >
                <span className="chat-panel__print-icon" aria-hidden="true" />
              </button>

              <button
                type="button"
                className="chat-panel__action chat-panel__action--rename"
                onClick={() => activeConversation && void handleRenameConversation(activeConversation)}
                disabled={!activeConversation || renamingConversationId !== null || deletingConversationId !== null}
                aria-label={activeConversation ? `Rename chat ${activeConversation.id}` : 'Rename chat'}
                title="Rename chat"
              >
                <span className="chat-dialogs__rename-icon" aria-hidden="true" />
              </button>

              <button
                type="button"
                className="chat-panel__action chat-panel__action--delete"
                onClick={() => activeConversationId && void handleDeleteConversation(activeConversationId)}
                disabled={!activeConversationId || deletingConversationId !== null || renamingConversationId !== null}
                aria-label={activeConversationId ? `Delete chat ${activeConversationId}` : 'Delete chat'}
                title="Delete chat"
              >
                <span className="chat-dialogs__delete-icon" aria-hidden="true" />
              </button>
            </div>

            {isMobileViewport && (
              <div className="chat-panel__mobile-profile">
                <img
                  className="chat-panel__mobile-avatar"
                  src={displayAgent.avatar}
                  alt={`${displayAgent.name} avatar`}
                />
                <div className="chat-panel__mobile-meta">
                  <strong>{displayAgent.name}</strong>
                  <span>{displayAgent.role}</span>
                </div>
              </div>
            )}
          </header>

          {isMobileViewport && mobileHistoryOpen && (
            <button
              type="button"
              className="chat-panel__mobile-backdrop"
              onClick={() => setMobileHistoryOpen(false)}
              aria-label="Close chat history"
            />
          )}

          {isMobileViewport && (
            <aside className={`chat-mobile-drawer ${mobileHistoryOpen ? 'chat-mobile-drawer--open' : ''}`}>
              <div className="chat-mobile-drawer__header">
                <div className="chat-mobile-drawer__title">
                  <strong>Chats</strong>
                  <span>{conversations.length} saved conversation{conversations.length === 1 ? '' : 's'}</span>
                </div>
                <button
                  type="button"
                  className="chat-mobile-drawer__close"
                  onClick={() => setMobileHistoryOpen(false)}
                >
                  Done
                </button>
              </div>

              <div className="chat-mobile-drawer__actions">
                <button
                  type="button"
                  className="chat-dialogs__new"
                  onClick={() => void handleCreateChat()}
                  disabled={creatingChat || sending || isAssistantBusy || deletingConversationId !== null || renamingConversationId !== null}
                >
                  + New chat
                </button>

                {activeConversation && (
                  <button
                    type="button"
                    className="chat-mobile-drawer__secondary"
                    onClick={() => activeConversation && void handleRenameConversation(activeConversation)}
                    disabled={renamingConversationId !== null || deletingConversationId !== null}
                  >
                    Rename
                  </button>
                )}

                {activeConversationId && (
                  <button
                    type="button"
                    className="chat-mobile-drawer__secondary chat-mobile-drawer__secondary--danger"
                    onClick={() => activeConversationId && void handleDeleteConversation(activeConversationId)}
                    disabled={deletingConversationId !== null || renamingConversationId !== null}
                  >
                    Delete
                  </button>
                )}
              </div>

              {conversationList}
            </aside>
          )}

          <div className={panelBodyClassName}>
            <div className="chat-layout">
              {!isMobileViewport && (
                <aside className={dialogsClassName}>
                  <button
                    type="button"
                    className="chat-dialogs__toggle"
                    onClick={handleToggleMobileHistory}
                    aria-label={historyCollapsed ? 'Expand chat history' : 'Collapse chat history'}
                    title={historyCollapsed ? 'Expand history' : 'Collapse history'}
                  >
                    {historyCollapsed ? '>>' : '<<'}
                  </button>

                  <button
                    type="button"
                    className="chat-dialogs__new"
                    onClick={() => void handleCreateChat()}
                    disabled={creatingChat || sending || isAssistantBusy || deletingConversationId !== null || renamingConversationId !== null}
                  >
                    + New chat
                  </button>

                  {conversationList}
                </aside>
              )}

              <section className="chat-main">
                {error && <div className="chat-error-banner">{error}</div>}
                {showAvatarAttachments && (
                  <div className="chat-attachments">
                    <div className="chat-attachments__title">Avatar attachments</div>
                    {attachmentsLoading && <p className="chat-attachments__state">Loading...</p>}
                    {!attachmentsLoading && attachmentsError && (
                      <p className="chat-attachments__state chat-attachments__state--error">{attachmentsError}</p>
                    )}
                    {!attachmentsLoading && !attachmentsError && attachments.length === 0 && (
                      <p className="chat-attachments__state">No attachments found for this avatar.</p>
                    )}
                    {!attachmentsLoading && !attachmentsError && attachments.length > 0 && (
                      <ul className="chat-attachments__list">
                        {attachments.map((item) => (
                          <li key={item.path}>
                            <a
                              href={item.path}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="chat-attachments__item"
                              title={item.path}
                            >
                              {item.name}
                            </a>
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                )}

                {isMobileViewport && (
                  <button
                    type="button"
                    className={`chat-mobile-section-toggle ${mobileConversationFilesOpen ? 'chat-mobile-section-toggle--open' : ''}`}
                    onClick={() => setMobileConversationFilesOpen((current) => !current)}
                  >
                    {mobileConversationFilesOpen ? 'Hide chat files' : `Chat files${mobileConversationAttachmentCount > 0 ? ` (${mobileConversationAttachmentCount})` : ''}`}
                  </button>
                )}

                {showConversationAttachments && (
                  <div className="chat-conversation-attachments">
                    <div className="chat-conversation-attachments__header">
                      <span>This chat attachments</span>
                      {conversationAttachmentsUploading && (
                        <span className="chat-conversation-attachments__meta">Uploading...</span>
                      )}
                      {!conversationAttachmentsUploading && conversationAttachmentsLoading && (
                        <span className="chat-conversation-attachments__meta">Loading...</span>
                      )}
                    </div>

                    {conversationAttachmentsError && (
                      <p className="chat-conversation-attachments__error">{conversationAttachmentsError}</p>
                    )}

                    {!conversationAttachmentsError && conversationAttachments.length > 0 && (
                      <ul className="chat-conversation-attachments__list">
                        {conversationAttachments.map((item) => (
                          <li key={item.id}>
                            <a
                              href={item.file_path}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="chat-conversation-attachments__item"
                              title={item.file_path}
                            >
                              {item.file_name}
                            </a>
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                )}

                {showStarterPrompts && (
                  <div className="chat-starters">
                    <div className="chat-starters__label">Starter prompts</div>
                    <div className="chat-starters__list">
                      {starterPrompts.map((prompt) => (
                        <button
                          key={prompt}
                          type="button"
                          className="chat-starter"
                          onClick={() => void sendMessage(prompt)}
                          disabled={sending || isAssistantBusy || !isOnline}
                        >
                          {prompt}
                        </button>
                      ))}
                    </div>
                  </div>
                )}

                <div className="chat-messages" ref={listRef}>
                  {messagesLoading && <div className="chat-empty">Loading messages...</div>}
                  {!messagesLoading && messages.length === 0 && (
                    <div className="chat-empty">No messages yet. Start the conversation.</div>
                  )}

                  {displayMessages.map((message) => (
                    (() => {
                      const parsedAgentMessage = message.role === 'agent' && !message.streaming
                        ? parseAgentMessageContent(message.content, message.ui)
                        : null;
                      const bubbleText = parsedAgentMessage?.displayText ?? message.content;

                      return (
                        <div
                          key={message.id}
                          className={`chat-message ${message.role === 'user' ? 'chat-message--user' : 'chat-message--agent'}`}
                        >
                          <div className="chat-message__content">
                            <div className="chat-bubble">
                              {message.role === 'agent' && !message.streaming
                                ? <MessageMarkdown content={bubbleText} />
                                : bubbleText}
                            </div>

                            {message.role === 'agent' && parsedAgentMessage && parsedAgentMessage.followUpQuestion && (
                              <p className="chat-follow-up-question">{parsedAgentMessage.followUpQuestion}</p>
                            )}

                            {message.role === 'agent' && parsedAgentMessage && parsedAgentMessage.quickReplies.length > 0 && (
                              <div className="chat-quick-replies">
                                {parsedAgentMessage.quickReplies.map((reply) => (
                                  <button
                                    key={`${message.id}-${reply}`}
                                    type="button"
                                    className="chat-quick-reply"
                                    onClick={() => void sendMessage(reply)}
                                    disabled={sending || isAssistantBusy || !isOnline}
                                  >
                                    {reply}
                                  </button>
                                ))}
                              </div>
                            )}

                            {message.role === 'agent' && parsedAgentMessage && parsedAgentMessage.sources.length > 0 && (
                              <details className="chat-sources">
                                <summary>Sources</summary>
                                <ul>
                                  {parsedAgentMessage.sources.map((source, index) => (
                                    <li key={`${message.id}-source-${index}`}>
                                      {source.path ? `${source.label} (${source.path})` : source.label}
                                    </li>
                                  ))}
                                </ul>
                              </details>
                            )}
                          </div>

                          {message.role === 'agent' && !message.streaming && (
                            <button
                              type="button"
                              className={`chat-voice-btn ${voicePlayingId === message.id ? 'is-playing' : ''} ${voiceLoadingId === message.id ? 'is-loading' : ''}`}
                              onClick={() => void handleSpeakMessage(message.id, bubbleText)}
                              aria-label={voicePlayingId === message.id ? 'Stop voice playback' : 'Play voice reply'}
                              title={voicePlayingId === message.id ? 'Stop' : 'Play'}
                              disabled={voiceLoadingId !== null}
                            >
                              <span className="chat-voice-icon" aria-hidden="true" />
                            </button>
                          )}

                          {message.role === 'agent' && !message.streaming && (
                            <button
                              type="button"
                              className={`chat-copy-btn ${copiedMessageId === message.id ? 'is-copied' : ''}`}
                              onClick={() => void handleCopyMessage(message.id, bubbleText)}
                              aria-label={copiedMessageId === message.id ? 'Copied' : 'Copy reply'}
                              title={copiedMessageId === message.id ? 'Copied' : 'Copy'}
                            >
                              <span className="chat-copy-icon" aria-hidden="true" />
                            </button>
                          )}
                        </div>
                      );
                    })()
                  ))}

                  {pendingUserMessage && (
                    <div className="chat-message chat-message--user chat-message--pending">
                      <div className="chat-bubble">{pendingUserMessage}</div>
                    </div>
                  )}

                  {waitingForAgent && streamingAgentMessage === null && (
                    <div className="chat-message chat-message--agent chat-message--typing">
                      <div className="chat-bubble">
                        Assistant is typing
                        <span className="chat-typing-dots" aria-hidden="true">
                          <span />
                          <span />
                          <span />
                        </span>
                      </div>
                    </div>
                  )}
                </div>

                <form className="chat-input" onSubmit={handleSubmit}>
                  <button
                    type="button"
                    className={`chat-input__attach ${conversationAttachmentsUploading ? 'chat-input__attach--active' : ''}`}
                    onClick={() => fileInputRef.current?.click()}
                    aria-label="Attach files to this chat"
                    title="Attach files to this chat"
                    disabled={!activeConversationId || conversationAttachmentsUploading || !isOnline}
                  >
                    <span className="chat-panel__paperclip" aria-hidden="true" />
                  </button>
                  <button
                    type="button"
                    className={`chat-input__voice ${voiceRecording ? 'chat-input__voice--active' : ''} ${voiceTranscribing ? 'chat-input__voice--busy' : ''}`}
                    onClick={handleVoiceToggle}
                    aria-label={voiceRecording ? 'Stop voice recording' : 'Start voice recording'}
                    title={voiceRecording ? 'Stop recording' : 'Start voice input'}
                    disabled={!activeConversationId || voiceTranscribing || !isOnline}
                  >
                    <span className="chat-input__voice-icon" aria-hidden="true" />
                  </button>
                  <input
                    ref={fileInputRef}
                    type="file"
                    className="chat-input__file-picker"
                    multiple
                    accept=".txt,.md,.pdf,.csv,.json,.docx,.xls,.xlsx,.pptx,.jpg,.jpeg,.png,.webp"
                    onChange={(event) => void handleConversationAttachmentUpload(event.target.files)}
                  />

                  <input
                    type="text"
                    value={input}
                    onChange={(event) => setInput(event.target.value)}
                    placeholder="Type a message..."
                    disabled={sending || !activeConversationId || !isOnline}
                  />
                  <button
                    type="submit"
                    disabled={
                      sending
                      || isAssistantBusy
                      || input.trim() === ''
                      || !activeConversationId
                      || !isOnline
                    }
                  >
                    Send
                  </button>
                </form>
                {voiceTranscribing && (
                  <p className="chat-input__voice-note">Transcribing voice message...</p>
                )}
                {!isOnline && (
                  <p className="chat-input__offline-note">Offline - reconnect to send.</p>
                )}
              </section>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
