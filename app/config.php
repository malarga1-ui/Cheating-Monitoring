<?php
/**
 * Exam Monitor Platform - Configuration
 * Copy to config.local.php and override for your environment.
 */

return [
    // Database (Laragon defaults; override in config.local.php)
    'db' => [
        'host'     => getenv('EM_DB_HOST') ?: '127.0.0.1',
        'port'     => getenv('EM_DB_PORT') ?: '3306',
        'database' => getenv('EM_DB_NAME') ?: 'exam_monitor',
        'username' => getenv('EM_DB_USER') ?: 'root',
        'password' => getenv('EM_DB_PASS') ?: '',
        'charset'  => 'utf8mb4',
    ],

    // App
    'app' => [
        'name'       => 'Exam Monitor Platform',
        'env'        => getenv('EM_ENV') ?: 'development', // development | production
        'timezone'   => 'UTC',
        'base_url'   => getenv('EM_BASE_URL') ?: '', // e.g. https://exammonitor.example.com
        'trust_proxies' => false, // enable only behind a trusted reverse proxy
    ],

    // Telemetry ingest limits & throttling
    'telemetry' => [
        'max_body_bytes'    => 524288,   // 512 KB per request (supports 50+ events batch)
        'max_batch_events'  => 500,      // max events per batch request
        'max_event_size'    => 65536,    // 64 KB per event
        'throttle' => [
            // DISABLED by default: the platform must never drop legitimate
            // events. Enable only if you are under active abuse.
            'enabled'        => false,
            'max_per_minute' => 600,     // events per IP per minute
            'window_seconds' => 60,
        ],
    ],

    // Moodle lifecycle sync secret (must match the plugin's setting)
    'sync' => [
        'secret' => getenv('EM_SYNC_SECRET') ?: 'em-sync-change-me',
    ],

    // License / trial activation (used by the "تسجيل الشراء" flow)
    'license' => [
        'secret' => getenv('EM_LICENSE_SECRET') ?: 'em-license-secret-change-me',
        'trial_days' => (int)(getenv('EM_TRIAL_DAYS') ?: 7),
    ],

    // Security
    'auth' => [
        'session_name'   => 'em_session',
        'session_lifetime' => 43200,     // 12 hours
        'cookie_secure'  => false,       // set true over HTTPS
        'cookie_httponly'=> true,
        'cookie_samesite'=> 'Lax',
    ],

    // AI Detection (OpenRouter API — legacy)
    'ai' => [
        'openrouter_key' => getenv('EM_OPENROUTER_KEY') ?: '',
        'model'          => getenv('EM_AI_MODEL') ?: 'google/gemini-2.0-flash-001',
        'enabled'        => (bool)(getenv('EM_AI_ENABLED') ?: '1'),
        'max_tokens'     => (int)(getenv('EM_AI_MAX_TOKENS') ?: '100'),
        'timeout_sec'    => (int)(getenv('EM_AI_TIMEOUT') ?: '15'),
    ],

    // AI Content Detection (RapidAPI — Failover chain)
    'ai_content_detection' => [
        'rapidapi_key'    => getenv('RAPIDAPI_KEY') ?: '',
        'enabled'         => (bool)(getenv('RAPIDAPI_ENABLED') ?: '1'),
        'min_words'       => (int)(getenv('RAPIDAPI_MIN_WORDS') ?: '30'),
        'timeout_per_provider' => (int)(getenv('RAPIDAPI_TIMEOUT') ?: '3'),
    ],
];
