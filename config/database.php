<?php

$requestedProfile = strtolower((string) env('DB_PROFILE', 'auto'));
$appEnv = strtolower((string) env('APP_ENV', 'local'));
$httpHost = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
$serverAddr = strtolower(trim((string) ($_SERVER['SERVER_ADDR'] ?? '')));
$remoteAddr = strtolower(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')));

$isLocalHost = $httpHost === 'localhost'
    || $httpHost === '127.0.0.1'
    || $httpHost === '::1'
    || $httpHost === '[::1]'
    || str_starts_with($httpHost, 'localhost:')
    || str_starts_with($httpHost, '127.0.0.1:')
    || str_starts_with($httpHost, '[::1]:');

$isLocalAddress = in_array($serverAddr, ['127.0.0.1', '::1'], true)
    || in_array($remoteAddr, ['127.0.0.1', '::1'], true);

$isLocalCli = PHP_SAPI === 'cli' && DIRECTORY_SEPARATOR === '\\';

$resolvedProfile = match ($requestedProfile) {
    'local', 'server' => $requestedProfile,
    default => ($isLocalHost || $isLocalAddress || $isLocalCli || in_array($appEnv, ['local', 'development', 'dev', 'test'], true))
        ? 'local'
        : 'server',
};

$rawEnv = static function (string $key): mixed {
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }

    if (array_key_exists($key, $_SERVER)) {
        return $_SERVER[$key];
    }

    $value = getenv($key);

    return $value === false ? null : $value;
};

$dbEnv = static function (string $suffix, mixed $default = null) use ($resolvedProfile, $rawEnv): mixed {
    $profileValue = $rawEnv('DB_' . strtoupper($resolvedProfile) . '_' . $suffix);

    if ($profileValue !== null) {
        return $profileValue;
    }

    $legacyValue = $rawEnv('DB_' . $suffix);

    if ($legacyValue !== null) {
        return $legacyValue;
    }

    return $default;
};

return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'profile' => $resolvedProfile,
    'host' => $dbEnv('HOST', '127.0.0.1'),
    'port' => $dbEnv('PORT', $resolvedProfile === 'local' ? '3307' : '3306'),
    'database' => $dbEnv('DATABASE', 'robo_agendamento'),
    'username' => $dbEnv('USERNAME', $resolvedProfile === 'local' ? 'root' : 'root'),
    'password' => $dbEnv('PASSWORD', ''),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
];