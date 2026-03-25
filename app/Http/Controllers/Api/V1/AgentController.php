<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use Illuminate\Http\JsonResponse;

class AgentController extends Controller
{
    /** List published agents. */
    public function index(): JsonResponse
    {
        $agents = Agent::published()
            ->select('id', 'slug', 'name', 'role', 'description', 'avatar_image_url', 'chat_background_url')
            ->orderBy('name')
            ->get();

        return response()->json($agents);
    }

    /** Get single agent details. */
    public function show(Agent $agent): JsonResponse
    {
        return response()->json($agent->only([
            'id', 'slug', 'name', 'role', 'description',
            'avatar_image_url', 'chat_background_url',
            'openai_voice', 'is_published',
        ]));
    }

    /** List knowledge files for an agent (public metadata only). */
    public function attachments(Agent $agent): JsonResponse
    {
        $files = $agent->knowledgeFiles()
            ->where('sync_status', 'synced')
            ->select('id', 'local_path', 'mime_type', 'size_bytes', 'created_at')
            ->get()
            ->map(fn ($f) => [
                'id'        => $f->id,
                'file_name' => basename($f->local_path),
                'mime_type' => $f->mime_type,
                'size_bytes' => $f->size_bytes,
            ]);

        return response()->json($files);
    }
}
