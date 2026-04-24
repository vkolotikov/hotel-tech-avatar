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

    'revenuecat' => [
        // Shared secret RevenueCat sends in the Authorization header on
        // every webhook. Compared constant-time against the incoming
        // header to verify the request actually came from RC and not
        // from someone who guessed the endpoint URL.
        'webhook_auth_header' => env('REVENUECAT_WEBHOOK_AUTH_HEADER'),
        // Server-side REST key for reconciliation calls (future — not
        // used by the v1 webhook flow, which treats webhooks as the
        // source of truth).
        'secret_api_key'      => env('REVENUECAT_SECRET_API_KEY'),
        // Slug of the plan a user gets when a subscription expires or
        // billing fails irrecoverably. Must match a subscription_plans.slug.
        'default_plan_slug'   => env('REVENUECAT_DEFAULT_PLAN', 'free'),
        // Map RevenueCat entitlement_ids → our subscription_plans.slug.
        // Stored as config so product/marketing can rename RC
        // entitlements without re-deploying code. Default assumes the
        // entitlement id matches our plan slug, which is what you get
        // if you configure RC consistently.
        'entitlement_plan_map' => [
            'premium' => 'premium',
        ],
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
        // gpt-4o is our known-good default for this OpenAI account. gpt-5.4
        // is the documented flagship, but access isn't universal — switch
        // via OPENAI_MODEL_DEFAULT in env when your account is cleared.
        'model'                => env('OPENAI_MODEL_DEFAULT', 'gpt-4o'),
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

    // LiveAvatar — successor to HeyGen Streaming Avatar after the v1/v2
    // API was retired April 2026. Separate platform (app.liveavatar.com)
    // and separate credit pool; same vendor, existing HeyGen avatars
    // auto-migrate on first sign-in.
    //
    // While api_key is empty, LiveAvatarController returns 503 and the
    // mobile voice-mode UI shows "Voice avatar not configured" — chat
    // continues to work text-only and audio-only.
    'liveavatar' => [
        'api_key'          => env('LIVEAVATAR_API_KEY', ''),
        'base_url'         => env('LIVEAVATAR_BASE_URL', 'https://api.liveavatar.com'),
        'default_language' => env('LIVEAVATAR_DEFAULT_LANGUAGE', 'en'),
        'timeout'          => env('LIVEAVATAR_TIMEOUT_SECONDS', 15),
        // Sandbox mode burns zero credits at LiveAvatar. Flip to false
        // via env LIVEAVATAR_SANDBOX=false only after the end-to-end
        // flow has been confirmed in a real WebView. Default-true means
        // dev/staging traffic is always safe.
        'sandbox'          => (bool) env('LIVEAVATAR_SANDBOX', true),
        // Max session duration in seconds. LiveAvatar caps per tier;
        // 300s is a reasonable dev ceiling so forgotten tabs don't
        // drain the credit pool.
        'max_session_seconds' => (int) env('LIVEAVATAR_MAX_SESSION_SECONDS', 300),
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
