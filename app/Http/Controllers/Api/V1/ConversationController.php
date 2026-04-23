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
            ->orderBy('created_at')
            ->get();

        return response()->json($messages);
    }

    /** Create a user message, optionally trigger auto-reply. */
    public function createMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureOwnership($request, $conversation);

        $validated = $request->validate([
            'content'    => 'required|string',
            'auto_reply' => 'boolean',
        ]);

        // Save user message
        $userMsg = $conversation->messages()->create([
            'role'    => 'user',
            'content' => $validated['content'],
        ]);

        // Auto-title from the first user message if the conversation still
        // has the default placeholder. Keeps "History" readable.
        $updates = [];
        if ($conversation->title === null || $conversation->title === 'New Chat') {
            $trimmed = trim(preg_replace('/\s+/', ' ', $validated['content']) ?? '');
            if ($trimmed !== '') {
                $updates['title'] = mb_substr($trimmed, 0, 60);
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
        $request->validate(['file' => 'required|file|max:6144']);

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
        $text     = $openai->transcribe($tmpPath, null, $filename);

        return response()->json(['text' => $text]);
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

}
