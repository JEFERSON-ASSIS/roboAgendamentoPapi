<?php

namespace App\Controller;

use App\Core\Request;
use App\Core\Response;
use App\DTO\IncomingMessageDTO;
use App\Infrastructure\Logging\Logger;
use App\Service\AudioTranscriptionService;
use App\Service\ConversationService;
use App\Service\DebounceQueueService;
use App\Service\MessageLogService;
use App\Service\MessageNormalizer;
use App\Service\WhatsAppService;
use Throwable;

class WebhookController
{
    public function __construct(
        private readonly Logger $logger,
        private readonly MessageNormalizer $normalizer,
        private readonly ConversationService $conversationService,
        private readonly ?WhatsAppService $whatsAppService = null,
        private readonly ?MessageLogService $messageLogService = null,
        private readonly ?AudioTranscriptionService $audioTranscriptionService = null,
        private readonly ?DebounceQueueService $debounceQueueService = null
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return Response::json([
                'ok' => false,
                'message' => 'MÃ©todo nÃ£o permitido. Use POST.',
            ], 405);
        }

        $payload = $request->json();

        if (!is_array($payload)) {
            return Response::json([
                'ok' => false,
                'message' => 'Payload JSON invÃ¡lido.',
            ], 422);
        }

        $this->logIncomingWebhook($request, $payload);

