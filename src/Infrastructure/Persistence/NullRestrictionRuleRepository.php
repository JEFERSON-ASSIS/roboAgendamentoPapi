<?php

namespace App\Infrastructure\Persistence;

class NullRestrictionRuleRepository implements RestrictionRuleRepositoryInterface
{
    public function findActive(): array
    {
        return [];
    }
}
