<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationAttachment;
use App\Models\Message;
use App\Services\Generation\GenerationService;
use App\Services\OpenAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(
        private readonly GenerationService $generationService,
    ) {}
    /** Get or create the latest conversation for an agent. */
    public function latest(Agent $agent): JsonResponse
    {
        $conv = $agent->conversations()->latest()->first();

        if (!$conv) {
            $conv = $agent->conversations()->create(['title' => 'New Chat']);
        }

        return response()->json($conv);
    }

    /** List all conversations for an agent. */
    public function index(Agent $agent): JsonResponse
    {
        $conversations = $agent->conversations()
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json($conversations);
    }

    /** Create a new conversation for an agent. */
    public function store(Request $request, Agent $agent): JsonResponse
    {
        $conv = $agent->conversations()->create([
            'title' => $request->input('title', 'New Chat'),
        ]);

        return response()->json($conv, 201);
    }

    /** List all conversations for the authenticated user (across agents). */
    public function indexForUser(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        // Only conversations the user has actually started appear in history.
        // Empty drafts (created when an avatar is tapped but no message sent
        // yet) stay out of the list — see ChatDetailScreen deferred-send flow.
        $paginator = Conversation::query()
            ->where('user_id', $request->user()->id)
            ->with(['agent.vertical:id,slug'])
            ->withCount('messages')
            ->whereHas('messages')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('updated_at')
            ->paginate($perPage);

        $data = collect($paginator->items())->map(fn (Conversation $c) => $this->presentConversation($c));

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /** Create a conversation scoped to the authenticated user. */
    public function storeForUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent_id' => 'required|integer|exists:agents,id',
            'title'    => 'nullable|string|max:255',
        ]);

        $agent = Agent::findOrFail($validated['agent_id']);

        $conv = Conversation::create([
            'agent_id'    => $agent->id,
            'vertical_id' => $agent->vertical_id,
            'user_id'     => $request->user()->id,
            'title'       => $validated['title'] ?? 'New Chat',
        ]);

        $conv->load(['agent.vertical:id,slug']);

        return response()->json($this->presentConversation($conv), 201);
    }

    /** Show a single conversation owned by the authenticated user. */
    public function showForUser(Request $request, Conversation $conversation): JsonResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            abort(404);
        }

        $conversation->load(['agent.vertical:id,slug']);
        $conversation->loadCount('messages');

        return response()->json($this->presentConversation($conversation));
    }

    /** Shape a Conversation for mobile clients (flat agent with vertical_slug). */
    private function presentConversation(Conversation $c): array
    {
        $agent = $c->agent;
        $agentPayload = $agent ? [
            'id'               => $agent->id,
            'slug'             => $agent->slug,
            'name'             => $agent->name,
            'role'             => $agent->role,
            'domain'           => $agent->domain ?? null,
            'description'      => $agent->description,
            'vertical_slug'    => $agent->vertical?->slug,
            'avatar_image_url' => $agent->avatar_image_url,
        ] : null;

        return [
            'id'               => $c->id,
            'agent_id'         => $c->agent_id,
            'title'            => $c->title,
            'created_at'       => optional($c->created_at)->toIso8601String(),
            'updated_at'       => optional($c->updated_at)->toIso8601String(),
            'last_activity_at' => optional($c->last_activity_at)->toIso8601String(),
            'messages_count'   => $c->messages_count ?? 0,
            'agent'            => $agentPayload,
        ];
    }

    /**
     * If the caller presents a Sanctum user AND the conversation has an
     * owner, enforce ownership. Unauthenticated callers (the hotel SPA
     * session flow) stay unaffected — the conversation's user_id is
     * null for them.
     */
    private function ensureOwnership(Request $request, Conversation $conversation): void
    {
        $user = $request->user('sanctum');
        if ($user && $conversation->user_id !== null && $conversation->user_id !== $user->id) {
            abort(403);
        }
    }

    /** Rename a conversation. */
    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureOwnership($request, $conversation);
        $conversation->update($request->only('title'));
        return response()->json($conversation);
    }

    /** Delete a conversation. */
    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureOwnership($request, $conversation);
        $conversation->delete();
        return response()->json(['message' => 'Deleted']);
    }

    /** Get message history for a conversation. */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureOwnership($request, $conversation);

        $messages = $conversation->messages()
            ->with('attachments')
            ->orderBy('created_at')
            ->get();

        return response()->json($messages);
    }

    /** Create a user message, optionally trigger auto-reply. */
    public function createMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureOwnership($request, $conversation);

        $validated = $request->validate([
            'content'          => 'nullable|string',
            'auto_reply'       => 'boolean',
            'attachment_ids'   => 'array',
            'attachment_ids.*' => 'integer',
        ]);

        // Quota gates. Two layers, both opt-in via the plan record:
        //   1. Monthly token budget — the user-facing quota. Counts
        //      generation tokens over the rolling 30-day window.
        //   2. Daily message ceiling — anti-abuse. Hard cap regardless
        //      of token budget; a runaway client can't burn 1M tokens
        //      in a single day even if the plan technically allows it.
        // Either NULL on the plan = skip that layer.
        $user = $request->user('sanctum');
        $conversation->loadMissing('agent.vertical');
        $vertical = $conversation->agent?->vertical?->slug;
        if ($user && $vertical === 'wellness') {
            $plan = $user->activePlan();

            $tokenBudget = $plan?->monthly_token_limit;
            if ($tokenBudget !== null) {
                $tokensUsed = $user->tokensUsedThisPeriod();
                if ($tokensUsed >= $tokenBudget) {
                    return response()->json([
                        'message'             => "You've used your monthly token allowance. Upgrade for more.",
                        'error'               => 'token_limit_reached',
                        'plan'                => $plan->slug,
                        'monthly_token_limit' => $tokenBudget,
                        'tokens_used_period'  => $tokensUsed,
                        'upgrade_required'    => true,
                    ], 402);
                }
            }

            $dailyLimit = $plan?->daily_message_limit;
            if ($dailyLimit !== null) {
                $messagesUsed = $user->messagesUsedToday();
                if ($messagesUsed >= $dailyLimit) {
                    return response()->json([
                        'message'          => "You've hit today's message limit. Try again tomorrow or upgrade.",
                        'error'            => 'daily_limit_reached',
                        'plan'             => $plan->slug,
                        'daily_limit'      => $dailyLimit,
                        'used_today'       => $messagesUsed,
                        'upgrade_required' => true,
                    ], 402);
                }
            }
        }

        $content = trim((string) ($validated['content'] ?? ''));
        $hasAttachments = !empty($validated['attachment_ids'] ?? []);
        if ($content === '' && !$hasAttachments) {
            abort(422, 'Either content or attachment_ids is required.');
        }

        // Save user message
        $userMsg = $conversation->messages()->create([
            'role'    => 'user',
            'content' => $content,
        ]);

        // Link any previously-uploaded attachments to this message. Only
        // attachments in the same conversation and not already linked to
        // another message are allowed — prevents a forged id from stealing
        // another user's upload.
        $attachmentIds = $validated['attachment_ids'] ?? [];
        if (!empty($attachmentIds)) {
            ConversationAttachment::where('conversation_id', $conversation->id)
                ->whereIn('id', $attachmentIds)
                ->whereNull('message_id')
                ->update(['message_id' => $userMsg->id]);
        }

        $userMsg->load('attachments');

        // Auto-title from the first user message if the conversation still
        // has the default placeholder. Keeps "History" readable. If the
        // user sent only an attachment, fall back to a short descriptor.
        $updates = [];
        if ($conversation->title === null || $conversation->title === 'New Chat') {
            $trimmed = trim((string) preg_replace('/\s+/', ' ', $content));
            if ($trimmed !== '') {
                $updates['title'] = mb_substr($trimmed, 0, 60);
            } elseif ($hasAttachments) {
                $updates['title'] = 'Attachment';
            }
        }
        $updates['last_activity_at'] = now();

        if (!empty($updates)) {
            $conversation->fill($updates);
        }
        $conversation->save();

        $result = ['user_message' => $userMsg, 'agent_message' => null];

        // Auto-reply if requested
        if ($request->boolean('auto_reply', true)) {
            $result['agent_message'] = $this->generationService->generateResponse($conversation);
        }

        return response()->json($result, 201);
    }

    /** Manually trigger an agent reply. */
    public function agentReply(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureOwnership($request, $conversation);

        $agentMsg = $this->generationService->generateResponse($conversation);
        return response()->json($agentMsg, 201);
    }

    /** List attachments for a conversation. */
    public function listAttachments(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureOwnership($request, $conversation);
        return response()->json($conversation->attachments);
    }

    /** Upload an attachment to a conversation. */
    public function uploadAttachment(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureOwnership($request, $conversation);
        $request->validate(['file' => 'required|file|max:10240']);

        $file = $request->file('file');
        $path = $file->store("uploads/conversations/{$conversation->id}", 'local');

        $attachment = $conversation->attachments()->create([
            'file_path'  => $path,
            'file_name'  => $file->getClientOriginalName(),
            'mime_type'  => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        return response()->json($attachment, 201);
    }

    /** Transcribe audio to text. */
    public function transcribe(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureOwnership($request, $conversation);
        $request->validate([
            'file'     => 'required|file|max:6144',
            // Permissive on shape — `resolveLanguageHint` does the
            // strict 2-letter ISO-639-1 normalisation. Tightening the
            // request validation here just forces 422s on edge cases
            // (locale-tagged "en-US", short prefixes from i18n init,
            // empty string before locale resolves) when we can simply
            // ignore those inputs and fall through to the next link
            // in the fallback chain.
            'language' => 'nullable|string|max:16',
        ]);

        $file    = $request->file('file');
        $tmpPath = $file->getRealPath();

        $extMap = [
            'audio/wav'    => 'wav',
            'audio/wave'   => 'wav',
            'audio/x-wav'  => 'wav',
            'audio/mpeg'   => 'mp3',
            'audio/mp3'    => 'mp3',
            'audio/mp4'    => 'm4a',
            'audio/x-m4a'  => 'm4a',
            'audio/webm'   => 'webm',
            'audio/ogg'    => 'ogg',
            'audio/flac'   => 'flac',
        ];

        $mime = strtolower((string) $file->getMimeType());
        $ext  = $extMap[$mime]
            ?? (strtolower((string) $file->getClientOriginalExtension()) ?: 'wav');

        $openai   = app(OpenAiService::class);
        $filename = 'voice-input.' . $ext;

        // Resolve the language hint through a fallback chain so Whisper
        // never has to auto-detect when we already know what the user
        // is speaking. Auto-detect was the source of voice-mode
        // language drift — bilingual users would get one turn in
        // English, the next in Russian, and the avatar's reply would
        // try to honour both inconsistently.
        //
        //   1. Form field — sent by the mobile client with the active
        //      i18n locale (covers anonymous users + profile drift).
        //   2. Authenticated user's profile.preferred_language — what
        //      the user picked in onboarding/settings.
        //   3. Accept-Language header — last-ditch hint from the HTTP
        //      stack for old clients that haven't been upgraded.
        //   4. null — let Whisper auto-detect (legacy behaviour).
        $language = $this->resolveLanguageHint($request);

        // Domain context dramatically improves accuracy on terms
        // Whisper otherwise garbles ("Nora" → "Nara", "ferritin" →
        // "ferret in", PMID → "P MID"). The avatar name + a short
        // wellness scope statement is enough; we don't need to dump
        // the full system prompt in here.
        $promptHint = $this->buildTranscribePrompt($conversation);

        $text = $openai->transcribe($tmpPath, null, $filename, $language, $promptHint);

        return response()->json(['text' => $text, 'language' => $language]);
    }

    /**
     * Walk the fallback chain to find the best language hint for
     * Whisper. Returns a 2-letter ISO 639-1 code or null.
     *
     * Each link does its own normalisation so a slightly-malformed
     * input ("en-US", "EN", "  ru  ") doesn't disqualify a link — we
     * trim, lowercase, and take the leading 2 letters before checking
     * format.
     */
    private function resolveLanguageHint(Request $request): ?string
    {
        $normalize = static function (mixed $raw): ?string {
            if (!is_string($raw)) return null;
            $head = strtolower(substr(trim($raw), 0, 2));
            return preg_match('/^[a-z]{2}$/', $head) ? $head : null;
        };

        // 1. Form field — what the mobile client sent on this request.
        if ($code = $normalize($request->input('language'))) return $code;

        // 2. Authenticated user's saved preference.
        if ($code = $normalize($request->user()?->profile?->preferred_language)) return $code;

        // 3. Accept-Language header — take the first comma-segment.
        $header = (string) $request->header('Accept-Language', '');
        if ($header !== '') {
            $first = trim(explode(',', $header)[0]);
            if ($code = $normalize($first)) return $code;
        }

        return null;
    }

    /**
     * Short prompt that biases Whisper toward our wellness vocabulary
     * + the active avatar's name. Per OpenAI's transcription guide,
     * this is most useful for proper nouns, acronyms, and domain
     * jargon — exactly where we were seeing transcription drift.
     */
    private function buildTranscribePrompt(Conversation $conversation): string
    {
        $agentName = $conversation->agent?->name ?? 'wellness coach';
        $vertical  = $conversation->agent?->vertical?->slug ?? 'wellness';

        if ($vertical === 'wellness') {
            return "Wellness conversation with {$agentName}. "
                . 'Topics may include nutrition, sleep, fitness, mindfulness, skin care, '
                . 'functional medicine, vitamins (B12, vitamin D), labs (CBC, ferritin, TSH, HbA1c), '
                . 'and supplements. Avatar names: Nora, Luna, Zen, Axel, Aura, Dr. Integra. '
                . 'Citations like PMID:12345678 may be spoken.';
        }

        return "Conversation with {$agentName}.";
    }

    /** Text-to-speech. */
    public function speak(Request $request, Conversation $conversation): mixed
    {
        $this->ensureOwnership($request, $conversation);
        $request->validate(['text' => 'required|string|max:4096']);

        $agent = $conversation->agent;
        $voice = $agent->openai_voice ?? 'alloy';

        $openai = app(OpenAiService::class);
        $audio  = $openai->speak($request->input('text'), $voice);

        return response($audio, 200, [
            'Content-Type'        => 'audio/mpeg',
            'Content-Disposition' => 'inline; filename="speech.mp3"',
        ]);
    }

    /**
     * Text-to-speech in PCM 16-bit LE / 24kHz / mono, chunked + base64-
     * encoded for direct delivery over LiveAvatar's LITE WebSocket
     * (each chunk feeds into one `agent.speak` payload).
     *
     * Chunk size: 48000 raw bytes ≈ 1 second of audio per chunk —
     * matches LiveAvatar's "recommended ~1 second" guidance and keeps
     * each WebSocket frame comfortably under the default 64 kB limit
     * after base64 inflation (~64 kB per frame).
     */
    public function speakPcm(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureOwnership($request, $conversation);
        $request->validate(['text' => 'required|string|max:4096']);

        $agent = $conversation->agent;
        $voice = $agent->openai_voice ?? 'alloy';

        $openai = app(OpenAiService::class);
        $rawPcm = $openai->speakPcm($request->input('text'), $voice);

        $chunkSize = 48_000; // ≈ 1s of 24 kHz 16-bit mono
        $chunks = [];
        for ($offset = 0; $offset < strlen($rawPcm); $offset += $chunkSize) {
            $chunks[] = base64_encode(substr($rawPcm, $offset, $chunkSize));
        }

        return response()->json([
            'sample_rate' => 24000,
            'format'      => 'pcm_s16le_mono',
            'chunk_count' => count($chunks),
            'chunks'      => $chunks,
        ]);
    }

}
