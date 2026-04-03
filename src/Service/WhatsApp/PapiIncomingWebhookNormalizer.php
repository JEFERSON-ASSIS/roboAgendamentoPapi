<?php

namespace App\Service\WhatsApp;

use App\DTO\IncomingMessageDTO;

class PapiIncomingWebhookNormalizer implements IncomingWebhookNormalizerInterface
{
    public function providerName(): string
    {
        return 'papi';
    }

    public function normalize(array $payload, array $headers = [], array $query = []): IncomingMessageDTO
    {
        $body = $payload['body'] ?? $payload;
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $message = $this->extractMessageContainer($data);
        $remoteJid = $this->resolvePreferredRemoteJid([
            $data['key']['remoteJidAlt'] ?? null,
            $data['key']['remoteJid'] ?? null,
            $data['remoteJid'] ?? null,
            $data['jid'] ?? null,
            $body['remoteJid'] ?? null,
            $body['jid'] ?? null,
            $body['from'] ?? null,
        ]);
        $messageType = $this->detectMessageType($message, $data, $body);

        return new IncomingMessageDTO(
            phone: preg_replace('/\D+/', '', (string) ($remoteJid ?? '')) ?: '',
            messageType: $messageType,
            message: $this->extractMessageText($message, $data, $messageType),
            mediaUrl: $this->extractMediaUrl($message, $data, $messageType),
            pushName: $this->firstNonEmptyString([
                $data['pushName'] ?? null,
                $data['senderName'] ?? null,
                $data['name'] ?? null,
                $body['pushName'] ?? null,
                $body['senderName'] ?? null,
                $body['name'] ?? null,
            ]),
            payload: $payload,
            provider: $this->providerName(),
            remoteJid: $remoteJid,
            instanceId: $this->firstNonEmptyString([
                $data['instanceId'] ?? null,
                $data['instance_id'] ?? null,
                $payload['instanceId'] ?? null,
                $body['instanceId'] ?? null,
                $body['instance_id'] ?? null,
            ]),
            externalMessageId: $this->firstNonEmptyString([
                $data['key']['id'] ?? null,
                $data['messageId'] ?? null,
                $body['messageId'] ?? null,
                $body['id'] ?? null,
            ]),
            interactivePayload: $this->extractInteractivePayload($message, $data)
        );
    }

    private function extractMessageContainer(array $data): array
    {
        $message = $data['message'] ?? $data['content'] ?? [];

        return is_array($message) ? $message : [];
    }

    private function detectMessageType(array $message, array $data, array $body): string
    {
        $types = [
            'conversation' => 'text',
            'extendedTextMessage' => 'text',
            'audioMessage' => 'audio',
            'imageMessage' => 'image',
            'videoMessage' => 'video',
            'documentMessage' => 'document',
            'buttonsResponseMessage' => 'interactive',
            'templateButtonReplyMessage' => 'interactive',
            'interactiveResponseMessage' => 'interactive',
            'listResponseMessage' => 'interactive',
        ];

        $rootType = strtolower(trim((string) ($body['type'] ?? $data['type'] ?? '')));
        if (in_array($rootType, ['presence', 'chats_update', 'status', 'statuses'], true)) {
            return 'unknown';
        }

        foreach ($types as $key => $type) {
            if (array_key_exists($key, $message)) {
                return $type;
            }
        }

        if ($this->firstNonEmptyString([$data['text'] ?? null, $message['text'] ?? null]) !== null) {
            return 'text';
        }

        if ($this->firstNonEmptyString([$data['audioUrl'] ?? null, $data['audio']['url'] ?? null]) !== null) {
            return 'audio';
        }

        return 'unknown';
    }

    private function extractMessageText(array $message, array $data, string $messageType): ?string
    {
        return match ($messageType) {
            'text' => $this->firstNonEmptyString([
                $message['conversation'] ?? null,
                $message['extendedTextMessage']['text'] ?? null,
                $message['text'] ?? null,
                $data['text'] ?? null,
                $data['messageText'] ?? null,
                $data['body'] ?? null,
            ]),
            'interactive' => $this->firstNonEmptyString([
                $message['buttonsResponseMessage']['selectedButtonId'] ?? null,
                $message['buttonsResponseMessage']['selectedDisplayText'] ?? null,
                $message['templateButtonReplyMessage']['selectedId'] ?? null,
                $message['templateButtonReplyMessage']['selectedDisplayText'] ?? null,
                $message['listResponseMessage']['singleSelectReply']['selectedRowId'] ?? null,
                $message['listResponseMessage']['singleSelectReply']['title'] ?? null,
                $message['listResponseMessage']['title'] ?? null,
                $this->extractInteractiveResponseId($message['interactiveResponseMessage'] ?? null),
                $this->extractInteractiveResponseTitle($message['interactiveResponseMessage'] ?? null),
                $data['buttonId'] ?? null,
                $data['buttonText'] ?? null,
            ]),
            default => null,
        };
    }

