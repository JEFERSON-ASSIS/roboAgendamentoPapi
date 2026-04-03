<?php

namespace App\DTO;

class OutgoingMessageDTO
{
    public function __construct(
        public readonly string $phone,
        public readonly string $type,
        public readonly ?string $text = null,
        public readonly ?string $footer = null,
        public readonly array $buttons = [],
        public readonly ?string $contactName = null,
        public readonly ?string $contactPhone = null,
        public readonly array $meta = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'phone' => $this->phone,
            'type' => $this->type,
            'text' => $this->text,
            'footer' => $this->footer,
            'buttons' => $this->buttons,
            'contact_name' => $this->contactName,
            'contact_phone' => $this->contactPhone,
            'meta' => $this->meta,
        ];
    }
}
