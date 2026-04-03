<?php

namespace App\Infrastructure\Persistence;

class NullMessageLogRepository implements MessageLogRepositoryInterface
{
    public function log(array $data): void
    {
    }

    public function deleteById(int $id): int
    {
        return 0;
    }

    public function deleteByPhone(string $phone, ?string $provider = null): int
    {
        return 0;
    }

    public function findConversations(int $limit = 50, ?string $search = null): array
    {
        return [];
    }

    public function findMessagesByPhone(string $phone, int $limit = 200, ?string $provider = null): array
    {
        return [];
    }
}
