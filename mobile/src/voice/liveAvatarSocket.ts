/**
 * Thin WebSocket wrapper for LiveAvatar's LITE-mode command socket.
 *
 * What we send:
 *   { type: 'agent.speak',     audio: '<base64 PCM 16-bit 24kHz>' }   — Phase 3
 *   { type: 'agent.interrupt' }                                       — Phase 3
 *   { type: 'agent.start_listening', event_id: '<uuid>' }             — Phase 3
 *   { type: 'agent.stop_listening',  event_id: '<uuid>' }             — Phase 3
 *
 * What we receive (per the LITE-events docs):
 *   { state: 'connected' }            — connection ready, may send commands
 *   { event_id, task: {...} }         — speech started / finished
 *   { type: 'transcript', ... }       — user-speech transcripts (shape TBC)
 *
 * For Phase 2 we don't send anything yet — we just open the socket,
 * log everything that arrives, and prove the connection round-trips.
 * onMessage callbacks fire for every parsed message; the parent
 * component can subscribe to specific events from there.
 */

export type LiveAvatarMessage = Record<string, unknown>;

export type LiveAvatarSocketEvents = {
  onConnected?: () => void;
  onMessage?: (msg: LiveAvatarMessage, raw: string) => void;
  onClose?: (code: number, reason: string) => void;
  onError?: (err: unknown) => void;
};

export class LiveAvatarSocket {
  private ws: WebSocket | null = null;
  private isOpen = false;
  private connectedAcked = false;

  constructor(
    private readonly wsUrl: string,
    private readonly events: LiveAvatarSocketEvents = {},
  ) {}

  open(): void {
    if (this.ws) return;
    try {
      this.ws = new WebSocket(this.wsUrl);
    } catch (err) {
      this.events.onError?.(err);
      return;
    }

    this.ws.onopen = () => {
      this.isOpen = true;
    };

    this.ws.onmessage = (event: { data: string | ArrayBuffer }) => {
      const raw = typeof event.data === 'string' ? event.data : '<binary>';
      let parsed: LiveAvatarMessage = {};
      try {
        parsed = typeof event.data === 'string' ? JSON.parse(event.data) : {};
      } catch {
        // Some events may be plain text or binary frames — pass raw on.
      }
      // Surface the LITE "session up" handshake exactly once so callers
      // can flip a UI state without repeatedly parsing every frame.
      const stateField = (parsed as Record<string, unknown>).state;
      if (!this.connectedAcked && stateField === 'connected') {
        this.connectedAcked = true;
        this.events.onConnected?.();
      }
      this.events.onMessage?.(parsed, raw);
    };

    this.ws.onclose = (event: { code?: number; reason?: string }) => {
      this.isOpen = false;
      this.connectedAcked = false;
      this.events.onClose?.(event.code ?? 0, event.reason ?? '');
    };

    this.ws.onerror = (err: unknown) => {
      this.events.onError?.(err);
    };
  }

  /** True once the underlying socket fired `onopen`. */
  get connected(): boolean {
    return this.isOpen;
  }

  /** True once we've seen LITE's `{state: "connected"}` ack. */
  get ready(): boolean {
    return this.connectedAcked;
  }

  /**
   * Generic command sender. Phase 3 wraps this with typed helpers
   * (speak, interrupt, ...) once we know the protocol from observed
   * traffic.
   */
  send(payload: LiveAvatarMessage): boolean {
    if (!this.ws || !this.isOpen) return false;
    try {
      this.ws.send(JSON.stringify(payload));
      return true;
    } catch (err) {
      this.events.onError?.(err);
      return false;
    }
  }

  /**
   * Tell the server we're an active LITE client and want it to start
   * routing user-audio transcripts back to us. Phase 2 sends this
   * immediately on connect to keep the session from auto-closing
   * after ~280ms with a video-starvation warning. event_id lets the
   * server correlate any later replies with this listen window.
   */
  startListening(): boolean {
    return this.send({
      type: 'agent.start_listening',
      event_id: randomEventId(),
    });
  }

  stopListening(): boolean {
    return this.send({
      type: 'agent.stop_listening',
      event_id: randomEventId(),
    });
  }

  close(): void {
    try {
      this.ws?.close();
    } catch {
      // already-closed sockets sometimes throw on .close() — fine.
    }
    this.ws = null;
    this.isOpen = false;
    this.connectedAcked = false;
  }
}

/**
 * RFC4122-flavoured random id. Hermes / RN's `crypto.randomUUID` is
 * available on newer runtimes but not guaranteed; falls back to a
 * Math.random-based 32-hex string which is good enough for client-
 * generated event correlation (not security).
 */
function randomEventId(): string {
  const g = globalThis as unknown as { crypto?: { randomUUID?: () => string } };
  if (g.crypto?.randomUUID) {
    try {
      return g.crypto.randomUUID();
    } catch {
      // fall through
    }
  }
  let s = '';
  for (let i = 0; i < 32; i++) {
    s += Math.floor(Math.random() * 16).toString(16);
  }
  return s.slice(0, 8) + '-' + s.slice(8, 12) + '-' + s.slice(12, 16) + '-' + s.slice(16, 20) + '-' + s.slice(20, 32);
}
