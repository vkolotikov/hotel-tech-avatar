<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmRequest;
use App\Services\Verification\Contracts\ClaimExtractionServiceInterface;
use App\Services\Verification\Drivers\Claim;
use Illuminate\Support\Facades\Log;

final class ClaimExtractionService implements ClaimExtractionServiceInterface
{
    public function __construct(
        private readonly LlmClient $llmClient,
    ) {}

    /**
     * Extract claims from a response text using LLM.
     *
     * @return array<Claim>
     */
    public function extract(string $responseText): array
    {
        $prompt = <<<'PROMPT'
You are an expert at extracting factual claims from wellness education content.

Analyze the following response text and extract ALL factual claims that require evidence or citation.

For each claim, determine:
1. The exact claim text
2. Whether it requires citation (true if it makes any factual assertion; false only for generic wellness principles, personal opinions, or definitions)
3. The inferred source category: one of [research, clinical, nutritional, general]

Return a JSON array with this exact structure:
[
  {
    "text": "exact claim text from the response",
    "requires_citation": true or false,
    "inferred_source_category": "research|clinical|nutritional|general"
  }
]

Return an empty array [] if the response contains no factual claims or is purely conversational.

Response text to analyze:
{response_text}
PROMPT;

        try {
            $response = $this->llmClient->chat(
                new LlmRequest(
                    messages: [
                        [
                            'role' => 'user',
                            'content' => str_replace('{response_text}', $responseText, $prompt),
                        ],
                    ],
                    model: config('llm.model', 'gpt-4o'),
                    temperature: 0.3,
                    maxTokens: 500,
                    purpose: 'claim_extraction',
                )
            );

            return $this->parseClaimsFromResponse($response->content);
        } catch (\Throwable $e) {
            Log::warning('Claim extraction failed', [
                'error' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Parse JSON response from LLM into Claim objects.
     *
     * @return array<Claim>
     */
    private function parseClaimsFromResponse(string $responseContent): array
    {
        try {
            $decoded = json_decode($responseContent, associative: true, flags: JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                Log::warning('Claim extraction returned non-array JSON', [
                    'response' => $responseContent,
                ]);

                return [];
            }

            $claims = [];
            foreach ($decoded as $item) {
                if (!is_array($item) || !isset($item['text'], $item['requires_citation'], $item['inferred_source_category'])) {
                    continue;
                }

                $claims[] = new Claim(
                    text: (string) $item['text'],
                    requires_citation: (bool) $item['requires_citation'],
                    inferred_source_category: (string) $item['inferred_source_category'],
                );
            }

            return $claims;
        } catch (\JsonException $e) {
            Log::warning('Failed to parse claim extraction response as JSON', [
                'error' => $e->getMessage(),
                'response' => $responseContent,
            ]);

            return [];
        }
    }
}
