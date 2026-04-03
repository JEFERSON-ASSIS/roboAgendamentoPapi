<?php

namespace App\Domain\Conversation;

use App\DTO\FlowRecoveryDecisionDTO;
use App\DTO\IncomingMessageDTO;
use App\DTO\SessionDTO;

class NullFlowRecoveryService implements FlowRecoveryServiceInterface
{
    public function decide(IncomingMessageDTO $message, SessionDTO $session): FlowRecoveryDecisionDTO
    {
        return new FlowRecoveryDecisionDTO();
    }
}
