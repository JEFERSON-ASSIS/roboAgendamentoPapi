<?php

namespace App\Infrastructure\Persistence;

use App\DTO\IncomingMessageDTO;
use PDO;

class PdoMessageQueueRepository implements MessageQueueRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function enqueue(IncomingMessageDTO $message): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO message_queue (phone, message_text, message_type, media_url, payload_json, processed) VALUES (:phone, :message_text, :message_type, :media_url, :payload_json, 0)'
        );

        $statement->execute([
            'phone' => $message->phone,
            'message_text' => $message->message,
            'message_type' => $message->messageType,
            'media_url' => $message->mediaUrl,
            'payload_json' => json_encode($message->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function acquirePhoneLock(string $phone, int $timeoutSeconds): bool
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, :timeout_seconds)');
        $statement->execute([
            'lock_name' => $this->buildLockName($phone),
            'timeout_seconds' => max(0, $timeoutSeconds),
        ]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function releasePhoneLock(string $phone): void
    {
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $statement->execute([
            'lock_name' => $this->buildLockName($phone),
        ]);
    }

    public function findPendingByPhone(string $phone): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, phone, message_text, message_type, media_url, payload_json, created_at
             FROM message_queue
             WHERE phone = :phone AND processed = 0
             ORDER BY created_at ASC, id ASC'
        );
        $statement->execute(['phone' => $phone]);

        return $statement->fetchAll() ?: [];
    }

    public function markProcessed(array $ids): void
    {
        $ids = array_values(array_filter(array_map(static fn ($id): int => (int) $id, $ids), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare('UPDATE message_queue SET processed = 1, processed_at = NOW() WHERE id IN (' . $placeholders . ')');
        $statement->execute($ids);
    }

    public function deleteByPhone(string $phone): int
    {
        $statement = $this->pdo->prepare('DELETE FROM message_queue WHERE phone = :phone');
        $statement->execute(['phone' => $phone]);

        return $statement->rowCount();
    }

    private function buildLockName(string $phone): string
    {
        return 'message_queue_phone_' . preg_replace('/\D+/', '', $phone);
    }
}
