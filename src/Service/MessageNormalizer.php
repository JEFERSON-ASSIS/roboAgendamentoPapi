<?php

namespace App\Service;

use App\DTO\IncomingMessageDTO;
use App\Service\WhatsApp\EvolutionIncomingWebhookNormalizer;
use App\Service\WhatsApp\IncomingWebhookNormalizerInterface;
use App\Service\WhatsApp\IncomingWebhookProviderResolver;
use App\Service\WhatsApp\IncomingWebhookProviderResolverInterface;
use App\Service\WhatsApp\PapiIncomingWebhookNormalizer;
use RuntimeException;

class MessageNormalizer
{
    private readonly IncomingWebhookProviderResolverInterface $providerResolver;

    /** @var array<string, IncomingWebhookNormalizerInterface> */
    private array $normalizers = [];

    public function __construct(
        ?IncomingWebhookProviderResolverInterface $providerResolver = null,
        array $normalizers = []
    ) {
        $defaultProvider = (string) config('services.whatsapp.default_provider', config('services.whatsapp.provider', 'evolution'));
        $queryKey = (string) config('services.whatsapp.incoming_hint_query_key', 'provider');
        $mixedMode = (bool) config('services.whatsapp.mixed_webhook_mode', false);

        $this->providerResolver = $providerResolver ?? new IncomingWebhookProviderResolver($defaultProvider, $queryKey, $mixedMode);
        $normalizers = $normalizers !== [] ? $normalizers : [
            new EvolutionIncomingWebhookNormalizer(),
            new PapiIncomingWebhookNormalizer(),
        ];

        foreach ($normalizers as $normalizer) {
            if ($normalizer instanceof IncomingWebhookNormalizerInterface) {
                $this->normalizers[$normalizer->providerName()] = $normalizer;
            }
        }
    }

    public function normalize(array $payload, array $headers = [], array $query = []): IncomingMessageDTO
    {
        $provider = $this->providerResolver->resolve($payload, $headers, $query);
        $normalizer = $this->normalizers[$provider] ?? $this->normalizers['evolution'] ?? null;

        if ($normalizer === null) {
            throw new RuntimeException('Nenhum normalizer de WhatsApp foi configurado.');
        }

        return $normalizer->normalize($payload, $headers, $query);
    }

    public function resolveProvider(array $payload, array $headers = [], array $query = []): string
    {
        return $this->providerResolver->resolve($payload, $headers, $query);
    }
}