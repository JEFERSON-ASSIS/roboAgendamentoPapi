<?php

namespace App\DTO;

class AiAnalysisDTO
{
    public function __construct(
        public readonly string $intent,
        public readonly array $entities = [],
        public readonly float $confidence = 0.0,
        public readonly string $source = 'fallback'
    ) {
    }
}