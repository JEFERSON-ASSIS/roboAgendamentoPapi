<?php

namespace App\DTO;

class ConversationResultDTO
{
    public function __construct(
        public readonly string $reply,
        public readonly SessionDTO $session,
        public readonly string $intent,
        public readonly array $entities = [],
        public readonly array $toolCalls = [],
        public readonly ?AssistantReplyDTO $replyPayload = null
    ) {
    }

    public function replyPayload(): AssistantReplyDTO
    {
        return $this->replyPayload ?? AssistantReplyDTO::text($this->reply);
    }

    public function toArray(): array
    {
        return [
            'reply' => $this->reply,
            'reply_payload' => $this->replyPayload()->toArray(),
            'intent' => $this->intent,
            'entities' => $this->entities,
            'tool_calls' => $this->toolCalls,
            'session' => $this->session->toArray(),
        ];
    }
}
