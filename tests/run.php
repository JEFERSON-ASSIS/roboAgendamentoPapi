<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/bootstrap.php';

use App\DTO\AiAnalysisDTO;
use App\DTO\FlowRecoveryDecisionDTO;
use App\DTO\IncomingMessageDTO;
use App\DTO\SessionDTO;
use App\Domain\Agenda\AgendaService;
use App\Domain\Agenda\AgendaToolRegistry;
use App\Domain\Conversation\AiOrchestrator;
use App\Domain\Conversation\ConversationInterpreterInterface;
use App\Domain\Conversation\FlowRecoveryServiceInterface;
use App\Domain\Conversation\IntentDetector;
use App\Domain\Conversation\NullFlowRecoveryService;
use App\Infrastructure\Http\HttpClient;
use App\Infrastructure\Logging\Logger;
use App\Infrastructure\Persistence\MessageLogRepositoryInterface;
use App\Infrastructure\Persistence\MessageQueueRepositoryInterface;
use App\Infrastructure\Persistence\RestrictionRuleRepositoryInterface;
use App\Infrastructure\Persistence\SessionRepositoryInterface;
use App\Service\CpfValidator;
use App\Service\ConversationService;
use App\Service\DebounceQueueService;
use App\Service\MessageLogService;
use App\Service\MessageNormalizer;
use App\Service\RestrictionRuleService;
use App\Service\SessionService;
use App\Service\WhatsAppService;

final class TestFailure extends RuntimeException
{
}

final class InMemoryMessageQueueRepository implements MessageQueueRepositoryInterface
{
    private array $rows = [];
    private int $nextId = 1;
    private array $locks = [];

    public function enqueue(IncomingMessageDTO $message): int
    {
        $id = $this->nextId++;
        $this->rows[] = [
            'id' => $id,
            'phone' => $message->phone,
            'message_text' => $message->message,
            'message_type' => $message->messageType,
            'media_url' => $message->mediaUrl,
            'payload_json' => json_encode($message->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'processed' => 0,
            'created_at' => sprintf('2026-03-23 18:00:%02d', $id),
        ];

        return $id;
    }

    public function acquirePhoneLock(string $phone, int $timeoutSeconds): bool
    {
        if (($this->locks[$phone] ?? false) === true) {
            return false;
        }

        $this->locks[$phone] = true;
        return true;
    }

    public function releasePhoneLock(string $phone): void
    {
        unset($this->locks[$phone]);
    }

    public function findPendingByPhone(string $phone): array
    {
        return array_values(array_filter($this->rows, static fn (array $row): bool => $row['phone'] === $phone && (int) $row['processed'] === 0));
    }

    public function markProcessed(array $ids): void
    {
        $ids = array_map(static fn ($id): int => (int) $id, $ids);

        foreach ($this->rows as &$row) {
            if (in_array((int) $row['id'], $ids, true)) {
                $row['processed'] = 1;
                $row['processed_at'] = '2026-03-23 18:01:00';
            }
        }
        unset($row);
    }

    public function deleteByPhone(string $phone): int
    {
        $before = count($this->rows);
        $this->rows = array_values(array_filter($this->rows, static fn (array $row): bool => $row['phone'] !== $phone));
        return $before - count($this->rows);
    }
}

final class InMemoryMessageLogRepository implements MessageLogRepositoryInterface
{
    public array $logged = [];

    public function log(array $data): void
    {
        $this->logged[] = $data;
    }

    public function deleteById(int $id): int
    {
        return $id === 123 ? 1 : 0;
    }

    public function deleteByPhone(string $phone): int
    {
        return $phone !== '' ? 1 : 0;
    }

    public function findConversations(int $limit = 50, ?string $search = null): array
    {
        return [];
    }

    public function findMessagesByPhone(string $phone, int $limit = 200): array
    {
        return [];
    }
}

final class InMemoryRestrictionRuleRepository implements RestrictionRuleRepositoryInterface
{
    public function __construct(private array $rules)
    {
    }

    public function findActive(): array
    {
        return $this->rules;
    }
}

final class InMemorySessionRepository implements SessionRepositoryInterface
{
    private array $rows = [];

    public function findByPhone(string $phone): ?SessionDTO
    {
        return isset($this->rows[$phone]) ? SessionDTO::fromArray($this->rows[$phone]) : null;
    }

    public function save(SessionDTO $session): SessionDTO
    {
        $this->rows[$session->phone] = $session->toArray();
        return $session;
    }

    public function deleteByPhone(string $phone): int
    {
        if (!isset($this->rows[$phone])) {
            return 0;
        }

        unset($this->rows[$phone]);
        return 1;
    }
}

final class FakeHttpClient extends HttpClient
{
    public array $posts = [];

    public function post(string $url, array $payload = [], array $headers = [], int $timeout = 30): array
    {
        $this->posts[] = compact('url', 'payload', 'headers', 'timeout');

        return [
            'status' => 201,
            'body' => '{"ok":true}',
            'json' => ['ok' => true],
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
        ];
    }
}

final class FakeConversationInterpreter implements ConversationInterpreterInterface
{
    public function __construct(
        private string $intent = 'unknown',
        private array $entities = [],
        private float $confidence = 0.95,
        private string $source = 'test'
    ) {
    }

