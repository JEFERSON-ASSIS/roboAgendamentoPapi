<?php

$selectedWhatsappProfile = strtolower(trim((string) env('WHATSAPP_PROFILE', '')));
$profileSuffix = preg_replace('/[^a-z0-9]+/', '_', $selectedWhatsappProfile) ?? '';
$profileSuffix = strtoupper(trim($profileSuffix, '_'));

$resolveProfileValue = static function (string $key, mixed $default = null) use ($profileSuffix): mixed {
    $resolved = env($key, $default);

    if ($profileSuffix !== '') {
        $resolved = env($key . '_' . $profileSuffix, $resolved);
    }

    return $resolved;
};

$resolvedEvolutionInstance = $resolveProfileValue('WHATSAPP_INSTANCE', '');
$resolvedEvolutionApiKey = $resolveProfileValue('WHATSAPP_API_KEY', '');
$resolvedPapiInstance = $resolveProfileValue('WHATSAPP_PAPI_INSTANCE', '');
$resolvedPapiApiKey = $resolveProfileValue('WHATSAPP_PAPI_API_KEY', '');
$resolvedPapiBaseUrl = $resolveProfileValue('WHATSAPP_PAPI_BASE_URL', '');
$defaultWhatsappProvider = strtolower(trim((string) env('WHATSAPP_DEFAULT_PROVIDER', env('WHATSAPP_PROVIDER', 'evolution'))));
$defaultWhatsappProvider = in_array($defaultWhatsappProvider, ['evolution', 'papi'], true) ? $defaultWhatsappProvider : 'evolution';
$sharedWhatsappConfig = [
    'split_messages' => filter_var(env('WHATSAPP_SPLIT_MESSAGES', true), FILTER_VALIDATE_BOOL),
    'split_max_length' => (int) env('WHATSAPP_SPLIT_MAX_LENGTH', 700),
    'split_delay_ms' => (int) env('WHATSAPP_SPLIT_DELAY_MS', 400),
];
$evolutionWhatsappConfig = array_merge($sharedWhatsappConfig, [
    'base_url' => env('WHATSAPP_BASE_URL', ''),
    'instance' => $resolvedEvolutionInstance,
    'api_key' => $resolvedEvolutionApiKey,
    'send_enabled' => filter_var(env('WHATSAPP_SEND_ENABLED', false), FILTER_VALIDATE_BOOL),
    'interactive_enabled' => filter_var(env('WHATSAPP_INTERACTIVE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'typing_enabled' => filter_var(env('WHATSAPP_TYPING_ENABLED', false), FILTER_VALIDATE_BOOL),
    'typing_delay_ms' => (int) env('WHATSAPP_TYPING_DELAY_MS', 1200),
    'typing_each_chunk' => filter_var(env('WHATSAPP_TYPING_EACH_CHUNK', true), FILTER_VALIDATE_BOOL),
]);
$papiWhatsappConfig = array_merge($sharedWhatsappConfig, [
    'base_url' => $resolvedPapiBaseUrl !== '' ? $resolvedPapiBaseUrl : env('WHATSAPP_BASE_URL', ''),
    'instance' => $resolvedPapiInstance,
    'api_key' => $resolvedPapiApiKey,
    'send_enabled' => filter_var(env('WHATSAPP_PAPI_SEND_ENABLED', env('WHATSAPP_SEND_ENABLED', false)), FILTER_VALIDATE_BOOL),
    'interactive_enabled' => filter_var(env('WHATSAPP_PAPI_INTERACTIVE_ENABLED', true), FILTER_VALIDATE_BOOL),
    'typing_enabled' => filter_var(env('WHATSAPP_PAPI_TYPING_ENABLED', env('WHATSAPP_TYPING_ENABLED', false)), FILTER_VALIDATE_BOOL),
    'typing_delay_ms' => (int) env('WHATSAPP_PAPI_TYPING_DELAY_MS', 0),
    'typing_each_chunk' => filter_var(env('WHATSAPP_PAPI_TYPING_EACH_CHUNK', env('WHATSAPP_TYPING_EACH_CHUNK', true)), FILTER_VALIDATE_BOOL),
    'validate_number' => filter_var(env('WHATSAPP_PAPI_VALIDATE_NUMBER', true), FILTER_VALIDATE_BOOL),
]);
$activeWhatsappConfig = $defaultWhatsappProvider === 'papi' ? $papiWhatsappConfig : $evolutionWhatsappConfig;

return [
    'log' => [
        'channel' => env('LOG_CHANNEL', 'file'),
        'level' => env('LOG_LEVEL', 'debug'),
        'path' => env('LOG_PATH', 'storage/logs/app.log'),
    ],
    'storage' => [
        'sessions_path' => env('SESSION_STORE_PATH', 'storage/data/sessions.json'),
    ],
    'session' => [
        'ttl_minutes' => (int) env('SESSION_TTL_MINUTES', 180),
        'admin_token' => env('SESSION_ADMIN_TOKEN', ''),
    ],
    'queue' => [
        'debounce_enabled' => filter_var(env('MESSAGE_DEBOUNCE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'debounce_window_ms' => (int) env('MESSAGE_DEBOUNCE_WINDOW_MS', 2500),
        'lock_wait_seconds' => (int) env('MESSAGE_QUEUE_LOCK_WAIT_SECONDS', 12),
    ],
    'whatsapp' => [
        'provider' => $defaultWhatsappProvider,
        'default_provider' => $defaultWhatsappProvider,
        'mixed_webhook_mode' => filter_var(env('WHATSAPP_MIXED_WEBHOOK_MODE', false), FILTER_VALIDATE_BOOL),
        'incoming_hint_query_key' => env('WHATSAPP_PROVIDER_QUERY_KEY', 'provider'),
        'profile' => $selectedWhatsappProfile,
        'providers' => [
            'evolution' => $evolutionWhatsappConfig,
            'papi' => $papiWhatsappConfig,
        ],
        'base_url' => $activeWhatsappConfig['base_url'],
        'instance' => $activeWhatsappConfig['instance'],
        'api_key' => $activeWhatsappConfig['api_key'],
        'send_enabled' => $activeWhatsappConfig['send_enabled'],
        'split_messages' => $activeWhatsappConfig['split_messages'],
        'split_max_length' => $activeWhatsappConfig['split_max_length'],
        'split_delay_ms' => $activeWhatsappConfig['split_delay_ms'],
        'typing_enabled' => $activeWhatsappConfig['typing_enabled'],
        'typing_delay_ms' => $activeWhatsappConfig['typing_delay_ms'],
        'typing_each_chunk' => $activeWhatsappConfig['typing_each_chunk'],
        'validate_number' => $papiWhatsappConfig['validate_number'],
    ],
    'ai' => [
        'driver' => env('AI_DRIVER', 'fallback'),
        'provider' => env('AI_PROVIDER', ''),
        'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),
        'api_key' => env('AI_API_KEY', ''),
        'model' => env('AI_MODEL', ''),
        'transcription_model' => env('AI_TRANSCRIPTION_MODEL', 'gpt-4o-mini-transcribe'),
        'transcription_language' => env('AI_TRANSCRIPTION_LANGUAGE', 'pt'),
        'audio_debug_save' => filter_var(env('AI_AUDIO_DEBUG_SAVE', false), FILTER_VALIDATE_BOOL),
        'audio_debug_path' => env('AI_AUDIO_DEBUG_PATH', 'storage/audio-debug'),
    ],
    'agenda' => [
        'base_url' => env('AGENDA_BASE_URL', ''),
        'empresa' => env('AGENDA_EMPRESA', '2'),
        'mock' => filter_var(env('AGENDA_MOCK', true), FILTER_VALIDATE_BOOL),
    ],
];