    private function extractMediaUrl(array $message, array $data, string $messageType): ?string
    {
        return match ($messageType) {
            'audio' => $this->firstNonEmptyString([
                $message['mediaUrl'] ?? null,
                $message['audioMessage']['mediaUrl'] ?? null,
                $message['audioMessage']['url'] ?? null,
                $data['audioUrl'] ?? null,
                $data['audio']['url'] ?? null,
                $data['mediaUrl'] ?? null,
            ]),
            'image' => $this->firstNonEmptyString([
                $message['mediaUrl'] ?? null,
                $message['imageMessage']['mediaUrl'] ?? null,
                $message['imageMessage']['url'] ?? null,
                $data['imageUrl'] ?? null,
                $data['mediaUrl'] ?? null,
            ]),
            'video' => $this->firstNonEmptyString([
                $message['mediaUrl'] ?? null,
                $message['videoMessage']['mediaUrl'] ?? null,
                $message['videoMessage']['url'] ?? null,
                $data['videoUrl'] ?? null,
                $data['mediaUrl'] ?? null,
            ]),
            'document' => $this->firstNonEmptyString([
                $message['mediaUrl'] ?? null,
                $message['documentMessage']['mediaUrl'] ?? null,
                $message['documentMessage']['url'] ?? null,
                $data['documentUrl'] ?? null,
                $data['mediaUrl'] ?? null,
            ]),
            default => null,
        };
    }

    private function extractInteractivePayload(array $message, array $data): array
    {
        $candidates = [
            $message['buttonsResponseMessage'] ?? null,
            $message['templateButtonReplyMessage'] ?? null,
            $message['interactiveResponseMessage'] ?? null,
            $message['listResponseMessage'] ?? null,
            $data['button'] ?? null,
            $data['interactive'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $this->mergeInteractiveCandidateWithParsedJson($candidate);
            }
        }

        return [];
    }

    private function extractInteractiveResponseId(mixed $candidate): ?string
    {
        $parsed = $this->mergeInteractiveCandidateWithParsedJson($candidate);

        return $this->firstNonEmptyString([
            $parsed['id'] ?? null,
            $parsed['buttonId'] ?? null,
            $parsed['selectedId'] ?? null,
            $parsed['selectedButtonId'] ?? null,
        ]);
    }

    private function extractInteractiveResponseTitle(mixed $candidate): ?string
    {
        $parsed = $this->mergeInteractiveCandidateWithParsedJson($candidate);

        return $this->firstNonEmptyString([
            $parsed['title'] ?? null,
            $parsed['displayText'] ?? null,
            $parsed['selectedDisplayText'] ?? null,
        ]);
    }

    private function mergeInteractiveCandidateWithParsedJson(mixed $candidate): array
    {
        if (!is_array($candidate) || $candidate === []) {
            return [];
        }

        $nativeFlow = is_array($candidate['nativeFlowResponseMessage'] ?? null)
            ? $candidate['nativeFlowResponseMessage']
            : [];
        $paramsJson = $nativeFlow['paramsJson'] ?? null;
        $parsed = [];

        if (is_string($paramsJson) && trim($paramsJson) !== '') {
            $decoded = json_decode($paramsJson, true);
            if (is_array($decoded)) {
                $parsed = $decoded;
            }
        }

        if ($parsed === []) {
            return $candidate;
        }

        return array_merge($candidate, ['parsed_params' => $parsed], $parsed);
    }

    private function firstNonEmptyString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
    private function resolvePreferredRemoteJid(array $values): ?string
    {
        $normalized = [];

        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $normalized[] = trim($value);
        }

        if ($normalized === []) {
            return null;
        }

        foreach ($normalized as $candidate) {
            if (str_ends_with(strtolower($candidate), '@s.whatsapp.net')) {
                return $candidate;
            }
        }

        foreach ($normalized as $candidate) {
            if (str_ends_with(strtolower($candidate), '@lid')) {
                return $candidate;
            }
        }

        return $normalized[0] ?? null;
    }
}
