<?php

return [
    'citation_validators' => [
        'usda' => [
            'timeout_seconds' => env('USDA_VALIDATOR_TIMEOUT', 3),
            'cache_ttl_hours' => env('USDA_VALIDATOR_CACHE_TTL', 24),
        ],
        'pubmed' => [
            'timeout_seconds' => env('PUBMED_VALIDATOR_TIMEOUT', 3),
            'cache_ttl_hours' => env('PUBMED_VALIDATOR_CACHE_TTL', 24),
        ],
        'openfood' => [
            'timeout_seconds' => env('OPENFOOD_VALIDATOR_TIMEOUT', 3),
            'cache_ttl_hours' => env('OPENFOOD_VALIDATOR_CACHE_TTL', 24),
        ],
        'generic' => [
            'timeout_seconds' => env('GENERIC_VALIDATOR_TIMEOUT', 2),
            'cache_ttl_hours' => env('GENERIC_VALIDATOR_CACHE_TTL', 24),
            'cache_ttl_hours_error' => env('GENERIC_VALIDATOR_CACHE_TTL_ERROR', 1),
        ],
    ],
];
