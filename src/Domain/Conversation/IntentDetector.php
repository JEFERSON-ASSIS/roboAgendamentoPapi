<?php

namespace App\Domain\Conversation;

class IntentDetector
{
    public function detect(?string $message, string $currentFlow = 'idle'): string
    {
        $text = $this->normalize($message);

        if ($text === '') {
            return $currentFlow === 'idle' ? 'menu' : 'unknown';
        }

        if ($this->containsAny($text, ['trocar cpf', 'mudar cpf', 'alterar cpf', 'outro cpf', 'cpf diferente', 'novo cpf', 'nao e meu cpf', 'nao e esse cpf'])) {
            return 'change_cpf';
        }

        if ($currentFlow !== 'idle' && $this->containsAny($text, ['outro paciente', 'outra pessoa', 'meu filho', 'minha filha', 'meu marido', 'minha esposa', 'minha mae', 'meu pai', 'para outra pessoa'])) {
            return 'change_cpf';
        }

        if (preg_match('/\bcancel(a|ar|amento)\b/', $text) === 1) {
            return 'cancelar_agendamento';
        }

        if ($this->containsAny($text, ['consultar', 'meus agendamentos', 'ver meus agendamentos'])) {
            return 'consultar_agendamentos';
        }

        if ($this->containsAny($text, ['dentista', 'odonto', 'odontologia'])) {
            return 'agendar_dentista';
        }

        if ($this->containsAny($text, ['enfermeiro', 'enfermeira'])) {
            return 'agendar_enfermeiro';
        }

        if ($this->containsAny($text, ['medico', 'médico', 'consulta'])) {
            return 'agendar_medico';
        }

        if (in_array($text, ['sim', 's', 'confirmar'], true)) {
            return 'confirmar';
        }

        if (in_array($text, ['nao', 'não', 'n'], true)) {
            return 'negar';
        }

        if ($this->containsAny($text, ['oi', 'ola', 'olá', 'bom dia', 'boa tarde', 'boa noite'])) {
            return 'menu';
        }

        return $currentFlow !== 'idle' ? $currentFlow : 'unknown';
    }

    private function normalize(?string $message): string
    {
        $text = mb_strtolower(trim((string) $message));

        return preg_replace('/\s+/', ' ', $text) ?? $text;
    }

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
