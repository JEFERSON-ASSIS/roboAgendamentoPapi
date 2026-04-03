<?php

namespace App\Core;

class Config
{
    private static array $items = [];

    public static function load(string $configPath): void
    {
        foreach (glob($configPath . '/*.php') ?: [] as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            self::$items[$key] = require $file;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
