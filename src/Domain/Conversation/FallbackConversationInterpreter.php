<?php

namespace App\Domain\Conversation;

use App\DTO\AiAnalysisDTO;
use App\DTO\IncomingMessageDTO;
use App\DTO\SessionDTO;

class FallbackConversationInterpreter implements ConversationInterpreterInterface
{
    public function __construct(
        private readonly IntentDetector $intentDetector,
        private readonly EntityExtractor $entityExtractor
    ) {
    }

    public function analyze(IncomingMessageDTO $message, SessionDTO $session): AiAnalysisDTO
    {
        $intent = $this->intentDetector->detect($message->message, $session->currentFlow);
        $entities = $this->entityExtractor->extract($message->message, $message->pushName);

        return new AiAnalysisDTO(
            intent: $intent,
            entities: $entities,
            confidence: 0.7,
            source: 'fallback'
        );
    }
}