    public function analyze(IncomingMessageDTO $message, SessionDTO $session): AiAnalysisDTO
    {
        return new AiAnalysisDTO($this->intent, $this->entities, $this->confidence, $this->source);
    }
}

final class FakeFlowRecoveryService implements FlowRecoveryServiceInterface
{
    public function __construct(private readonly FlowRecoveryDecisionDTO $decision)
    {
    }

    public function decide(IncomingMessageDTO $message, SessionDTO $session): FlowRecoveryDecisionDTO
    {
        return $this->decision;
    }
}

final class FakeAgendaService extends AgendaService
{
    public array $agendamentosResponse = [
        'agendamentos' => [],
        'bloquear_por_servico' => ['medico' => false, 'dentista' => false, 'enfermeiro' => false],
    ];
    public array $agendaMedicoResponse = ['datas' => ['24/03/2026', '26/03/2026']];
    public array $agendaEnfermeiroResponse = ['datas' => ['25/03/2026']];
    public array $agendaDentistaResponse = ['datas' => ['24/03/2026']];
    public array $horariosDentistaResponse = ['data' => '24/03/2026', 'horarios' => ['07:15', '07:30']];
    public array $writeMedicoResponse = ['success' => true, 'message' => 'Agendamento realizado.', 'id' => '9001', 'horaAgendada' => '08:15', 'horaComparecer' => '08:00'];
    public array $writeEnfermeiroResponse = ['success' => true, 'message' => 'Agendamento realizado.', 'id' => '9002'];
    public array $writeDentistaResponse = ['success' => true, 'message' => 'Agendamento realizado.', 'id' => '9003'];
    public array $cancelLookupResponse = [
        'agendamentos' => [
            ['id' => 101, 'servico' => 'Dentista', 'data' => '25/03/2026', 'hora' => '13:00'],
        ],
    ];
    public array $cancelResponse = ['success' => true, 'message' => 'Cancelado com sucesso.'];

    public function __construct()
    {
        parent::__construct(new HttpClient(), 'https://example.test', '2', true, null);
    }

    public function consultarAgendamentoAusente(string $cpf): array
    {
        return $this->agendamentosResponse;
    }

    public function consultarAgendaMedico(): array
    {
        return $this->agendaMedicoResponse;
    }

    public function consultarAgendaEnfermeiro(): array
    {
        return $this->agendaEnfermeiroResponse;
    }

    public function consultarAgendaDentista(): array
    {
        return $this->agendaDentistaResponse;
    }

    public function consultarHorarioDentista(string $data): array
    {
        return $this->horariosDentistaResponse;
    }

    public function cadastrarAgendaMedico(array $payload): array
    {
        return $this->writeMedicoResponse;
    }

    public function cadastrarAgendaEnfermeiro(array $payload): array
    {
        return $this->writeEnfermeiroResponse;
    }

    public function cadastrarAgendaDentista(array $payload): array
    {
        return $this->writeDentistaResponse;
    }

    public function consultarCancelamentoAgendamento(string $cpf): array
    {
        return $this->cancelLookupResponse;
    }

    public function confirmarCancelamentoGeral(string $idCancelamento): array
    {
        return $this->cancelResponse;
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new TestFailure($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new TestFailure($message . ' Expected: ' . var_export($expected, true) . ' Actual: ' . var_export($actual, true));
    }
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new TestFailure($message . ' Needle: ' . $needle . ' Haystack: ' . $haystack);
    }
}

function makeOrchestrator(
    ConversationInterpreterInterface $interpreter,
    ?FakeAgendaService $agendaService = null,
    ?RestrictionRuleService $restrictionRuleService = null,
    ?FlowRecoveryServiceInterface $flowRecoveryService = null
): AiOrchestrator {
    $agendaService ??= new FakeAgendaService();
    $restrictionRuleService ??= new RestrictionRuleService(new InMemoryRestrictionRuleRepository([]));
    $flowRecoveryService ??= new NullFlowRecoveryService();

    return new AiOrchestrator(
        $interpreter,
        new AgendaToolRegistry($agendaService),
        new Logger('storage/logs/test.log'),
        $restrictionRuleService,
        $flowRecoveryService
    );
}

$tests = [];

$tests['conversation_service_stores_last_exchange_for_contextual_recovery'] = static function (): void {
    $repository = new InMemorySessionRepository();
    $service = new ConversationService(
        new SessionService($repository),
        makeOrchestrator(new FakeConversationInterpreter('consultar_agendamentos'))
    );

    $result = $service->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: '4',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        )
    );

    $saved = $repository->findByPhone('5566999999999');
    assertTrue($saved instanceof SessionDTO, 'A sessao precisa ser salva para o proximo turno.');
    assertContains('CPF', (string) ($saved->context['last_assistant_reply'] ?? ''), 'A sessao deve guardar a ultima orientacao do robo.');
    assertSame('4', $saved->context['last_user_message'] ?? null, 'A sessao deve guardar a ultima mensagem do usuario.');
    assertSame($result->session->currentStep, $saved->currentStep, 'A sessao salva deve refletir o estado retornado ao usuario.');
};

