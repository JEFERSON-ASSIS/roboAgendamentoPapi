<?php

namespace App\Service;

final class CpfValidator
{
    public static function sanitize(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    public static function isValid(?string $value): bool
    {
        $cpf = self::sanitize($value);

        if (strlen($cpf) !== 11) {
            return false;
        }

        if (preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        for ($position = 9; $position < 11; $position++) {
            $sum = 0;

            for ($index = 0; $index < $position; $index++) {
                $sum += ((int) $cpf[$index]) * (($position + 1) - $index);
            }

            $digit = ((10 * $sum) % 11) % 10;

            if ((int) $cpf[$position] !== $digit) {
                return false;
            }
        }

        return true;
    }

    public static function extractValidFromText(string $text): ?string
    {
        if (preg_match('/\b(\d{3}\.?\d{3}\.?\d{3}\-?\d{2})\b/', $text, $matches) !== 1) {
            return null;
        }

        $cpf = self::sanitize($matches[1]);

        return self::isValid($cpf) ? $cpf : null;
    }

    public static function analyzeInput(?string $text): array
    {
        $rawText = trim((string) $text);
        $digits = self::sanitize($rawText);

        if (!self::looksLikeCpfInput($rawText, $digits)) {
            return [
                'submitted' => false,
                'digits' => $digits,
                'value' => null,
                'reason' => null,
            ];
        }

        if (strlen($digits) < 11) {
            return [
                'submitted' => true,
                'digits' => $digits,
                'value' => null,
                'reason' => 'missing_digits',
            ];
        }

        if (strlen($digits) > 11) {
            return [
                'submitted' => true,
                'digits' => $digits,
                'value' => null,
                'reason' => 'extra_digits',
            ];
        }

        if (preg_match('/^(\d)\1{10}$/', $digits) === 1) {
            return [
                'submitted' => true,
                'digits' => $digits,
                'value' => null,
                'reason' => 'repeated_digits',
            ];
        }

        if (!self::isValid($digits)) {
            return [
                'submitted' => true,
                'digits' => $digits,
                'value' => null,
                'reason' => 'invalid_checksum',
            ];
        }

        return [
            'submitted' => true,
            'digits' => $digits,
            'value' => $digits,
            'reason' => null,
        ];
    }

    private static function looksLikeCpfInput(string $text, string $digits): bool
    {
        if ($digits === '') {
            return false;
        }

        $normalized = mb_strtolower($text);

        if (str_contains($normalized, 'cpf')) {
            return true;
        }

        $withoutCpfChars = preg_replace('/[\d\.\-\s]/', '', $text) ?? $text;

        if (trim($withoutCpfChars) === '') {
            return true;
        }

        return strlen($digits) >= 9;
    }
}
