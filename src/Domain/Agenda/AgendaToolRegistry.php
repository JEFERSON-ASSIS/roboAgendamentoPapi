<?php

namespace App\Domain\Agenda;

use InvalidArgumentException;

class AgendaToolRegistry
{
    public function __construct(
        private readonly AgendaService $agendaService
    ) {
    }

    public function list(): array
    {
        return [
            'consultar_agendamento_ausente',
            'consultar_agenda_medico',
            'consultar_agenda_enfer',
            'consultar_agenda_dent',
            'consultar_horario_dentista',
            'cadastrar_agenda_medico',
            'cadastrar_agenda_enfer',
            'cadastrar_agenda_dent',
            'consultar_cancelamento_agen',
            'confimar_cancelamento_geral',
        ];
    }

    public function call(string $toolName, array $arguments = []): array
    {
        return match ($toolName) {
            'consultar_agendamento_ausente' => $this->agendaService->consultarAgendamentoAusente((string) ($arguments['cpf'] ?? '')),
            'consultar_agenda_medico' => $this->agendaService->consultarAgendaMedico(),
            'consultar_agenda_enfer' => $this->agendaService->consultarAgendaEnfermeiro(),
            'consultar_agenda_dent' => $this->agendaService->consultarAgendaDentista(),
            'consultar_horario_dentista' => $this->agendaService->consultarHorarioDentista((string) ($arguments['data_selecionada'] ?? '')),
            'cadastrar_agenda_medico' => $this->agendaService->cadastrarAgendaMedico($arguments),
            'cadastrar_agenda_enfer' => $this->agendaService->cadastrarAgendaEnfermeiro($arguments),
            'cadastrar_agenda_dent' => $this->agendaService->cadastrarAgendaDentista($arguments),
            'consultar_cancelamento_agen' => $this->agendaService->consultarCancelamentoAgendamento((string) ($arguments['cpf'] ?? '')),
            'confimar_cancelamento_geral' => $this->agendaService->confirmarCancelamentoGeral((string) ($arguments['idCancelamento'] ?? '')),
            default => throw new InvalidArgumentException('Tool nao registrada: ' . $toolName),
        };
    }
}