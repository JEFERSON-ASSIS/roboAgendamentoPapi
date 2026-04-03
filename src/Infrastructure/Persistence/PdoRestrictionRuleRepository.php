<?php

namespace App\Infrastructure\Persistence;

use PDO;

class PdoRestrictionRuleRepository implements RestrictionRuleRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function findActive(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, name, match_type, trigger_value, response_message, is_active, priority, created_at, updated_at
             FROM restriction_rules
             WHERE is_active = 1
             ORDER BY priority ASC, id ASC'
        );

        return $statement !== false ? ($statement->fetchAll() ?: []) : [];
    }
}
