<?php

namespace App\Service\WhatsApp;

use RuntimeException;

class EvolutionOutboundProvider extends AbstractOutboundProvider
{
    public function providerName(): string
    {
        return 'evolution';
    }

    protected function sendTypingPresence(string $phone): ?array
    {
        if ($this->typingDelayMs() <= 0) {
            return ['status' => 'skipped', 'reason' => 'typing_delay_disabled', 'provider' => $this->providerName()];
        }

        return $this->httpClient->post(
            $this->baseUrl() . '/chat/sendPresence/' . rawurlencode($this->instanceId()),
            [
                'number' => $phone,
                'delay' => $this->typingDelayMs(),
                'presence' => 'composing',
            ],
            [
                'apikey' => $this->apiKey(),
            ],
            30
        );
    }

    protected function sendSingleText(string $phone, string $text): array
    {
        $response = $this->httpClient->post(
            $this->baseUrl() . '/message/sendText/' . rawurlencode($this->instanceId()),
            [
                'number' => $phone,
                'text' => $text,
            ],
            [
                'apikey' => $this->apiKey(),
            ],
            30
        );

        $status = (int) ($response['status'] ?? 0);

        if ($status < 200 || $status >= 300) {
            $body = trim((string) ($response['body'] ?? ''));
            $details = $body !== '' ? ' Resposta: ' . $body : '';

            throw new RuntimeException('Falha no envio via WhatsApp. HTTP ' . $status . '.' . $details);
        }

        return $response;
    }
}
