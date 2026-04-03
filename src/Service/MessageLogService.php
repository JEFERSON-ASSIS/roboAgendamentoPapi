<?php

namespace App\Service;

use App\DTO\ConversationResultDTO;
use App\DTO\IncomingMessageDTO;
use App\Infrastructure\Persistence\MessageLogRepositoryInterface;

class MessageLogService
{
    public function __construct(
        private readonly MessageLogRepositoryInterface $repository
    ) {
    }

    public function logIncoming(IncomingMessageDTO $message): void
    {
        $payload = $message->payload;
        $payload['_meta'] = array_merge(
            is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [],
            [
                'provider' => $message->provider,
                'remote_jid' => $message->remoteJid,
                'instance_id' => $message->instanceId,
                'external_message_id' => $message->externalMessageId,
            ]
        );

        $this->repository->log([
            'provider' => $message->provider,
            'phone' => $message->phone,
            'direction' => 'in',
            'message_type' => $message->messageType,
            'raw_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'normalized_text' => $message->message,
        ]);
    }

    public function logOutgoing(IncomingMessageDTO $incoming, ConversationResultDTO $result, array $metadata = []): void
    {
        $payload = $result->toArray();
        $payload['_meta'] = [
            'provider' => $incoming->provider,
            'remote_jid' => $incoming->remoteJid,
            'instance_id' => $incoming->instanceId,
        ];

        if ($metadata !== []) {
            $payload = array_merge($payload, $metadata);
        }

        $this->repository->log([
            'provider' => $incoming->provider,
            'phone' => $incoming->phone,
            'direction' => 'out',
            'message_type' => $result->replyPayload()->type,
            'raw_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'normalized_text' => $result->reply,
        ]);
    }


    public function logManualOutgoing(string $phone, string $text, array $metadata = []): void
    {
        $payload = $metadata;
        $payload['_meta'] = array_merge(
            is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [],
            ['provider' => (string) ($metadata['provider'] ?? config('services.whatsapp.default_provider', config('services.whatsapp.provider', 'evolution')))]
        );

        $this->repository->log([
            'provider' => (string) ($metadata['provider'] ?? config('services.whatsapp.default_provider', config('services.whatsapp.provider', 'evolution'))),
            'phone' => $phone,
            'direction' => 'out',
            'message_type' => 'text',
            'raw_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'normalized_text' => $text,
        ]);
    }

    public function deleteById(int $id): int
    {
        return $this->repository->deleteById($id);
    }

    public function deleteByPhone(string $phone, ?string $provider = null): int
    {
        return $this->repository->deleteByPhone($phone, $provider);
    }

    public function findConversations(int $limit = 50, ?string $search = null): array
    {
        return $this->repository->findConversations($limit, $search);
    }

    public function findMessagesByPhone(string $phone, int $limit = 200, ?string $provider = null): array
    {
        return $this->repository->findMessagesByPhone($phone, $limit, $provider);
    }
}

