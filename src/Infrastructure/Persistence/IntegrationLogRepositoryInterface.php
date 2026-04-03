<?php

namespace App\Infrastructure\Persistence;

interface IntegrationLogRepositoryInterface
{
    public function log(array $data): void;

    public function findRecent(int $limit = 20, ?string $service = null): array;

    public function deleteByPhone(string $phone): int;

    public function deleteByCpf(string $cpf): int;
}
