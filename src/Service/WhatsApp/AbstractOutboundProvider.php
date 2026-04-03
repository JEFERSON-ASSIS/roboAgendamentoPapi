<?php

namespace App\Service\WhatsApp;

use App\DTO\OutgoingMessageDTO;
use App\Infrastructure\Http\HttpClient;
use RuntimeException;

abstract class AbstractOutboundProvider implements OutboundProviderInterface
{
    public function __construct(
        protected readonly HttpClient $httpClient,
        protected readonly array $config = []
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['send_enabled'] ?? false)
            && trim((string) ($this->config['base_url'] ?? '')) !== ''
            && trim((string) ($this->config['instance'] ?? '')) !== ''
            && trim((string) ($this->config['api_key'] ?? '')) !== '';
    }

    public function supports(string $messageType): bool
    {
        return $messageType === 'text';
    }

    public function send(OutgoingMessageDTO $message): array
    {
        if (!$this->isEnabled()) {
            return ['status' => 'skipped', 'reason' => 'whatsapp_send_disabled', 'provider' => $this->providerName()];
        }

        return match ($message->type) {
            'buttons' => $this->sendButtonsOrFallback($message),
            'contact' => $this->sendContactOrFallback($message),
            'text' => $this->sendTextMessage($message),
            default => throw new RuntimeException('Tipo de mensagem nao suportado pelo provider ' . $this->providerName() . '.'),
        };
    }

    protected function sendTextMessage(OutgoingMessageDTO $message): array
    {
        if (!$this->supports('text')) {
            throw new RuntimeException('Tipo de mensagem nao suportado pelo provider ' . $this->providerName() . '.');
        }

        if ($message->phone === '' || trim((string) $message->text) === '') {
            throw new RuntimeException('Telefone ou texto invalido para envio via WhatsApp.');
        }

        $chunks = $this->splitMessagesEnabled()
            ? $this->splitText((string) $message->text)
            : [trim($this->normalizeText((string) $message->text))];

        $chunks = array_values(array_filter($chunks, static fn (string $chunk): bool => trim($chunk) !== ''));

        if ($chunks === []) {
            throw new RuntimeException('Nenhum conteudo valido foi gerado para envio via WhatsApp.');
        }

        $responses = [];
        $presenceResponses = [];
        $totalChunks = count($chunks);

        foreach ($chunks as $index => $chunk) {
            if ($this->shouldSendTypingForChunk($index)) {
                $presenceResponse = $this->sendTypingPresence($message->phone);
                if ($presenceResponse !== null) {
                    $presenceResponses[] = $presenceResponse;
                }
            }

            $responses[] = $this->sendSingleText($message->phone, $chunk);

            if ($index < $totalChunks - 1 && $this->splitDelayMs() > 0) {
                usleep($this->splitDelayMs() * 1000);
            }
        }

        return [
            'status' => $totalChunks > 1 ? 'multi_sent' : 'sent',
            'provider' => $this->providerName(),
            'parts' => $totalChunks,
            'chunks' => $chunks,
            'presence' => $presenceResponses,
            'responses' => $responses,
        ];
    }

    protected function sendButtonsMessage(OutgoingMessageDTO $message): array
    {
        throw new RuntimeException('Tipo de mensagem nao suportado pelo provider ' . $this->providerName() . '.');
    }

    protected function sendContactMessage(OutgoingMessageDTO $message): array
    {
        throw new RuntimeException('Tipo de mensagem nao suportado pelo provider ' . $this->providerName() . '.');
    }

    abstract protected function sendSingleText(string $phone, string $text): array;

    protected function sendButtonsOrFallback(OutgoingMessageDTO $message): array
    {
        if ($this->supports('buttons')) {
            return $this->sendButtonsMessage($message);
        }

        $fallbackMessage = new OutgoingMessageDTO(
            phone: $message->phone,
            type: 'text',
            text: $this->buttonsToTextFallback($message),
            meta: array_merge($message->meta, ['fallback_from' => 'buttons'])
        );

        $result = $this->sendTextMessage($fallbackMessage);
        $result['fallback_from'] = 'buttons';

        return $result;
    }

    protected function sendContactOrFallback(OutgoingMessageDTO $message): array
    {
        if ($this->supports('contact')) {
            return $this->sendContactMessage($message);
        }

        $fallbackMessage = new OutgoingMessageDTO(
            phone: $message->phone,
            type: 'text',
            text: $this->contactToTextFallback($message),
            meta: array_merge($message->meta, ['fallback_from' => 'contact'])
        );

        $result = $this->sendTextMessage($fallbackMessage);
        $result['fallback_from'] = 'contact';

        return $result;
    }

    protected function sendTypingPresence(string $phone): ?array
    {
        return null;
    }

    protected function shouldSendTypingForChunk(int $chunkIndex): bool
    {
        if (!$this->typingEnabled()) {
            return false;
        }

        return $this->typingEachChunk() || $chunkIndex === 0;
    }

    protected function splitMessagesEnabled(): bool
    {
        return (bool) ($this->config['split_messages'] ?? true);
    }

    protected function maxChunkLength(): int
    {
        return max(1, (int) ($this->config['split_max_length'] ?? 700));
    }

    protected function splitDelayMs(): int
    {
        return max(0, (int) ($this->config['split_delay_ms'] ?? 400));
    }

    protected function typingEnabled(): bool
    {
        return (bool) ($this->config['typing_enabled'] ?? false);
    }

    protected function typingDelayMs(): int
    {
        return max(0, (int) ($this->config['typing_delay_ms'] ?? 1200));
    }

    protected function typingEachChunk(): bool
    {
        return (bool) ($this->config['typing_each_chunk'] ?? true);
    }

    protected function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? ''), '/');
    }

    protected function instanceId(): string
    {
        return trim((string) ($this->config['instance'] ?? ''));
    }

    protected function apiKey(): string
    {
        return trim((string) ($this->config['api_key'] ?? ''));
    }

    protected function normalizeText(string $text): string
    {
        $text = $this->repairEncoding($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/(?:\\\\n|\/n)/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    protected function buttonsToTextFallback(OutgoingMessageDTO $message): string
    {
        $parts = [];
        $text = trim($this->normalizeText((string) $message->text));

        if ($text !== '') {
            $parts[] = $text;
        }

        $buttonLines = [];
        foreach (array_values($message->buttons) as $index => $button) {
            if (!is_array($button)) {
                continue;
            }

            $label = trim((string) ($button['displayText'] ?? ''));
            if ($label === '') {
                continue;
            }

            $type = strtolower(trim((string) ($button['type'] ?? '')));
            $prefix = ($index + 1) . ' - ';
            $suffix = match ($type) {
                'cta_url' => $this->appendDetail((string) ($button['url'] ?? '')),
                'cta_call' => $this->appendDetail((string) ($button['phoneNumber'] ?? '')),
                'cta_copy' => $this->appendDetail((string) ($button['copyCode'] ?? '')),
                default => '',
            };

            $buttonLines[] = $prefix . $label . $suffix;
        }

        if ($buttonLines !== []) {
            $parts[] = "Opcoes:\n" . implode("\n", $buttonLines);
        }

        $footer = trim($this->normalizeText((string) $message->footer));
        if ($footer !== '') {
            $parts[] = $footer;
        }

        return trim(implode("\n\n", array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }

    protected function contactToTextFallback(OutgoingMessageDTO $message): string
    {
        $parts = [];
        $text = trim($this->normalizeText((string) $message->text));

        if ($text !== '') {
            $parts[] = $text;
        }

        $contactName = trim($this->normalizeText((string) $message->contactName));
        $contactPhone = trim($this->normalizeText((string) $message->contactPhone));

        if ($contactName !== '' || $contactPhone !== '') {
            $contactLines = [];

            if ($contactName !== '') {
                $contactLines[] = 'Contato: ' . $contactName;
            }

            if ($contactPhone !== '') {
                $contactLines[] = 'Telefone: ' . $contactPhone;
            }

            $parts[] = implode("\n", $contactLines);
        }

        return trim(implode("\n\n", array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }

    protected function splitText(string $text): array
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
        if (mb_strlen($block) <= $this->maxChunkLength()) {
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

            if (mb_strlen($line) > $this->maxChunkLength()) {
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

            if (mb_strlen($candidate) <= $this->maxChunkLength()) {
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

            if (mb_strlen($word) > $this->maxChunkLength()) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }

                $offset = 0;
                while ($offset < mb_strlen($word)) {
                    $chunks[] = mb_substr($word, $offset, $this->maxChunkLength());
                    $offset += $this->maxChunkLength();
                }

                continue;
            }

            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if (mb_strlen($candidate) <= $this->maxChunkLength()) {
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

    private function repairEncoding(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        if (!$this->looksLikeMojibake($text)) {
            return $text;
        }

        $best = $text;
        $candidates = [$text];

        for ($i = 0; $i < 3; $i++) {
            $last = end($candidates);
            if (!is_string($last) || $last === '') {
                break;
            }

            $decoded = @utf8_decode($last);
            if (is_string($decoded) && $decoded !== '') {
                $candidates[] = $decoded;
            }

            if (function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($last, 'UTF-8', 'Windows-1252');
                if (is_string($converted) && $converted !== '') {
                    $candidates[] = $converted;
                }

                $converted = @mb_convert_encoding($last, 'UTF-8', 'ISO-8859-1');
                if (is_string($converted) && $converted !== '') {
                    $candidates[] = $converted;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            if (!$this->looksLikeMojibake($candidate)) {
                return $candidate;
            }

            if (substr_count($candidate, 'Ã') < substr_count($best, 'Ã')) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function looksLikeMojibake(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
            return true;
        }

        return preg_match(
            '/(?:\x{00C3}[\x{0080}-\x{00FF}]|\x{00C2}[\x{0080}-\x{00FF}]|\x{00E2}\x{20AC}[\x{0080}-\x{00FF}])/u',
            $text
        ) === 1;
    }

    private function appendDetail(string $detail): string
    {
        $detail = trim($this->normalizeText($detail));

        return $detail !== '' ? ' - ' . $detail : '';
    }
}





