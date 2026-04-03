<?php

namespace App\Domain\Conversation;

use App\DTO\AiAnalysisDTO;
use App\DTO\IncomingMessageDTO;
use App\DTO\SessionDTO;

interface ConversationInterpreterInterface
{
    public function analyze(IncomingMessageDTO $message, SessionDTO $session): AiAnalysisDTO;
}