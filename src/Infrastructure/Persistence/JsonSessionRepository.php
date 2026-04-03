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

    public function findByPhone(string $phone): ?SessionDTO
    {
        $sessions = $this->readAll();

        if (!isset($sessions[$phone]) || !is_array($sessions[$phone])) {
            return null;
        }

        $row = $sessions[$phone];

        if ($this->isExpired($row['last_interaction_at'] ?? null)) {
            unset($sessions[$phone]);
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
        $sessions[$session->phone] = $data;
        $this->writeAll($sessions);

        return $session;
    }

    public function deleteByPhone(string $phone): int
    {
        $sessions = $this->readAll();

        if (!isset($sessions[$phone])) {
            return 0;
        }

        unset($sessions[$phone]);
        $this->writeAll($sessions);

        return 1;
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
}
