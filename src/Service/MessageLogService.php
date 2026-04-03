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
        $this->repository->log([
            'phone' => $message->phone,
            'direction' => 'in',
            'message_type' => $message->messageType,
            'raw_payload' => json_encode($message->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'normalized_text' => $message->message,
        ]);
    }

    public function logOutgoing(IncomingMessageDTO $incoming, ConversationResultDTO $result, array $metadata = []): void
    {
        $payload = $result->toArray();

        if ($metadata !== []) {
            $payload = array_merge($payload, $metadata);
        }

        $this->repository->log([
            'phone' => $incoming->phone,
            'direction' => 'out',
            'message_type' => 'text',
            'raw_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'normalized_text' => $result->reply,
        ]);
    }


    public function logManualOutgoing(string $phone, string $text, array $metadata = []): void
    {
        $this->repository->log([
            'phone' => $phone,
            'direction' => 'out',
            'message_type' => 'text',
            'raw_payload' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'normalized_text' => $text,
        ]);
    }

    public function deleteById(int $id): int
    {
        return $this->repository->deleteById($id);
    }

    public function deleteByPhone(string $phone): int
    {
        return $this->repository->deleteByPhone($phone);
    }

    public function findConversations(int $limit = 50, ?string $search = null): array
    {
        return $this->repository->findConversations($limit, $search);
    }

    public function findMessagesByPhone(string $phone, int $limit = 200): array
    {
        return $this->repository->findMessagesByPhone($phone, $limit);
    }
}

