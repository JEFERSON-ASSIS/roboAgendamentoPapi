<?php

require_once dirname(__DIR__) . '/src/Core/bootstrap.php';

use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Persistence\DatabaseConnectionFactory;
use App\Infrastructure\Persistence\JsonSessionRepository;
use App\Infrastructure\Persistence\PdoIntegrationLogRepository;
use App\Infrastructure\Persistence\PdoMessageLogRepository;
use App\Infrastructure\Persistence\PdoSessionRepository;
use App\Service\IntegrationLogService;
use App\Service\MessageLogService;
use App\Service\SessionService;

$request = Request::capture();
$remoteAddress = (string) $request->server('REMOTE_ADDR', '');
$isLocalRequest = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
$adminToken = (string) config('services.session.admin_token', '');
$providedToken = (string) $request->query('token', '');

if (!$isLocalRequest) {
    if ($adminToken === '' || !hash_equals($adminToken, $providedToken)) {
        Response::json([
            'ok' => false,
            'message' => 'Acesso negado.',
        ], 403)->send();
        return;
    }
}

$action = strtolower((string) $request->query('action', 'status'));
$phone = preg_replace('/\D+/', '', (string) $request->query('phone', '')) ?: '';
$cpf = preg_replace('/\D+/', '', (string) $request->query('cpf', '')) ?: null;
$sessionTtlMinutes = (int) config('services.session.ttl_minutes', 180);

if ($phone === '') {
    Response::json([
        'ok' => false,
        'message' => 'Informe o phone com DDD. Exemplo: ?phone=5566999999999',
    ], 422)->send();
    return;
}

$pdo = null;
$sessionRepository = new JsonSessionRepository(config('services.storage.sessions_path', 'storage/data/sessions.json'), $sessionTtlMinutes);
$messageLogService = null;
$integrationLogService = null;
$dbAvailable = false;

try {
    $pdo = (new DatabaseConnectionFactory())->make();
    $sessionRepository = new PdoSessionRepository($pdo, $sessionTtlMinutes);
    $messageLogService = new MessageLogService(new PdoMessageLogRepository($pdo));
    $integrationLogService = new IntegrationLogService(new PdoIntegrationLogRepository($pdo));
    $dbAvailable = true;
} catch (Throwable) {
}

$sessionService = new SessionService($sessionRepository);
$currentSession = $sessionRepository->findByPhone($phone);
$cpfFromSession = $currentSession?->cpf;
$resolvedCpf = $cpf ?? $cpfFromSession;

if ($action === 'reset') {
    $session = $sessionService->reset($phone);

    Response::json([
        'ok' => true,
        'action' => 'reset',
        'phone' => $phone,
        'session' => $session->toArray(),
        'ttl_minutes' => $sessionTtlMinutes,
    ])->send();
    return;
}

if ($action === 'purge') {
    $deletedSession = $sessionService->forget($phone);
    $deletedMessageLogs = 0;
    $deletedIntegrationLogs = 0;
    $deletedQueue = 0;

    if ($dbAvailable && $pdo !== null && $messageLogService !== null && $integrationLogService !== null) {
        $deletedMessageLogs = $messageLogService->deleteByPhone($phone);
        $deletedIntegrationLogs += $integrationLogService->deleteByPhone($phone);

        if ($resolvedCpf !== null && $resolvedCpf !== '') {
            $deletedIntegrationLogs += $integrationLogService->deleteByCpf($resolvedCpf);
        }

        $statement = $pdo->prepare('DELETE FROM message_queue WHERE phone = :phone');
        $statement->execute(['phone' => $phone]);
        $deletedQueue = $statement->rowCount();
    }

    Response::json([
        'ok' => true,
        'action' => 'purge',
        'phone' => $phone,
        'cpf' => $resolvedCpf,
        'deleted' => [
            'sessions' => $deletedSession,
            'message_logs' => $deletedMessageLogs,
            'integration_logs' => $deletedIntegrationLogs,
            'message_queue' => $deletedQueue,
        ],
        'db_available' => $dbAvailable,
    ])->send();
    return;
}

$messageLogCount = 0;
$queueCount = 0;
$integrationLogCount = 0;
$recentIntegrationLogs = [];

if ($dbAvailable && $pdo !== null && $integrationLogService !== null) {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM message_logs WHERE phone = :phone');
    $statement->execute(['phone' => $phone]);
    $messageLogCount = (int) $statement->fetchColumn();

    $statement = $pdo->prepare('SELECT COUNT(*) FROM message_queue WHERE phone = :phone');
    $statement->execute(['phone' => $phone]);
    $queueCount = (int) $statement->fetchColumn();

    if ($resolvedCpf !== null && $resolvedCpf !== '') {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM integration_logs WHERE phone = :phone OR cpf = :cpf');
        $statement->execute(['phone' => $phone, 'cpf' => $resolvedCpf]);
        $integrationLogCount = (int) $statement->fetchColumn();
        $recentIntegrationLogs = $integrationLogService->findRecent(10, null, $resolvedCpf);
    } else {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM integration_logs WHERE phone = :phone');
        $statement->execute(['phone' => $phone]);
        $integrationLogCount = (int) $statement->fetchColumn();
        $recentIntegrationLogs = $integrationLogService->findRecent(10, null, $phone);
    }
}

Response::json([
    'ok' => true,
    'action' => 'status',
    'phone' => $phone,
    'cpf' => $resolvedCpf,
    'ttl_minutes' => $sessionTtlMinutes,
    'db_available' => $dbAvailable,
    'session' => $currentSession?->toArray(),
    'history' => [
        'message_logs' => $messageLogCount,
        'message_queue' => $queueCount,
        'integration_logs' => $integrationLogCount,
    ],
    'recent_integration_logs' => $recentIntegrationLogs,
])->send();
