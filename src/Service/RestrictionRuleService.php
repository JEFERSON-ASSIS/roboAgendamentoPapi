<?php

namespace App\Service;

use App\Infrastructure\Persistence\RestrictionRuleRepositoryInterface;

class RestrictionRuleService
{
    public function __construct(
        private readonly RestrictionRuleRepositoryInterface $repository
    ) {
    }

    public function match(?string $message): ?array
    {
        $normalizedMessage = $this->normalizeText((string) $message);

        if ($normalizedMessage === '') {
            return null;
        }

        foreach ($this->repository->findActive() as $rule) {
            $matchType = (string) ($rule['match_type'] ?? 'contains');
            $triggerValue = $this->normalizeText((string) ($rule['trigger_value'] ?? ''));

            if ($triggerValue === '') {
                continue;
            }

            if ($this->matches($normalizedMessage, $triggerValue, $matchType)) {
                $rule['matched_message'] = $normalizedMessage;
                return $rule;
            }
        }

        return null;
    }

    private function matches(string $message, string $triggerValue, string $matchType): bool
    {
        return match ($matchType) {
            'exact' => $message === $triggerValue,
            'regex' => @preg_match($triggerValue, $message) === 1,
            default => str_contains($message, $triggerValue),
        };
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text));

        return strtr($text, [
            'á' => 'a',
            'à' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'é' => 'e',
            'ê' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ú' => 'u',
            'ç' => 'c',
            'Ã¡' => 'a',
            'Ã ' => 'a',
            'Ã¢' => 'a',
            'Ã£' => 'a',
            'Ã©' => 'e',
            'Ãª' => 'e',
            'Ã­' => 'i',
            'Ã³' => 'o',
            'Ã´' => 'o',
            'Ãµ' => 'o',
            'Ãº' => 'u',
            'Ã§' => 'c',
        ]);
    }
}
