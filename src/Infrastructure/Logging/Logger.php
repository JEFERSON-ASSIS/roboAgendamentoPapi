<?php

namespace App\Infrastructure\Logging;

class Logger
{
    public function __construct(
        private readonly string $path
    ) {
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('DEBUG', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context = []): void
    {
        $file = base_path($this->path);
        $directory = dirname($file);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $normalizedContext = $this->normalizeValue($context);
        $encodedContext = $normalizedContext !== []
            ? json_encode($normalizedContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
            : '';

        $line = sprintf(
            '[%s] %s: %s %s%s',
            date('Y-m-d H:i:s'),
            $level,
            $this->normalizeString($message),
            $encodedContext,
            PHP_EOL
        );

        file_put_contents($file, $line, FILE_APPEND);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeValue($item);
            }

            return $normalized;
        }

        if (is_string($value)) {
            return $this->normalizeString($value);
        }

        return $value;
    }

    private function normalizeString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');

        return is_string($converted) && $converted !== '' ? $converted : $value;
    }
}
