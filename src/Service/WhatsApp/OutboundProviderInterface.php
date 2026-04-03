<?php

namespace App\Service\WhatsApp;

use App\DTO\OutgoingMessageDTO;

interface OutboundProviderInterface
{
    public function providerName(): string;

    public function isEnabled(): bool;

    public function supports(string $messageType): bool;

    public function send(OutgoingMessageDTO $message): array;
}
