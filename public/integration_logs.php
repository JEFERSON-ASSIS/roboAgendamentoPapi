<?php

require_once dirname(__DIR__) . '/src/Core/bootstrap.php';

use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Persistence\DatabaseConnectionFactory;
use App\Infrastructure\Persistence\PdoIntegrationLogRepository;
use App\Service\IntegrationLogService;

$request = Request::capture();
$remoteAddress = (string) $request->server('REMOTE_ADDR', '');
$isLocalRequest = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
$debugToken = (string) env('INTEGRATION_DEBUG_TOKEN', '');
$providedToken = (string) $request->query('token', '');

if (!$isLocalRequest) {
    if ($debugToken === '' || !hash_equals($debugToken, $providedToken)) {
        Response::json([
            'ok' => false,
            'message' => 'Acesso negado.',
        ], 403)->send();
        return;
    }
}

try {
    $pdo = (new DatabaseConnectionFactory())->make();
    $service = new IntegrationLogService(new PdoIntegrationLogRepository($pdo));
    $limit = (int) $request->query('limit', 20);
    $serviceFilter = $request->query('service');
    $contains = $request->query('contains');

    Response::json([
        'ok' => true,
        'filters' => [
            'limit' => $limit,
            'service' => $serviceFilter,
            'contains' => $contains,
        ],
        'logs' => $service->findRecent($limit, is_string($serviceFilter) ? $serviceFilter : null, is_string($contains) ? $contains : null),
    ])->send();
} catch (Throwable $exception) {
    Response::json([
        'ok' => false,
        'message' => 'Nao foi possivel consultar os logs de integracao.',
        'error' => $exception->getMessage(),
    ], 500)->send();
}
