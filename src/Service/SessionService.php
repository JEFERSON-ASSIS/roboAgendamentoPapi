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

    public function getOrCreate(string $phone, string $provider = 'evolution'): SessionDTO
    {
        return $this->repository->findByPhone($phone, $provider) ?? SessionDTO::createEmpty($phone, $provider);
    }

    public function save(SessionDTO $session): SessionDTO
    {
        return $this->repository->save($session);
    }

    public function reset(string $phone, string $provider = 'evolution'): SessionDTO
    {
        return $this->save(SessionDTO::createEmpty($phone, $provider));
    }

    public function forget(string $phone, ?string $provider = null): int
    {
        return $this->repository->deleteByPhone($phone, $provider);
    }
}
