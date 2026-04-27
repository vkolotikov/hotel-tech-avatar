<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentKnowledgeFile;
use App\Models\AgentPromptVersion;
use App\Models\Message;
use App\Models\SubscriptionEntitlement;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Vertical;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    /** Get UI assets catalog (avatar images, backgrounds, intro videos). */
    public function assets(): JsonResponse
    {
        $avatars = [];
        $backgrounds = [];
        $videos = [];

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

        $videoDir = public_path('assets/avatars/videos');
        if (is_dir($videoDir)) {
            foreach (glob("{$videoDir}/*.{mp4,mov,webm,m4v}", GLOB_BRACE) as $f) {
                $videos[] = '/assets/avatars/videos/' . basename($f);
            }
            sort($videos);
        }

        // Current OpenAI TTS voice catalog. The full 13 voices are supported
        // by gpt-4o-mini-tts; tts-1 / tts-1-hd support only 9 (marin, cedar,
        // ballad, verse are unavailable on the legacy models).
        $voices = [
            'alloy', 'ash', 'ballad', 'coral', 'echo', 'fable',
            'nova', 'onyx', 'sage', 'shimmer', 'verse', 'marin', 'cedar',
        ];

        // Text generation. gpt-5.5 is the current flagship; gpt-5.4 family
        // remains as fallbacks; the mini/nano variants are lower-cost for
        // budget-sensitive avatars.
        $models = [
            'gpt-5.5',
            'gpt-5.4',
            'gpt-5.4-mini',
            'gpt-5.4-nano',
            'gpt-4o',
            'gpt-4o-mini',
        ];

        // Text-to-speech models (used by /voice/speak).
        $ttsModels = ['gpt-4o-mini-tts', 'tts-1-hd', 'tts-1'];

        // Speech-to-text models (used by /voice/transcribe).
        $sttModels = ['gpt-4o-transcribe', 'gpt-4o-mini-transcribe', 'whisper-1'];

        return response()->json([
            'avatars'               => $avatars,
            'backgrounds'           => $backgrounds,
            'videos'                => $videos,
            'voices'                => $voices,
            'models'                => $models,
            'tts_models'            => $ttsModels,
            'stt_models'            => $sttModels,
            // Defaults pulled from config so the admin picker can pre-select
            // sensible values on new avatars.
            'openai_default_model'  => (string) config('services.openai.model', 'gpt-4o'),
            'openai_default_voice'  => 'alloy',
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
            ->orderForDisplay()
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
            'intro_video_url'        => 'nullable|string|max:255',
            'system_instructions'    => 'nullable|string',
            'knowledge_text'         => 'nullable|string',
            'knowledge_sources_json' => 'nullable|array',
            'persona_json'           => 'nullable|array',
            'scope_json'             => 'nullable|array',
            'red_flag_rules_json'    => 'nullable|array',
            'handoff_rules_json'     => 'nullable|array',
            'prompt_suggestions_json'=> 'nullable|array',
            'prompt_suggestions_json.*' => 'string|max:200',
            'openai_model'           => 'nullable|string|max:120',
            'openai_voice'           => 'nullable|string|max:64',
            'use_advanced_ai'        => 'boolean',
            'is_published'           => 'boolean',
            'display_order'          => 'nullable|integer|min:0',
        ];
    }

    /** Delete an agent. */
    public function destroy(Agent $agent): JsonResponse
    {
        $agent->delete();
        return response()->json(['message' => 'Agent deleted']);
    }

    /**
     * Persist a new ordering for the avatar list. Body: `{ order: [id, id, ...] }`.
     * Each agent gets `display_order` = its zero-based index * 10. The 10-step
     * gap matches the migration's backfill so future single-row inserts can
     * land between two existing rows without renumbering.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order'   => 'required|array|min:1',
            'order.*' => 'integer|distinct|exists:agents,id',
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['order'] as $index => $agentId) {
                DB::table('agents')
                    ->where('id', $agentId)
                    ->update(['display_order' => $index * 10]);
            }
        });

        return response()->json(['ok' => true, 'count' => count($validated['order'])]);
    }

    /**
     * Generate (or return cached) TTS preview audio for one of the OpenAI
     * voices. Used by the admin form to let an editor sample a voice before
     * assigning it to an avatar without burning OpenAI credits on every
     * click — repeated previews of the same {voice, text, model} hit a
     * disk cache under storage/app/voice-previews/.
     */
    public function voicePreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'voice' => 'required|string|max:32',
            'text'  => 'nullable|string|max:240',
            'model' => 'nullable|string|max:64',
        ]);

        $voice = $validated['voice'];
        $text  = $validated['text']  ?? "Hi, I'm here to help. Ask me anything you'd like to learn about.";
        $model = $validated['model'] ?? (string) config('services.openai.tts_model', 'gpt-4o-mini-tts');

        $hash = sha1($voice . '|' . $text . '|' . $model);
        $relPath = "voice-previews/{$hash}.mp3";

        if (!Storage::disk('local')->exists($relPath)) {
            $tts = app(\App\Services\OpenAiService::class);
            $audio = $tts->speak($text, $voice, $model);
            Storage::disk('local')->put($relPath, $audio);
        }

        $bytes = Storage::disk('local')->get($relPath);

        // Inline data URL keeps the admin a single static page — no need to
        // expose a streaming media route, and the response is small enough
        // (≈30-60 KB for a 6-second clip) for JSON transport.
        return response()->json([
            'voice'    => $voice,
            'model'    => $model,
            'mime'     => 'audio/mpeg',
            'data_url' => 'data:audio/mpeg;base64,' . base64_encode($bytes),
            'cached'   => Storage::disk('local')->exists($relPath),
        ]);
    }

    /**
     * Run a test message against the agent's red-flag / scope / handoff rules
     * and return which rule (if any) would fire first + its canned response.
     *
     * This is a dry-run classifier only — it does not generate a model
     * response or touch any conversation. Uses the currently-saved rules on
     * the agent row, not any unsaved form state.
     */
    public function safetyPreview(Request $request, Agent $agent): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $message = (string) $validated['message'];
        $normalised = mb_strtolower($message);

        $matched = $this->firstMatchingRule('red_flag', $agent->red_flag_rules_json ?? [], $normalised, $message);
        if (!$matched) {
            $matched = $this->firstMatchingRule('handoff', $agent->handoff_rules_json ?? [], $normalised, $message);
        }
        if (!$matched) {
            $matched = $this->firstMatchingRule('scope', $agent->scope_json ?? [], $normalised, $message);
        }

        return response()->json([
            'matched'  => $matched !== null,
            'category' => $matched['category'] ?? null,
            'rule'     => $matched['rule'] ?? null,
            'response' => $matched['response'] ?? null,
            'note'     => $matched
                ? 'A canned safety response would be sent; the model is NOT called.'
                : 'No rule matched. The message would be forwarded to the model.',
        ]);
    }

    /**
     * Walk the given rule list in order and return the first matching rule
     * along with a normalised category + response. Supports both the simple
     * admin shape ({keywords|topic|trigger, response|referral}) and legacy
     * richer shapes ({pattern_regex, canned_response_key, ...}).
     *
     * @param  array<int,array<string,mixed>>|array<string,mixed>  $rules
     */
    private function firstMatchingRule(string $category, $rules, string $normalised, string $raw): ?array
    {
        if (!is_array($rules)) {
            return null;
        }

        // Handle the legacy handoff_rules_json shape where it was a flat
        // { target: "tag1,tag2" } map. Not directly pattern-matchable here.
        $isAssoc = array_keys($rules) !== range(0, count($rules) - 1);
        if ($isAssoc) {
            return null;
        }

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $hit = null;

            // Regex-based rule (legacy NoraAvatarSeeder shape).
            if (!empty($rule['pattern_regex']) && is_string($rule['pattern_regex'])) {
                $pattern = $rule['pattern_regex'];
                // Only attempt if it looks like a PCRE pattern with delimiters;
                // otherwise fall through to keyword matching.
                if (@preg_match($pattern, '') !== false && @preg_match($pattern, $raw)) {
                    $hit = 'regex';
                }
            }

            // Keyword list (simple admin shape).
            if (!$hit && !empty($rule['keywords']) && is_array($rule['keywords'])) {
                foreach ($rule['keywords'] as $kw) {
                    $kw = is_string($kw) ? trim(mb_strtolower($kw)) : '';
                    if ($kw !== '' && str_contains($normalised, $kw)) {
                        $hit = 'keyword:' . $kw;
                        break;
                    }
                }
            }

            // Scope/handoff shape: treat "topic" or "trigger" as a single
            // keyword for preview purposes. Admin-entered trigger phrases
            // aren't necessarily strict literal matches in production — this
            // is a dry-run hint only.
            if (!$hit) {
                foreach (['topic', 'trigger'] as $field) {
                    if (!empty($rule[$field]) && is_string($rule[$field])) {
                        $needle = trim(mb_strtolower($rule[$field]));
                        if ($needle !== '' && str_contains($normalised, $needle)) {
                            $hit = $field . ':' . $needle;
                            break;
                        }
                    }
                }
            }

            if (!$hit) {
                continue;
            }

            $response = $rule['response']
                ?? $rule['referral']
                ?? $rule['canned_response_key']
                ?? null;

            return [
                'category' => $category,
                'rule'     => $rule,
                'response' => $response,
                'hit'      => $hit,
            ];
        }

        return null;
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

    /**
     * Trigger a knowledge-sync for this agent: walk its
     * knowledge_sources_json through the matching drivers, embed the
     * retrieved chunks, and write them to knowledge_chunks.
     *
     * Dispatched synchronously on this request for v1 so the admin
     * gets deterministic feedback via the status endpoint. Swap to
     * async dispatch() once a queue worker is set up on the host.
     */
    public function reindex(Agent $agent): JsonResponse
    {
        $agent->update([
            'knowledge_sync_status' => 'pending',
            'knowledge_last_error'  => null,
        ]);

        \App\Jobs\SyncKnowledgeSources::dispatchSync($agent->id);

        return response()->json([
            'message' => 'Reindex finished',
            'status'  => $agent->fresh()->knowledge_sync_status,
        ]);
    }

    /** List all prompt versions for an agent, newest first. */
    public function listPromptVersions(Agent $agent): JsonResponse
    {
        $versions = $agent->promptVersions()
            ->orderByDesc('version_number')
            ->get([
                'id', 'version_number', 'is_active', 'note',
                'created_by_user_id', 'created_at',
            ]);

        return response()->json([
            'active_id' => $agent->active_prompt_version_id,
            'versions'  => $versions,
        ]);
    }

    /** Snapshot the agent's current prompt fields as a new version. */
    public function createPromptVersion(Request $request, Agent $agent): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $nextNumber = ((int) $agent->promptVersions()->max('version_number')) + 1;

        $version = DB::transaction(function () use ($agent, $nextNumber, $validated, $request) {
            // Deactivate currently-active versions (there should be exactly one).
            $agent->promptVersions()->where('is_active', true)->update(['is_active' => false]);

            $version = AgentPromptVersion::create([
                'agent_id'            => $agent->id,
                'version_number'      => $nextNumber,
                'system_instructions' => $agent->system_instructions,
                'persona_json'        => $agent->persona_json,
                'scope_json'          => $agent->scope_json,
                'red_flag_rules_json' => $agent->red_flag_rules_json,
                'handoff_rules_json'  => $agent->handoff_rules_json,
                'is_active'           => true,
                'note'                => $validated['note'] ?? null,
                'created_by_user_id'  => $request->attributes->get('saas_user_id') ?: null,
            ]);

            $agent->update(['active_prompt_version_id' => $version->id]);

            return $version;
        });

        return response()->json($version, 201);
    }

    /**
     * Activate a specific prompt version — copies its fields back onto the
     * agent and marks it active. Mirror of createPromptVersion but for an
     * existing snapshot.
     */
    public function activatePromptVersion(Agent $agent, AgentPromptVersion $version): JsonResponse
    {
        if ($version->agent_id !== $agent->id) {
            abort(404);
        }

        DB::transaction(function () use ($agent, $version) {
            $agent->promptVersions()->where('is_active', true)->update(['is_active' => false]);
            $version->update(['is_active' => true]);

            $agent->update([
                'active_prompt_version_id' => $version->id,
                'system_instructions'      => $version->system_instructions,
                'persona_json'             => $version->persona_json,
                'scope_json'               => $version->scope_json,
                'red_flag_rules_json'      => $version->red_flag_rules_json,
                'handoff_rules_json'       => $version->handoff_rules_json,
            ]);
        });

        return response()->json([
            'message' => 'Version activated',
            'agent'   => $agent->fresh(),
            'version' => $version->fresh(),
        ]);
    }

    /**
     * Bulk export all agents as a JSON bundle (scoped to a vertical if
     * provided via ?vertical=slug). Intended as a version-controllable
     * snapshot — put it under docs/verticals/<slug>/ and commit.
     */
    public function bulkExport(Request $request): JsonResponse
    {
        $verticalSlug = $request->query('vertical');

        $query = Agent::query()->with('vertical:id,slug,name');
        if ($verticalSlug) {
            $query->whereHas('vertical', fn ($q) => $q->where('slug', $verticalSlug));
        }

        $agents = $query->orderBy('name')->get()->map(function (Agent $a) {
            $row = $a->toArray();
            // Keep it round-trippable: strip database-specific IDs and
            // timestamps, but preserve the vertical by slug so import can
            // re-resolve.
            unset(
                $row['id'],
                $row['vertical_id'],
                $row['active_prompt_version_id'],
                $row['created_at'],
                $row['updated_at'],
                $row['knowledge_synced_at'],
                $row['knowledge_sync_status'],
                $row['knowledge_last_error'],
                $row['openai_vector_store_id'],
            );
            $row['vertical_slug'] = $a->vertical?->slug;
            return $row;
        });

        return response()->json([
            'exported_at'   => now()->toIso8601String(),
            'vertical_slug' => $verticalSlug,
            'count'         => $agents->count(),
            'agents'        => $agents,
        ]);
    }

    /**
     * Bulk import an agent bundle produced by bulkExport. Matches on slug:
     * existing rows are updated, missing ones are created. Safe to re-run.
     */
    public function bulkImport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agents'              => 'required|array',
            'agents.*.slug'       => 'required|string|max:64',
            'agents.*.name'       => 'required|string|max:100',
            'agents.*.vertical_slug' => 'nullable|string|max:64',
        ]);

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($validated['agents'] as $payload) {
            $slug = $payload['slug'];
            $verticalSlug = $payload['vertical_slug'] ?? null;
            $verticalId = $verticalSlug
                ? Vertical::where('slug', $verticalSlug)->value('id')
                : null;

            // Drop keys we should never trust from an import.
            $safe = collect($payload)->except([
                'vertical_slug',
                'vertical',
                'id',
                'created_at',
                'updated_at',
                'knowledge_synced_at',
                'knowledge_sync_status',
                'knowledge_last_error',
                'openai_vector_store_id',
                'active_prompt_version_id',
            ])->toArray();

            if ($verticalId) {
                $safe['vertical_id'] = $verticalId;
            }

            $existing = Agent::where('slug', $slug)->first();
            if ($existing) {
                $existing->update($safe);
                $updated++;
            } elseif ($verticalId) {
                Agent::create($safe);
                $created++;
            } else {
                // No vertical match and no existing row — skip rather than
                // create an orphaned agent.
                $skipped++;
            }
        }

        return response()->json([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
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

    // ─────────────────────────────────────────────────────────────────
    //  USERS ADMIN
    //  Endpoints under /api/v1/admin/users let staff inspect and
    //  manually adjust user state when RevenueCat / billing flows
    //  alone aren't enough — comping a user, fixing entitlement
    //  drift after a refund, debugging conversation history, etc.
    //  Payment-side ops (refunds, dunning, subscription cancellation
    //  through Apple/Google) still happen on RevenueCat.
    // ─────────────────────────────────────────────────────────────────

    /**
     * Paginated user list. Optional `?q=` filters by name OR email
     * (LIKE %q%). Returns the same shape AdminController other list
     * endpoints use — flat array + pagination meta — for parity with
     * the existing admin SPA's table-rendering helpers.
     */
    public function listUsers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = max(10, min(200, (int) $request->query('per_page', 50)));

        $query = User::query()
            ->select('id', 'name', 'email', 'created_at')
            ->orderBy('id', 'desc');

        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                  ->orWhere('email', 'like', $like);
            });
        }

        $page = $query->paginate($perPage);

        // Pull plan slug + token usage in batch so the table can show
        // them in the row without N+1 queries.
        $userIds = collect($page->items())->pluck('id');
        $entitlements = SubscriptionEntitlement::with('plan:id,slug,name')
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $rows = collect($page->items())->map(function (User $u) use ($entitlements) {
            $ent = $entitlements->get($u->id);
            $plan = $ent?->plan;
            return [
                'id'          => $u->id,
                'name'        => $u->name,
                'email'       => $u->email,
                'created_at'  => $u->created_at?->toIso8601String(),
                'plan'        => $plan?->slug ?? 'free',
                'plan_name'   => $plan?->name ?? 'Free',
                'status'      => $ent?->status ?? 'none',
                'tokens_used_period' => $u->tokensUsedThisPeriod(),
            ];
        });

        return response()->json([
            'data'         => $rows,
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
            'per_page'     => $page->perPage(),
            'total'        => $page->total(),
        ]);
    }

    /**
     * Detailed view of a single user — profile, current plan +
     * subscription state, token + message usage, recent conversations.
     * Used by the admin's user-detail modal.
     */
    public function showUser(int $userId): JsonResponse
    {
        $user = User::with('profile', 'entitlement.plan')->findOrFail($userId);
        $plan = $user->activePlan();

        $tokenLimit = $plan?->monthly_token_limit;
        $tokensUsed = $user->tokensUsedThisPeriod();
        $msgsToday  = $user->messagesUsedToday();

        // Recent conversations (10 most recent) + message count, last
        // activity. Cheap aggregation — fine even for power users.
        $recentConversations = DB::table('conversations')
            ->leftJoin('messages', 'messages.conversation_id', '=', 'conversations.id')
            ->leftJoin('agents', 'conversations.agent_id', '=', 'agents.id')
            ->where('conversations.user_id', $user->id)
            ->select(
                'conversations.id',
                'conversations.title',
                'conversations.created_at',
                'agents.name as agent_name',
                'agents.slug as agent_slug',
            )
            ->selectRaw('COUNT(messages.id) as message_count')
            ->selectRaw('MAX(messages.created_at) as last_message_at')
            ->groupBy('conversations.id', 'conversations.title', 'conversations.created_at', 'agents.name', 'agents.slug')
            ->orderByRaw('MAX(messages.created_at) DESC NULLS LAST')
            ->limit(10)
            ->get();

        return response()->json([
            'user' => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'profile' => $user->profile ? [
                'display_name'        => $user->profile->display_name,
                'preferred_language'  => $user->profile->preferred_language,
                'age_band'            => $user->profile->age_band,
                'sex_at_birth'        => $user->profile->sex_at_birth,
                'goals'               => $user->profile->goals ?? [],
                'conditions'          => $user->profile->conditions ?? [],
                'allergies'           => $user->profile->allergies ?? [],
            ] : null,
            'subscription' => [
                'plan'                => $plan?->slug,
                'plan_name'           => $plan?->name,
                'status'              => $user->entitlement?->status ?? 'none',
                'monthly_token_limit' => $tokenLimit,
                'tokens_used_period'  => $tokensUsed,
                'tokens_remaining'    => $tokenLimit === null ? null : max(0, $tokenLimit - $tokensUsed),
                'daily_limit'         => $plan?->daily_message_limit,
                'used_today'          => $msgsToday,
                'trial_ends_at'       => $user->entitlement?->trial_ends_at,
                'renews_at'           => $user->entitlement?->renews_at,
                'billing_provider'    => $user->entitlement?->billing_provider,
            ],
            'recent_conversations' => $recentConversations,
        ]);
    }

    /**
     * Manually grant or revoke a subscription. Used by staff to comp
     * a plan, recover from a billing-sync glitch, or revoke access on
     * a chargeback. Logs an admin_metadata entry on the entitlement so
     * the override is auditable.
     *
     * Body: { plan_slug: 'pro' | 'free' | …, status?: 'active' | 'cancelled' }
     * Setting plan_slug='free' effectively revokes premium without
     * deleting the row (we keep history for audit).
     */
    public function updateUserSubscription(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'plan_slug' => 'required|string|exists:subscription_plans,slug',
            'status'    => 'nullable|in:active,trialing,in_grace_period,cancelled,expired',
            'note'      => 'nullable|string|max:240',
        ]);

        $user = User::findOrFail($userId);
        $plan = SubscriptionPlan::where('slug', $validated['plan_slug'])->firstOrFail();

        $entitlement = $user->entitlement
            ?? new SubscriptionEntitlement(['user_id' => $user->id]);

        $auditTrail = is_array($entitlement->billing_metadata) ? $entitlement->billing_metadata : [];
        $auditTrail['admin_overrides'] = $auditTrail['admin_overrides'] ?? [];
        $auditTrail['admin_overrides'][] = [
            'at'         => now()->toIso8601String(),
            'previous'   => [
                'plan_id' => $entitlement->plan_id,
                'status'  => $entitlement->status,
            ],
            'new'        => [
                'plan_slug' => $plan->slug,
                'status'    => $validated['status'] ?? 'active',
            ],
            'note'       => $validated['note'] ?? null,
            'admin_user' => $request->header('X-Admin-Email', 'unknown'),
        ];

        $entitlement->fill([
            'plan_id'           => $plan->id,
            'status'            => $validated['status'] ?? 'active',
            'billing_provider'  => $entitlement->billing_provider ?? 'admin_override',
            'billing_metadata'  => $auditTrail,
        ]);
        $entitlement->save();

        return response()->json([
            'ok'           => true,
            'subscription' => [
                'plan'      => $plan->slug,
                'plan_name' => $plan->name,
                'status'    => $entitlement->status,
            ],
        ]);
    }

    /**
     * Fleet-wide usage overview for the Usage tab. Counts users,
     * tokens, messages, and rough OpenAI cost over the last 30 days.
     * One-shot aggregate — no breakdown by user (use listUsers for
     * per-row stats). Cheap enough at our scale; if it gets slow,
     * back it with a materialised view of token_usage_daily.
     */
    public function usageOverview(): JsonResponse
    {
        $since = now()->subDays(30);

        $totalUsers = User::count();

        $activeUsers = (int) DB::table('messages')
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->where('messages.role', 'user')
            ->where('messages.created_at', '>=', $since)
            ->distinct('conversations.user_id')
            ->count('conversations.user_id');

        $tokenTotals = DB::table('llm_calls')
            ->where('created_at', '>=', $since)
            ->selectRaw('SUM(COALESCE(prompt_tokens, 0)) as prompt_tokens')
            ->selectRaw('SUM(COALESCE(completion_tokens, 0)) as completion_tokens')
            ->selectRaw('SUM(COALESCE(cost_usd_cents, 0)) as cost_usd_cents')
            ->selectRaw('COUNT(*) as call_count')
            ->first();

        $messagesSent = (int) Message::where('role', 'user')
            ->where('created_at', '>=', $since)
            ->count();

        // Plan distribution — pie chart fodder.
        $planMix = DB::table('subscription_entitlements')
            ->join('subscription_plans', 'subscription_entitlements.plan_id', '=', 'subscription_plans.id')
            ->whereIn('subscription_entitlements.status', ['active', 'in_grace_period', 'trialing'])
            ->selectRaw('subscription_plans.slug, subscription_plans.name, COUNT(*) as user_count')
            ->groupBy('subscription_plans.slug', 'subscription_plans.name')
            ->get();

        return response()->json([
            'period_days'        => 30,
            'total_users'        => $totalUsers,
            'active_users_30d'   => $activeUsers,
            'messages_sent_30d'  => $messagesSent,
            'tokens_in_30d'      => (int) ($tokenTotals->prompt_tokens ?? 0),
            'tokens_out_30d'     => (int) ($tokenTotals->completion_tokens ?? 0),
            'llm_calls_30d'      => (int) ($tokenTotals->call_count ?? 0),
            'cost_usd_cents_30d' => (int) ($tokenTotals->cost_usd_cents ?? 0),
            'plan_mix'           => $planMix,
        ]);
    }
}
