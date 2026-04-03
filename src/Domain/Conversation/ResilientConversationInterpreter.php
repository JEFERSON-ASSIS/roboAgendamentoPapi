<?php

namespace App\Domain\Conversation;

use App\DTO\AiAnalysisDTO;
use App\DTO\IncomingMessageDTO;
use App\DTO\SessionDTO;
use App\Infrastructure\Logging\Logger;
use Throwable;

class ResilientConversationInterpreter implements ConversationInterpreterInterface
{
    public function __construct(
        private readonly ConversationInterpreterInterface $primary,
        private readonly ConversationInterpreterInterface $fallback,
        private readonly Logger $logger
    ) {
    }

    public function analyze(IncomingMessageDTO $message, SessionDTO $session): AiAnalysisDTO
    {
        try {
            return $this->primary->analyze($message, $session);
        } catch (Throwable $exception) {
            $this->logger->warning('Falha no interpretador primario. Usando fallback.', [
                'error' => $exception->getMessage(),
            ]);

            return $this->fallback->analyze($message, $session);
        }
    }
}