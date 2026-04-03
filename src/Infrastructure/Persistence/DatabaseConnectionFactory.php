<?php

namespace App\Infrastructure\Persistence;

use PDO;
use PDOException;
use RuntimeException;

class DatabaseConnectionFactory
{
    public function make(): PDO
    {
        $driver = (string) config('database.default', 'mysql');

        if ($driver !== 'mysql') {
            throw new RuntimeException('Driver de banco nao suportado: ' . $driver);
        }

        $profile = (string) config('database.profile', 'default');
        $host = (string) config('database.host', '127.0.0.1');
        $port = (string) config('database.port', '3306');
        $database = (string) config('database.database', 'robo_agendamento');
        $username = (string) config('database.username', 'root');
        $password = (string) config('database.password', '');
        $charset = (string) config('database.charset', 'utf8mb4');
        $collation = $charset === 'utf8mb4' ? 'utf8mb4_unicode_ci' : $charset . '_general_ci';

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $database, $charset);

        try {
            return new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => sprintf("SET NAMES %s COLLATE %s", $charset, $collation),
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException(sprintf('Falha ao conectar no banco (%s): %s', $profile, $exception->getMessage()), 0, $exception);
        }
    }
}