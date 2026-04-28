<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\Conversation;
use App\Services\Generation\GenerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Focused live smoke test for the OpenAI Responses API path. Cheaper
 * and faster than `eval:run` (which is the full quality contract); use
 * this to verify after a deploy that the wired-up gpt-5.5 + Responses
 * + Structured Outputs path actually works end-to-end and that
 * avatars respond at the expected quality.
 *
 * For each requested avatar, runs one canned wellness question through
 * the full GenerationService pipeline (retrieval → prompt → LLM →
 * structured-output parsing → verification → save) and prints:
 *   - which provider + model + tuning was actually used
 *   - latency and token counts
 *   - whether the reply parsed as Structured Outputs JSON
 *   - whether verification passed
 *   - whether the language matches the requested locale
 *   - the first 400 chars of the reply
 *
 * Cleans up the test conversation + messages it creates unless --keep
 * is passed.
 */
final class LlmSmokeCommand extends Command
{
    protected $signature = 'llm:smoke
        {--avatar= : Single avatar slug to test (default: all published wellness avatars)}
        {--prompt= : Override the default question}
        {--user= : Existing user id to associate the test conversation with (drives the language directive). Default: anonymous, English-by-default.}
        {--lang=en : Locale to verify language compliance against (en, es, ru, …). Should match the user\'s preferred_language if --user is set.}
        {--keep : Don\'t delete the test conversation/messages after the run}';

    protected $description = 'End-to-end smoke test of the LLM pipeline against one or all wellness avatars.';

    /**
     * Default per-avatar prompts. Picked so each one exercises the
     * avatar's actual domain knowledge — a generic "hi" wouldn't tell
     * us whether retrieval and persona are working.
     */
    private const DEFAULT_PROMPTS = [
        'integra' => 'I have low energy in the afternoons and trouble focusing — where would you start?',
        'nora'    => 'What would a good high-protein lunch look like if I have 15 minutes to make it?',
        'luna'    => 'I keep waking up at 3am and can\'t fall back asleep — any ideas?',
        'zen'     => 'Work has been overwhelming this week. What\'s a 5-minute reset I can do at my desk?',
        'axel'    => 'I want to start lifting again after a year off. How should I ease back in?',
        'aura'    => 'My skin gets red and flaky in winter. What should I look at first?',
    ];

    public function handle(GenerationService $generation): int
    {
        $slug    = $this->option('avatar');
        $prompt  = $this->option('prompt');
        $userId  = $this->option('user') ? (int) $this->option('user') : null;
        $lang    = (string) $this->option('lang');
        $keep    = (bool) $this->option('keep');

        // Surface a friendly hint when the caller asked us to verify
        // a non-English language but didn't pin a user — without a
        // user profile the avatar has no language preference to honour
        // and will reply in its trained-default English.
        if ($lang !== 'en' && $userId === null) {
            $this->warn("Note: --lang={$lang} given without --user — avatars will reply in English unless you also pass --user=ID for a user whose profile.preferred_language={$lang}.");
        }

        $query = Agent::query()->published()->forVertical('wellness');
        if ($slug) $query->where('slug', $slug);

        $agents = $query->orderForDisplay()->get();
        if ($agents->isEmpty()) {
            $this->error($slug
                ? "No published wellness avatar with slug '{$slug}'."
                : "No published wellness avatars.");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Backend: " . config('llm.openai_api_backend', 'responses')
            . "  |  Model default: " . config('services.openai.model', 'gpt-5.5')
            . "  |  Effort default: " . config('services.openai.reasoning_effort', 'low')
            . "  |  Verbosity default: " . config('services.openai.verbosity', 'low'));
        $this->info("Lang assertion: {$lang}  |  Avatars: " . $agents->count());
        $this->newLine();

        $rows = [];
        $passCount = 0;

        foreach ($agents as $agent) {
            $question = $prompt ?? self::DEFAULT_PROMPTS[$agent->slug] ?? 'How can you help me today?';

            $this->line("─── <fg=cyan>{$agent->name}</> (<fg=gray>{$agent->slug}</>) ───");
            $this->line("Q: {$question}");

            try {
                $result = $this->runOne($generation, $agent, $question, $lang, $userId, $keep);
                $rows[] = $result;
                if ($result['ok']) $passCount++;

                $verdictColor = $result['ok'] ? 'green' : 'red';
                $this->line("<fg={$verdictColor}>" . ($result['ok'] ? 'PASS' : 'FAIL') . "</> "
                    . "model={$result['model']}  "
                    . "tokens={$result['tokens']}  "
                    . "latency={$result['latency_ms']}ms  "
                    . "verified=" . ($result['verified'] === null ? 'n/a' : ($result['verified'] ? 'yes' : 'no'))
                    . "  parsed=" . ($result['parsed_json'] ? 'json' : 'text')
                    . "  lang=" . ($result['language_match'] ? $lang : "≠{$lang}"));
                $this->line("A: " . mb_substr($result['reply'], 0, 400)
                    . (mb_strlen($result['reply']) > 400 ? '…' : ''));
                if (!empty($result['issues'])) {
                    foreach ($result['issues'] as $i) $this->line("  <fg=yellow>! {$i}</>");
                }
            } catch (\Throwable $e) {
                $this->error("EXCEPTION: " . $e->getMessage());
                $rows[] = [
                    'ok'       => false,
                    'avatar'   => $agent->slug,
                    'model'    => '—',
                    'tokens'   => 0,
                    'latency_ms' => 0,
                    'verified' => null,
                    'reply'    => '',
                    'parsed_json' => false,
                    'language_match' => false,
                    'issues'   => ['exception: ' . $e->getMessage()],
                ];
            }
            $this->newLine();
        }

        $this->info("Summary: {$passCount}/" . count($rows) . " avatars passed.");
        return $passCount === count($rows) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Run one avatar through the real GenerationService and pull back
     * everything we need to render a verdict.
     *
     * Why we hit GenerationService rather than the LlmClient directly:
     * the smoke test must catch regressions in retrieval, prompt
     * assembly, and verification too — those are the parts most
     * likely to break a deploy, not the bare API call.
     */
    private function runOne(
        GenerationService $generation,
        Agent $agent,
        string $question,
        string $lang,
        ?int $userId,
        bool $keep,
    ): array {
        $conv = null;
        try {
            $conv = Conversation::create([
                'agent_id'    => $agent->id,
                'vertical_id' => $agent->vertical_id,
                'user_id'     => $userId,
                'title'       => 'llm:smoke ' . now()->toIso8601String(),
            ]);

            $conv->messages()->create([
                'role'    => 'user',
                'content' => $question,
            ]);

            $reply = $generation->generateResponse($conv->fresh(['agent.vertical', 'user.profile']));
            if (!$reply) {
                return [
                    'ok' => false, 'avatar' => $agent->slug, 'model' => '—',
                    'tokens' => 0, 'latency_ms' => 0, 'verified' => null,
                    'reply' => '', 'parsed_json' => false, 'language_match' => false,
                    'issues' => ['GenerationService returned null'],
                ];
            }

            $parsedJson = $this->looksStructured($reply);
            $languageMatch = $this->matchesLanguage((string) $reply->content, $lang);
            $issues = [];

            if (!$parsedJson) $issues[] = 'reply did not parse as Structured Outputs JSON';
            if (!$languageMatch) $issues[] = "reply language doesn't look like '{$lang}'";
            if ($reply->verification_status === 'failed') {
                $issues[] = 'verification rejected the reply';
            }
            if ($reply->ai_latency_ms !== null && $reply->ai_latency_ms > 30_000) {
                $issues[] = "high latency ({$reply->ai_latency_ms}ms)";
            }

            $verified = $reply->is_verified;
            $ok = $reply->verification_status !== 'failed'
                && $languageMatch
                && trim((string) $reply->content) !== '';

            return [
                'ok'             => $ok,
                'avatar'         => $agent->slug,
                'model'          => (string) $reply->ai_model,
                'tokens'         => (int) $reply->total_tokens,
                'latency_ms'     => (int) $reply->ai_latency_ms,
                'verified'       => $verified,
                'reply'          => (string) $reply->content,
                'parsed_json'    => $parsedJson,
                'language_match' => $languageMatch,
                'issues'         => $issues,
            ];
        } finally {
            if ($conv && !$keep) {
                DB::transaction(function () use ($conv) {
                    $conv->messages()->delete();
                    $conv->delete();
                });
            }
        }
    }

    /**
     * GenerationService stores the model's *parsed* `reply` field as
     * the message content and stashes suggestions in `ui_json`. So a
     * "successfully structured" reply is one where ui_json has the
     * suggestions key — even if the suggestions array is empty,
     * ui_json is present (parseJsonReply returns []) only when the
     * model returned a valid JSON envelope. When parseJsonReply
     * couldn't decode JSON it returns the raw text + [], so ui_json
     * is null. That's the signal we can rely on.
     */
    private function looksStructured(\App\Models\Message $reply): bool
    {
        return $reply->ui_json !== null
            && is_array($reply->ui_json)
            && array_key_exists('suggestions', $reply->ui_json);
    }

    /**
     * Cheap heuristic: each language has a small set of high-frequency
     * function words / characters that almost always appear in any
     * substantial reply. We match on the presence of any of them.
     * Cheaper than another LLM call and good enough for "did the
     * model honour the language directive at all" smoke testing.
     */
    private function matchesLanguage(string $text, string $lang): bool
    {
        $lower = mb_strtolower($text);
        return match ($lang) {
            'en' => (bool) preg_match('/\b(the|and|you|with|your|that)\b/u', $lower),
            'es' => (bool) preg_match('/\b(que|para|con|tu|los|las|usted)\b/u', $lower),
            'fr' => (bool) preg_match('/\b(que|pour|avec|votre|vous|les|une)\b/u', $lower),
            'de' => (bool) preg_match('/\b(das|und|sie|mit|ihr|nicht|für)\b/u', $lower),
            'pl' => (bool) preg_match('/\b(jest|nie|się|tak|jak|dla|można)\b/u', $lower),
            'it' => (bool) preg_match('/\b(che|per|con|tuo|sono|della|alla)\b/u', $lower),
            'ru' => (bool) preg_match('/[а-яё]/u', $text),
            'uk' => (bool) preg_match('/[а-яії]/u', $text),
            'lv' => (bool) preg_match('/\b(ir|un|ar|jūs|tava|tas|nav)\b/u', $lower),
            default => true, // unknown locale → don't fail on this axis
        };
    }
}
