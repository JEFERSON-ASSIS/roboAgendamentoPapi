<?php

namespace App\Infrastructure\Persistence;

use App\DTO\SessionDTO;

class JsonSessionRepository implements SessionRepositoryInterface
{
    public function __construct(
        private readonly string $path,
        private readonly int $ttlMinutes = 0
    ) {
    }

    public function findByPhone(string $phone, ?string $provider = null): ?SessionDTO
    {
        $sessions = $this->readAll();
        $key = $this->buildKey($phone, $provider);

        if ((!isset($sessions[$key]) || !is_array($sessions[$key])) && isset($sessions[$phone]) && is_array($sessions[$phone])) {
            $key = $phone;
        }

        if (!isset($sessions[$key]) || !is_array($sessions[$key])) {
            return null;
        }

        $row = $sessions[$key];

        if ($this->isExpired($row['last_interaction_at'] ?? null)) {
            unset($sessions[$key]);
            $this->writeAll($sessions);

            return null;
        }

        return SessionDTO::fromArray($row);
    }

    public function save(SessionDTO $session): SessionDTO
    {
        $sessions = $this->readAll();
        $data = $session->toArray();
        $data['last_interaction_at'] = date('Y-m-d H:i:s');
        $sessions[$this->buildKey($session->phone, $session->provider)] = $data;
        $this->writeAll($sessions);

        return $session;
    }

    public function deleteByPhone(string $phone, ?string $provider = null): int
    {
        $sessions = $this->readAll();

        if ($provider !== null && trim($provider) !== '') {
            $key = $this->buildKey($phone, $provider);

            if (!isset($sessions[$key])) {
                return 0;
            }

            unset($sessions[$key]);
            $this->writeAll($sessions);

            return 1;
        }

        $removed = 0;
        foreach (array_keys($sessions) as $key) {
            if ((string) $key === $phone || preg_match('/:' . preg_quote($phone, '/') . '$/', (string) $key) === 1) {
                unset($sessions[$key]);
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->writeAll($sessions);
        }

        return $removed;
    }

    private function readAll(): array
    {
        $file = base_path($this->path);
        $directory = dirname($file);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (!is_file($file)) {
            file_put_contents($file, json_encode([], JSON_PRETTY_PRINT));
        }

        $content = file_get_contents($file);
        $decoded = json_decode($content ?: '{}', true);

        return is_array($decoded) ? $decoded : [];
    }

    private function writeAll(array $sessions): void
    {
        $file = base_path($this->path);
        file_put_contents($file, json_encode($sessions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    private function isExpired(mixed $lastInteractionAt): bool
    {
        if ($this->ttlMinutes <= 0 || !is_string($lastInteractionAt) || trim($lastInteractionAt) === '') {
            return false;
        }

        $timestamp = strtotime($lastInteractionAt);

        if ($timestamp === false) {
            return false;
        }

        return $timestamp < strtotime('-' . $this->ttlMinutes . ' minutes');
    }

    private function buildKey(string $phone, ?string $provider = null): string
    {
        $provider = strtolower(trim((string) ($provider ?? 'evolution'))) ?: 'evolution';

        return $provider . ':' . $phone;
    }
}

