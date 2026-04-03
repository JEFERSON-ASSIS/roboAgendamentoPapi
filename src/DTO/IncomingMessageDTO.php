<?php

namespace App\DTO;

class IncomingMessageDTO
{
    public function __construct(
        public readonly string $phone,
        public readonly string $messageType,
        public readonly ?string $message,
        public readonly ?string $mediaUrl,
        public readonly ?string $pushName,
        public readonly array $payload
    ) {
    }

    public function toArray(): array
    {
        return [
            'phone' => $this->phone,
            'message_type' => $this->messageType,
            'message' => $this->message,
            'media_url' => $this->mediaUrl,
            'push_name' => $this->pushName,
            'payload' => $this->payload,
        ];
    }
}