$tests['ai_orchestrator_requires_full_name_during_scheduling_even_when_push_name_is_available'] = static function (): void {
    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('agendar_medico', ['cpf' => '01545934193', 'nome' => 'Maria Silva']));
    $session = SessionDTO::fromArray([
        'phone' => '5566999999999',
        'current_flow' => 'agendar_medico',
        'current_step' => 'awaiting_cpf',
        'selected_service' => 'medico',
    ]);

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: '01545934193',
            mediaUrl: null,
            pushName: 'Maria Silva',
            payload: []
        ),
        $session
    );

    assertSame('awaiting_name', $result->session->currentStep, 'No agendamento, deve continuar pedindo o nome completo mesmo se houver pushName.');
    assertContains('nome completo', mb_strtolower($result->reply), 'Deve pedir explicitamente o nome completo do paciente.');
    assertSame(null, $result->session->nome, 'Nao deve preencher o nome automaticamente antes da etapa de nome.');
};

$tests['ai_orchestrator_shows_confirmed_time_for_enfermeiro_when_write_response_has_no_hour'] = static function (): void {
    $agendaService = new FakeAgendaService();
    $agendaService->agendamentosResponse = [
        'agendamentos' => [
            ['id' => 9002, 'servico' => 'Enfermeiro', 'data' => '30/03/2026', 'hora' => '09:20'],
        ],
        'bloquear_por_servico' => ['medico' => false, 'dentista' => false, 'enfermeiro' => false],
    ];
    $agendaService->writeEnfermeiroResponse = [
        'success' => true,
        'message' => 'Agendamento realizado.',
        'id' => '9002',
    ];

    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('agendar_enfermeiro'), $agendaService);
    $session = SessionDTO::fromArray([
        'phone' => '5566999999999',
        'cpf' => '01545934193',
        'nome' => 'Zelia Rocha',
        'telefone' => '66996033097',
        'current_flow' => 'agendar_enfermeiro',
        'current_step' => 'awaiting_date_choice',
        'selected_service' => 'enfermeiro',
        'selected_date' => '30/03/2026',
        'context' => [],
    ]);

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: '30/03/2026',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        $session
    );

    assertContains('PSF02', $result->reply, 'Deve mencionar a unidade para o comparecimento da enfermagem.');
    assertContains('09:20', $result->reply, 'Deve mostrar a hora confirmada para enfermagem mesmo quando o POST nao devolver horario.');
    assertSame('09:20', $result->session->context['hora_agendada'] ?? null, 'Deve guardar a hora confirmada na sessao.');
};

$tests['bootstrap_utf8_defaults'] = static function (): void {
    assertSame('UTF-8', strtoupper((string) ini_get('default_charset')), 'default_charset deve estar em UTF-8.');
};

$tests['message_normalizer_prioritizes_evolution_media_url'] = static function (): void {
    $normalizer = new MessageNormalizer();
    $dto = $normalizer->normalize([
        'data' => [
            'key' => ['remoteJid' => '5566999999999@s.whatsapp.net'],
            'message' => [
                'audioMessage' => [
                    'url' => 'https://mmg.whatsapp.net/audio.enc',
                    'mimetype' => 'audio/ogg; codecs=opus',
                ],
                'mediaUrl' => 'https://storage.i7ai.com.br/audio.oga?sig=123',
            ],
        ],
    ]);

    assertSame('audio', $dto->messageType, 'Tipo deve ser audio.');
    assertSame('https://storage.i7ai.com.br/audio.oga?sig=123', $dto->mediaUrl, 'Deve priorizar mediaUrl da Evolution.');
};

$tests['restriction_rule_service_matches_accented_text'] = static function (): void {
    $service = new RestrictionRuleService(new InMemoryRestrictionRuleRepository([
        [
            'id' => 1,
            'name' => 'Especialidades',
            'match_type' => 'contains',
            'trigger_value' => 'psiquiatra',
            'response_message' => 'Especialidades nÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â£o sÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¾ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â£o atendidas por aqui.',
        ],
    ]));

    $match = $service->match('Quero consulta com psiquiatra');
    assertTrue(is_array($match), 'A regra deve casar com psiquiatra.');
    assertSame(1, $match['id'], 'A regra correta deve ser retornada.');
};

