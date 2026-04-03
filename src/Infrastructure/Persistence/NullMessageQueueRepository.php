<?php

namespace App\Infrastructure\Persistence;

use App\DTO\IncomingMessageDTO;

class NullMessageQueueRepository implements MessageQueueRepositoryInterface
{
    public function enqueue(IncomingMessageDTO $message): int
    {
        return 0;
    }

    public function hasRecentExternalMessageId(string $provider, string $phone, string $externalMessageId): bool
    {
        return false;
    }

    public function acquireConversationLock(string $provider, string $phone, int $timeoutSeconds): bool
    {
        return true;
    }

    public function releaseConversationLock(string $provider, string $phone): void
    {
    }

    public function findPendingByConversation(string $provider, string $phone): array
    {
        return [];
    }

    public function markProcessed(array $ids): void
    {
    }

    public function deleteByPhone(string $phone, ?string $provider = null): int
    {
        return 0;
    }
}
