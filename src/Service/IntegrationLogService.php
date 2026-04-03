<?php

namespace App\Service;

use App\Infrastructure\Persistence\IntegrationLogRepositoryInterface;

class IntegrationLogService
{
    public function __construct(
        private readonly IntegrationLogRepositoryInterface $repository
    ) {
    }

    public function log(array $data): void
    {
        $this->repository->log([
            'service' => $data['service'] ?? 'unknown',
            'endpoint' => $data['endpoint'] ?? '',
            'phone' => $data['phone'] ?? null,
            'cpf' => $data['cpf'] ?? null,
            'request_payload' => $this->encode($data['request_payload'] ?? null),
            'response_payload' => $this->encode($data['response_payload'] ?? null),
            'status_code' => $data['status_code'] ?? null,
        ]);
    }

    public function findRecent(int $limit = 20, ?string $service = null, ?string $contains = null): array
    {
        $rows = $this->repository->findRecent($limit, $service);

        if ($contains === null || trim($contains) === '') {
            return $rows;
        }

        $needle = mb_strtolower($contains);

        return array_values(array_filter($rows, static function (array $row) use ($needle): bool {
            $requestPayload = mb_strtolower((string) ($row['request_payload'] ?? ''));
            $responsePayload = mb_strtolower((string) ($row['response_payload'] ?? ''));
            $phone = mb_strtolower((string) ($row['phone'] ?? ''));
            $cpf = mb_strtolower((string) ($row['cpf'] ?? ''));

            return str_contains($requestPayload, $needle)
                || str_contains($responsePayload, $needle)
                || str_contains($phone, $needle)
                || str_contains($cpf, $needle);
        }));
    }

    public function deleteByPhone(string $phone): int
    {
        return $this->repository->deleteByPhone($phone);
    }

    public function deleteByCpf(string $cpf): int
    {
        return $this->repository->deleteByCpf($cpf);
    }

    private function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
