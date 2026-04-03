<?php

namespace App\Domain\Conversation;

use App\DTO\AiAnalysisDTO;
use App\DTO\IncomingMessageDTO;
use App\DTO\SessionDTO;
use App\Service\OpenAIClient;
use RuntimeException;

class OpenAiConversationInterpreter implements ConversationInterpreterInterface
{
    public function __construct(
        private readonly OpenAIClient $client,
        private readonly string $model
    ) {
    }

    public function analyze(IncomingMessageDTO $message, SessionDTO $session): AiAnalysisDTO
    {
        $payload = [
            'message' => $message->message,
            'message_type' => $message->messageType,
            'push_name' => $message->pushName,
            'session' => $session->toArray(),
            'valid_intents' => [
                'menu',
                'agendar_medico',
                'agendar_dentista',
                'agendar_enfermeiro',
                'consultar_agendamentos',
                'cancelar_agendamento',
                'confirmar',
                'negar',
                'change_cpf',
                'unknown',
            ],
        ];

        $result = $this->client->chatJson([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'Voce e um interpretador estruturado do robo de agendamento da UBS Vida Nova (PSF02).',
                    'Sua funcao e entender a intencao e extrair entidades, nao conversar com o usuario.',
                    'Retorne somente JSON valido.',
                    'Campos esperados: intent, confidence, entities.',
                    'entities deve conter: cpf, telefone, nome, date, time.',
                    'Use null quando nao houver valor.',
                    'Sempre considere current_flow, current_step, pending_action e context.last_assistant_reply da sessao para validar se a resposta combina com o que esta pendente.',
                    'Se a mensagem nao combinar com a etapa pendente e tambem nao indicar claramente outro fluxo, use intent=unknown.',
                    'Se o usuario disser que quer trocar o CPF, usar outro paciente, outra pessoa, filho, filha ou responsavel, use intent=change_cpf.',
                    'date deve estar em DD/MM/AAAA e time em HH:MM.',
                    'Nao invente dados.',
                    'Se houver duvida, use intent=unknown.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ], $this->model);

        $intent = (string) ($result['intent'] ?? 'unknown');
        $confidence = (float) ($result['confidence'] ?? 0.0);
        $entities = is_array($result['entities'] ?? null) ? $result['entities'] : [];

        if ($intent === '') {
            throw new RuntimeException('Intent vazia retornada pela OpenAI.');
        }

        return new AiAnalysisDTO(
            intent: $intent,
            entities: [
                'cpf' => $this->normalizeStringOrNull($entities['cpf'] ?? null),
                'telefone' => $this->normalizeStringOrNull($entities['telefone'] ?? null),
                'nome' => $this->normalizeStringOrNull($entities['nome'] ?? null),
                'date' => $this->normalizeStringOrNull($entities['date'] ?? null),
                'time' => $this->normalizeStringOrNull($entities['time'] ?? null),
            ],
            confidence: $confidence,
            source: 'openai'
        );
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