<?php

namespace App\Infrastructure\Persistence;

use PDO;
use Throwable;

class PdoMessageLogRepository implements MessageLogRepositoryInterface
{
    private bool $monitorConversationTableEnsured = false;
    private bool $providerColumnsEnsured = false;

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function log(array $data): void
    {
        $this->ensureProviderColumns();

        $phone = (string) ($data['phone'] ?? '');
        $direction = (string) ($data['direction'] ?? 'in');
        $provider = strtolower(trim((string) ($data['provider'] ?? 'evolution'))) ?: 'evolution';

        $statement = $this->pdo->prepare(
            'INSERT INTO message_logs (provider, phone, direction, message_type, raw_payload, normalized_text) VALUES (:provider, :phone, :direction, :message_type, :raw_payload, :normalized_text)'
        );

        $statement->execute([
            'provider' => $provider,
            'phone' => $phone,
            'direction' => $direction,
            'message_type' => $data['message_type'] ?? 'text',
            'raw_payload' => $data['raw_payload'] ?? null,
            'normalized_text' => $data['normalized_text'] ?? null,
        ]);

        if ($direction === 'in' && $phone !== '') {
            $this->reactivateConversationOnIncomingMessage($provider, $phone);
        }
    }

    public function deleteById(int $id): int
    {
        $statement = $this->pdo->prepare('DELETE FROM message_logs WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount();
    }

    public function deleteByPhone(string $phone, ?string $provider = null): int
    {
        $this->ensureProviderColumns();

        if ($provider !== null && trim($provider) !== '') {
            $statement = $this->pdo->prepare('DELETE FROM message_logs WHERE provider = :provider AND phone = :phone');
            $statement->execute([
                'provider' => strtolower(trim($provider)),
                'phone' => $phone,
            ]);
        } else {
            $statement = $this->pdo->prepare('DELETE FROM message_logs WHERE phone = :phone');
            $statement->execute(['phone' => $phone]);
        }

        return $statement->rowCount();
    }

    public function findConversations(int $limit = 50, ?string $search = null): array
    {
        $this->ensureProviderColumns();

        $limit = max(1, min($limit, 200));
        $searchTerm = trim((string) $search);

        $sql = sprintf(<<<'SQL'
SELECT summary.provider,
       summary.phone,
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
               WHERE unread.provider = summary.provider
                 AND unread.phone = summary.phone
                 AND unread.direction = 'in'
                 AND unread.id > monitor.last_read_message_id
           )
       END AS unread_count
FROM (
    SELECT provider,
           phone,
           MAX(id) AS last_id,
           MAX(created_at) AS last_message_at,
           COUNT(*) AS total_messages,
           SUM(CASE WHEN direction = 'in' THEN 1 ELSE 0 END) AS incoming_messages,
           SUM(CASE WHEN direction = 'out' THEN 1 ELSE 0 END) AS outgoing_messages
    FROM message_logs
    WHERE phone LIKE :search
    GROUP BY provider, phone
    ORDER BY last_message_at DESC, last_id DESC
    LIMIT %d
) AS summary
INNER JOIN message_logs ml ON ml.id = summary.last_id
LEFT JOIN message_monitor_conversations monitor ON monitor.provider = summary.provider AND monitor.phone = summary.phone
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

    public function findMessagesByPhone(string $phone, int $limit = 200, ?string $provider = null): array
    {
        $this->ensureProviderColumns();

        $limit = max(1, min($limit, 500));

        $sql = sprintf(<<<'SQL'
SELECT recent.id,
       recent.provider,
       recent.phone,
       recent.direction,
       recent.message_type,
       recent.raw_payload,
       recent.normalized_text,
       recent.created_at
FROM (
    SELECT id, provider, phone, direction, message_type, raw_payload, normalized_text, created_at
    FROM message_logs
    WHERE phone = :phone %s
    ORDER BY id DESC
    LIMIT %d
) AS recent
ORDER BY recent.id ASC
SQL,
            $provider !== null && trim($provider) !== '' ? 'AND provider = :provider' : '',
            $limit
        );

        $statement = $this->pdo->prepare($sql);
        $params = [
            'phone' => $phone,
        ];
        if ($provider !== null && trim($provider) !== '') {
            $params['provider'] = strtolower(trim($provider));
        }
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

    private function reactivateConversationOnIncomingMessage(string $provider, string $phone): void
    {
        try {
            $this->ensureMonitorConversationStateTable();

            $statement = $this->pdo->prepare(
                "INSERT INTO message_monitor_conversations (provider, phone, status) VALUES (:provider, :phone, 'active') ON DUPLICATE KEY UPDATE status = VALUES(status)"
            );

            $statement->execute([
                'provider' => $provider,
                'phone' => $phone,
            ]);
        } catch (Throwable) {
            // O log principal da mensagem nao deve falhar se o monitor ainda nao estiver disponivel.
        }
    }

    private function ensureMonitorConversationStateTable(): void
    {
        if ($this->monitorConversationTableEnsured) {
            return;
        }

        $this->ensureProviderColumns();

        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS message_monitor_conversations (
    provider VARCHAR(20) NOT NULL DEFAULT 'evolution',
    phone VARCHAR(30) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    last_read_message_id INT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (provider, phone)
);
SQL);

        $this->monitorConversationTableEnsured = true;
    }

    private function ensureProviderColumns(): void
    {
        if ($this->providerColumnsEnsured) {
            return;
        }

        $logColumns = [];
        foreach ($this->pdo->query('SHOW COLUMNS FROM message_logs') ?: [] as $row) {
            $logColumns[] = strtolower((string) ($row['Field'] ?? ''));
        }

        if (!in_array('provider', $logColumns, true)) {
            $this->pdo->exec("ALTER TABLE message_logs ADD COLUMN provider VARCHAR(20) NOT NULL DEFAULT 'evolution' AFTER id");
        }

        $monitorColumns = [];
        foreach ($this->pdo->query("SHOW TABLES LIKE 'message_monitor_conversations'") ?: [] as $row) {
            $monitorColumns = ['exists'];
        }

        if ($monitorColumns !== []) {
            $columns = [];
            foreach ($this->pdo->query('SHOW COLUMNS FROM message_monitor_conversations') ?: [] as $row) {
                $columns[] = strtolower((string) ($row['Field'] ?? ''));
            }

            if (!in_array('provider', $columns, true)) {
                $this->pdo->exec("ALTER TABLE message_monitor_conversations DROP PRIMARY KEY, ADD COLUMN provider VARCHAR(20) NOT NULL DEFAULT 'evolution' FIRST, ADD PRIMARY KEY (provider, phone)");
            }
        }

        $this->providerColumnsEnsured = true;
    }
}
