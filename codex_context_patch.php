<?php
function replace_once(string $content, string $old, string $new, string $label): string
{
    if (substr_count($content, $old) !== 1) {
        fwrite(STDERR, "Falha em {$label}: trecho esperado nao encontrado exatamente uma vez.\n");
        exit(1);
    }

    return str_replace($old, $new, $content);
}

function replace_section(string $content, string $startMarker, string $endMarker, string $newBlock, string $label): string
{
    $start = strpos($content, $startMarker);
    if ($start === false) {
        fwrite(STDERR, "Falha em {$label}: inicio nao encontrado.\n");
        exit(1);
    }

    $end = strpos($content, $endMarker, $start);
    if ($end === false) {
        fwrite(STDERR, "Falha em {$label}: fim nao encontrado.\n");
        exit(1);
    }

    return substr($content, 0, $start) . $newBlock . "\n\n" . substr($content, $end);
}

$conversationServicePath = __DIR__ . '/src/Service/ConversationService.php';
$conversationService = <<<'PHP'
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
        $session = $this->sessionService->getOrCreate($message->phone);
        $result = $this->orchestrator->handle($message, $session);
        $sessionWithHistory = $this->rememberConversationContext($result->session, $message, $result);
        $savedSession = $this->sessionService->save($sessionWithHistory);

        return new ConversationResultDTO(
            $result->reply,
            $savedSession,
            $result->intent,
            $result->entities,
            $result->toolCalls
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
PHP;
file_put_contents($conversationServicePath, $conversationService);

$orchestratorPath = __DIR__ . '/src/Domain/Conversation/AiOrchestrator.php';
$orchestrator = file_get_contents($orchestratorPath);
$testsPath = __DIR__ . '/tests/run.php';
$tests = file_get_contents($testsPath);
