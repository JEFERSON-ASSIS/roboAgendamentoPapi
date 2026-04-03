<?php

namespace App\Infrastructure\Persistence;

use App\DTO\SessionDTO;
use PDO;

class PdoSessionRepository implements SessionRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $ttlMinutes = 0
    ) {
    }

    public function findByPhone(string $phone): ?SessionDTO
    {
        $statement = $this->pdo->prepare('SELECT * FROM sessions WHERE phone = :phone LIMIT 1');
        $statement->execute(['phone' => $phone]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        if ($this->isExpired($row['last_interaction_at'] ?? null)) {
            $this->deleteByPhone($phone);
            return null;
        }

        return SessionDTO::fromArray([
            'phone' => $row['phone'] ?? '',
            'cpf' => $row['cpf'] ?? null,
            'nome' => $row['nome'] ?? null,
            'telefone' => $row['telefone'] ?? null,
            'current_flow' => $row['current_flow'] ?? 'idle',
            'current_step' => $row['current_step'] ?? 'awaiting_menu_choice',
            'selected_service' => $row['selected_service'] ?? null,
            'selected_date' => $row['selected_date'] ?? null,
            'selected_time' => $row['selected_time'] ?? null,
            'pending_action' => $row['pending_action'] ?? null,
            'context' => $this->decodeContext($row['context_json'] ?? null),
        ]);
    }

    public function save(SessionDTO $session): SessionDTO
    {
        $sql = <<<'SQL'
INSERT INTO sessions (
    phone, cpf, nome, telefone, current_flow, current_step,
    selected_service, selected_date, selected_time, pending_action,
    context_json, last_interaction_at
) VALUES (
    :phone, :cpf, :nome, :telefone, :current_flow, :current_step,
    :selected_service, :selected_date, :selected_time, :pending_action,
    :context_json, NOW()
)
ON DUPLICATE KEY UPDATE
    cpf = VALUES(cpf),
    nome = VALUES(nome),
    telefone = VALUES(telefone),
    current_flow = VALUES(current_flow),
    current_step = VALUES(current_step),
    selected_service = VALUES(selected_service),
    selected_date = VALUES(selected_date),
    selected_time = VALUES(selected_time),
    pending_action = VALUES(pending_action),
    context_json = VALUES(context_json),
    last_interaction_at = NOW()
SQL;

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'phone' => $session->phone,
            'cpf' => $session->cpf,
            'nome' => $session->nome,
            'telefone' => $session->telefone,
            'current_flow' => $session->currentFlow,
            'current_step' => $session->currentStep,
            'selected_service' => $session->selectedService,
            'selected_date' => $session->selectedDate,
            'selected_time' => $session->selectedTime,
            'pending_action' => $session->pendingAction,
            'context_json' => json_encode($session->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $session;
    }

    public function deleteByPhone(string $phone): int
    {
        $statement = $this->pdo->prepare('DELETE FROM sessions WHERE phone = :phone');
        $statement->execute(['phone' => $phone]);

        return $statement->rowCount();
    }

    private function decodeContext(?string $context): array
    {
        if ($context === null || trim($context) === '') {
            return [];
        }

        $decoded = json_decode($context, true);

        return is_array($decoded) ? $decoded : [];
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