$tests['debounce_queue_batches_same_phone_without_mixing_other_phones'] = static function (): void {
    $repo = new InMemoryMessageQueueRepository();
    $logger = new Logger('storage/logs/test.log');
    $service = new DebounceQueueService($repo, $logger, true, 0, 1);

    $repo->enqueue(new IncomingMessageDTO(
        phone: '556600000001',
        messageType: 'text',
        message: 'quero agendar',
        mediaUrl: null,
        pushName: 'Teste',
        payload: ['body' => ['data' => ['pushName' => 'Teste']]]
    ));

    $repo->enqueue(new IncomingMessageDTO(
        phone: '556600000002',
        messageType: 'text',
        message: 'mensagem de outro usuario',
        mediaUrl: null,
        pushName: 'Outro',
        payload: ['body' => ['data' => ['pushName' => 'Outro']]]
    ));

    $result = $service->process(
        new IncomingMessageDTO(
            phone: '556600000001',
            messageType: 'text',
            message: 'dentista',
            mediaUrl: null,
            pushName: 'Teste',
            payload: ['body' => ['data' => ['pushName' => 'Teste']]]
        ),
        static function (IncomingMessageDTO $message): array {
            return ['text' => $message->message, 'phone' => $message->phone];
        }
    );

    assertSame('batched', $result['queue_status'], 'Deve montar lote para o mesmo telefone.');
    assertSame(2, $result['batch_parts'], 'Deve juntar duas mensagens do mesmo telefone.');
    assertContains("quero agendar\ndentista", $result['result']['text'], 'O lote deve concatenar as mensagens em ordem.');
    assertSame('556600000001', $result['result']['phone'], 'Nao pode misturar telefone de outro usuario.');
};

$tests['whatsapp_service_splits_blocks_into_multiple_messages'] = static function (): void {
    $http = new FakeHttpClient();
    $service = new WhatsAppService($http, 'https://evolution.example', 'instancia', 'token', true, true, 700, 0);
    $result = $service->sendText('5566999999999', "Ola! Seja bem-vindo.\n\nLinha 1\nLinha 2\n\nResponda com seu CPF.");

    assertSame('multi_sent', $result['status'], 'Deve enviar em multiplas partes quando ha blocos.');
    assertSame(3, $result['parts'], 'Deve gerar tres partes a partir dos blocos.');
    assertSame(3, count($http->posts), 'Deve realizar tres envios HTTP.');
};

$tests['whatsapp_service_sends_typing_presence_before_text'] = static function (): void {
    $http = new FakeHttpClient();
    $service = new WhatsAppService($http, 'https://evolution.example', 'instancia', 'token', true, false, 700, 0, true, 1200, false);

    $result = $service->sendText('5566999999999', 'Teste de digitando');

    assertSame('sent', $result['status'], 'Deve continuar reportando envio simples.');
    assertSame(2, count($http->posts), 'Deve fazer uma chamada de presence e uma de texto.');
    assertContains('/chat/sendPresence/instancia', $http->posts[0]['url'], 'A primeira chamada deve ser de presence.');
    assertSame('composing', $http->posts[0]['payload']['presence'] ?? null, 'Presence deve ser composing.');
    assertSame(1200, $http->posts[0]['payload']['delay'] ?? null, 'Delay do typing deve respeitar a configuracao.');
    assertContains('/message/sendText/instancia', $http->posts[1]['url'], 'A segunda chamada deve ser o envio do texto.');
};

$tests['ai_orchestrator_guides_lost_user_after_repeated_invalid_step_reply'] = static function (): void {
    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('unknown'));
    $session = SessionDTO::fromArray([
        'phone' => '5566999999999',
        'cpf' => '01545934193',
        'nome' => 'Jeferson Assis',
        'telefone' => '5566996553735',
        'current_flow' => 'agendar_medico',
        'current_step' => 'awaiting_date_choice',
        'selected_service' => 'medico',
        'context' => [
            'available_dates' => ['24/03/2026', '26/03/2026'],
            'step_attempts' => ['awaiting_date_choice' => 1],
        ],
    ]);

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: 'nao sei',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        $session
    );

    assertContains('Vamos por partes.', $result->reply, 'Depois de repeticao invalida, o robo deve orientar o usuario.');
    assertContains('24/03/2026', $result->reply, 'A resposta guiada deve trazer um exemplo valido da etapa atual.');
    assertSame('awaiting_date_choice', $result->session->currentStep, 'O fluxo deve continuar na mesma etapa.');
};

$tests['ai_orchestrator_redirects_cancel_dispute_to_general_consulta'] = static function (): void {
    $agendaService = new FakeAgendaService();
    $agendaService->cancelLookupResponse = ['agendamentos' => []];
    $agendaService->agendamentosResponse = [
        'agendamentos' => [
            ['id' => 16593, 'servico' => 'Dentista', 'data' => '20/05/2026', 'hora' => '07:45'],
        ],
        'bloquear_por_servico' => ['medico' => false, 'dentista' => false, 'enfermeiro' => false],
    ];

    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('unknown'), $agendaService);
    $session = SessionDTO::fromArray([
        'phone' => '5566999999999',
        'cpf' => '01545934102',
        'current_flow' => 'cancelar_agendamento',
        'current_step' => 'awaiting_lookup_retry',
        'context' => [
            'last_lookup_flow' => 'cancelar_agendamento',
            'last_lookup_status' => 'empty',
        ],
    ]);

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: 'mas eu tenho',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        $session
    );

    assertContains('Vou conferir todos os seus agendamentos', $result->reply, 'Deve redirecionar a contestacao do cancelamento para a consulta geral.');
    assertContains('Dentista - 20/05/2026 - 07:45', $result->reply, 'Deve mostrar os agendamentos encontrados na consulta geral.');
    assertSame('completed', $result->session->currentFlow, 'Depois da consulta geral bem-sucedida, o fluxo deve ser encerrado.');
};

