<?php

namespace App\Service\WhatsApp;

use App\DTO\IncomingMessageDTO;

interface IncomingWebhookNormalizerInterface
{
    public function providerName(): string;

    public function normalize(array $payload, array $headers = [], array $query = []): IncomingMessageDTO;
}
