<?php

namespace App\Domain\Conversation;

use App\Service\CpfValidator;

class EntityExtractor
{
    public function extract(?string $message, ?string $pushName = null): array
    {
        $text = trim((string) $message);

        return [
            'cpf' => $this->extractCpf($text),
            'telefone' => $this->extractPhone($text),
            'nome' => $this->extractName($text, $pushName),
            'date' => $this->extractDate($text),
            'time' => $this->extractTime($text),
        ];
    }

    private function extractCpf(string $text): ?string
    {
        return CpfValidator::extractValidFromText($text);
    }

    private function extractPhone(string $text): ?string
    {
        if (preg_match('/\b(\d{2}\s?9?\d{4}\-?\d{4})\b/', $text, $matches) !== 1) {
            return null;
        }

        return preg_replace('/\D+/', '', $matches[1]);
    }

    private function extractName(string $text, ?string $pushName): ?string
    {
        if (preg_match('/(?:nome\s*[:\-]?\s*)([A-Za-zÃ€-Ã¿]+(?:\s+[A-Za-zÃ€-Ã¿]+)+)/u', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        $trimmed = trim($text);
        if ($this->looksLikeHumanName($trimmed)) {
            return $trimmed;
        }

        if (is_string($pushName)) {
            $pushName = trim($pushName);
            if ($this->looksLikeHumanName($pushName)) {
                return $pushName;
            }
        }

        return null;
    }

    private function extractDate(string $text): ?string
    {
        if (preg_match('/\b(\d{2}\/\d{2}\/\d{4})\b/', $text, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function extractTime(string $text): ?string
    {
        if (preg_match('/\b(\d{2}:\d{2})\b/', $text, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function looksLikeHumanName(string $value): bool
    {
        if ($value === '' || preg_match('/^[A-Za-zÃ€-Ã¿]+(?:\s+[A-Za-zÃ€-Ã¿]+)+$/u', $value) !== 1) {
            return false;
        }

        $lower = mb_strtolower($value);
        $blockedTerms = [
            'quero', 'agendar', 'agendamento', 'cancelar', 'consulta', 'consultar',
            'dentista', 'medico', 'mÃ©dico', 'enfermeiro', 'enfermeira', 'horario',
            'data', 'bom dia', 'boa tarde', 'boa noite', 'oi', 'ola', 'olÃ¡'
        ];

        foreach ($blockedTerms as $term) {
            if (str_contains($lower, $term)) {
                return false;
            }
        }

        return true;
    }
}