        try {
            $normalized = $this->normalizer->normalize($payload);

            if ($normalized->messageType === 'audio') {
                if ($this->audioTranscriptionService === null) {
                    return $this->respondAudioFallback($normalized, 'No momento, eu ainda nÃ£o consigo transcrever Ã¡udio por aqui. Pode me mandar em texto, por favor?');
                }

                try {
                    $normalized = $this->audioTranscriptionService->transcribe($normalized);
                } catch (Throwable $audioException) {
                    $this->logger->warning('Falha na transcricao do audio.', [
                        'error' => $audioException->getMessage(),
                        'phone' => $normalized->phone,
                    ]);

                    return $this->respondAudioFallback($normalized, 'NÃ£o consegui entender seu Ã¡udio por aqui. Pode me mandar em texto, por favor?');
                }

                if (!is_string($normalized->message) || trim($normalized->message) === '') {
                    return $this->respondAudioFallback($normalized, 'NÃ£o consegui transcrever seu Ã¡udio. Pode me mandar em texto, por favor?');
                }
            }

            if ($this->debounceQueueService !== null) {
                $queueResult = $this->debounceQueueService->process($normalized, fn (IncomingMessageDTO $batchedMessage): array => $this->processConversation($batchedMessage));

                if (($queueResult['processed'] ?? false) === false || !is_array($queueResult['result'] ?? null)) {
                    return Response::json([
                        'ok' => true,
                        'message' => 'Mensagem enfileirada para processamento.',
                        'data' => [
                            'incoming' => $normalized->toArray(),
                            'queue_status' => $queueResult['queue_status'] ?? 'queued',
                            'queue_ids' => $queueResult['queue_ids'] ?? [],
                            'batch_parts' => $queueResult['batch_parts'] ?? 0,
                        ],
                    ]);
                }

                $processed = $queueResult['result'];

                return Response::json([
                    'ok' => true,
                    'message' => 'Webhook processado com sucesso.',
                    'data' => [
                        'incoming' => $processed['incoming']->toArray(),
                        'assistant' => $processed['assistant']->toArray(),
                        'whatsapp_send' => $processed['whatsapp_send'],
                        'queue_status' => $queueResult['queue_status'] ?? 'processed',
                        'queue_ids' => $queueResult['queue_ids'] ?? [],
                        'batch_parts' => $queueResult['batch_parts'] ?? 1,
                    ],
                ]);
            }

            $processed = $this->processConversation($normalized);

            return Response::json([
                'ok' => true,
                'message' => 'Webhook processado com sucesso.',
                'data' => [
                    'incoming' => $processed['incoming']->toArray(),
                    'assistant' => $processed['assistant']->toArray(),
                    'whatsapp_send' => $processed['whatsapp_send'],
                    'queue_status' => 'bypassed',
                    'queue_ids' => [],
                    'batch_parts' => 1,
                ],
            ]);
        } catch (Throwable $exception) {
            $this->logger->error('Erro ao processar webhook', [
                'error' => $exception->getMessage(),
            ]);

            return Response::json([
                'ok' => false,
                'message' => 'Falha ao processar webhook.',
            ], 500);
        }
    }

    private function processConversation(IncomingMessageDTO $message): array
    {
        if ($this->messageLogService !== null) {
            $this->messageLogService->logIncoming($message);
        }

        $result = $this->conversationService->handle($message);
        $sendResult = null;

        if ($this->whatsAppService !== null) {
            try {
                $sendResult = $this->whatsAppService->sendText($message->phone, $result->reply);
            } catch (Throwable $sendException) {
                $this->logger->warning('Falha ao enviar mensagem pelo WhatsApp.', [
                    'error' => $sendException->getMessage(),
                ]);
                $sendResult = ['status' => 'error', 'error' => $sendException->getMessage()];
            }
        }

        if ($this->messageLogService !== null) {
            $this->messageLogService->logOutgoing($message, $result, [
                'source' => 'webhook_auto_reply',
                'send_result' => $sendResult,
            ]);
        }

        $this->logger->info('Webhook recebido', [
            'phone' => $message->phone,
            'message_type' => $message->messageType,
            'message' => $message->message,
            'reply' => $result->reply,
            'intent' => $result->intent,
            'tool_calls' => $result->toolCalls,
            'whatsapp_send' => $sendResult,
            'queue_batch' => $message->payload['queue_batch'] ?? null,
        ]);

        return [
            'incoming' => $message,
            'assistant' => $result,
            'whatsapp_send' => $sendResult,
        ];
    }

    private function respondAudioFallback(IncomingMessageDTO $normalized, string $reply): Response
    {
        $sendResult = null;

        if ($this->whatsAppService !== null) {
            try {
                $sendResult = $this->whatsAppService->sendText($normalized->phone, $reply);
            } catch (Throwable $sendException) {
                $this->logger->warning('Falha ao enviar fallback de audio pelo WhatsApp.', [
                    'error' => $sendException->getMessage(),
                    'phone' => $normalized->phone,
                ]);
                $sendResult = ['status' => 'error', 'error' => $sendException->getMessage()];
            }
        }

        $this->logger->info('Fallback de audio aplicado', [
            'phone' => $normalized->phone,
            'message_type' => $normalized->messageType,
            'reply' => $reply,
            'whatsapp_send' => $sendResult,
        ]);

        return Response::json([
            'ok' => true,
            'message' => 'Ãudio recebido, mas foi necessÃ¡rio responder com fallback.',
            'data' => [
                'incoming' => $normalized->toArray(),
                'assistant' => [
                    'reply' => $reply,
                    'intent' => 'audio_fallback',
                ],
                'whatsapp_send' => $sendResult,
            ],
        ]);
    }

    private function logIncomingWebhook(Request $request, array $payload): void
    {
        $this->logger->info('Webhook payload recebido', [
            'method' => $request->method(),
            'summary' => $this->summarizePayload($payload),
            'raw_payload' => $this->truncateJson($payload),
        ]);
    }

    private function summarizePayload(array $payload): array
    {
        $body = $payload['body'] ?? $payload;
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $message = is_array($data['message'] ?? null) ? $data['message'] : [];
        $audio = is_array($message['audioMessage'] ?? null) ? $message['audioMessage'] : [];
        $key = is_array($data['key'] ?? null) ? $data['key'] : [];

        return [
            'remote_jid' => $key['remoteJid'] ?? null,
            'push_name' => $data['pushName'] ?? $body['pushName'] ?? null,
            'message_keys' => array_keys($message),
            'audio_keys' => array_keys($audio),
            'audio_url' => (
                is_string(
                    $message['mediaUrl'] ?? null
                ) && trim((string) ($message['mediaUrl'] ?? '')) !== ''
            ) ? $message['mediaUrl'] : ($audio['mediaUrl'] ?? $audio['url'] ?? null),
            'audio_mimetype' => $audio['mimetype'] ?? $audio['mimeType'] ?? null,
            'has_audio_base64' => $this->hasNonEmptyString($audio['base64'] ?? null)
                || $this->hasNonEmptyString($audio['fileBase64'] ?? null)
                || $this->hasNonEmptyString($audio['mediaBase64'] ?? null)
                || $this->hasNonEmptyString($audio['data'] ?? null),
        ];
    }

    private function truncateJson(array $payload, int $limit = 4000): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            return '[json_encode_failed]';
        }

        if (strlen($json) <= $limit) {
            return $json;
        }

        return substr($json, 0, $limit) . '...[truncated]';
    }

    private function hasNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}