$tests['ai_orchestrator_requires_yes_or_no_to_confirm_cancellation'] = static function (): void {
    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('unknown'));
    $session = SessionDTO::fromArray([
        'phone' => '5566999999999',
        'cpf' => '01545934102',
        'current_flow' => 'cancelar_agendamento',
        'current_step' => 'awaiting_cancellation_confirmation',
        'pending_action' => 'confirm_cancelamento',
        'context' => [
            'cancel_id' => '16593',
            'last_assistant_reply' => 'Deseja cancelar esse agendamento? Sim ou Nao',
        ],
    ]);

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: 'talvez',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        $session
    );

    assertContains('Sim', $result->reply, 'Deve pedir confirmacao explicita quando a resposta nao for valida.');
    assertContains('Nao', $result->reply, 'Deve pedir confirmacao explicita quando a resposta nao for valida.');
    assertSame('awaiting_cancellation_confirmation', $result->session->currentStep, 'Deve permanecer aguardando confirmacao.');
};

$tests['ai_orchestrator_confirms_success_and_flags_internal_review_when_post_write_verification_is_pending'] = static function (): void {
    $agendaService = new FakeAgendaService();
    $agendaService->agendamentosResponse = [
        'agendamentos' => [],
        'bloquear_por_servico' => ['medico' => false, 'dentista' => false, 'enfermeiro' => false],
    ];

    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('agendar_medico'), $agendaService);
    $session = SessionDTO::fromArray([
        'phone' => '5566999999999',
        'cpf' => '01545934193',
        'nome' => 'Jeferson Assis',
        'telefone' => '5566996553735',
        'current_flow' => 'agendar_medico',
        'current_step' => 'awaiting_date_choice',
        'selected_service' => 'medico',
        'selected_date' => '24/03/2026',
        'context' => [],
    ]);

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: '24/03/2026',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        $session
    );

    assertContains('Pronto, seu agendamento foi realizado.', $result->reply, 'Deve confirmar o agendamento para o usuario quando a tool de escrita retornar sucesso.');
    assertSame('completed', $result->session->currentFlow, 'O fluxo deve ser encerrado apos o agendamento.');
    assertTrue(($result->session->context['pending_manual_review'] ?? false) === true, 'Deve marcar a sessao para revisao interna quando a verificacao nao localizar o registro imediatamente.');
};



$tests['cpf_validator_rejects_wrong_length_and_invalid_digits'] = static function (): void {
    $short = CpfValidator::analyzeInput('0154593410');
    $invalid = CpfValidator::analyzeInput('11111111111');
    $valid = CpfValidator::analyzeInput('52998224725');

    assertSame('missing_digits', $short['reason'] ?? null, 'CPF curto deve ser identificado como incompleto.');
    assertSame('repeated_digits', $invalid['reason'] ?? null, 'CPF com digitos repetidos deve ser rejeitado.');
    assertSame('52998224725', $valid['value'] ?? null, 'CPF valido deve ser aceito.');
};

$tests['ai_orchestrator_rejects_invalid_cpf_while_waiting_for_cpf'] = static function (): void {
    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('consultar_agendamentos'));
    $session = SessionDTO::fromArray([
        'phone' => '5566999999999',
        'current_flow' => 'consultar_agendamentos',
        'current_step' => 'awaiting_cpf',
    ]);

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: '11111111111',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        $session
    );

    assertContains('nao e valido', mb_strtolower($result->reply), 'Deve avisar quando o CPF for invalido.');
    assertSame(null, $result->session->cpf, 'Nao deve salvar CPF invalido na sessao.');
    assertSame('awaiting_cpf', $result->session->currentStep, 'Deve continuar aguardando CPF valido.');
};

$tests['ai_orchestrator_rejects_short_cpf_while_waiting_for_cpf'] = static function (): void {
    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('cancelar_agendamento'));
    $session = SessionDTO::fromArray([
        'phone' => '5566999999999',
        'current_flow' => 'cancelar_agendamento',
        'current_step' => 'awaiting_cpf',
    ]);

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: '0154593410',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        $session
    );

    assertContains('11 numeros', mb_strtolower($result->reply), 'Deve avisar quando o CPF vier incompleto.');
    assertSame(null, $result->session->cpf, 'Nao deve salvar CPF incompleto na sessao.');
    assertSame('awaiting_cpf', $result->session->currentStep, 'Deve continuar aguardando CPF valido.');
};


$tests['ai_orchestrator_allows_request_to_change_cpf_after_empty_consulta'] = static function (): void {
    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('unknown'));
    $session = SessionDTO::fromArray([
        'phone' => '5566999999999',
        'cpf' => '01545934193',
        'current_flow' => 'consultar_agendamentos',
        'current_step' => 'awaiting_lookup_retry',
    ]);

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: 'quero trocar cpf',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        $session
    );

    assertContains('cpf que voce quer consultar', mb_strtolower($result->reply), 'Deve pedir o CPF novo quando o usuario pedir troca.');
    assertSame(null, $result->session->cpf, 'Deve limpar o CPF atual para receber outro.');
    assertSame('awaiting_cpf', $result->session->currentStep, 'Deve voltar para a etapa de CPF.');
};

