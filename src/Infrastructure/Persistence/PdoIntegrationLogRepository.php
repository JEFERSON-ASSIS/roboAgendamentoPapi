<?php

namespace App\Infrastructure\Persistence;

use PDO;

class PdoIntegrationLogRepository implements IntegrationLogRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function log(array $data): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO integration_logs (service, endpoint, phone, cpf, request_payload, response_payload, status_code) VALUES (:service, :endpoint, :phone, :cpf, :request_payload, :response_payload, :status_code)'
        );

        $statement->execute([
            'service' => $data['service'] ?? 'unknown',
            'endpoint' => $data['endpoint'] ?? '',
            'phone' => $data['phone'] ?? null,
            'cpf' => $data['cpf'] ?? null,
            'request_payload' => $data['request_payload'] ?? null,
            'response_payload' => $data['response_payload'] ?? null,
            'status_code' => $data['status_code'] ?? null,
        ]);
    }

    public function findRecent(int $limit = 20, ?string $service = null): array
    {
        $limit = max(1, min($limit, 100));

        if ($service !== null && trim($service) !== '') {
            $statement = $this->pdo->prepare(
                'SELECT id, service, endpoint, phone, cpf, request_payload, response_payload, status_code, created_at
                 FROM integration_logs
                 WHERE service = :service
                 ORDER BY id DESC
                 LIMIT ' . $limit
            );
            $statement->execute(['service' => $service]);

            return $statement->fetchAll() ?: [];
        }

        $statement = $this->pdo->query(
            'SELECT id, service, endpoint, phone, cpf, request_payload, response_payload, status_code, created_at
             FROM integration_logs
             ORDER BY id DESC
             LIMIT ' . $limit
        );

        return $statement !== false ? ($statement->fetchAll() ?: []) : [];
    }

    public function deleteByPhone(string $phone): int
    {
        $statement = $this->pdo->prepare('DELETE FROM integration_logs WHERE phone = :phone');
        $statement->execute(['phone' => $phone]);

        return $statement->rowCount();
    }

    public function deleteByCpf(string $cpf): int
    {
        $statement = $this->pdo->prepare('DELETE FROM integration_logs WHERE cpf = :cpf');
        $statement->execute(['cpf' => $cpf]);

        return $statement->rowCount();
    }
}
