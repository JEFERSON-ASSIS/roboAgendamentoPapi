<?php

namespace App\Service;

use App\DTO\IncomingMessageDTO;

class MessageNormalizer
{
    public function normalize(array $payload): IncomingMessageDTO
    {
        $body = $payload['body'] ?? $payload;
        $data = $body['data'] ?? [];
        $message = $data['message'] ?? [];
        $key = $data['key'] ?? [];

        $phone = preg_replace('/\D+/', '', (string) ($key['remoteJid'] ?? '')) ?: '';
        $pushName = $data['pushName'] ?? $body['pushName'] ?? null;
        $messageType = $this->detectMessageType($message);
        $messageText = $this->extractMessageText($message, $messageType);
        $mediaUrl = $this->extractMediaUrl($message, $messageType);

        return new IncomingMessageDTO(
            phone: $phone,
            messageType: $messageType,
            message: $messageText,
            mediaUrl: $mediaUrl,
            pushName: is_string($pushName) ? trim($pushName) : null,
            payload: $payload
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
}
