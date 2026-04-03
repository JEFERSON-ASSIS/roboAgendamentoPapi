<?php

namespace App\Service;

use App\DTO\IncomingMessageDTO;
use App\DTO\QueuedMessageBatchDTO;
use App\Infrastructure\Logging\Logger;
use App\Infrastructure\Persistence\MessageQueueRepositoryInterface;
use Throwable;

class DebounceQueueService
{
    public function __construct(
        private readonly MessageQueueRepositoryInterface $repository,
        private readonly Logger $logger,
        private readonly bool $enabled = false,
        private readonly int $windowMs = 3000,
        private readonly int $lockWaitSeconds = 8
    ) {
    }

    public function process(IncomingMessageDTO $message, callable $processor): array
    {
        if (!$this->enabled || $message->phone === '') {
            return [
                'queue_status' => 'bypassed',
                'queue_ids' => [],
                'batch_parts' => 1,
                'processed' => true,
                'result' => $processor($message),
            ];
        }

        $provider = $message->provider !== '' ? $message->provider : 'evolution';

        if ($this->shouldIgnoreDuplicateExternalMessage($provider, $message)) {
            $this->logger->info('Mensagem duplicada ignorada antes do enfileiramento.', [
                'phone' => $message->phone,
                'provider' => $provider,
                'external_message_id' => $message->externalMessageId,
                'message_type' => $message->messageType,
            ]);

            return [
                'queue_status' => 'duplicate_ignored',
                'queue_ids' => [],
                'batch_parts' => 0,
                'processed' => false,
                'result' => null,
            ];
        }

        $queueId = $this->repository->enqueue($message);
        $lockAcquired = $this->repository->acquireConversationLock($provider, $message->phone, $this->lockWaitSeconds);

        if (!$lockAcquired) {
            $this->logger->warning('Nao foi possivel adquirir lock de fila para o telefone.', [
                'phone' => $message->phone,
                'provider' => $provider,
                'queue_id' => $queueId,
            ]);

            return [
                'queue_status' => 'lock_timeout',
                'queue_ids' => [$queueId],
                'batch_parts' => 1,
                'processed' => false,
                'result' => null,
            ];
        }

        try {
            $this->waitForQuietWindow($provider, $message->phone);

            $batch = $this->buildBatch($provider, $message->phone);

            if ($batch === null) {
                return [
                    'queue_status' => 'already_processed',
                    'queue_ids' => [$queueId],
                    'batch_parts' => 0,
                    'processed' => false,
                    'result' => null,
                ];
            }

            $result = $processor($batch->message);
            $this->repository->markProcessed($batch->queueIds);

            return [
                'queue_status' => $batch->status,
                'queue_ids' => $batch->queueIds,
                'batch_parts' => $batch->parts,
                'processed' => true,
                'result' => $result,
            ];
        } catch (Throwable $exception) {
            $this->logger->error('Erro ao processar fila com debounce.', [
                'phone' => $message->phone,
                'provider' => $provider,
                'queue_id' => $queueId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $this->repository->releaseConversationLock($provider, $message->phone);
        }
    }

    private function waitForQuietWindow(string $provider, string $phone): void
    {
        if ($this->windowMs <= 0) {
            return;
        }

        $start = microtime(true);
        $pollMs = max(120, min(300, (int) ceil($this->windowMs / 4)));
        $settleMs = max(400, min(800, (int) ceil($this->windowMs / 2)));
        $maxWaitMs = max($this->windowMs, $settleMs) + $settleMs;
        $lastCount = -1;
        $lastChangeAt = $start;

        while (true) {
            $pendingCount = count($this->repository->findPendingByConversation($provider, $phone));
            $now = microtime(true);

            if ($pendingCount !== $lastCount) {
                $lastCount = $pendingCount;
                $lastChangeAt = $now;
            }

            $elapsedMs = (int) round(($now - $start) * 1000);
            $quietMs = (int) round(($now - $lastChangeAt) * 1000);

            if ($elapsedMs >= $this->windowMs && $quietMs >= $settleMs) {
                break;
            }

            if ($elapsedMs >= $maxWaitMs) {
                break;
            }

            usleep($pollMs * 1000);
        }
    }
    private function buildBatch(string $provider, string $phone): ?QueuedMessageBatchDTO
    {
        $rows = $this->repository->findPendingByConversation($provider, $phone);

        if ($rows === []) {
            return null;
        }

        $queueIds = [];
        $texts = [];
        $latestPayload = [];
        $latestPushName = null;
        $latestMediaUrl = null;
        $latestMessageType = 'text';
        $latestRemoteJid = null;
        $latestInstanceId = null;
        $latestExternalMessageId = null;
        $latestInteractivePayload = [];
        $seenExternalIds = [];
        $duplicateQueueIds = [];

        foreach ($rows as $row) {
            $queueIds[] = (int) ($row['id'] ?? 0);
            $rowExternalMessageId = null;
            $text = trim((string) ($row['message_text'] ?? ''));

            $decodedPayload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (is_array($decodedPayload) && $decodedPayload !== []) {
                $latestPayload = $decodedPayload;
                $body = $decodedPayload['body'] ?? $decodedPayload;
                $data = $body['data'] ?? [];
                $meta = is_array($decodedPayload['_meta'] ?? null) ? $decodedPayload['_meta'] : [];
                $pushName = $data['pushName'] ?? $body['pushName'] ?? $meta['push_name'] ?? null;
                if (is_string($pushName) && trim($pushName) !== '') {
                    $latestPushName = trim($pushName);
                }
                $remoteJid = $meta['remote_jid'] ?? $data['key']['remoteJid'] ?? $data['remoteJid'] ?? $body['remoteJid'] ?? null;
                if (is_string($remoteJid) && trim($remoteJid) !== '') {
                    $latestRemoteJid = trim($remoteJid);
                }
                $instanceId = $meta['instance_id'] ?? $decodedPayload['instanceId'] ?? $body['instanceId'] ?? $data['instanceId'] ?? null;
                if (is_string($instanceId) && trim($instanceId) !== '') {
                    $latestInstanceId = trim($instanceId);
                }
                $externalMessageId = $meta['external_message_id'] ?? $data['key']['id'] ?? $data['messageId'] ?? $body['messageId'] ?? null;
                if (is_string($externalMessageId) && trim($externalMessageId) !== '') {
                    $rowExternalMessageId = trim($externalMessageId);
                    $latestExternalMessageId = $rowExternalMessageId;
                }
                $interactivePayload = $decodedPayload['interactive_payload'] ?? $meta['interactive_payload'] ?? [];
                if (is_array($interactivePayload) && $interactivePayload !== []) {
                    $latestInteractivePayload = $interactivePayload;
                }
            }

            if ($rowExternalMessageId === null) {
                $externalMessageIdColumn = trim((string) ($row['external_message_id'] ?? ''));
                if ($externalMessageIdColumn !== '') {
                    $rowExternalMessageId = $externalMessageIdColumn;
                    $latestExternalMessageId = $externalMessageIdColumn;
                }
            }

            if ($rowExternalMessageId !== null) {
                if (isset($seenExternalIds[$rowExternalMessageId])) {
                    $duplicateQueueIds[] = (int) ($row['id'] ?? 0);
                    continue;
                }

                $seenExternalIds[$rowExternalMessageId] = true;
            }

            if ($text !== '') {
                $texts[] = $text;
            }

            $mediaUrl = $row['media_url'] ?? null;
            if (is_string($mediaUrl) && trim($mediaUrl) !== '') {
                $latestMediaUrl = trim($mediaUrl);
            }

            $messageType = (string) ($row['message_type'] ?? 'text');
            if ($messageType !== '') {
                $latestMessageType = $messageType;
            }
        }

        $aggregatedText = trim(implode("\n", $texts));
        $messageType = $aggregatedText !== '' ? 'text' : $latestMessageType;
        $payload = $latestPayload;
        $payload['queue_batch'] = [
            'parts' => count($rows),
            'unique_parts' => count($rows) - count($duplicateQueueIds),
            'queue_ids' => $queueIds,
            'duplicate_queue_ids' => $duplicateQueueIds,
            'aggregated_text' => $aggregatedText,
        ];

        if ($duplicateQueueIds !== []) {
            $this->logger->info('Fila consolidou mensagens duplicadas pelo external_message_id.', [
                'provider' => $provider,
                'phone' => $phone,
                'queue_ids' => $queueIds,
                'duplicate_queue_ids' => $duplicateQueueIds,
                'external_message_id' => $latestExternalMessageId,
            ]);
        }

        return new QueuedMessageBatchDTO(
            new IncomingMessageDTO(
                phone: $phone,
                messageType: $messageType,
                message: $aggregatedText !== '' ? $aggregatedText : null,
                mediaUrl: $latestMediaUrl,
                pushName: $latestPushName,
                payload: $payload,
                provider: $provider,
                remoteJid: $latestRemoteJid,
                instanceId: $latestInstanceId,
                externalMessageId: $latestExternalMessageId,
                interactivePayload: $latestInteractivePayload
            ),
            queueIds: $queueIds,
            parts: count($rows),
            status: count($rows) > 1 ? 'batched' : 'single'
        );
    }

    private function shouldIgnoreDuplicateExternalMessage(string $provider, IncomingMessageDTO $message): bool
    {
        $externalMessageId = trim((string) ($message->externalMessageId ?? ''));

        if ($externalMessageId === '' || $message->phone === '') {
            return false;
        }

        return $this->repository->hasRecentExternalMessageId($provider, $message->phone, $externalMessageId);
    }
}
