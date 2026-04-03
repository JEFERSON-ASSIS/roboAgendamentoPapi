<?php

require_once dirname(__DIR__) . '/src/Core/bootstrap.php';

use App\Controller\WebhookController;
use App\Core\Request;
use App\Domain\Agenda\AgendaService;
use App\Domain\Agenda\AgendaToolRegistry;
use App\Domain\Conversation\AiOrchestrator;
use App\Domain\Conversation\EntityExtractor;
use App\Domain\Conversation\FallbackConversationInterpreter;
use App\Domain\Conversation\IntentDetector;
use App\Domain\Conversation\NullFlowRecoveryService;
use App\Domain\Conversation\OpenAiConversationInterpreter;
use App\Domain\Conversation\OpenAiFlowRecoveryService;
use App\Domain\Conversation\ResilientConversationInterpreter;
use App\Infrastructure\Http\HttpClient;
use App\Infrastructure\Logging\Logger;
use App\Infrastructure\Persistence\DatabaseConnectionFactory;
use App\Infrastructure\Persistence\JsonSessionRepository;
use App\Infrastructure\Persistence\NullIntegrationLogRepository;
use App\Infrastructure\Persistence\NullMessageLogRepository;
use App\Infrastructure\Persistence\NullMessageQueueRepository;
use App\Infrastructure\Persistence\NullRestrictionRuleRepository;
use App\Infrastructure\Persistence\PdoIntegrationLogRepository;
use App\Infrastructure\Persistence\PdoMessageLogRepository;
use App\Infrastructure\Persistence\PdoMessageQueueRepository;
use App\Infrastructure\Persistence\PdoRestrictionRuleRepository;
use App\Infrastructure\Persistence\PdoSessionRepository;
use App\Service\AudioTranscriptionService;
use App\Service\ConversationService;
use App\Service\DebounceQueueService;
use App\Service\IntegrationLogService;
use App\Service\MessageLogService;
use App\Service\MessageNormalizer;
use App\Service\OpenAIClient;
use App\Service\RestrictionRuleService;
use App\Service\SessionService;
use App\Service\WhatsAppService;

$logger = new Logger(config('services.log.path', 'storage/logs/app.log'));
$httpClient = new HttpClient();
$normalizer = new MessageNormalizer();
$sessionTtlMinutes = (int) config('services.session.ttl_minutes', 180);

$sessionRepository = new JsonSessionRepository(
    config('services.storage.sessions_path', 'storage/data/sessions.json'),
    $sessionTtlMinutes
);
$messageLogRepository = new NullMessageLogRepository();
$integrationLogRepository = new NullIntegrationLogRepository();
$restrictionRuleRepository = new NullRestrictionRuleRepository();
$messageQueueRepository = new NullMessageQueueRepository();
$dbAvailable = false;

try {
    $pdo = (new DatabaseConnectionFactory())->make();
    $sessionRepository = new PdoSessionRepository($pdo, $sessionTtlMinutes);
    $messageLogRepository = new PdoMessageLogRepository($pdo);
    $integrationLogRepository = new PdoIntegrationLogRepository($pdo);
    $restrictionRuleRepository = new PdoRestrictionRuleRepository($pdo);
    $messageQueueRepository = new PdoMessageQueueRepository($pdo);
    $dbAvailable = true;
    $logger->info('Persistencia SQL ativa para sessao, logs e integracoes.', ['session_ttl_minutes' => $sessionTtlMinutes]);
} catch (Throwable $exception) {
    $logger->warning('Banco indisponivel. Usando fallback JSON para sessao e NullLog para logs e integracoes.', [
        'error' => $exception->getMessage(),
        'session_ttl_minutes' => $sessionTtlMinutes,
    ]);
}

$sessionService = new SessionService($sessionRepository);
$messageLogService = new MessageLogService($messageLogRepository);
$integrationLogService = new IntegrationLogService($integrationLogRepository);
$restrictionRuleService = new RestrictionRuleService($restrictionRuleRepository);
$debounceQueueService = new DebounceQueueService(
    $messageQueueRepository,
    $logger,
    $dbAvailable && (bool) config('services.queue.debounce_enabled', true),
    (int) config('services.queue.debounce_window_ms', 3000),
    (int) config('services.queue.lock_wait_seconds', 20)
);

$agendaService = new AgendaService(
    $httpClient,
    config('services.agenda.base_url', ''),
    (string) config('services.agenda.empresa', '2'),
    (bool) config('services.agenda.mock', true),
    $integrationLogService
);
$toolRegistry = new AgendaToolRegistry($agendaService);

$fallbackInterpreter = new FallbackConversationInterpreter(new IntentDetector(), new EntityExtractor());
$flowRecoveryService = new NullFlowRecoveryService();
$audioTranscriptionService = null;
$aiDriver = (string) config('services.ai.driver', 'fallback');

if ($aiDriver === 'openai') {
    $openAiClient = new OpenAIClient(
        $httpClient,
        (string) config('services.ai.api_key', ''),
        (string) config('services.ai.base_url', 'https://api.openai.com/v1')
    );

    $primaryInterpreter = new OpenAiConversationInterpreter(
        $openAiClient,
        (string) config('services.ai.model', 'gpt-4.1-mini')
    );

    $flowRecoveryService = new OpenAiFlowRecoveryService(
        $openAiClient,
        (string) config('services.ai.model', 'gpt-4.1-mini')
    );

    $audioTranscriptionService = new AudioTranscriptionService(
        $httpClient,
        $openAiClient,
        $logger,
        (string) config('services.ai.transcription_model', 'gpt-4o-mini-transcribe'),
        (string) config('services.ai.transcription_language', 'pt'),
        (string) config('services.whatsapp.base_url', ''),
        (string) config('services.whatsapp.api_key', '')
    );

    $interpreter = new ResilientConversationInterpreter($primaryInterpreter, $fallbackInterpreter, $logger);
} else {
    $interpreter = $fallbackInterpreter;
}

$orchestrator = new AiOrchestrator($interpreter, $toolRegistry, $logger, $restrictionRuleService, $flowRecoveryService);
$conversationService = new ConversationService($sessionService, $orchestrator);
$whatsAppService = new WhatsAppService(
    $httpClient,
    (string) config('services.whatsapp.base_url', ''),
    (string) config('services.whatsapp.instance', ''),
    (string) config('services.whatsapp.api_key', ''),
    (bool) config('services.whatsapp.send_enabled', false),
    (bool) config('services.whatsapp.split_messages', true),
    (int) config('services.whatsapp.split_max_length', 700),
    (int) config('services.whatsapp.split_delay_ms', 400),
    (bool) config('services.whatsapp.typing_enabled', false),
    (int) config('services.whatsapp.typing_delay_ms', 1200),
    (bool) config('services.whatsapp.typing_each_chunk', true)
);

$controller = new WebhookController(
    $logger,
    $normalizer,
    $conversationService,
    $whatsAppService,
    $messageLogService,
    $audioTranscriptionService,
    $debounceQueueService
);
$response = $controller->handle(Request::capture());
$response->send();

