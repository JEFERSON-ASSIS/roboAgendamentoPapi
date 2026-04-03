<?php

namespace App\DTO;

class QueuedMessageBatchDTO
{
    public function __construct(
        public readonly IncomingMessageDTO $message,
        public readonly array $queueIds = [],
        public readonly int $parts = 1,
        public readonly string $status = 'processed'
    ) {
    }
}
