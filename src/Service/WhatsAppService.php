<?php

namespace App\Service;

use App\DTO\ConversationResultDTO;
use App\DTO\IncomingMessageDTO;
use App\DTO\OutgoingMessageDTO;
use App\Infrastructure\Http\HttpClient;
use App\Service\WhatsApp\EvolutionOutboundProvider;
use App\Service\WhatsApp\OutboundProviderInterface;
use App\Service\WhatsApp\PapiOutboundProvider;
use RuntimeException;

class WhatsAppService
{
    private string $defaultProvider = 'evolution';

    /** @var array<string, OutboundProviderInterface> */
    private array $providers = [];

    public function __construct(mixed ...$args)
    {
        if (($args[0] ?? null) instanceof HttpClient) {
            $this->bootLegacy(...$args);
            return;
        }

        $this->defaultProvider = strtolower(trim((string) ($args[0] ?? 'evolution'))) ?: 'evolution';

        foreach ((array) ($args[1] ?? []) as $provider) {
            if ($provider instanceof OutboundProviderInterface) {
                $this->providers[$provider->providerName()] = $provider;
            }
        }
    }

    public static function fromConfig(HttpClient $httpClient, array $config): self
    {
        $providersConfig = is_array($config['providers'] ?? null) ? $config['providers'] : [];
        $legacyDefaults = [
            'base_url' => (string) ($config['base_url'] ?? ''),
            'instance' => (string) ($config['instance'] ?? ''),
            'api_key' => (string) ($config['api_key'] ?? ''),
            'send_enabled' => (bool) ($config['send_enabled'] ?? false),
            'split_messages' => (bool) ($config['split_messages'] ?? true),
            'split_max_length' => (int) ($config['split_max_length'] ?? 700),
            'split_delay_ms' => (int) ($config['split_delay_ms'] ?? 400),
            'typing_enabled' => (bool) ($config['typing_enabled'] ?? false),
            'typing_delay_ms' => (int) ($config['typing_delay_ms'] ?? 1200),
            'typing_each_chunk' => (bool) ($config['typing_each_chunk'] ?? true),
        ];

        $evolutionConfig = array_merge(
            $legacyDefaults,
            is_array($providersConfig['evolution'] ?? null) ? $providersConfig['evolution'] : []
        );
        $papiConfig = array_merge(
            $legacyDefaults,
            [
                'typing_enabled' => false,
                'validate_number' => (bool) ($config['validate_number'] ?? true),
            ],
            is_array($providersConfig['papi'] ?? null) ? $providersConfig['papi'] : []
        );

        return new self(
            (string) ($config['default_provider'] ?? $config['provider'] ?? 'evolution'),
            [
                new EvolutionOutboundProvider($httpClient, $evolutionConfig),
                new PapiOutboundProvider($httpClient, $papiConfig),
            ]
        );
    }

    public function isEnabled(?string $provider = null): bool
    {
        $providerInstance = $this->resolveProvider($provider);

        return $providerInstance !== null && $providerInstance->isEnabled();
    }

    public function sendText(string $phone, string $text, ?string $provider = null): array
    {
        $providerInstance = $this->resolveProvider($provider);

        if ($providerInstance === null) {
            return ['status' => 'skipped', 'reason' => 'provider_not_configured', 'provider' => $provider ?? $this->defaultProvider];
        }

        return $providerInstance->send(new OutgoingMessageDTO(
            phone: $phone,
            type: 'text',
            text: $text
        ));
    }

    public function sendButtons(string $phone, string $text, array $buttons, ?string $footer = null, ?string $provider = null): array
    {
        $providerInstance = $this->resolveProvider($provider);

        if ($providerInstance === null) {
            return ['status' => 'skipped', 'reason' => 'provider_not_configured', 'provider' => $provider ?? $this->defaultProvider];
        }

        return $providerInstance->send(new OutgoingMessageDTO(
            phone: $phone,
            type: 'buttons',
            text: $text,
            footer: $footer,
            buttons: $buttons
        ));
    }

    public function sendContact(string $phone, string $text, string $contactName, string $contactPhone, ?string $provider = null): array
    {
        $providerInstance = $this->resolveProvider($provider);

        if ($providerInstance === null) {
            return ['status' => 'skipped', 'reason' => 'provider_not_configured', 'provider' => $provider ?? $this->defaultProvider];
        }

        return $providerInstance->send(new OutgoingMessageDTO(
            phone: $phone,
            type: 'contact',
            text: $text,
            contactName: $contactName,
            contactPhone: $contactPhone
        ));
    }

    public function sendReply(IncomingMessageDTO $incoming, ConversationResultDTO|string $reply): array
    {
        $provider = $incoming->provider !== '' ? $incoming->provider : $this->defaultProvider;

        if ($reply instanceof ConversationResultDTO) {
            $payload = $reply->replyPayload();

            $result = match ($payload->type) {
                'buttons' => $this->sendButtons($incoming->phone, (string) $payload->text, $payload->buttons, $payload->footer, $provider),
                'contact' => $this->sendContact($incoming->phone, (string) $payload->text, (string) $payload->contactName, (string) $payload->contactPhone, $provider),
                default => $this->sendText($incoming->phone, (string) $payload->text, $provider),
            };

            return $this->appendFollowUpContact($incoming->phone, $provider, $payload->meta, $result);
        }

        return $this->sendText($incoming->phone, $reply, $provider);
    }

    private function appendFollowUpContact(string $phone, string $provider, array $meta, array $result): array
    {
        $followUp = is_array($meta['follow_up_contact'] ?? null) ? $meta['follow_up_contact'] : [];
        $contactName = trim((string) ($followUp['name'] ?? ''));
        $contactPhone = trim((string) ($followUp['phone'] ?? ''));

        if ($contactName === '' || $contactPhone === '') {
            return $result;
        }

        $contactText = trim((string) ($followUp['text'] ?? ''));
        $followUpResult = $this->sendContact($phone, $contactText, $contactName, $contactPhone, $provider);

        $result['follow_up'] = $followUpResult;

        return $result;
    }

    private function resolveProvider(?string $provider = null): ?OutboundProviderInterface
    {
        $provider = strtolower(trim((string) ($provider ?? $this->defaultProvider)));

        return $this->providers[$provider] ?? null;
    }

    private function bootLegacy(
        HttpClient $httpClient,
        string $baseUrl,
        string $instance,
        string $apiKey,
        bool $sendEnabled = false,
        bool $splitMessages = true,
        int $maxChunkLength = 700,
        int $splitDelayMs = 400,
        bool $typingEnabled = false,
        int $typingDelayMs = 1200,
        bool $typingEachChunk = true
    ): void {
        $this->defaultProvider = 'evolution';
        $this->providers = [
            'evolution' => new EvolutionOutboundProvider($httpClient, [
                'base_url' => $baseUrl,
                'instance' => $instance,
                'api_key' => $apiKey,
                'send_enabled' => $sendEnabled,
                'split_messages' => $splitMessages,
                'split_max_length' => $maxChunkLength,
                'split_delay_ms' => $splitDelayMs,
                'typing_enabled' => $typingEnabled,
                'typing_delay_ms' => $typingDelayMs,
                'typing_each_chunk' => $typingEachChunk,
            ]),
        ];
    }
}


