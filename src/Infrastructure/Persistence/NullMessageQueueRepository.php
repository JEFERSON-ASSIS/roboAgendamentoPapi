<?php

namespace App\Infrastructure\Persistence;

use App\DTO\IncomingMessageDTO;

class NullMessageQueueRepository implements MessageQueueRepositoryInterface
{
    public function enqueue(IncomingMessageDTO $message): int
    {
        return 0;
    }

    public function acquirePhoneLock(string $phone, int $timeoutSeconds): bool
    {
        return true;
    }

    public function releasePhoneLock(string $phone): void
    {
    }

    public function findPendingByPhone(string $phone): array
    {
        return [];
    }

    public function markProcessed(array $ids): void
    {
    }

    public function deleteByPhone(string $phone): int
    {
        return 0;
    }
}
