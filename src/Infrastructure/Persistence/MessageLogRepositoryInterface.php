<?php

namespace App\Infrastructure\Persistence;

interface MessageLogRepositoryInterface
{
    public function log(array $data): void;

    public function deleteById(int $id): int;

    public function deleteByPhone(string $phone, ?string $provider = null): int;

    public function findConversations(int $limit = 50, ?string $search = null): array;

    public function findMessagesByPhone(string $phone, int $limit = 200, ?string $provider = null): array;
}
