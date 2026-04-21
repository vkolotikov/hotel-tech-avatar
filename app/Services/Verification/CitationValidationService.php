<?php

namespace App\Services\Verification;

use App\Services\Verification\CitationValidators\CitationValidatorInterface;
use App\Services\Verification\CitationValidators\GenericCitationValidator;
use App\Services\Verification\CitationValidators\OpenFoodFactsCitationValidator;
use App\Services\Verification\CitationValidators\PubMedCitationValidator;
use App\Services\Verification\CitationValidators\UsdaCitationValidator;
use App\Services\Verification\Contracts\CitationValidationServiceInterface;
use App\Services\Verification\Drivers\Claim;

final class CitationValidationService implements CitationValidationServiceInterface
{
    private PubMedCitationValidator $pubmedValidator;
    private UsdaCitationValidator $usdaValidator;
    private OpenFoodFactsCitationValidator $openFoodFactsValidator;
    private GenericCitationValidator $genericValidator;

    public function __construct()
    {
        $this->pubmedValidator = new PubMedCitationValidator();
        $this->usdaValidator = new UsdaCitationValidator();
        $this->openFoodFactsValidator = new OpenFoodFactsCitationValidator();
        $this->genericValidator = new GenericCitationValidator();
    }

    /**
     * Validate all citations in an array of claims.
     *
     * @param  array  $claims  Array of Claim objects
     * @return array Array of Claim objects with citation validation results
     */
    public function validate_all_citations(array $claims): array
    {
        return array_map(fn (Claim $claim) => $this->validate_single_citation($claim), $claims);
    }

    /**
     * Validate a single claim's citation.
     *
     * Skips validation if:
     * - The claim's grounding is null
     * - The grounding result is not grounded
     * - The matched_chunk is null
     *
     * Routes to appropriate validator based on citation_key format.
     * Returns claim with citation result attached, or null citation on exception.
     *
     * @param  Claim  $claim  The claim to validate
     * @return Claim The claim with citation validation result attached
     */
    private function validate_single_citation(Claim $claim): Claim
    {
        // Skip if grounding is null, not grounded, or no matched chunk
        if ($claim->grounding === null || ! $claim->grounding->is_grounded || $claim->grounding->matched_chunk === null) {
            return $claim;
        }

        $citation_key = $claim->grounding->matched_chunk->metadata['citation_key'] ?? null;

        // Skip if no citation key found
        if ($citation_key === null) {
            return $claim;
        }

        try {
            $validator = $this->get_validator($claim->inferred_source_category, $citation_key);
            $citation_result = $validator->validate($citation_key);

            return new Claim(
                text: $claim->text,
                requires_citation: $claim->requires_citation,
                inferred_source_category: $claim->inferred_source_category,
                grounding: $claim->grounding,
                citation: $citation_result
            );
        } catch (\Exception $e) {
            // Return claim with null citation on exception
            return $claim;
        }
    }

    /**
     * Get the appropriate validator based on citation key format.
     *
     * Routes based on citation_key prefix patterns:
     * - "PMID:" prefix → PubMedCitationValidator
     * - "FDC ID:" prefix → UsdaCitationValidator
     * - Long numeric string → OpenFoodFactsCitationValidator
     * - URLs (http/https) → GenericCitationValidator
     *
     * @param  string  $category  The inferred source category
     * @param  string  $citation_key  The citation key to validate
     * @return CitationValidatorInterface The appropriate validator
     */
    private function get_validator(string $category, string $citation_key): CitationValidatorInterface
    {
        // Check for PMID prefix (case-insensitive)
        if (preg_match('/^PMID[:\s]+/i', $citation_key)) {
            return $this->pubmedValidator;
        }

        // Check for FDC ID prefix (case-insensitive)
        if (preg_match('/^FDC\s+ID[:\s]+/i', $citation_key)) {
            return $this->usdaValidator;
        }

        // Check for long numeric string (Open Food Facts format)
        if (preg_match('/^\d{8,}$/', $citation_key)) {
            return $this->openFoodFactsValidator;
        }

        // Check for URL patterns
        if (preg_match('/^https?:\/\//i', $citation_key)) {
            return $this->genericValidator;
        }

        // Default to generic validator
        return $this->genericValidator;
    }
}
