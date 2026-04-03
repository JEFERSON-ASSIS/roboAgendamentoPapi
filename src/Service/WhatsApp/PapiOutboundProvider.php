<?php

namespace App\Service\WhatsApp;

use App\DTO\OutgoingMessageDTO;
use RuntimeException;

class PapiOutboundProvider extends AbstractOutboundProvider
{
    public function providerName(): string
    {
        return 'papi';
    }

    public function supports(string $messageType): bool
    {
        return match ($messageType) {
            'text' => true,
            'buttons' => (bool) ($this->config['interactive_enabled'] ?? true),
            'contact' => true,
            default => false,
        };
    }

    protected function sendTypingPresence(string $phone): ?array
    {
        return $this->httpClient->post(
            $this->buildEndpoint('/presence'),
            [
                'jid' => $this->toJid($phone),
                'presence' => 'composing',
            ],
            $this->authHeaders(),
            30
        );
    }

    protected function sendSingleText(string $phone, string $text): array
    {
        $response = $this->httpClient->post(
            $this->buildEndpoint('/send-text'),
            [
                'jid' => $this->toJid($phone),
                'text' => $text,
                'validateNumber' => (bool) ($this->config['validate_number'] ?? true),
            ],
            $this->authHeaders(),
            30
        );

        $status = (int) ($response['status'] ?? 0);
        $json = is_array($response['json'] ?? null) ? $response['json'] : [];

        if ($status < 200 || $status >= 300) {
            $body = trim((string) ($response['body'] ?? ''));
            $details = $body !== '' ? ' Resposta: ' . $body : '';

            throw new RuntimeException('Falha no envio via PAPI. HTTP ' . $status . '.' . $details);
        }

        if (($json['success'] ?? null) === false) {
            $error = trim((string) ($json['error'] ?? 'Falha no envio via PAPI.'));
            $number = trim((string) ($json['number'] ?? ''));
            $details = $number !== '' ? ' Numero: ' . $number . '.' : '';

            throw new RuntimeException($error . $details);
        }

        return $response;
    }

    protected function sendContactMessage(OutgoingMessageDTO $message): array
    {
        if ($message->phone === '') {
            throw new RuntimeException('Telefone invalido para envio de contato via PAPI.');
        }

        $contactName = trim($this->normalizeText((string) $message->contactName));
        $contactPhone = $this->normalizeContactPhone((string) $message->contactPhone);

        if ($contactName === '' || $contactPhone === '') {
            throw new RuntimeException('Contato invalido para envio via PAPI.');
        }

        $responses = [];
        $presenceResponses = [];
        $text = trim($this->normalizeText((string) $message->text));

        if ($text !== '') {
            if ($this->typingEnabled()) {
                $presenceResponse = $this->sendTypingPresence($message->phone);
                if ($presenceResponse !== null) {
                    $presenceResponses[] = $presenceResponse;
                }
            }

            $responses[] = $this->sendSingleText($message->phone, $text);
        }

        $response = $this->httpClient->post(
            $this->buildEndpoint('/send-contact'),
            [
                'jid' => $this->toJid($message->phone),
                'name' => $contactName,
                'phone' => $contactPhone,
            ],
            $this->authHeaders(),
            30
        );

        $status = (int) ($response['status'] ?? 0);
        $json = is_array($response['json'] ?? null) ? $response['json'] : [];

        if ($status < 200 || $status >= 300) {
            $body = trim((string) ($response['body'] ?? ''));
            $details = $body !== '' ? ' Resposta: ' . $body : '';

            throw new RuntimeException('Falha no envio de contato via PAPI. HTTP ' . $status . '.' . $details);
        }

        if (($json['success'] ?? null) === false) {
            $error = trim((string) ($json['error'] ?? 'Falha no envio de contato via PAPI.'));

            throw new RuntimeException($error);
        }

        $responses[] = $response;

        return [
            'status' => 'sent',
            'provider' => $this->providerName(),
            'parts' => count($responses),
            'presence' => $presenceResponses,
            'responses' => $responses,
            'message_type' => 'contact',
        ];
    }

