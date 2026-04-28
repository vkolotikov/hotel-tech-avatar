<?php

return [
    'default_provider' => env('LLM_DEFAULT_PROVIDER', 'openai'),
    'ledger_enabled'   => env('LLM_LEDGER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | OpenAI API backend
    |--------------------------------------------------------------------------
    |
    | Selects which OpenAI surface the OpenAI provider hits:
    |
    |   responses (default) → POST /v1/responses
    |     The recommended surface for gpt-5.5 and later. Unlocks
    |     `reasoning.effort`, `text.verbosity`, strict Structured
    |     Outputs via `text.format`, `previous_response_id`, hosted
    |     tools, and per-phase tool preambles.
    |
    |   chat → POST /v1/chat/completions
    |     Legacy surface. Kept available so we can roll back with a
    |     single env flip if Responses regresses anywhere — also the
    |     only surface that handles gpt-4o cleanly without translation.
    |
    | Set `LLM_API_BACKEND=chat` in env to force the legacy surface.
    */
    'openai_api_backend' => env('LLM_API_BACKEND', 'responses'),
];
