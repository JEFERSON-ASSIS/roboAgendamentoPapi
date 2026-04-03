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

        $queueId = $this->repository->enqueue($message);
        $lockAcquired = $this->repository->acquirePhoneLock($message->phone, $this->lockWaitSeconds);

        if (!$lockAcquired) {
            $this->logger->warning('Nao foi possivel adquirir lock de fila para o telefone.', [
                'phone' => $message->phone,
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
            $this->waitForQuietWindow($message->phone);

            $batch = $this->buildBatch($message->phone);

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
                'queue_id' => $queueId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $this->repository->releasePhoneLock($message->phone);
        }
    }

    private function waitForQuietWindow(string $phone): void
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
            $pendingCount = count($this->repository->findPendingByPhone($phone));
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
    private function buildBatch(string $phone): ?QueuedMessageBatchDTO
    {
        $rows = $this->repository->findPendingByPhone($phone);

        if ($rows === []) {
            return null;
        }

        $queueIds = [];
        $texts = [];
        $latestPayload = [];
        $latestPushName = null;
        $latestMediaUrl = null;
        $latestMessageType = 'text';

        foreach ($rows as $row) {
            $queueIds[] = (int) ($row['id'] ?? 0);
            $text = trim((string) ($row['message_text'] ?? ''));

            if ($text !== '') {
                $texts[] = $text;
            }

            $decodedPayload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (is_array($decodedPayload) && $decodedPayload !== []) {
                $latestPayload = $decodedPayload;
                $body = $decodedPayload['body'] ?? $decodedPayload;
                $data = $body['data'] ?? [];
                $pushName = $data['pushName'] ?? $body['pushName'] ?? null;
                if (is_string($pushName) && trim($pushName) !== '') {
                    $latestPushName = trim($pushName);
                }
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
            'queue_ids' => $queueIds,
            'aggregated_text' => $aggregatedText,
        ];

        return new QueuedMessageBatchDTO(
            new IncomingMessageDTO(
                phone: $phone,
                messageType: $messageType,
                message: $aggregatedText !== '' ? $aggregatedText : null,
                mediaUrl: $latestMediaUrl,
                pushName: $latestPushName,
                payload: $payload
            ),
            queueIds: $queueIds,
            parts: count($rows),
            status: count($rows) > 1 ? 'batched' : 'single'
        );
    }
}
