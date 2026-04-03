<?php

namespace App\Service\WhatsApp;

class IncomingWebhookProviderResolver implements IncomingWebhookProviderResolverInterface
{
    public function __construct(
        private readonly string $defaultProvider = 'evolution',
        private readonly string $queryKey = 'provider',
        private readonly bool $mixedMode = false
    ) {
    }

    public function resolve(array $payload, array $headers = [], array $query = []): string
    {
        $defaultProvider = $this->normalizeProviderName($this->defaultProvider) ?? 'evolution';

        if (!$this->mixedMode) {
            return $defaultProvider;
        }

        $hint = $this->normalizeProviderName(
            $query[$this->queryKey]
                ?? $headers['X-WhatsApp-Provider']
                ?? $headers['x-whatsapp-provider']
                ?? $headers['X-Provider']
                ?? $headers['x-provider']
                ?? null
        );

        if ($hint !== null) {
            return $hint;
        }

        if ($this->looksLikePapiPayload($payload)) {
            return 'papi';
        }

        if ($this->looksLikeEvolutionPayload($payload)) {
            return 'evolution';
        }

        return $defaultProvider;
    }

    private function normalizeProviderName(mixed $value): ?string
    {
        $provider = strtolower(trim((string) $value));

        return in_array($provider, ['evolution', 'papi'], true) ? $provider : null;
    }

    private function looksLikeEvolutionPayload(array $payload): bool
    {
        $body = $payload['body'] ?? $payload;
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $key = is_array($data['key'] ?? null) ? $data['key'] : [];

        return $data !== [] && (isset($key['remoteJid']) || isset($data['message']));
    }

    private function looksLikePapiPayload(array $payload): bool
    {
        $body = $payload['body'] ?? $payload;
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $key = is_array($data['key'] ?? null) ? $data['key'] : [];

        $candidates = [
            $payload['provider'] ?? null,
            $body['provider'] ?? null,
            $payload['type'] ?? null,
            $body['type'] ?? null,
            $payload['instanceId'] ?? null,
            $body['instanceId'] ?? null,
            $data['instanceId'] ?? null,
            $payload['jid'] ?? null,
            $body['jid'] ?? null,
            $data['jid'] ?? null,
            $key['remoteJidAlt'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return true;
            }
        }

        return false;
    }
}