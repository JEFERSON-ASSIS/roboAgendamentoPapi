<?php

namespace App\Core;

class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register(function (string $class): void {
            $prefix = 'App\\';

            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relativeClass = substr($class, strlen($prefix));
            $file = base_path('src/' . str_replace('\\', '/', $relativeClass) . '.php');

            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
