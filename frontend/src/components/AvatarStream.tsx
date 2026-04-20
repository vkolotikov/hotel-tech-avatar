import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react';
import StreamingAvatar, {
  AvatarQuality,
  StreamingEvents,
  TaskType,
} from '@heygen/streaming-avatar';
import { createHeygenToken } from '../api/endpoints';

export type AvatarStreamHandle = {
  speak: (text: string) => Promise<void>;
  interrupt: () => Promise<void>;
  isReady: () => boolean;
};

type AvatarStreamProps = {
  active: boolean;
  fallbackImage?: string;
  fallbackAlt?: string;
  className?: string;
  onReady?: () => void;
  onError?: (err: Error) => void;
  onAvatarStopTalking?: () => void;
};

type Status = 'idle' | 'connecting' | 'ready' | 'error';

const qualityMap: Record<string, AvatarQuality> = {
  low: AvatarQuality.Low,
  medium: AvatarQuality.Medium,
  high: AvatarQuality.High,
};

export const AvatarStream = forwardRef<AvatarStreamHandle, AvatarStreamProps>(function AvatarStream(
  { active, fallbackImage, fallbackAlt, className, onReady, onError, onAvatarStopTalking },
  ref,
) {
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const avatarRef = useRef<StreamingAvatar | null>(null);
  const onReadyRef = useRef(onReady);
  const onErrorRef = useRef(onError);
  const onAvatarStopTalkingRef = useRef(onAvatarStopTalking);
  const [status, setStatus] = useState<Status>('idle');
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  useEffect(() => { onReadyRef.current = onReady; }, [onReady]);
  useEffect(() => { onErrorRef.current = onError; }, [onError]);
  useEffect(() => { onAvatarStopTalkingRef.current = onAvatarStopTalking; }, [onAvatarStopTalking]);

  useImperativeHandle(ref, () => ({
    speak: async (text: string) => {
      if (!avatarRef.current) {
        return;
      }
      await avatarRef.current.speak({ text, taskType: TaskType.REPEAT });
    },
    interrupt: async () => {
      if (!avatarRef.current) {
        return;
      }
      await avatarRef.current.interrupt();
    },
    isReady: () => status === 'ready',
  }), [status]);

  useEffect(() => {
    if (!active) {
      return;
    }

    let cancelled = false;
    let instance: StreamingAvatar | null = null;

    const tearDown = async () => {
      try {
        if (instance) {
          await instance.stopAvatar();
        }
      } catch {
        // ignore teardown errors
      }
      avatarRef.current = null;
      if (videoRef.current) {
        videoRef.current.srcObject = null;
      }
    };

    (async () => {
      setStatus('connecting');
      setErrorMessage(null);

      try {
        const tokenResponse = await createHeygenToken();
        if (cancelled) {
          return;
        }

        instance = new StreamingAvatar({ token: tokenResponse.token });
        avatarRef.current = instance;

        instance.on(StreamingEvents.STREAM_READY, (event) => {
          if (videoRef.current && event.detail) {
            videoRef.current.srcObject = event.detail as MediaStream;
            void videoRef.current.play().catch(() => { /* autoplay may be blocked */ });
          }
          setStatus('ready');
          onReadyRef.current?.();
        });

        instance.on(StreamingEvents.STREAM_DISCONNECTED, () => {
          setStatus('idle');
          if (videoRef.current) {
            videoRef.current.srcObject = null;
          }
        });

        instance.on(StreamingEvents.AVATAR_STOP_TALKING, () => {
          onAvatarStopTalkingRef.current?.();
        });

        const quality = qualityMap[tokenResponse.config.quality || 'high'] ?? AvatarQuality.High;
        const avatarName = tokenResponse.config.avatar_name || 'Anna_public_3_20240108';
        const voiceId = tokenResponse.config.voice_id || undefined;

        await instance.createStartAvatar({
          quality,
          avatarName,
          voice: voiceId ? { voiceId } : undefined,
        });
      } catch (err) {
        if (cancelled) {
          return;
        }
        const message = err instanceof Error ? err.message : 'Failed to start avatar stream';
        setErrorMessage(message);
        setStatus('error');
        onErrorRef.current?.(err instanceof Error ? err : new Error(message));
        await tearDown();
      }
    })();

    return () => {
      cancelled = true;
      void tearDown();
    };
  }, [active]);

  return (
    <div className={`avatar-stream ${className ?? ''}`} data-status={status}>
      {fallbackImage && (status !== 'ready') && (
        <img
          className="avatar-stream__fallback"
          src={fallbackImage}
          alt={fallbackAlt ?? 'Avatar'}
        />
      )}
      <video
        ref={videoRef}
        className="avatar-stream__video"
        autoPlay
        playsInline
        muted={false}
        style={{ display: status === 'ready' ? 'block' : 'none' }}
      />
      {status === 'connecting' && (
        <div className="avatar-stream__overlay">
          <span className="avatar-stream__spinner" aria-hidden="true" />
          <span>Connecting avatar…</span>
        </div>
      )}
      {status === 'error' && errorMessage && (
        <div className="avatar-stream__overlay avatar-stream__overlay--error">
          <span>{errorMessage}</span>
        </div>
      )}
    </div>
  );
});