$tests['ai_orchestrator_requeries_when_user_sends_another_cpf_after_empty_consulta'] = static function (): void {
    $agendaService = new FakeAgendaService();
    $agendaService->agendamentosResponse = [
        'agendamentos' => [
            ['id' => 88, 'servico' => 'Dentista', 'data' => '25/03/2026', 'hora' => '09:15'],
        ],
        'bloquear_por_servico' => ['medico' => false, 'dentista' => false, 'enfermeiro' => false],
    ];

    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('unknown', ['cpf' => '52998224725']), $agendaService);
    $session = SessionDTO::fromArray([
        'phone' => '5566999999999',
        'cpf' => '01545934193',
        'current_flow' => 'consultar_agendamentos',
        'current_step' => 'awaiting_lookup_retry',
    ]);

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: '52998224725',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        $session
    );

    assertContains('Dentista - 25/03/2026 - 09:15', $result->reply, 'Deve consultar novamente usando o novo CPF enviado.');
    assertSame('52998224725', $result->toolCalls[0]['args']['cpf'] ?? null, 'A consulta deve ser feita com o CPF novo.');
    assertSame('completed', $result->session->currentFlow, 'Se encontrar agendamentos no novo CPF, deve concluir a consulta.');
};


$tests['ai_orchestrator_uses_ai_change_cpf_intent_to_request_new_document'] = static function (): void {
    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('change_cpf'));
    $session = SessionDTO::fromArray([
        'phone' => '5566999999999',
        'cpf' => '01545934193',
        'current_flow' => 'cancelar_agendamento',
        'current_step' => 'awaiting_lookup_retry',
    ]);

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: 'nao e esse cpf',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        $session
    );

    assertContains('cpf da outra pessoa', mb_strtolower($result->reply), 'A intencao change_cpf deve pedir o CPF da outra pessoa.' );
    assertSame(null, $result->session->cpf, 'Deve limpar o CPF atual quando a IA reconhecer troca de CPF.');
    assertSame('awaiting_cpf', $result->session->currentStep, 'Deve voltar a aguardar CPF.');
};

$tests['ai_orchestrator_uses_ai_change_cpf_intent_with_new_cpf_to_resume_flow'] = static function (): void {
    $agendaService = new FakeAgendaService();
    $agendaService->cancelLookupResponse = [
        'agendamentos' => [
            ['id' => 321, 'servico' => 'Dentista', 'data' => '26/03/2026', 'hora' => '10:00'],
        ],
    ];

    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('change_cpf', ['cpf' => '52998224725']), $agendaService);
    $session = SessionDTO::fromArray([
        'phone' => '5566999999999',
        'cpf' => '01545934193',
        'current_flow' => 'cancelar_agendamento',
        'current_step' => 'awaiting_lookup_retry',
    ]);

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: 'e do cpf 52998224725',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        $session
    );

    assertContains('ID: 321', $result->reply, 'Com change_cpf e CPF valido, deve retomar o fluxo com o novo documento.');
    assertSame('52998224725', $result->session->cpf, 'A sessao deve trocar para o novo CPF reconhecido pela IA.');
    assertSame('52998224725', $result->toolCalls[0]['args']['cpf'] ?? null, 'A busca deve ser feita com o novo CPF.');
};


$tests['intent_detector_recognizes_other_patient_during_active_flow'] = static function (): void {
    $detector = new IntentDetector();

    assertSame('change_cpf', $detector->detect('e do meu filho', 'agendar_medico'), 'Durante um fluxo ativo, deve reconhecer troca de paciente.');
    assertSame('unknown', $detector->detect('e do meu filho', 'idle'), 'No idle, essa frase sozinha nao deve sequestrar o menu.');
};

$tests['ai_orchestrator_clears_person_data_when_switching_patient_during_scheduling'] = static function (): void {
    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('change_cpf'));
    $session = SessionDTO::fromArray([
        'phone' => '5566999999999',
        'cpf' => '01545934193',
        'nome' => 'Paciente Atual',
        'telefone' => '5566996553735',
        'current_flow' => 'agendar_medico',
        'current_step' => 'awaiting_date_choice',
        'selected_service' => 'medico',
        'selected_date' => '24/03/2026',
    ]);

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: 'e de outra pessoa',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        $session
    );

    assertContains('outra pessoa', mb_strtolower($result->reply), 'Deve responder de forma natural para troca de paciente.');
    assertSame(null, $result->session->cpf, 'Deve limpar o CPF ao trocar de paciente.');
    assertSame(null, $result->session->nome, 'Deve limpar o nome ao trocar de paciente.');
    assertSame(null, $result->session->telefone, 'Deve limpar o telefone ao trocar de paciente.');
    assertSame('awaiting_cpf', $result->session->currentStep, 'Deve voltar a pedir o CPF da nova pessoa.');
};

