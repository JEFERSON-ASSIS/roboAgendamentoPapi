<?php

namespace App\Service;

use RuntimeException;

class EnvFileManager
{
    public function __construct(
        private readonly string $path
    ) {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function read(): array
    {
        if (!$this->exists()) {
            return [];
        }

        $lines = file($this->path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException('Nao foi possivel ler o arquivo .env.');
        }

        $values = [];

        foreach ($lines as $line) {
            $parsed = $this->parseAssignment($line);

            if ($parsed === null) {
                continue;
            }

            $values[$parsed['key']] = $parsed['value'];
        }

        return $values;
    }

    public function write(array $updates): void
    {
        $normalizedUpdates = [];

        foreach ($updates as $key => $value) {
            $normalizedKey = strtoupper(trim((string) $key));

            if ($normalizedKey === '' || preg_match('/^[A-Z0-9_]+$/', $normalizedKey) !== 1) {
                continue;
            }

            $normalizedUpdates[$normalizedKey] = $this->normalizeValue($value);
        }

        if ($normalizedUpdates === []) {
            return;
        }

        $lines = $this->exists() ? file($this->path, FILE_IGNORE_NEW_LINES) : [];

        if ($lines === false) {
            throw new RuntimeException('Nao foi possivel carregar o arquivo .env para atualizacao.');
        }

        $seenKeys = [];
        $output = [];

        foreach ($lines as $line) {
            $parsed = $this->parseAssignment($line);

            if ($parsed === null) {
                $output[] = $line;
                continue;
            }

            $key = $parsed['key'];

            if (array_key_exists($key, $normalizedUpdates)) {
                $output[] = $this->formatAssignment($key, $normalizedUpdates[$key]);
                $seenKeys[$key] = true;
                continue;
            }

            $output[] = $line;
        }

        foreach ($normalizedUpdates as $key => $value) {
            if (isset($seenKeys[$key])) {
                continue;
            }

            $output[] = $this->formatAssignment($key, $value);
        }

        $content = implode(PHP_EOL, $output);

        if ($content !== '') {
            $content .= PHP_EOL;
        }

        $result = file_put_contents($this->path, $content, LOCK_EX);

        if ($result === false) {
            throw new RuntimeException('Nao foi possivel salvar o arquivo .env.');
        }
    }

    private function parseAssignment(string $line): ?array
    {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            return null;
        }

        if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/', $line, $matches) !== 1) {
            return null;
        }

        return [
            'key' => strtoupper(trim((string) $matches[1])),
            'value' => $this->decodeValue((string) $matches[2]),
        ];
    }

    private function decodeValue(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $first = substr($value, 0, 1);
        $last = substr($value, -1);

        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $unwrapped = substr($value, 1, -1);

            if ($first === '"') {
                $unwrapped = str_replace(['\\"', '\\\\', '\\n', '\\r'], ['"', '\\', "\n", "\r"], $unwrapped);
            }

            return $unwrapped;
        }

        return $value;
    }

    private function normalizeValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    private function formatAssignment(string $key, string $value): string
    {
        if ($value === '') {
            return $key . '=';
        }

        if ($this->shouldQuote($value)) {
            $escaped = str_replace(
                ['\\', '"', "\r", "\n"],
                ['\\\\', '\\"', '\\r', '\\n'],
                $value
            );

            return $key . '="' . $escaped . '"';
        }

        return $key . '=' . $value;
    }

    private function shouldQuote(string $value): bool
    {
        return preg_match('/\s|#|"|\'|=/', $value) === 1;
    }
}
