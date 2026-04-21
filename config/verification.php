<?php

return [
    'grounding_threshold' => env('VERIFICATION_GROUNDING_THRESHOLD', 0.65),
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