$tests['ai_orchestrator_treats_agende_as_generic_scheduling_request'] = static function (): void {
    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('agendar_medico'));

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: "ola\nquero\nagende",
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        SessionDTO::createEmpty('5566999999999')
    );

    assertSame('choose_service', $result->intent, 'Pedido generico como "agende" deve pedir o servico antes do CPF.');
    assertContains('Claro.', $result->reply, 'Nao deve assumir Medico quando o pedido ainda estiver generico.');
    assertContains('Dentista', $result->reply, 'Quando o pedido for generico, a resposta deve listar os servicos disponiveis.');
};

$tests['ai_orchestrator_replaces_previous_cpf_when_new_valid_cpf_arrives_while_awaiting_cpf'] = static function (): void {
    $agendaService = new FakeAgendaService();
    $agendaService->cancelLookupResponse = [
        'agendamentos' => [
            ['id' => 654, 'servico' => 'Dentista', 'data' => '28/03/2026', 'hora' => '09:30'],
        ],
    ];

    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('unknown', ['cpf' => '52998224725']), $agendaService);
    $session = SessionDTO::fromArray([
        'phone' => '5566999999999',
        'cpf' => '01545934193',
        'current_flow' => 'cancelar_agendamento',
        'current_step' => 'awaiting_cpf',
    ]);

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: '52998224725',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        $session
    );

    assertSame('52998224725', $result->session->cpf, 'O CPF novo valido deve substituir o antigo quando o robo estiver aguardando CPF.');
    assertSame('52998224725', $result->toolCalls[0]['args']['cpf'] ?? null, 'A busca deve usar o CPF mais recente enviado pelo usuario.');
    assertContains('ID: 654', $result->reply, 'O fluxo deve continuar com o CPF novo sem ficar preso no documento anterior.');
};

$tests['ai_orchestrator_thanks_external_reminder_confirmation_messages'] = static function (): void {
    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('unknown'));

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: 'CONFIMO',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        SessionDTO::createEmpty('5566999999999')
    );

    assertSame('external_reminder_confirmation', $result->intent, 'Confirmacoes avulsas de lembrete devem ser reconhecidas mesmo com digitacao imperfeita.');
    assertContains('Obrigado pela confirmacao', $result->reply, 'A resposta deve apenas agradecer a confirmacao.');
};

$tests['ai_orchestrator_routes_cancelar_from_idle_to_cancellation_flow'] = static function (): void {
    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('cancelar_agendamento'));

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: 'cancelar',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        SessionDTO::createEmpty('5566999999999')
    );

    assertSame('cancelar_agendamento', $result->session->currentFlow, 'Cancelar avulso deve entrar no fluxo de cancelamento.');
    assertSame('awaiting_cpf', $result->session->currentStep, 'Cancelar avulso deve pedir o CPF.');
    assertContains('me informe seu CPF', $result->reply, 'O cancelamento deve seguir o item 5 do menu pedindo CPF.');
};
$tests['ai_orchestrator_exits_from_idle_when_user_types_sair'] = static function (): void {
    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('unknown'));

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: 'Sair',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        SessionDTO::createEmpty('5566999999999')
    );

    assertSame('exit_conversation', $result->intent, 'Sair no menu deve encerrar o atendimento atual.');
    assertContains('Encerrei este atendimento', $result->reply, 'A resposta deve confirmar o encerramento.');
    assertSame('idle', $result->session->currentFlow, 'Depois do encerramento, a sessao deve voltar ao estado idle.');
};

$tests['ai_orchestrator_keeps_menu_navigation_words_inside_active_flows_only'] = static function (): void {
    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('menu'));

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: 'menu',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        SessionDTO::createEmpty('5566999999999')
    );

    assertSame('menu', $result->intent, 'No idle, a palavra menu deve continuar abrindo o menu principal.');
    assertContains('Escolha uma das opções abaixo', $result->reply, 'No idle, menu deve continuar mostrando as opções.');
};

$tests['ai_orchestrator_uses_flow_recovery_clarify_when_message_does_not_match_pending_step'] = static function (): void {
    $recovery = new FakeFlowRecoveryService(new FlowRecoveryDecisionDTO(
        action: 'clarify',
        replyMessage: 'Ainda estou aguardando um dos horarios mostrados. Me responda com o horario que voce deseja.',
        confidence: 0.91,
        source: 'test_recovery'
    ));

    $orchestrator = makeOrchestrator(
        new FakeConversationInterpreter('unknown'),
        null,
        null,
        $recovery
    );

    $session = SessionDTO::fromArray([
        'phone' => '5566999999999',
        'current_flow' => 'agendar_dentista',
        'current_step' => 'awaiting_time_choice',
        'selected_service' => 'dentista',
        'cpf' => '52998224725',
        'nome' => 'Paciente Teste',
        'telefone' => '5566996553735',
        'selected_date' => '25/03/2026',
        'context' => [
            'available_times' => ['07:15', '07:30'],
        ],
    ]);

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: 'esse mesmo',
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        $session
    );

    assertSame('flow_recovery_clarify', $result->intent, 'Quando a IA identificar que a resposta nao bate com a etapa pendente, o robo deve pedir esclarecimento.');
    assertContains('aguardando um dos horarios', $result->reply, 'A resposta deve orientar o usuario sobre o que falta para seguir.');
    assertSame('awaiting_time_choice', $result->session->currentStep, 'O esclarecimento nao deve perder a etapa atual.');
};
$tests['ai_orchestrator_uses_short_menu_for_active_session'] = static function (): void {
    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('menu'));

    $session = SessionDTO::fromArray([
        'phone' => '5566999999999',
        'nome' => 'Maria Silva',
        'current_flow' => 'idle',
        'current_step' => 'awaiting_menu_choice',
        'context' => [
            'last_user_message' => 'oi',
            'last_assistant_reply' => 'Escolha uma das opcoes abaixo:',
        ],
    ]);

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: 'oi',
            mediaUrl: null,
            pushName: 'Maria Silva',
            payload: []
        ),
        $session
    );

    assertContains('Olá, Maria! Como posso te ajudar?', $result->reply, 'Sessao valida deve responder com saudacao curta e personalizada.');

    if (str_contains($result->reply, 'assistente virtual da UBS Vida Nova')) {
        throw new TestFailure('Sessao valida nao deve repetir a apresentacao completa do robo.');
    }
};

