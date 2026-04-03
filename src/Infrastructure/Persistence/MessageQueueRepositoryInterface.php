<?php

namespace App\Infrastructure\Persistence;

use App\DTO\IncomingMessageDTO;

interface MessageQueueRepositoryInterface
{
    public function enqueue(IncomingMessageDTO $message): int;

    public function acquirePhoneLock(string $phone, int $timeoutSeconds): bool;

    public function releasePhoneLock(string $phone): void;

    public function findPendingByPhone(string $phone): array;

    public function markProcessed(array $ids): void;

    public function deleteByPhone(string $phone): int;
}
