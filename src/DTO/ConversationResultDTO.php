<?php

namespace App\DTO;

class ConversationResultDTO
{
    public function __construct(
        public readonly string $reply,
        public readonly SessionDTO $session,
        public readonly string $intent,
        public readonly array $entities = [],
        public readonly array $toolCalls = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'reply' => $this->reply,
            'intent' => $this->intent,
            'entities' => $this->entities,
            'tool_calls' => $this->toolCalls,
            'session' => $this->session->toArray(),
        ];
    }
}