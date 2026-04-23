<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentKnowledgeFile;
use App\Models\Message;
use App\Models\Vertical;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    /** Get UI assets catalog (avatar images, backgrounds). */
    public function assets(): JsonResponse
    {
        $avatars = [];
        $backgrounds = [];

        // Scan public asset directories from the old app location or storage
        $avatarDir = public_path('assets/avatars');
        if (is_dir($avatarDir)) {
            foreach (glob("{$avatarDir}/*.{png,jpg,jpeg,webp,svg}", GLOB_BRACE) as $f) {
                $avatars[] = '/assets/avatars/' . basename($f);
            }
        }

        $bgDir = public_path('assets/backgrounds');
        if (is_dir($bgDir)) {
            foreach (glob("{$bgDir}/*.{png,jpg,jpeg,webp,svg}", GLOB_BRACE) as $f) {
                $backgrounds[] = '/assets/backgrounds/' . basename($f);
            }
        }

        $voices = [
            'alloy', 'ash', 'ballad', 'coral', 'echo', 'fable',
            'nova', 'onyx', 'sage', 'shimmer', 'verse',
        ];

        return response()->json([
            'avatars'     => $avatars,
            'backgrounds' => $backgrounds,
            'voices'      => $voices,
            'models'      => ['gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'gpt-4.1-mini'],
        ]);
    }

    /** List all verticals (for the vertical picker in admin). */
    public function verticals(): JsonResponse
    {
        $verticals = Vertical::orderBy('name')
            ->get(['id', 'slug', 'name', 'is_active']);

        return response()->json($verticals);
    }

    /** List all agents (admin view with full config). */
    public function index(): JsonResponse
    {
        $agents = Agent::withCount(['conversations', 'knowledgeFiles'])
            ->orderBy('name')
            ->get();

        return response()->json($agents);
    }

    /** Get single agent with full config. */
    public function show(Agent $agent): JsonResponse
    {
        $agent->loadCount(['conversations', 'knowledgeFiles']);
        return response()->json($agent);
    }

    /** Create a new agent. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->agentRules());

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $agent = Agent::create($validated);
        return response()->json($agent, 201);
    }

    /** Update an agent. */
    public function update(Request $request, Agent $agent): JsonResponse
    {
        $validated = $request->validate($this->agentRules($agent->id));
        $agent->update($validated);
        return response()->json($agent);
    }

    /** Shared validation rules for create/update. */
    private function agentRules(?int $agentId = null): array
    {
        $slugRule = $agentId === null
            ? 'nullable|string|max:64|unique:agents'
            : 'sometimes|string|max:64|unique:agents,slug,' . $agentId;

        return [
            'name'                   => ($agentId === null ? 'required' : 'sometimes') . '|string|max:100',
            'slug'                   => $slugRule,
            'role'                   => 'nullable|string|max:100',
            'description'            => 'nullable|string',
            'domain'                 => 'nullable|string|max:64',
            'vertical_id'            => 'nullable|integer|exists:verticals,id',
            'avatar_image_url'       => 'nullable|string|max:255',
            'chat_background_url'    => 'nullable|string|max:255',
            'system_instructions'    => 'nullable|string',
            'knowledge_text'         => 'nullable|string',
            'knowledge_sources_json' => 'nullable|array',
            'persona_json'           => 'nullable|array',
            'scope_json'             => 'nullable|array',
            'red_flag_rules_json'    => 'nullable|array',
            'handoff_rules_json'     => 'nullable|array',
            'openai_model'           => 'nullable|string|max:120',
            'openai_voice'           => 'nullable|string|max:64',
            'use_advanced_ai'        => 'boolean',
            'is_published'           => 'boolean',
        ];
    }

    /** Delete an agent. */
    public function destroy(Agent $agent): JsonResponse
    {
        $agent->delete();
        return response()->json(['message' => 'Agent deleted']);
    }

    /** Upload knowledge files for an agent. */
    public function uploadKnowledgeFiles(Request $request): JsonResponse
    {
        $request->validate([
            'agent_id' => 'required|exists:agents,id',
            'files'    => 'required|array',
            'files.*'  => 'file|max:10240',
        ]);

        $agent = Agent::findOrFail($request->input('agent_id'));
        $results = [];

        foreach ($request->file('files') as $file) {
            $path = $file->store("uploads/knowledge/{$agent->id}", 'local');

            $kf = $agent->knowledgeFiles()->create([
                'local_path'  => $path,
                'file_hash'   => hash_file('sha256', $file->getRealPath()),
                'mime_type'   => $file->getMimeType(),
                'size_bytes'  => $file->getSize(),
                'sync_status' => 'pending',
            ]);

            $results[] = $kf;
        }

        return response()->json($results, 201);
    }

    /** Check knowledge sync status. */
    public function knowledgeStatus(Agent $agent): JsonResponse
    {
        return response()->json([
            'sync_status'    => $agent->knowledge_sync_status,
            'synced_at'      => $agent->knowledge_synced_at,
            'last_error'     => $agent->knowledge_last_error,
            'files'          => $agent->knowledgeFiles()->get(),
        ]);
    }

    /** Trigger vector store reindex. */
    public function reindex(Agent $agent): JsonResponse
    {
        // Mark agent as pending sync
        $agent->update(['knowledge_sync_status' => 'pending']);

        // In a production app this would be a queued job.
        // For now, mark pending and return.
        return response()->json([
            'message' => 'Reindex queued',
            'status'  => 'pending',
        ]);
    }

    /** AI usage metrics (last 30 days). */
    public function usage(): JsonResponse
    {
        $since = now()->subDays(30);

        $byDay = Message::where('role', 'agent')
            ->where('created_at', '>=', $since)
            ->whereNotNull('ai_model')
            ->selectRaw("DATE(created_at) as date, count(*) as messages, sum(coalesce(total_tokens,0)) as tokens, avg(coalesce(ai_latency_ms,0))::int as avg_latency_ms")
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $byModel = Message::where('role', 'agent')
            ->where('created_at', '>=', $since)
            ->whereNotNull('ai_model')
            ->selectRaw("ai_model as model, count(*) as messages, sum(coalesce(total_tokens,0)) as tokens")
            ->groupBy('ai_model')
            ->get();

        $byAgent = Message::where('messages.role', 'agent')
            ->where('messages.created_at', '>=', $since)
            ->whereNotNull('messages.ai_model')
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->join('agents', 'conversations.agent_id', '=', 'agents.id')
            ->selectRaw("agents.name as agent, count(messages.id) as messages, sum(coalesce(messages.total_tokens,0)) as tokens")
            ->groupBy('agents.name')
            ->get();

        $totals = Message::where('role', 'agent')
            ->where('created_at', '>=', $since)
            ->whereNotNull('ai_model')
            ->selectRaw("count(*) as messages, sum(coalesce(total_tokens,0)) as tokens, sum(coalesce(prompt_tokens,0)) as prompt_tokens, sum(coalesce(completion_tokens,0)) as completion_tokens")
            ->first();

        return response()->json([
            'period'   => '30d',
            'totals'   => $totals,
            'by_day'   => $byDay,
            'by_model' => $byModel,
            'by_agent' => $byAgent,
        ]);
    }
}
