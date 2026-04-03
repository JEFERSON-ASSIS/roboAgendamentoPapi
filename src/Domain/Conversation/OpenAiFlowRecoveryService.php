<?php

namespace App\Domain\Conversation;

use App\DTO\FlowRecoveryDecisionDTO;
use App\DTO\IncomingMessageDTO;
use App\DTO\SessionDTO;
use App\Service\OpenAIClient;
use RuntimeException;

class OpenAiFlowRecoveryService implements FlowRecoveryServiceInterface
{
    public function __construct(
        private readonly OpenAIClient $client,
        private readonly string $model
    ) {
    }

    public function decide(IncomingMessageDTO $message, SessionDTO $session): FlowRecoveryDecisionDTO
    {
        if (in_array($session->currentFlow, ['idle', 'completed'], true)) {
            return new FlowRecoveryDecisionDTO();
        }

        $payload = [
            'message' => $message->message,
            'message_type' => $message->messageType,
            'session' => [
                'current_flow' => $session->currentFlow,
                'current_step' => $session->currentStep,
                'selected_service' => $session->selectedService,
                'pending_action' => $session->pendingAction,
                'selected_date' => $session->selectedDate,
                'selected_time' => $session->selectedTime,
                'has_cpf' => $session->cpf !== null,
                'has_nome' => $session->nome !== null,
                'has_telefone' => $session->telefone !== null,
                'last_assistant_reply' => $session->context['last_assistant_reply'] ?? null,
                'last_user_message' => $session->context['last_user_message'] ?? null,
                'available_dates' => $session->context['available_dates'] ?? [],
                'available_times' => $session->context['available_times'] ?? [],
                'available_cancel_ids' => $session->context['available_cancel_ids'] ?? [],
                'pending_expectation' => $this->describePendingExpectation($session),
            ],
            'valid_actions' => ['continue', 'clarify', 'complete', 'menu', 'switch_flow'],
            'valid_target_intents' => [
                'agendar_medico',
                'agendar_dentista',
                'agendar_enfermeiro',
                'consultar_agendamentos',
                'cancelar_agendamento',
            ],
        ];

        $result = $this->client->chatJson([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'Voce e um validador de continuidade de fluxo do robo de agendamento da UBS Vida Nova (PSF02).',
                    'Sua funcao e ajudar a destravar a conversa quando o usuario muda de assunto, agradece, encerra ou pede outro fluxo no meio do atendimento.',
                    'Retorne somente JSON valido.',
                    'Campos esperados: action, target_intent, reply_message, confidence.',
                    'action deve ser um de: continue, clarify, complete, menu, switch_flow.',
                    'target_intent so deve ser preenchido quando action=switch_flow.',
                    'reply_message pode ser null.',
                    'Sempre avalie primeiro se a resposta do usuario combina com o que esta pendente em session.current_step, session.pending_action e session.pending_expectation.',
                    'Se a resposta nao combinar com a etapa pendente e o usuario tambem nao estiver mudando claramente de assunto, use action=clarify com uma reply_message curta dizendo o que falta para continuar.',
                    'Se a mensagem for apenas agradecimento, confirmacao leve ou encerramento natural, prefira action=complete.',
                    'Se o usuario estiver claramente pedindo outro fluxo, use action=switch_flow.',
                    'Se a mensagem ainda combina com a etapa atual, use action=continue.',
                    'Nao invente dados e nao chame tools.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ], $this->model);

        $action = (string) ($result['action'] ?? 'continue');
        $targetIntent = $this->normalizeStringOrNull($result['target_intent'] ?? null);
        $replyMessage = $this->normalizeStringOrNull($result['reply_message'] ?? null);
        $confidence = (float) ($result['confidence'] ?? 0.0);

        if ($action === '') {
            throw new RuntimeException('Action vazia retornada pela OpenAI no flow recovery.');
        }

        if (!in_array($action, ['continue', 'clarify', 'complete', 'menu', 'switch_flow'], true)) {
            return new FlowRecoveryDecisionDTO();
        }

        return new FlowRecoveryDecisionDTO(
            action: $action,
            targetIntent: $targetIntent,
            replyMessage: $replyMessage,
            confidence: $confidence,
            source: 'openai_recovery'
        );
    }

    private function describePendingExpectation(SessionDTO $session): ?string
    {
        return match ($session->currentStep) {
            'awaiting_cpf' => 'O usuario precisa enviar um CPF valido com 11 numeros.',
            'awaiting_name' => 'O usuario precisa informar o nome completo do paciente.',
            'awaiting_phone' => 'O usuario precisa informar o telefone com DDD.',
            'awaiting_date_choice' => 'O usuario precisa escolher uma das datas disponiveis.',
            'awaiting_time_choice' => 'O usuario precisa escolher um dos horarios disponiveis.',
            'awaiting_cancellation_choice' => 'O usuario precisa informar o ID do agendamento que deseja cancelar.',
            'awaiting_cancellation_confirmation' => 'O usuario precisa responder Sim ou Nao para confirmar o cancelamento.',
            'awaiting_lookup_retry' => $session->currentFlow === 'cancelar_agendamento'
                ? 'O usuario pode enviar outro CPF, dizer a data aproximada ou pedir para consultar os agendamentos gerais.'
                : 'O usuario pode enviar outro CPF ou dizer a data aproximada do atendimento.',
            default => null,
        };
    }

    private function normalizeStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
