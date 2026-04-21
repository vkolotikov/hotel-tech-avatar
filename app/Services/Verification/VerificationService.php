<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Models\Agent;
use App\Services\Knowledge\RetrievedContext;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmRequest;
use App\Services\Verification\Contracts\CitationValidationServiceInterface;
use App\Services\Verification\Contracts\ClaimExtractionServiceInterface;
use App\Services\Verification\Contracts\GroundingServiceInterface;
use App\Services\Verification\Contracts\SafetyClassifierInterface;
use App\Services\Verification\Contracts\StructuredReviewServiceInterface;
use App\Services\Verification\Drivers\SafetyFlagSeverity;
use App\Services\Verification\Drivers\VerificationFailure;
use App\Services\Verification\Drivers\VerificationFailureType;
use App\Services\Verification\Drivers\VerificationResult;
use Illuminate\Support\Facades\Log;

final class VerificationService
{
    public const MAX_REVISIONS = 2;

    public function __construct(
        private readonly ClaimExtractionServiceInterface $claimExtractionService,
        private readonly GroundingServiceInterface $groundingService,
        private readonly CitationValidationServiceInterface $citationValidationService,
        private readonly SafetyClassifierInterface $safetyClassifier,
        private readonly StructuredReviewServiceInterface $structuredReviewService,
        private readonly LlmClient $llmClient,
    ) {}

    public function verify(
        string $response_text,
        RetrievedContext $context,
        Agent $agent,
    ): VerificationResult {
        $started_at = hrtime(true);
        $revision_count = 0;
        $current_text = $response_text;

        while ($revision_count < self::MAX_REVISIONS) {
            $failures = [];

            // Stage 1: Extract claims
            $claims = $this->claimExtractionService->extract($current_text);

            // Stage 2: Ground claims
            $claims = $this->groundingService->ground_all_claims($claims, $context);

            // Stage 3: Validate citations
            $claims = $this->citationValidationService->validate_all_citations($claims);

            // Check grounding and citation failures
            foreach ($claims as $claim) {
                if ($claim->requires_citation) {
                    if ($claim->grounding !== null && ! $claim->grounding->is_grounded) {
                        $failures[] = new VerificationFailure(
                            type: VerificationFailureType::NOT_GROUNDED,
                            claim_text: $claim->text,
                            reason: 'Claim similarity below threshold (0.65)',
                        );
                    } elseif ($claim->citation !== null && ! $claim->citation->is_valid) {
                        $failures[] = new VerificationFailure(
                            type: VerificationFailureType::CITATION_INVALID,
                            claim_text: $claim->text,
                            reason: $claim->citation->validation_detail,
                        );
                    }
                }
            }

            // Stage 4: Safety classification
            $safety_flags = $this->safetyClassifier->classify($current_text);

            foreach ($safety_flags as $flag) {
                if ($flag->severity === SafetyFlagSeverity::HARD) {
                    $failures[] = new VerificationFailure(
                        type: VerificationFailureType::SAFETY_VIOLATION,
                        claim_text: $flag->matched_text,
                        reason: 'Hard safety pattern matched: ' . $flag->matched_pattern,
                    );
                }
            }

            // Stage 5: Structured review
            $review = $this->structuredReviewService->review(
                response_text: $current_text,
                context: $context,
                failures_so_far: array_map(
                    fn (VerificationFailure $f) => ['type' => $f->type->value, 'claim' => $f->claim_text, 'reason' => $f->reason],
                    $failures,
                ),
            );

            if (! $review->passed) {
                foreach ($review->issues as $issue) {
                    $failures[] = new VerificationFailure(
                        type: VerificationFailureType::INCOMPLETE,
                        claim_text: $issue['criterion'] ?? 'unknown',
                        reason: $issue['description'] ?? 'Review failed',
                    );
                }
            }

            // If verified, exit loop
            if (empty($failures)) {
                break;
            }

            // Attempt revision if suggestion exists and revisions budget remains
            if ($review->revision_suggestion !== null && $revision_count < self::MAX_REVISIONS - 1) {
                $current_text = $this->revise_response(
                    original: $current_text,
                    failures: $failures,
                    suggestion: $review->revision_suggestion,
                );
                $revision_count++;
            } else {
                break;
            }
        }

        $latency_ms = (int) round((hrtime(true) - $started_at) / 1_000_000);

        return new VerificationResult(
            is_verified: empty($failures),
            failures: $failures ?? [],
            safety_flags: $safety_flags ?? [],
            revision_count: $revision_count,
            latency_ms: $latency_ms,
        );
    }

    private function revise_response(
        string $original,
        array $failures,
        string $suggestion,
    ): string {
        $failures_text = implode("\n", array_map(
            fn (VerificationFailure $f) => '- [' . $f->type->value . '] ' . $f->claim_text . ': ' . $f->reason,
            $failures,
        ));

        $prompt = <<<PROMPT
You are a wellness content editor. Revise the following response to address all identified failures.

ORIGINAL RESPONSE:
{$original}

FAILURES TO ADDRESS:
{$failures_text}

REVISION SUGGESTION:
{$suggestion}

Return only the revised response text, with no preamble or explanation.
PROMPT;

        try {
            $response = $this->llmClient->chat(new LlmRequest(
                messages: [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                model: config('llm.model', 'gpt-4o'),
                temperature: 0.3,
                maxTokens: 1000,
                purpose: 'verification_revision',
            ));

            return $response->content;
        } catch (\Throwable $e) {
            Log::warning('VerificationService: revision LLM call failed', [
                'error' => $e->getMessage(),
            ]);

            return $original;
        }
    }
}
