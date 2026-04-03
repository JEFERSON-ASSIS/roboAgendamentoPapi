<?php

namespace App\Infrastructure\Persistence;

use App\DTO\IncomingMessageDTO;
use PDO;

class PdoMessageQueueRepository implements MessageQueueRepositoryInterface
{
    private bool $providerColumnsEnsured = false;

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function enqueue(IncomingMessageDTO $message): int
    {
        $this->ensureProviderColumns();

        $statement = $this->pdo->prepare(
            'INSERT INTO message_queue (provider, phone, external_message_id, message_text, message_type, media_url, payload_json, processed) VALUES (:provider, :phone, :external_message_id, :message_text, :message_type, :media_url, :payload_json, 0)'
        );

        $statement->execute([
            'provider' => $message->provider,
            'phone' => $message->phone,
            'external_message_id' => $message->externalMessageId,
            'message_text' => $message->message,
            'message_type' => $message->messageType,
            'media_url' => $message->mediaUrl,
            'payload_json' => json_encode($message->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function hasRecentExternalMessageId(string $provider, string $phone, string $externalMessageId): bool
    {
        $this->ensureProviderColumns();

        if (trim($externalMessageId) === '') {
            return false;
        }

        $statement = $this->pdo->prepare(
            'SELECT id
             FROM message_queue
             WHERE provider = :provider
               AND phone = :phone
               AND external_message_id = :external_message_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute([
            'provider' => $provider,
            'phone' => $phone,
            'external_message_id' => $externalMessageId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function acquireConversationLock(string $provider, string $phone, int $timeoutSeconds): bool
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, :timeout_seconds)');
        $statement->execute([
            'lock_name' => $this->buildLockName($provider, $phone),
            'timeout_seconds' => max(0, $timeoutSeconds),
        ]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function releaseConversationLock(string $provider, string $phone): void
    {
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $statement->execute([
            'lock_name' => $this->buildLockName($provider, $phone),
        ]);
    }

    public function findPendingByConversation(string $provider, string $phone): array
    {
        $this->ensureProviderColumns();

        $statement = $this->pdo->prepare(
            'SELECT id, provider, phone, external_message_id, message_text, message_type, media_url, payload_json, created_at
             FROM message_queue
             WHERE provider = :provider AND phone = :phone AND processed = 0
             ORDER BY created_at ASC, id ASC'
        );
        $statement->execute([
            'provider' => $provider,
            'phone' => $phone,
        ]);

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

    public function deleteByPhone(string $phone, ?string $provider = null): int
    {
        $this->ensureProviderColumns();

        if ($provider !== null && trim($provider) !== '') {
            $statement = $this->pdo->prepare('DELETE FROM message_queue WHERE provider = :provider AND phone = :phone');
            $statement->execute([
                'provider' => strtolower(trim($provider)),
                'phone' => $phone,
            ]);
        } else {
            $statement = $this->pdo->prepare('DELETE FROM message_queue WHERE phone = :phone');
            $statement->execute(['phone' => $phone]);
        }

        return $statement->rowCount();
    }

    private function buildLockName(string $provider, string $phone): string
    {
        return 'message_queue_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($provider)) . '_' . preg_replace('/\D+/', '', $phone);
    }

    private function ensureProviderColumns(): void
    {
        if ($this->providerColumnsEnsured) {
            return;
        }

        $columns = [];
        foreach ($this->pdo->query('SHOW COLUMNS FROM message_queue') ?: [] as $row) {
            $columns[] = strtolower((string) ($row['Field'] ?? ''));
        }

        if (!in_array('provider', $columns, true)) {
            $this->pdo->exec("ALTER TABLE message_queue ADD COLUMN provider VARCHAR(20) NOT NULL DEFAULT 'evolution' AFTER id");
        }

        if (!in_array('external_message_id', $columns, true)) {
            $this->pdo->exec('ALTER TABLE message_queue ADD COLUMN external_message_id VARCHAR(191) NULL AFTER phone');
        }

        $this->providerColumnsEnsured = true;
    }
}
