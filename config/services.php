<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key'              => env('OPENAI_API_KEY', ''),
        'base_url'             => env('OPENAI_API_BASE_URL', 'https://api.openai.com/v1'),
        // gpt-5.4 is the current flagship for agentic / professional chat.
        'model'                => env('OPENAI_MODEL_DEFAULT', 'gpt-5.4'),
        'timeout'              => env('OPENAI_TIMEOUT_SECONDS', 45),
        'temperature'          => env('OPENAI_TEMPERATURE', 0.3),
        // Tight default; natural chat wants short replies by default, and the
        // mobile "Tell me more" path can relax it per-turn.
        'max_output_tokens'    => env('OPENAI_MAX_OUTPUT_TOKENS', 180),
        'max_context_messages' => env('OPENAI_MAX_CONTEXT_MESSAGES', 20),
        'max_knowledge_chars'  => env('OPENAI_MAX_KNOWLEDGE_CHARS', 12000),
        'tts_model'            => env('OPENAI_TTS_MODEL', 'gpt-4o-mini-tts'),
        'transcribe_model'     => env('OPENAI_TRANSCRIBE_MODEL', 'gpt-4o-transcribe'),
    ],

    'saas' => [
        'platform_url' => env('SAAS_PLATFORM_URL', 'http://localhost:3000'),
        'jwt_secret'   => env('SAAS_JWT_SECRET', ''),
    ],

    'heygen' => [
        'api_key'         => env('HEYGEN_API_KEY', ''),
        'base_url'        => env('HEYGEN_BASE_URL', 'https://api.heygen.com'),
        'default_avatar'  => env('HEYGEN_DEFAULT_AVATAR', 'Anna_public_3_20240108'),
        'default_voice'   => env('HEYGEN_DEFAULT_VOICE', ''),
        'default_quality' => env('HEYGEN_DEFAULT_QUALITY', 'high'),
        'timeout'         => env('HEYGEN_TIMEOUT_SECONDS', 15),
    ],

    'langfuse' => [
        'public_key' => env('LANGFUSE_PUBLIC_KEY'),
        'secret_key' => env('LANGFUSE_SECRET_KEY'),
        'host' => env('LANGFUSE_HOST', 'https://cloud.langfuse.com'),
        'enabled' => env('LANGFUSE_ENABLED', false),
        'timeout' => (int) env('LANGFUSE_TIMEOUT_SECONDS', 5),
    ],

    // Wellness knowledge sources. The PubMed + USDA API keys are read
    // here and injected into the matching drivers
    // (app/Services/Knowledge/Drivers/{PubMed,Usda}/) when an agent's
    // knowledge_sources_json is synced.
    'pubmed' => [
        'api_key' => env('PUBMED_API_KEY', ''),
        // NCBI rate-limits unauthenticated requests to 3/sec and
        // authenticated ones to 10/sec. Stay below to be polite.
        'rate_per_second' => (int) env('PUBMED_RATE_PER_SECOND', 8),
    ],

    'usda' => [
        // USDA FoodData Central (https://fdc.nal.usda.gov/api-key-signup)
        'api_key' => env('USDA_API_KEY', ''),
    ],

    'open_food_facts' => [
        // Open Food Facts is unauthenticated but polite-rate-limits.
        // Identify ourselves via a User-Agent with this contact email.
        'contact_email' => env('OPEN_FOOD_FACTS_CONTACT_EMAIL', ''),
    ],

];
