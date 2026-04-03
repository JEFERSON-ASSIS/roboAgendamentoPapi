<?php

namespace App\Infrastructure\Http;

use RuntimeException;

class HttpClient
{
    public function get(string $url, array $headers = [], int $timeout = 30): array
    {
        return $this->request('GET', $url, [], $headers, $timeout);
    }

    public function post(string $url, array $payload = [], array $headers = [], int $timeout = 30): array
    {
        return $this->request('POST', $url, $payload, $headers, $timeout);
    }

    public function request(
        string $method,
        string $url,
        array $payload = [],
        array $headers = [],
        int $timeout = 30
    ): array {
        $ch = curl_init();

        if ($ch === false) {
            throw new RuntimeException('Falha ao inicializar cURL.');
        }

        $formattedHeaders = [];
        $responseHeaders = [];

        foreach ($headers as $name => $value) {
            $formattedHeaders[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $formattedHeaders,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                $trimmed = trim($headerLine);

                if ($trimmed === '' || !str_contains($trimmed, ':')) {
                    return $length;
                }

                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[trim($name)] = trim($value);

                return $length;
            },
        ];

        if (strtoupper($method) !== 'GET') {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json; charset=utf-8';
        }

        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Erro na requisicao HTTP: ' . $error);
        }

        $body = $this->normalizeBodyEncoding((string) $body, $responseHeaders);
        $decoded = json_decode($body, true);

        return [
            'status' => $status,
            'body' => $body,
            'json' => json_last_error() === JSON_ERROR_NONE ? $decoded : null,
            'headers' => $responseHeaders,
        ];
    }

    private function normalizeBodyEncoding(string $body, array $headers): string
    {
        if ($body === '') {
            return $body;
        }

        if (function_exists('mb_check_encoding') && mb_check_encoding($body, 'UTF-8')) {
            return $body;
        }

        $contentType = strtolower((string) ($headers['Content-Type'] ?? $headers['content-type'] ?? ''));
        $charset = null;

        if ($contentType !== '' && preg_match('/charset=([^;]+)/i', $contentType, $matches) === 1) {
            $charset = strtoupper(trim($matches[1], " \t\n\r\0\x0B\"'"));
        }

        $encodings = array_filter([
            $charset,
            'UTF-8',
            'ISO-8859-1',
            'Windows-1252',
        ]);

        $converted = @mb_convert_encoding($body, 'UTF-8', implode(', ', array_unique($encodings)));

        return is_string($converted) && $converted !== '' ? $converted : $body;
    }
}
