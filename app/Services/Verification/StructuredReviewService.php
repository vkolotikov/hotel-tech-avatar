<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Services\Knowledge\RetrievedContext;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmRequest;
use Illuminate\Support\Facades\Log;

final class StructuredReviewService
{
    public function __construct(
        private readonly LlmClient $llmClient,
    ) {}

    public function review(
        string $response_text,
        RetrievedContext $context,
        array $failures_so_far = [],
    ): StructuredReviewResult {
        try {
            // Build sources JSON from first 100 chars of each chunk
            $sources = [];
            foreach ($context->chunks as $chunk) {
                $sources[] = [
                    'source' => $chunk->source_name,
                    'content' => substr($chunk->content, 0, 100),
                ];
            }
            $sourcesJson = json_encode($sources);

            // Build failures JSON
            $failuresJson = json_encode($failures_so_far);

            // Build checklist prompt
            $checklist = [
                'accuracy' => 'Are claims factually accurate based on sources?',
                'completeness' => 'Does response address the user question fully?',
                'scope' => 'Is the response within wellness education scope?',
                'safety' => 'Does response avoid dangerous medical advice?',
                'persona' => 'Does response match the avatar persona?',
            ];
            $checklistJson = json_encode($checklist);

            // Build the prompt
            $prompt = <<<PROMPT
Review this wellness response against the following criteria.

RESPONSE:
{$response_text}

RETRIEVED SOURCES (first 100 chars each):
{$sourcesJson}

PREVIOUS FAILURES:
{$failuresJson}

CHECKLIST TO REVIEW:
{$checklistJson}

Respond with valid JSON only, no other text. Format:
{
  "passed": boolean,
  "issues": [
    {
      "criterion": "accuracy" | "completeness" | "scope" | "safety" | "persona",
      "description": "what failed"
    }
  ],
  "revision_suggestion": "how to fix, or null if passed"
}
PROMPT;

            // Call LLM
            $request = new LlmRequest(
                messages: [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                model: config('llm.default_model', 'gpt-4'),
                temperature: 0.1,
                maxTokens: 500,
                purpose: 'structured_review',
            );

            $response = $this->llmClient->chat($request);

            // Parse JSON response
            $decoded = json_decode($response->content, associative: true);

            if ($decoded === null || !is_array($decoded)) {
                Log::warning('StructuredReviewService: Invalid JSON response from LLM', [
                    'response' => $response->content,
                ]);
                return new StructuredReviewResult(
                    passed: false,
                    issues: [['criterion' => 'unknown', 'description' => 'Failed to parse LLM response']],
                    revision_suggestion: 'Unable to review response due to LLM error',
                );
            }

            return new StructuredReviewResult(
                passed: (bool) ($decoded['passed'] ?? false),
                issues: (array) ($decoded['issues'] ?? []),
                revision_suggestion: $decoded['revision_suggestion'] ?? null,
            );
        } catch (\Exception $e) {
            Log::warning('StructuredReviewService error', [
                'error' => $e->getMessage(),
            ]);

            return new StructuredReviewResult(
                passed: false,
                issues: [['criterion' => 'unknown', 'description' => 'Review service error']],
                revision_suggestion: null,
            );
        }
    }
}

/**
 * DTO representing the result of a structured review.
 */
final class StructuredReviewResult
{
    public function __construct(
        public readonly bool $passed,
        public readonly array $issues,
        public readonly ?string $revision_suggestion,
    ) {}
}
