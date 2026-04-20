<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationAttachment;
use App\Models\Message;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmRequest;
use App\Services\OpenAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
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
            $result['agent_message'] = $this->generateReply($conversation);
        }

        return response()->json($result, 201);
    }

    /** Manually trigger an agent reply. */
    public function agentReply(Conversation $conversation): JsonResponse
    {
        $agentMsg = $this->generateReply($conversation);
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

        $openai = app(OpenAiService::class);
        $text   = $openai->transcribe($tmpPath);

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

    /**
     * Build context and generate an AI reply.
     */
    private function generateReply(Conversation $conversation): ?Message
    {
        $agent = $conversation->agent;

        if (empty(config('services.openai.api_key'))) {
            return $conversation->messages()->create([
                'role'    => 'agent',
                'content' => "I'm currently offline — the AI service is not configured.",
            ]);
        }

        $maxContext = (int) config('services.openai.max_context_messages', 20);

        // Build system prompt
        $systemPrompt = $agent->system_instructions ?? "You are {$agent->name}, {$agent->role}. {$agent->description}";

        if ($agent->knowledge_text) {
            $maxChars = (int) config('services.openai.max_knowledge_chars', 12000);
            $knowledge = mb_substr($agent->knowledge_text, 0, $maxChars);
            $systemPrompt .= "\n\n--- Knowledge Base ---\n{$knowledge}";
        }

        // Build message history
        $history = $conversation->messages()
            ->orderByDesc('created_at')
            ->limit($maxContext)
            ->get()
            ->reverse()
            ->map(fn ($m) => [
                'role'    => $m->role === 'agent' ? 'assistant' : 'user',
                'content' => $m->content,
            ])
            ->values()
            ->toArray();

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history
        );

        // Call OpenAI
        $tools = [];
        if ($agent->use_advanced_ai && $agent->openai_vector_store_id) {
            $tools = [[
                'type'         => 'file_search',
                'file_search'  => ['vector_store_ids' => [$agent->openai_vector_store_id]],
            ]];
        }

        $client = app(LlmClient::class);
        $response = $client->chat(new LlmRequest(
            messages: $messages,
            model: $agent->openai_model ?? (string) config('services.openai.model', 'gpt-4o'),
            temperature: (float) config('services.openai.temperature', 0.3),
            maxTokens: (int) config('services.openai.max_output_tokens', 220),
            tools: $tools ?? [],
            purpose: 'generation',
            messageId: null,
        ));

        $result = [
            'content'           => $response->content,
            'role'              => $response->role,
            'ai_provider'       => $response->provider,
            'ai_model'          => $response->model,
            'prompt_tokens'     => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'total_tokens'      => $response->totalTokens,
            'ai_latency_ms'     => $response->latencyMs,
            'trace_id'          => $response->traceId,
        ];

        return $conversation->messages()->create([
            'role'                   => 'agent',
            'content'                => $result['content'],
            'ai_provider'            => $result['ai_provider'],
            'ai_model'               => $result['ai_model'],
            'prompt_tokens'          => $result['prompt_tokens'],
            'completion_tokens'      => $result['completion_tokens'],
            'total_tokens'           => $result['total_tokens'],
            'ai_latency_ms'          => $result['ai_latency_ms'],
            'retrieval_used'         => !empty($tools),
            'retrieval_source_count' => 0,
        ]);
    }
}
