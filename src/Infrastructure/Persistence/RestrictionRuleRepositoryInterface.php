<?php

namespace App\Infrastructure\Persistence;

interface RestrictionRuleRepositoryInterface
{
    public function findActive(): array;
}
