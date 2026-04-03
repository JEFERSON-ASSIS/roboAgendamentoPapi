<?php

namespace App\DTO;

class SessionDTO
{
    public function __construct(
        public readonly string $phone,
        public readonly string $provider = 'evolution',
        public readonly ?string $cpf = null,
        public readonly ?string $nome = null,
        public readonly ?string $telefone = null,
        public readonly string $currentFlow = 'idle',
        public readonly string $currentStep = 'awaiting_menu_choice',
        public readonly ?string $selectedService = null,
        public readonly ?string $selectedDate = null,
        public readonly ?string $selectedTime = null,
        public readonly ?string $pendingAction = null,
        public readonly array $context = []
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            phone: (string) ($data['phone'] ?? ''),
            provider: self::normalizeProvider($data['provider'] ?? 'evolution'),
            cpf: self::emptyToNull($data['cpf'] ?? null),
            nome: self::emptyToNull($data['nome'] ?? null),
            telefone: self::emptyToNull($data['telefone'] ?? null),
            currentFlow: (string) ($data['current_flow'] ?? 'idle'),
            currentStep: (string) ($data['current_step'] ?? 'awaiting_menu_choice'),
            selectedService: self::emptyToNull($data['selected_service'] ?? null),
            selectedDate: self::emptyToNull($data['selected_date'] ?? null),
            selectedTime: self::emptyToNull($data['selected_time'] ?? null),
            pendingAction: self::emptyToNull($data['pending_action'] ?? null),
            context: is_array($data['context'] ?? null) ? $data['context'] : []
        );
    }

    public static function createEmpty(string $phone, string $provider = 'evolution'): self
    {
        return new self(phone: $phone, provider: self::normalizeProvider($provider));
    }

    public function toArray(): array
    {
        return [
            'phone' => $this->phone,
            'provider' => $this->provider,
            'cpf' => $this->cpf,
            'nome' => $this->nome,
            'telefone' => $this->telefone,
            'current_flow' => $this->currentFlow,
            'current_step' => $this->currentStep,
            'selected_service' => $this->selectedService,
            'selected_date' => $this->selectedDate,
            'selected_time' => $this->selectedTime,
            'pending_action' => $this->pendingAction,
            'context' => $this->context,
        ];
    }

    public function with(array $changes): self
    {
        return self::fromArray(array_merge($this->toArray(), $changes));
    }

    private static function emptyToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private static function normalizeProvider(mixed $value): string
    {
        $provider = strtolower(trim((string) $value));

        return in_array($provider, ['evolution', 'papi'], true) ? $provider : 'evolution';
    }
}
