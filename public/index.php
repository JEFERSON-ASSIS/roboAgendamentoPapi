<?php

require_once dirname(__DIR__) . '/src/Core/bootstrap.php';

use App\Core\Response;

$response = Response::json([
    'ok' => true,
    'app' => config('app.name'),
    'env' => config('app.env'),
    'message' => 'Base do robo de agendamento pronta.',
]);

$response->send();
