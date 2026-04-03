<?php

namespace App\Core;

class Response
{
    public function __construct(
        private readonly mixed $data,
        private readonly int $status = 200,
        private readonly array $headers = ['Content-Type' => 'application/json; charset=utf-8']
    ) {
    }

    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self($data, $status, $headers + ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if (is_array($this->data) || is_object($this->data)) {
            echo json_encode(
                $this->data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
            );
            return;
        }

        echo (string) $this->data;
    }
}
