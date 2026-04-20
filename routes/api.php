<?php

use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\HeygenController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ─── Health ────────────────────────────────────────────────────────────
    Route::get('health', fn () => response()->json([
        'status' => 'ok',
        'app'    => 'hotel-avatar',
        'laravel' => app()->version(),
    ]));

    // ─── Mobile Auth (Sanctum personal access tokens) ──────────────────────
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me',           [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
    });

    // ─── Public Agent Endpoints ────────────────────────────────────────────
    Route::get('agents',                        [AgentController::class, 'index']);
    Route::get('agents/{agent}',                [AgentController::class, 'show']);
    Route::get('agents/{agent}/attachments',    [AgentController::class, 'attachments']);

    // ─── Conversation Endpoints (End-User) ─────────────────────────────────
    Route::get('agents/{agent}/conversation',   [ConversationController::class, 'latest']);
    Route::get('agents/{agent}/conversations',  [ConversationController::class, 'index']);
    Route::post('agents/{agent}/conversations', [ConversationController::class, 'store']);

    Route::get('conversations/{conversation}/messages',       [ConversationController::class, 'messages']);
    Route::post('conversations/{conversation}/messages',      [ConversationController::class, 'createMessage']);
    Route::post('conversations/{conversation}/agent-reply',   [ConversationController::class, 'agentReply']);
    Route::put('conversations/{conversation}',                [ConversationController::class, 'update']);
    Route::delete('conversations/{conversation}',             [ConversationController::class, 'destroy']);

    Route::get('conversations/{conversation}/attachments',    [ConversationController::class, 'listAttachments']);
    Route::post('conversations/{conversation}/attachments',   [ConversationController::class, 'uploadAttachment']);

    // ─── Voice Endpoints ───────────────────────────────────────────────────
    Route::post('conversations/{conversation}/voice/transcribe', [ConversationController::class, 'transcribe']);
    Route::post('conversations/{conversation}/voice/speak',      [ConversationController::class, 'speak']);

    // ─── HeyGen Streaming Avatar ───────────────────────────────────────────
    Route::post('heygen/token', [HeygenController::class, 'token']);

    // ─── Admin Endpoints (SaaS JWT Auth) ───────────────────────────────────
    Route::prefix('admin')->middleware('saas.auth')->group(function () {
        Route::get('assets',                           [AdminController::class, 'assets']);
        Route::get('agents',                           [AdminController::class, 'index']);
        Route::get('agents/{agent}',                   [AdminController::class, 'show']);
        Route::post('agents',                          [AdminController::class, 'store']);
        Route::put('agents/{agent}',                   [AdminController::class, 'update']);
        Route::delete('agents/{agent}',                [AdminController::class, 'destroy']);
        Route::post('knowledge-files',                 [AdminController::class, 'uploadKnowledgeFiles']);
        Route::get('agents/{agent}/knowledge/status',  [AdminController::class, 'knowledgeStatus']);
        Route::post('agents/{agent}/knowledge/reindex',[AdminController::class, 'reindex']);
        Route::get('usage',                            [AdminController::class, 'usage']);
    });
});