$tests['ai_orchestrator_recognizes_batched_menu_choice_before_cpf'] = static function (): void {
    $orchestrator = makeOrchestrator(
        new FakeConversationInterpreter('unknown', ['cpf' => '52998224725'])
    );

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'text',
            message: "5\n529.982.247-25",
            mediaUrl: null,
            pushName: 'Paciente Teste',
            payload: [
                'queue_batch' => [
                    'parts' => 2,
                ],
            ]
        ),
        SessionDTO::createEmpty('5566999999999')
    );

    if (str_contains($result->reply, 'assistente virtual da UBS Vida Nova')) {
        throw new TestFailure('Quando o item do menu vem antes do CPF no mesmo lote, o robo deve seguir o fluxo escolhido em vez de reapresentar o menu inicial.');
    }

    assertSame('cancelar_agendamento', $result->intent, 'O item 5 enviado junto com CPF deve continuar entrando no fluxo de cancelamento.');
};
$tests['message_normalizer_extracts_reaction_message_text'] = static function (): void {
    $normalizer = new MessageNormalizer();
    $dto = $normalizer->normalize([
        'data' => [
            'key' => ['remoteJid' => '5566999999999@s.whatsapp.net'],
            'message' => [
                'reactionMessage' => [
                    'text' => "\u{1F64F}",
                ],
            ],
        ],
    ]);

    assertSame('reaction', $dto->messageType, 'Tipo deve ser reaction.');
    assertSame("\u{1F64F}", $dto->message, 'Deve extrair o emoji enviado como reacao.');
};

$tests['ai_orchestrator_replies_with_plain_thanks_for_emoji_reaction'] = static function (): void {
    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('unknown'));

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'reaction',
            message: "\u{1F64F}",
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        SessionDTO::createEmpty('5566999999999')
    );

    assertSame('external_reminder_confirmation', $result->intent, 'Emoji isolado deve ser tratado como confirmacao curta fora de fluxo.');
    assertSame('Obrigado.', $result->reply, 'Emoji isolado deve responder apenas com obrigado.');
};

$tests['ai_orchestrator_replies_with_plain_thanks_for_emoji_after_completed_flow'] = static function (): void {
    $orchestrator = makeOrchestrator(new FakeConversationInterpreter('unknown'));
    $session = SessionDTO::fromArray([
        'phone' => '5566999999999',
        'current_flow' => 'completed',
        'current_step' => 'completed',
    ]);

    $result = $orchestrator->handle(
        new IncomingMessageDTO(
            phone: '5566999999999',
            messageType: 'reaction',
            message: "\u{1F44D}\u{1F3FB}",
            mediaUrl: null,
            pushName: 'Teste',
            payload: []
        ),
        $session
    );

    assertSame('Obrigado.', $result->reply, 'Depois de fluxo concluido, reacao com emoji deve encerrar apenas com obrigado.');
    assertSame('completed', $result->session->currentFlow, 'A reacao nao deve resetar a sessao concluida.');
};

$tests['message_log_service_deletes_single_message_by_id'] = static function (): void {
    $repository = new InMemoryMessageLogRepository();
    $service = new MessageLogService($repository);

    assertSame(1, $service->deleteById(123), 'Deve retornar a quantidade removida ao excluir por id.');
    assertSame(0, $service->deleteById(999), 'Deve retornar zero quando a mensagem nao existir.');
};

$passed = 0;
$failed = 0;

foreach ($tests as $name => $test) {
    try {
        $test();
        echo "[OK] {$name}" . PHP_EOL;
        $passed++;
    } catch (Throwable $exception) {
        echo "[FAIL] {$name}: {$exception->getMessage()}" . PHP_EOL;
        $failed++;
    }
}

echo PHP_EOL;
echo sprintf('Resumo: %d passou, %d falhou.', $passed, $failed) . PHP_EOL;

exit($failed > 0 ? 1 : 0);









