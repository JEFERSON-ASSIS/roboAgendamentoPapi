<?php

namespace App\Core;

class Request
{
    public function __construct(
        private readonly string $method,
        private readonly array $headers,
        private readonly array $query,
        private readonly array $request,
        private readonly array $server,
        private readonly string $rawBody,
        private readonly mixed $json
    ) {
    }

    public static function capture(): self
    {
        $rawBody = file_get_contents('php://input') ?: '';
        $decoded = json_decode($rawBody, true);

        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            function_exists('getallheaders') ? (getallheaders() ?: []) : [],
            $_GET,
            $_POST,
            $_SERVER,
            $rawBody,
            json_last_error() === JSON_ERROR_NONE ? $decoded : null
        );
    }

    public function method(): string
    {
        return strtoupper($this->method);
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function query(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    public function input(string $key = null, mixed $default = null): mixed
    {
        $payload = is_array($this->json) ? $this->json : $this->request;

        if ($key === null) {
            return $payload;
        }

        return $payload[$key] ?? $default;
    }

    public function json(): mixed
    {
        return $this->json;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function server(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->server;
        }

        return $this->server[$key] ?? $default;
    }
}
