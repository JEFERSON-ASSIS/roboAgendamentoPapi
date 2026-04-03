<?php

namespace App\Infrastructure\Persistence;

class NullIntegrationLogRepository implements IntegrationLogRepositoryInterface
{
    public function log(array $data): void
    {
    }

    public function findRecent(int $limit = 20, ?string $service = null): array
    {
        return [];
    }

    public function deleteByPhone(string $phone): int
    {
        return 0;
    }

    public function deleteByCpf(string $cpf): int
    {
        return 0;
    }
}
