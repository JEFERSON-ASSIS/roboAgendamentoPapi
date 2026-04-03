<?php

$selectedWhatsappProfile = strtolower(trim((string) env('WHATSAPP_PROFILE', '')));
$profileSuffix = preg_replace('/[^a-z0-9]+/', '_', $selectedWhatsappProfile) ?? '';
$profileSuffix = strtoupper(trim($profileSuffix, '_'));

$resolvedWhatsappInstance = env('WHATSAPP_INSTANCE', '');
$resolvedWhatsappApiKey = env('WHATSAPP_API_KEY', '');

if ($profileSuffix !== '') {
    $resolvedWhatsappInstance = env('WHATSAPP_INSTANCE_' . $profileSuffix, $resolvedWhatsappInstance);
    $resolvedWhatsappApiKey = env('WHATSAPP_API_KEY_' . $profileSuffix, $resolvedWhatsappApiKey);
}

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
        'provider' => env('WHATSAPP_PROVIDER', 'evolution'),
        'profile' => $selectedWhatsappProfile,
        'base_url' => env('WHATSAPP_BASE_URL', ''),
        'instance' => $resolvedWhatsappInstance,
        'api_key' => $resolvedWhatsappApiKey,
        'send_enabled' => filter_var(env('WHATSAPP_SEND_ENABLED', false), FILTER_VALIDATE_BOOL),
        'split_messages' => filter_var(env('WHATSAPP_SPLIT_MESSAGES', true), FILTER_VALIDATE_BOOL),
        'split_max_length' => (int) env('WHATSAPP_SPLIT_MAX_LENGTH', 700),
        'split_delay_ms' => (int) env('WHATSAPP_SPLIT_DELAY_MS', 400),
        'typing_enabled' => filter_var(env('WHATSAPP_TYPING_ENABLED', false), FILTER_VALIDATE_BOOL),
        'typing_delay_ms' => (int) env('WHATSAPP_TYPING_DELAY_MS', 1200),
        'typing_each_chunk' => filter_var(env('WHATSAPP_TYPING_EACH_CHUNK', true), FILTER_VALIDATE_BOOL),
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