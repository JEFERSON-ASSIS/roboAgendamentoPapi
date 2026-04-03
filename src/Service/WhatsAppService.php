<?php

namespace App\Service;

use App\Infrastructure\Http\HttpClient;
use RuntimeException;

class WhatsAppService
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $baseUrl,
        private readonly string $instance,
        private readonly string $apiKey,
        private readonly bool $sendEnabled = false,
        private readonly bool $splitMessages = true,
        private readonly int $maxChunkLength = 700,
        private readonly int $splitDelayMs = 400,
        private readonly bool $typingEnabled = false,
        private readonly int $typingDelayMs = 1200,
        private readonly bool $typingEachChunk = true
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->sendEnabled && $this->baseUrl !== '' && $this->instance !== '' && $this->apiKey !== '';
    }

    public function sendText(string $phone, string $text): array
    {
        if (!$this->isEnabled()) {
            return ['status' => 'skipped', 'reason' => 'whatsapp_send_disabled'];
        }

        if ($phone === '' || $text === '') {
            throw new RuntimeException('Telefone ou texto invalido para envio via WhatsApp.');
        }

        $chunks = $this->splitMessages ? $this->splitText($text) : [trim($this->normalizeText($text))];
        $chunks = array_values(array_filter($chunks, static fn (string $chunk): bool => trim($chunk) !== ''));

        if ($chunks === []) {
            throw new RuntimeException('Nenhum conteudo valido foi gerado para envio via WhatsApp.');
        }

        $responses = [];
        $presenceResponses = [];
        $totalChunks = count($chunks);

        foreach ($chunks as $index => $chunk) {
            if ($this->shouldSendTypingForChunk($index)) {
                $presenceResponses[] = $this->sendTypingPresence($phone);
            }

            $responses[] = $this->sendSingleText($phone, $chunk);

            if ($index < $totalChunks - 1 && $this->splitDelayMs > 0) {
                usleep($this->splitDelayMs * 1000);
            }
        }

        return [
            'status' => $totalChunks > 1 ? 'multi_sent' : 'sent',
            'parts' => $totalChunks,
            'chunks' => $chunks,
            'presence' => $presenceResponses,
            'responses' => $responses,
        ];
    }

    private function shouldSendTypingForChunk(int $chunkIndex): bool
    {
        if (!$this->typingEnabled) {
            return false;
        }

        return $this->typingEachChunk || $chunkIndex === 0;
    }

    private function sendTypingPresence(string $phone): array
    {
        if ($this->typingDelayMs <= 0) {
            return ['status' => 'skipped', 'reason' => 'typing_delay_disabled'];
        }

        return $this->httpClient->post(
            rtrim($this->baseUrl, '/') . '/chat/sendPresence/' . rawurlencode($this->instance),
            [
                'number' => $phone,
                'delay' => $this->typingDelayMs,
                'presence' => 'composing',
            ],
            [
                'apikey' => $this->apiKey,
            ],
            30
        );
    }

    private function sendSingleText(string $phone, string $text): array
    {
        $response = $this->httpClient->post(
            rtrim($this->baseUrl, '/') . '/message/sendText/' . rawurlencode($this->instance),
            [
                'number' => $phone,
                'text' => $text,
            ],
            [
                'apikey' => $this->apiKey,
            ],
            30
        );

        $status = (int) ($response['status'] ?? 0);

        if ($status < 200 || $status >= 300) {
            $body = trim((string) ($response['body'] ?? ''));
            $details = $body !== '' ? ' Resposta: ' . $body : '';

            throw new RuntimeException('Falha no envio via WhatsApp. HTTP ' . $status . '.' . $details);
        }

        return $response;
    }

    private function splitText(string $text): array
    {
        $text = trim($this->normalizeText($text));

        if ($text === '') {
            return [];
        }

        $blocks = preg_split('/\n{2,}/', $text) ?: [$text];
        $chunks = [];

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            foreach ($this->splitLargeBlock($block) as $piece) {
                $piece = trim($piece);

                if ($piece !== '') {
                    $chunks[] = $piece;
                }
            }
        }

        return $chunks;
    }

    private function splitLargeBlock(string $block): array
    {
        if (mb_strlen($block) <= $this->maxChunkLength) {
            return [$block];
        }

        $lines = preg_split('/\n+/', $block) ?: [$block];
        $chunks = [];
        $current = '';

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (mb_strlen($line) > $this->maxChunkLength) {
                foreach ($this->splitByWords($line) as $wordChunk) {
                    if ($current !== '') {
                        $chunks[] = $current;
                        $current = '';
                    }

                    $chunks[] = $wordChunk;
                }

                continue;
            }

            $candidate = $current === '' ? $line : $current . "\n" . $line;

            if (mb_strlen($candidate) <= $this->maxChunkLength) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
            }

            $current = $line;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function splitByWords(string $text): array
    {
        $words = preg_split('/\s+/', $text) ?: [$text];
        $chunks = [];
        $current = '';

        foreach ($words as $word) {
            $word = trim($word);

            if ($word === '') {
                continue;
            }

            if (mb_strlen($word) > $this->maxChunkLength) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }

                $offset = 0;
                while ($offset < mb_strlen($word)) {
                    $chunks[] = mb_substr($word, $offset, $this->maxChunkLength);
                    $offset += $this->maxChunkLength;
                }

                continue;
            }

            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if (mb_strlen($candidate) <= $this->maxChunkLength) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
            }

            $current = $word;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function normalizeText(string $text): string
    {
        $text = $this->repairEncoding($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/(?:\\\\n|\/n)/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function repairEncoding(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        if (!str_contains($text, 'Ãƒ') && !str_contains($text, 'Ã‚')) {
            return $text;
        }

        $decoded = @utf8_decode($text);
        $reencoded = is_string($decoded) ? @utf8_encode($decoded) : false;

        if (is_string($reencoded) && $reencoded !== '' && !str_contains($reencoded, 'Ãƒ') && !str_contains($reencoded, 'Ã‚')) {
            return $reencoded;
        }

        return $text;
    }
}


