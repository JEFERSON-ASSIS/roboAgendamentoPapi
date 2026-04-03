<?php

namespace App\DTO;

class FlowRecoveryDecisionDTO
{
    public function __construct(
        public readonly string $action = 'continue',
        public readonly ?string $targetIntent = null,
        public readonly ?string $replyMessage = null,
        public readonly float $confidence = 0.0,
        public readonly string $source = 'none'
    ) {
    }
}
