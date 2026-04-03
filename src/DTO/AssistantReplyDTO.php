<?php

namespace App\DTO;

class AssistantReplyDTO
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $text = null,
        public readonly ?string $footer = null,
        public readonly array $buttons = [],
        public readonly ?string $contactName = null,
        public readonly ?string $contactPhone = null,
        public readonly array $meta = []
    ) {
    }

    public static function text(string $text, array $meta = []): self
    {
        return new self('text', $text, null, [], null, null, $meta);
    }

    public static function buttons(string $text, array $buttons, ?string $footer = null, array $meta = []): self
    {
        return new self('buttons', $text, $footer, $buttons, null, null, $meta);
    }

    public static function contact(string $text, string $contactName, string $contactPhone, array $meta = []): self
    {
        return new self('contact', $text, null, [], $contactName, $contactPhone, $meta);
    }

    public function toArray(): array
    {
        return [
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
