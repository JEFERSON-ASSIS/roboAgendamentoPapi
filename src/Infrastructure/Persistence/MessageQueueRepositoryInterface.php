<?php

namespace App\Infrastructure\Persistence;

use App\DTO\IncomingMessageDTO;

interface MessageQueueRepositoryInterface
{
    public function enqueue(IncomingMessageDTO $message): int;

    public function hasRecentExternalMessageId(string $provider, string $phone, string $externalMessageId): bool;

    public function acquireConversationLock(string $provider, string $phone, int $timeoutSeconds): bool;

    public function releaseConversationLock(string $provider, string $phone): void;

    public function findPendingByConversation(string $provider, string $phone): array;

    public function markProcessed(array $ids): void;

    public function deleteByPhone(string $phone, ?string $provider = null): int;
}
