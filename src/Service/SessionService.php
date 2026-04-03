<?php

namespace App\Service;

use App\DTO\SessionDTO;
use App\Infrastructure\Persistence\SessionRepositoryInterface;

class SessionService
{
    public function __construct(
        private readonly SessionRepositoryInterface $repository
    ) {
    }

    public function getOrCreate(string $phone): SessionDTO
    {
        return $this->repository->findByPhone($phone) ?? SessionDTO::createEmpty($phone);
    }

    public function save(SessionDTO $session): SessionDTO
    {
        return $this->repository->save($session);
    }

    public function reset(string $phone): SessionDTO
    {
        return $this->save(SessionDTO::createEmpty($phone));
    }

    public function forget(string $phone): int
    {
        return $this->repository->deleteByPhone($phone);
    }
}
