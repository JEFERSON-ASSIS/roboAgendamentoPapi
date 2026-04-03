<?php

namespace App\Infrastructure\Persistence;

use App\DTO\SessionDTO;

interface SessionRepositoryInterface
{
    public function findByPhone(string $phone, ?string $provider = null): ?SessionDTO;

    public function save(SessionDTO $session): SessionDTO;

    public function deleteByPhone(string $phone, ?string $provider = null): int;
}
