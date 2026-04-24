<?php

return [
    'high_risk_keywords' => [
        'warfarin',
        'ssri',
        'maoi',
        'metformin',
        'drug',
        'medication',
        'supplement.*interaction',
        'contraindic',
        'clinical',
        'diagnosis',
        'melanoma',
        'seizure',
        'cardiac',
        'cardiovascular',
        'stroke',
        'heart attack',
        'anaphylaxis',
        'anaphylactic',
    ],
    'live_timeout_sec' => 3,
    // Cosine similarity threshold for considering a cached chunk a match.
    //
    // text-embedding-3-large vectors are unit-normalised, but real-world
    // cosine similarities between a conversational query and a PubMed
    // abstract land in the 0.35–0.50 range even when the abstract is
    // directly on-topic (see `php artisan knowledge:debug`). 0.7 rejected
    // every match on every live query; 0.35 lets the top handful through
    // while still excluding obviously-unrelated content. Tune per-vertical
    // via the RETRIEVAL_VECTOR_SIMILARITY_THRESHOLD env.
    'vector_similarity_threshold' => (float) env('RETRIEVAL_VECTOR_SIMILARITY_THRESHOLD', 0.35),
    'max_cached_results' => 5,
];