    protected function sendButtonsMessage(OutgoingMessageDTO $message): array
    {
        $buttons = $this->normalizeButtons($message->buttons);

        if ($message->phone === '' || trim((string) $message->text) === '') {
            throw new RuntimeException('Telefone ou texto invalido para envio de botoes via PAPI.');
        }

        if ($buttons === []) {
            throw new RuntimeException('A mensagem interativa da PAPI precisa ter ao menos um botao.');
        }

        $payload = [
            'jid' => $this->toJid($message->phone),
            'text' => $this->normalizeText((string) $message->text),
            'buttons' => $buttons,
        ];

        $footer = trim($this->normalizeText((string) $message->footer));
        if ($footer !== '') {
            $payload['footer'] = $footer;
        }

        $response = $this->httpClient->post(
            $this->buildEndpoint('/send-buttons'),
            $payload,
            $this->authHeaders(),
            30
        );

        $status = (int) ($response['status'] ?? 0);
        $json = is_array($response['json'] ?? null) ? $response['json'] : [];

        if ($status < 200 || $status >= 300) {
            $body = trim((string) ($response['body'] ?? ''));
            $details = $body !== '' ? ' Resposta: ' . $body : '';

            throw new RuntimeException('Falha no envio de botoes via PAPI. HTTP ' . $status . '.' . $details);
        }

        if (($json['success'] ?? null) === false) {
            $error = trim((string) ($json['error'] ?? 'Falha no envio de botoes via PAPI.'));

            throw new RuntimeException($error);
        }

        return [
            'status' => 'sent',
            'provider' => $this->providerName(),
            'parts' => 1,
            'responses' => [$response],
            'message_type' => 'buttons',
        ];
    }

    private function authHeaders(): array
    {
        return [
            'x-api-key' => $this->apiKey(),
            'Authorization' => 'Bearer ' . $this->apiKey(),
        ];
    }

    private function buildEndpoint(string $suffix): string
    {
        $base = $this->baseUrl() . '/api/instances/' . rawurlencode($this->instanceId()) . $suffix;
        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . 'key=' . rawurlencode($this->apiKey());
    }

    private function toJid(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if ($digits === '') {
            throw new RuntimeException('Telefone invalido para envio via PAPI.');
        }

        return $digits . '@s.whatsapp.net';
    }

    private function normalizeContactPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if ($digits === '') {
            return '';
        }

        return '+' . $digits;
    }

    private function normalizeButtons(array $buttons): array
    {
        $normalized = [];
        $types = [];

        foreach (array_values($buttons) as $button) {
            if (!is_array($button)) {
                continue;
            }

            $type = strtolower(trim((string) ($button['type'] ?? '')));
            $displayText = trim($this->normalizeText((string) ($button['displayText'] ?? '')));

            if ($type === '' || $displayText === '') {
                throw new RuntimeException('Cada botao da PAPI precisa informar type e displayText.');
            }

            $normalizedButton = [
                'type' => $type,
                'displayText' => $displayText,
            ];

            switch ($type) {
                case 'quick_reply':
                    $id = trim((string) ($button['id'] ?? ''));
                    if ($id === '') {
                        throw new RuntimeException('Botoes quick_reply da PAPI precisam informar id.');
                    }
                    $normalizedButton['id'] = $id;
                    break;

                case 'cta_url':
                    $url = trim((string) ($button['url'] ?? ''));
                    if ($url === '') {
                        throw new RuntimeException('Botoes cta_url da PAPI precisam informar url.');
                    }
                    $normalizedButton['url'] = $url;
                    break;

                case 'cta_call':
                    $phoneNumber = trim((string) ($button['phoneNumber'] ?? ''));
                    if ($phoneNumber === '') {
                        throw new RuntimeException('Botoes cta_call da PAPI precisam informar phoneNumber.');
                    }
                    $normalizedButton['phoneNumber'] = $phoneNumber;
                    break;

                case 'cta_copy':
                    $copyCode = trim((string) ($button['copyCode'] ?? ''));
                    if ($copyCode === '') {
                        throw new RuntimeException('Botoes cta_copy da PAPI precisam informar copyCode.');
                    }
                    $normalizedButton['copyCode'] = $copyCode;
                    break;

                default:
                    throw new RuntimeException('Tipo de botao nao suportado pela PAPI: ' . $type . '.');
            }

            $types[] = $type;
            $normalized[] = $normalizedButton;
        }

        if (count($normalized) > 3) {
            throw new RuntimeException('A PAPI aceita no maximo 3 botoes por mensagem interativa.');
        }

        $hasQuickReply = in_array('quick_reply', $types, true);
        $hasCta = count(array_diff($types, ['quick_reply'])) > 0;

        if ($hasQuickReply && $hasCta) {
            throw new RuntimeException('Nao misture quick_reply com botoes CTA na PAPI para manter compatibilidade com WhatsApp Web.');
        }

        return $normalized;
    }
}


