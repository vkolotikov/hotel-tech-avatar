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
        'model'                => env('OPENAI_MODEL_DEFAULT', 'gpt-4o'),
        'timeout'              => env('OPENAI_TIMEOUT_SECONDS', 45),
        'temperature'          => env('OPENAI_TEMPERATURE', 0.3),
        'max_output_tokens'    => env('OPENAI_MAX_OUTPUT_TOKENS', 220),
        'max_context_messages' => env('OPENAI_MAX_CONTEXT_MESSAGES', 20),
        'max_knowledge_chars'  => env('OPENAI_MAX_KNOWLEDGE_CHARS', 12000),
        'tts_model'            => env('OPENAI_TTS_MODEL', 'gpt-4o-mini-tts'),
        'transcribe_model'     => env('OPENAI_TRANSCRIBE_MODEL', 'gpt-4o-transcribe'),
    ],

    'saas' => [
        'platform_url' => env('SAAS_PLATFORM_URL', 'http://localhost:3000'),
        'jwt_secret'   => env('SAAS_JWT_SECRET', ''),
    ],

];
