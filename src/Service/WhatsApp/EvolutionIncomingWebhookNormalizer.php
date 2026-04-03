<?php

namespace App\Service\WhatsApp;

use App\DTO\IncomingMessageDTO;

class EvolutionIncomingWebhookNormalizer implements IncomingWebhookNormalizerInterface
{
    public function providerName(): string
    {
        return 'evolution';
    }

    public function normalize(array $payload, array $headers = [], array $query = []): IncomingMessageDTO
    {
        $body = $payload['body'] ?? $payload;
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $message = is_array($data['message'] ?? null) ? $data['message'] : [];
        $key = is_array($data['key'] ?? null) ? $data['key'] : [];

        $remoteJid = is_string($key['remoteJid'] ?? null) ? trim((string) $key['remoteJid']) : null;
        $messageType = $this->detectMessageType($message);

        return new IncomingMessageDTO(
            phone: preg_replace('/\D+/', '', (string) ($remoteJid ?? '')) ?: '',
            messageType: $messageType,
            message: $this->extractMessageText($message, $messageType),
            mediaUrl: $this->extractMediaUrl($message, $messageType),
            pushName: $this->normalizeString($data['pushName'] ?? $body['pushName'] ?? null),
            payload: $payload,
            provider: $this->providerName(),
            remoteJid: $remoteJid,
            instanceId: $this->normalizeString($payload['instance'] ?? $body['instance'] ?? $data['instance'] ?? null),
            externalMessageId: $this->normalizeString($key['id'] ?? $data['messageId'] ?? $body['messageId'] ?? null),
            interactivePayload: $this->extractInteractivePayload($message)
        );
    }

    private function detectMessageType(array $message): string
    {
        $types = [
            'conversation' => 'text',
            'extendedTextMessage' => 'text',
            'reactionMessage' => 'reaction',
            'encReactionMessage' => 'reaction',
            'audioMessage' => 'audio',
            'imageMessage' => 'image',
            'videoMessage' => 'video',
            'documentMessage' => 'document',
            'stickerMessage' => 'sticker',
            'buttonsResponseMessage' => 'interactive',
            'templateButtonReplyMessage' => 'interactive',
            'interactiveResponseMessage' => 'interactive',
            'listResponseMessage' => 'interactive',
        ];

        foreach ($types as $key => $type) {
            if (array_key_exists($key, $message)) {
                return $type;
            }
        }

        return 'unknown';
    }

    private function extractMessageText(array $message, string $messageType): ?string
    {
        return match ($messageType) {
            'text' => $message['conversation']
                ?? $message['extendedTextMessage']['text']
                ?? null,
            'reaction' => $message['reactionMessage']['text']
                ?? $message['reactionMessage']['emoji']
                ?? $message['encReactionMessage']['text']
                ?? $message['encReactionMessage']['emoji']
                ?? null,
            'interactive' => $message['buttonsResponseMessage']['selectedDisplayText']
                ?? $message['templateButtonReplyMessage']['selectedDisplayText']
                ?? $message['interactiveResponseMessage']['nativeFlowResponseMessage']['paramsJson']
                ?? $message['listResponseMessage']['title']
                ?? null,
            default => null,
        };
    }

    private function extractMediaUrl(array $message, string $messageType): ?string
    {
        return match ($messageType) {
            'audio' => $message['mediaUrl']
                ?? $message['audioMessage']['mediaUrl']
                ?? $message['audioMessage']['url']
                ?? null,
            'image' => $message['mediaUrl']
                ?? $message['imageMessage']['mediaUrl']
                ?? $message['imageMessage']['url']
                ?? null,
            'video' => $message['mediaUrl']
                ?? $message['videoMessage']['mediaUrl']
                ?? $message['videoMessage']['url']
                ?? null,
            'document' => $message['mediaUrl']
                ?? $message['documentMessage']['mediaUrl']
                ?? $message['documentMessage']['url']
                ?? null,
            default => null,
        };
    }

    private function extractInteractivePayload(array $message): array
    {
        $candidates = [
            $message['buttonsResponseMessage'] ?? null,
            $message['templateButtonReplyMessage'] ?? null,
            $message['interactiveResponseMessage'] ?? null,
            $message['listResponseMessage'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    private function normalizeString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
