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
    ],
];
