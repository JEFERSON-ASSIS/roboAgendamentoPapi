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
                'message' => 'MÃƒÂ©todo nÃƒÂ£o permitido. Use POST.',
            ], 405);
        }

        $payload = $request->json();

        if (!is_array($payload)) {
            return Response::json([
                'ok' => false,
                'message' => 'Payload JSON invÃƒÂ¡lido.',
            ], 422);
        }

        $this->logIncomingWebhook($request, $payload);

        try {
            $normalized = $this->normalizer->normalize($payload, $request->headers(), $request->query());
            $this->logger->info('Webhook normalizado', [
                'provider' => $normalized->provider,
                'phone' => $normalized->phone,
                'message_type' => $normalized->messageType,
                'remote_jid' => $normalized->remoteJid,
                'instance_id' => $normalized->instanceId,
                'external_message_id' => $normalized->externalMessageId,
                'interactive_payload' => $normalized->interactivePayload,
                'event_type' => (string) ($payload['type'] ?? ($payload['body']['type'] ?? '')),
            ]);

            if ($this->shouldIgnoreMessage($normalized)) {
                $this->logger->info('Webhook ignorado por nao representar mensagem processavel.', [
                    'provider' => $normalized->provider,
                    'message_type' => $normalized->messageType,
                    'phone' => $normalized->phone,
                ]);

                return Response::json([
                    'ok' => true,
                    'message' => 'Evento ignorado.',
                    'data' => [
                        'incoming' => $normalized->toArray(),
                    ],
                ]);
            }

            if ($normalized->messageType === 'audio') {
                if ($this->audioTranscriptionService === null) {
                    return $this->respondAudioFallback($normalized, 'No momento, eu ainda nÃƒÂ£o consigo transcrever ÃƒÂ¡udio por aqui. Pode me mandar em texto, por favor?');
                }

                try {
                    $normalized = $this->audioTranscriptionService->transcribe($normalized);
                } catch (Throwable $audioException) {
                    $this->logger->warning('Falha na transcricao do audio.', [
                        'error' => $audioException->getMessage(),
                        'phone' => $normalized->phone,
                    ]);

                    return $this->respondAudioFallback($normalized, 'NÃƒÂ£o consegui entender seu ÃƒÂ¡udio por aqui. Pode me mandar em texto, por favor?');
                }

                if (!is_string($normalized->message) || trim($normalized->message) === '') {
                    return $this->respondAudioFallback($normalized, 'NÃƒÂ£o consegui transcrever seu ÃƒÂ¡udio. Pode me mandar em texto, por favor?');
                }
            }

            if ($this->debounceQueueService !== null) {
                $queueResult = $this->debounceQueueService->process($normalized, fn (IncomingMessageDTO $batchedMessage): array => $this->processConversation($batchedMessage));

                if (($queueResult['processed'] ?? false) === false || !is_array($queueResult['result'] ?? null)) {
                    $queueStatus = (string) ($queueResult['queue_status'] ?? 'queued');

                    if ($queueStatus === 'duplicate_ignored') {
                        $this->logger->info('Webhook ignorado por mensagem duplicada.', [
                            'provider' => $normalized->provider,
                            'phone' => $normalized->phone,
                            'external_message_id' => $normalized->externalMessageId,
                            'message_type' => $normalized->messageType,
                        ]);
                    }

                    return Response::json([
                        'ok' => true,
                        'message' => $queueStatus === 'duplicate_ignored'
                            ? 'Mensagem duplicada ignorada.'
                            : 'Mensagem enfileirada para processamento.',
                        'data' => [
                            'incoming' => $normalized->toArray(),
                            'queue_status' => $queueStatus,
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
                $sendResult = $this->whatsAppService->sendReply($message, $result);
            } catch (Throwable $sendException) {
                $this->logger->warning('Falha ao enviar mensagem pelo WhatsApp.', [
                    'error' => $sendException->getMessage(),
                    'provider' => $message->provider,
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
            'provider' => $message->provider,
            'external_message_id' => $message->externalMessageId,
            'instance_id' => $message->instanceId,
            'remote_jid' => $message->remoteJid,
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
                $sendResult = $this->whatsAppService->sendText($normalized->phone, $reply, $normalized->provider);
            } catch (Throwable $sendException) {
                $this->logger->warning('Falha ao enviar fallback de audio pelo WhatsApp.', [
                    'error' => $sendException->getMessage(),
                    'phone' => $normalized->phone,
                    'provider' => $normalized->provider,
                ]);
                $sendResult = ['status' => 'error', 'error' => $sendException->getMessage()];
            }
        }

        $this->logger->info('Fallback de audio aplicado', [
            'phone' => $normalized->phone,
            'provider' => $normalized->provider,
            'message_type' => $normalized->messageType,
            'reply' => $reply,
            'whatsapp_send' => $sendResult,
        ]);

        return Response::json([
            'ok' => true,
            'message' => 'ÃƒÂudio recebido, mas foi necessÃƒÂ¡rio responder com fallback.',
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
            'path' => $request->server('REQUEST_URI'),
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
            'provider' => $payload['provider'] ?? $body['provider'] ?? null,
            'event_type' => $payload['type'] ?? $body['type'] ?? null,
            'remote_jid' => $key['remoteJid'] ?? null,
            'remote_jid_alt' => $key['remoteJidAlt'] ?? null,
            'push_name' => $data['pushName'] ?? $body['pushName'] ?? null,
            'instance_id' => $payload['instanceId'] ?? $body['instanceId'] ?? $data['instanceId'] ?? null,
            'external_message_id' => $key['id'] ?? $data['messageId'] ?? $body['messageId'] ?? null,
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

    private function shouldIgnoreMessage(IncomingMessageDTO $message): bool
    {
        if ($message->phone === '') {
            return true;
        }

        if ($message->messageType === 'unknown' && trim((string) ($message->message ?? '')) === '') {
            return true;
        }

        return false;
    }
}
