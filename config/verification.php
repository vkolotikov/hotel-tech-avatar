<?php

return [
    // Claim-vs-chunk cosine threshold for grounding. Matches the scale
    // of retrieval.vector_similarity_threshold; see comment there for why
    // the previous 0.65 was above every real-world match.
    'grounding_threshold' => (float) env('VERIFICATION_GROUNDING_THRESHOLD', 0.35),
    'citation_validation_cache_ttl_hours' => 24,
    'citation_validation_error_cache_ttl_hours' => 1,
    'max_revisions' => 2,
    'revision_timeout_seconds' => 10,

    'safety_patterns' => [
        'hard' => [
            'diagnosed with',
            'you have',
            'prescribe',
            'dosage of',
            'take \d+ mg',
            'chest pain',
            'shortness of breath',
            'suicidal',
            'self-harm',
            'severe allergic',
            'anaphylaxis',
        ],
        'soft' => [
            'medical advice',
            'clinical',
            'treatment',
            'consult your doctor',
        ],
    ],
];
