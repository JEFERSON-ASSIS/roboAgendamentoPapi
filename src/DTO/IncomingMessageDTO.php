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
        public readonly array $payload,
        public readonly string $provider = 'evolution',
        public readonly ?string $remoteJid = null,
        public readonly ?string $instanceId = null,
        public readonly ?string $externalMessageId = null,
        public readonly array $interactivePayload = []
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
            'provider' => $this->provider,
            'remote_jid' => $this->remoteJid,
            'instance_id' => $this->instanceId,
            'external_message_id' => $this->externalMessageId,
            'interactive_payload' => $this->interactivePayload,
            'payload' => $this->payload,
        ];
    }
}
