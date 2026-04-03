<?php

namespace App\Service\WhatsApp;

interface IncomingWebhookProviderResolverInterface
{
    public function resolve(array $payload, array $headers = [], array $query = []): string;
}
