<?php

define('BASE_PATH', dirname(__DIR__, 2));

require_once BASE_PATH . '/src/Core/helpers.php';

if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require_once BASE_PATH . '/vendor/autoload.php';
} else {
    require_once BASE_PATH . '/src/Core/Autoloader.php';

    App\Core\Autoloader::register();
}

App\Core\Env::load(BASE_PATH . '/.env');
App\Core\Config::load(BASE_PATH . '/config');

ini_set('default_charset', 'UTF-8');

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

if (function_exists('mb_http_output')) {
    mb_http_output('UTF-8');
}

date_default_timezone_set(App\Core\Config::get('app.timezone', 'America/Cuiaba'));
