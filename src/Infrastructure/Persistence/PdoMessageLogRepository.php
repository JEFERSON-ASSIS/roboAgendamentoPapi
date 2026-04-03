<?php

namespace App\Infrastructure\Persistence;

use PDO;
use Throwable;

class PdoMessageLogRepository implements MessageLogRepositoryInterface
{
    private bool $monitorConversationTableEnsured = false;

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function log(array $data): void
    {
        $phone = (string) ($data['phone'] ?? '');
        $direction = (string) ($data['direction'] ?? 'in');

        $statement = $this->pdo->prepare(
            'INSERT INTO message_logs (phone, direction, message_type, raw_payload, normalized_text) VALUES (:phone, :direction, :message_type, :raw_payload, :normalized_text)'
        );

        $statement->execute([
            'phone' => $phone,
            'direction' => $direction,
            'message_type' => $data['message_type'] ?? 'text',
            'raw_payload' => $data['raw_payload'] ?? null,
            'normalized_text' => $data['normalized_text'] ?? null,
        ]);

        if ($direction === 'in' && $phone !== '') {
            $this->reactivateConversationOnIncomingMessage($phone);
        }
    }

    public function deleteById(int $id): int
    {
        $statement = $this->pdo->prepare('DELETE FROM message_logs WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount();
    }

    public function deleteByPhone(string $phone): int
    {
        $statement = $this->pdo->prepare('DELETE FROM message_logs WHERE phone = :phone');
        $statement->execute(['phone' => $phone]);

        return $statement->rowCount();
    }

    public function findConversations(int $limit = 50, ?string $search = null): array
    {
        $limit = max(1, min($limit, 200));
        $searchTerm = trim((string) $search);

        $sql = sprintf(<<<'SQL'
SELECT summary.phone,
       summary.last_message_at,
       summary.total_messages,
       summary.incoming_messages,
       summary.outgoing_messages,
       ml.direction AS last_direction,
       ml.message_type AS last_message_type,
       ml.normalized_text AS last_message_text,
       ml.raw_payload AS last_raw_payload,
       COALESCE(monitor.status, 'active') AS conversation_status,
       monitor.last_read_message_id,
       CASE
           WHEN monitor.last_read_message_id IS NULL THEN CASE WHEN ml.direction = 'in' THEN 1 ELSE 0 END
           ELSE (
               SELECT COUNT(*)
               FROM message_logs unread
               WHERE unread.phone = summary.phone
                 AND unread.direction = 'in'
                 AND unread.id > monitor.last_read_message_id
           )
       END AS unread_count
FROM (
    SELECT phone,
           MAX(id) AS last_id,
           MAX(created_at) AS last_message_at,
           COUNT(*) AS total_messages,
           SUM(CASE WHEN direction = 'in' THEN 1 ELSE 0 END) AS incoming_messages,
           SUM(CASE WHEN direction = 'out' THEN 1 ELSE 0 END) AS outgoing_messages
    FROM message_logs
    WHERE phone LIKE :search
    GROUP BY phone
    ORDER BY last_message_at DESC, last_id DESC
    LIMIT %d
) AS summary
INNER JOIN message_logs ml ON ml.id = summary.last_id
LEFT JOIN message_monitor_conversations monitor ON monitor.phone = summary.phone
ORDER BY summary.last_message_at DESC, summary.last_id DESC
SQL,
            $limit
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'search' => $searchTerm === '' ? '%' : '%' . $searchTerm . '%',
        ]);

        return $statement->fetchAll() ?: [];
    }

    public function findMessagesByPhone(string $phone, int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));

        $sql = sprintf(<<<'SQL'
SELECT recent.id,
       recent.phone,
       recent.direction,
       recent.message_type,
       recent.raw_payload,
       recent.normalized_text,
       recent.created_at
FROM (
    SELECT id, phone, direction, message_type, raw_payload, normalized_text, created_at
    FROM message_logs
    WHERE phone = :phone
    ORDER BY id DESC
    LIMIT %d
) AS recent
ORDER BY recent.id ASC
SQL,
            $limit
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'phone' => $phone,
        ]);

        return $statement->fetchAll() ?: [];
    }

    private function reactivateConversationOnIncomingMessage(string $phone): void
    {
        try {
            $this->ensureMonitorConversationStateTable();

            $statement = $this->pdo->prepare(
                "INSERT INTO message_monitor_conversations (phone, status) VALUES (:phone, 'active') ON DUPLICATE KEY UPDATE status = VALUES(status)"
            );

            $statement->execute(['phone' => $phone]);
        } catch (Throwable) {
            // O log principal da mensagem nao deve falhar se o monitor ainda nao estiver disponivel.
        }
    }

    private function ensureMonitorConversationStateTable(): void
    {
        if ($this->monitorConversationTableEnsured) {
            return;
        }

        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS message_monitor_conversations (
    phone VARCHAR(30) NOT NULL PRIMARY KEY,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    last_read_message_id INT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
SQL);

        $this->monitorConversationTableEnsured = true;
    }
}