<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Models\KnowledgeChunk;
use App\Services\Knowledge\Drivers\RetrievedChunk;
use App\Services\Knowledge\EmbeddingService;
use App\Services\Knowledge\RetrievedContext;
use App\Services\Verification\Contracts\GroundingServiceInterface;
use App\Services\Verification\Drivers\Claim;
use App\Services\Verification\Drivers\GroundingResult;
use Illuminate\Support\Facades\Log;

final class GroundingService implements GroundingServiceInterface
{
    private const GROUNDING_THRESHOLD = 0.65;

    public function __construct(
        private readonly EmbeddingService $embeddingService,
    ) {}

    /**
     * Ground all claims against the retrieved knowledge context.
     * Claims that do not require citation are returned unchanged.
     * Claims requiring citation are matched against context chunks via cosine similarity.
     *
     * @param array<Claim> $claims
     * @param RetrievedContext $context
     * @return array<Claim>
     */
    public function ground_all_claims(array $claims, RetrievedContext $context): array
    {
        return array_map(
            fn (Claim $claim) => $this->ground_single_claim($claim, $context),
            $claims,
        );
    }

    /**
     * Ground a single claim against context chunks.
     * Returns the claim unchanged if citation is not required.
     * Returns claim with GroundingResult if citation is required.
     *
     * @param Claim $claim
     * @param RetrievedContext $context
     * @return Claim
     */
    private function ground_single_claim(Claim $claim, RetrievedContext $context): Claim
    {
        if (! $claim->requires_citation) {
            return $claim;
        }

        if (empty($context->chunks)) {
            return new Claim(
                text: $claim->text,
                requires_citation: $claim->requires_citation,
                inferred_source_category: $claim->inferred_source_category,
                grounding: new GroundingResult(is_grounded: false),
                citation: $claim->citation,
            );
        }

        try {
            $claimEmbedding = $this->embeddingService->embed($claim->text);
        } catch (\Throwable $e) {
            Log::warning('GroundingService: Failed to embed claim', [
                'claim_text' => $claim->text,
                'error' => $e->getMessage(),
            ]);

            return new Claim(
                text: $claim->text,
                requires_citation: $claim->requires_citation,
                inferred_source_category: $claim->inferred_source_category,
                grounding: new GroundingResult(is_grounded: false),
                citation: $claim->citation,
            );
        }

        // Check for zero vector (embedding failure via graceful degradation)
        if ($this->isZeroVector($claimEmbedding)) {
            Log::warning('GroundingService: Zero vector returned for claim embedding — skipping grounding', [
                'claim_text' => $claim->text,
            ]);

            return new Claim(
                text: $claim->text,
                requires_citation: $claim->requires_citation,
                inferred_source_category: $claim->inferred_source_category,
                grounding: new GroundingResult(is_grounded: false),
                citation: $claim->citation,
            );
        }

        $bestChunk = null;
        $bestScore = 0.0;

        foreach ($context->chunks as $chunk) {
            // Accept both the RetrievedChunk DTO (what RetrievalService
            // hands back for cached vector search hits) and the
            // KnowledgeChunk model (what some tests/older callers pass
            // directly). The DTO carries the embedding as an array; the
            // model keeps it in pgvector string form.
            $chunkVector = $this->extractVector($chunk);
            if (empty($chunkVector)) {
                continue;
            }

            $score = $this->calculate_similarity($claimEmbedding, $chunkVector);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestChunk = $chunk;
            }
        }

        if ($bestChunk !== null && $bestScore >= self::GROUNDING_THRESHOLD) {
            $matchedChunk = $bestChunk instanceof KnowledgeChunk
                ? $bestChunk
                : ($bestChunk->chunk_id ? KnowledgeChunk::find($bestChunk->chunk_id) : null);

            $grounding = new GroundingResult(
                is_grounded: true,
                matched_chunk: $matchedChunk,
                similarity_score: $bestScore,
                supporting_evidence: $bestChunk->content,
            );
        } else {
            $grounding = new GroundingResult(
                is_grounded: false,
                similarity_score: $bestScore,
            );
        }

        return new Claim(
            text: $claim->text,
            requires_citation: $claim->requires_citation,
            inferred_source_category: $claim->inferred_source_category,
            grounding: $grounding,
            citation: $claim->citation,
        );
    }

    /**
     * Extract a native float-array embedding vector from whatever kind
     * of chunk object the retrieval layer handed us. Returns empty array
     * if no usable embedding is available.
     *
     * @param mixed $chunk
     * @return array<int, float>
     */
    private function extractVector(mixed $chunk): array
    {
        if ($chunk instanceof RetrievedChunk) {
            return is_array($chunk->embedding) ? $chunk->embedding : [];
        }

        if ($chunk instanceof KnowledgeChunk) {
            $raw = $chunk->getRawOriginal('embedding') ?? $chunk->embedding ?? null;
            if ($raw === null) {
                return [];
            }
            return $this->parse_pgvector((string) $raw);
        }

        return [];
    }

    /**
     * Calculate cosine similarity between two vectors.
     * Returns 0.0 if either vector is zero or dimensions mismatch.
     *
     * @param array<float> $a
     * @param array<float> $b
     * @return float Similarity in range [0.0, 1.0]
     */
    public function calculate_similarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $val) {
            $dot += $val * $b[$i];
            $normA += $val * $val;
            $normB += $b[$i] * $b[$i];
        }

        $denominator = sqrt($normA) * sqrt($normB);

        if ($denominator < 1e-10) {
            return 0.0;
        }

        return (float) ($dot / $denominator);
    }

    /**
     * Parse pgvector string format "[0.1, 0.2, ...]" into a float array.
     *
     * @param string $pgvectorString
     * @return array<float>
     */
    public function parse_pgvector(string $pgvectorString): array
    {
        $trimmed = trim($pgvectorString);

        if ($trimmed === '' || $trimmed === '[]') {
            return [];
        }

        // Strip surrounding brackets
        $inner = trim($trimmed, '[]');

        if ($inner === '') {
            return [];
        }

        $parts = explode(',', $inner);

        return array_map(fn (string $v) => (float) trim($v), $parts);
    }

    /**
     * Check whether an embedding is a zero vector (indicating embedding failure).
     *
     * @param array<float> $vector
     * @return bool
     */
    private function isZeroVector(array $vector): bool
    {
        foreach ($vector as $v) {
            if (abs($v) > 1e-10) {
                return false;
            }
        }

        return true;
    }
}
