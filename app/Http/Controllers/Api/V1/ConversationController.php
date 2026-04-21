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

    /** Rename a conversation. */
    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $conversation->update($request->only('title'));
        return response()->json($conversation);
    }

    /** Delete a conversation. */
    public function destroy(Conversation $conversation): JsonResponse
    {
        $conversation->delete();
        return response()->json(['message' => 'Deleted']);
    }

    /** Get message history for a conversation. */
    public function messages(Conversation $conversation): JsonResponse
    {
        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->get();

        return response()->json($messages);
    }

    /** Create a user message, optionally trigger auto-reply. */
    public function createMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'content'    => 'required|string',
            'auto_reply' => 'boolean',
        ]);

        // Save user message
        $userMsg = $conversation->messages()->create([
            'role'    => 'user',
            'content' => $validated['content'],
        ]);
        $conversation->touch();

        $result = ['user_message' => $userMsg, 'agent_message' => null];

        // Auto-reply if requested
        if ($request->boolean('auto_reply', true)) {
            $result['agent_message'] = $this->generationService->generateResponse($conversation);
        }

        return response()->json($result, 201);
    }

    /** Manually trigger an agent reply. */
    public function agentReply(Conversation $conversation): JsonResponse
    {
        $agentMsg = $this->generationService->generateResponse($conversation);
        return response()->json($agentMsg, 201);
    }

    /** List attachments for a conversation. */
    public function listAttachments(Conversation $conversation): JsonResponse
    {
        return response()->json($conversation->attachments);
    }

    /** Upload an attachment to a conversation. */
    public function uploadAttachment(Request $request, Conversation $conversation): JsonResponse
    {
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
