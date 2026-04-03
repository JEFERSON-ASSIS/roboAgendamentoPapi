<?php

namespace App\Service;

use App\DTO\ConversationResultDTO;
use App\DTO\IncomingMessageDTO;
use App\DTO\SessionDTO;
use App\Domain\Conversation\AiOrchestrator;

class ConversationService
{
    public function __construct(
        private readonly SessionService $sessionService,
        private readonly AiOrchestrator $orchestrator
    ) {
    }

    public function handle(IncomingMessageDTO $message): ConversationResultDTO
    {
        $session = $this->sessionService->getOrCreate($message->phone, $message->provider);
        $result = $this->orchestrator->handle($message, $session);
        $sessionWithHistory = $this->rememberConversationContext($result->session, $message, $result);
        $savedSession = $this->sessionService->save($sessionWithHistory);

        return new ConversationResultDTO(
            $result->reply,
            $savedSession,
            $result->intent,
            $result->entities,
            $result->toolCalls,
            $result->replyPayload
        );
    }

    private function rememberConversationContext(SessionDTO $session, IncomingMessageDTO $message, ConversationResultDTO $result): SessionDTO
    {
        $context = $session->context;
        $context['last_user_message'] = $this->truncateText($message->message);
        $context['last_assistant_reply'] = $this->truncateText($result->reply);
        $context['last_detected_intent'] = $result->intent;
        $context['last_flow'] = $session->currentFlow;
        $context['last_step'] = $session->currentStep;
        $context['last_interaction_role'] = 'assistant';

        return $session->with(['context' => $context]);
    }

    private function truncateText(?string $value, int $limit = 280): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($text, 0, $limit, '...');
        }

        return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
    }
}
