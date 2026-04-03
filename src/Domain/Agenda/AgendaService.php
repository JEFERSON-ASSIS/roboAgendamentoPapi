<?php

namespace App\Domain\Agenda;

use App\Infrastructure\Http\HttpClient;
use App\Service\IntegrationLogService;

class AgendaService
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $baseUrl,
        private readonly string $empresa,
        private readonly bool $mock = true,
        private readonly ?IntegrationLogService $integrationLogService = null
    ) {
    }

    public function consultarAgendamentoAusente(string $cpf): array
    {
        if ($this->mock) {
            $response = [
                'status' => 'not_found',
                'agendamentos' => [],
                'bloquear_por_servico' => [
                    'medico' => false,
                    'dentista' => false,
                    'enfermeiro' => false,
                ],
            ];

            $this->logMockCall('consultar_agendamento_ausente', '/api_listar_agendamentos.php', ['cpf' => $cpf], $response);

            return $response;
        }

        return $this->normalizeAgendamentos(
            $this->getJson('consultar_agendamento_ausente', '/api_listar_agendamentos.php?cpf=' . urlencode($cpf), ['cpf' => $cpf])
        );
    }

    public function consultarAgendaMedico(): array
    {
        if ($this->mock) {
            $response = ['datas' => ['24/03/2026', '26/03/2026', '30/03/2026']];
            $this->logMockCall('consultar_agenda_medico', '/dia_medica_livre.php', [], $response);

            return $response;
        }

        return $this->normalizeAgendaDias($this->getJson('consultar_agenda_medico', '/dia_medica_livre.php'));
    }

    public function consultarAgendaEnfermeiro(): array
    {
        if ($this->mock) {
            $response = ['datas' => ['25/03/2026', '27/03/2026', '31/03/2026']];
            $this->logMockCall('consultar_agenda_enfer', '/dia_enfermeira_livre.php', [], $response);

            return $response;
        }

        return $this->normalizeAgendaDias($this->getJson('consultar_agenda_enfer', '/dia_enfermeira_livre.php'));
    }

    public function consultarAgendaDentista(): array
    {
        if ($this->mock) {
            $response = ['datas' => ['24/03/2026', '25/03/2026', '28/03/2026']];
            $this->logMockCall('consultar_agenda_dent', '/dia_dentista_livre.php', [], $response);

            return $response;
        }

        return $this->normalizeAgendaDias($this->getJson('consultar_agenda_dent', '/dia_dentista_livre.php'));
    }

    public function consultarHorarioDentista(string $data): array
    {
        if ($this->mock) {
            $response = ['data' => $data, 'horarios' => ['08:00', '09:00', '13:00', '14:30']];
            $this->logMockCall('consultar_horario_dentista', '/horario_livre_dentista.php', ['data' => $data], $response);

            return $response;
        }

        return $this->normalizeHorariosDentista(
            $this->getJson('consultar_horario_dentista', '/horario_livre_dentista.php?data=' . urlencode($data), ['data' => $data])
        );
    }

    public function cadastrarAgendaMedico(array $payload): array
    {
        if ($this->mock) {
            $response = [
                'success' => true,
                'message' => 'Consulta médica cadastrada.',
                'id' => 99901,
                'horaAgendada' => '08:00',
                'horaComparecer' => '08:00',
                'raw' => $payload,
            ];
            $this->logMockCall('cadastrar_agenda_medico', '/api_consulta_medica_psf2.php', $payload, $response);

            return $response;
        }

        return $this->normalizeWriteOperation(
            $this->postJson('cadastrar_agenda_medico', '/api_consulta_medica_psf2.php', [
                'nome' => $payload['nome'] ?? '',
                'cns_cpf' => $payload['cpf'] ?? '',
                'telefone' => $payload['telefone'] ?? '',
                'setor' => $payload['bairro'] ?? '',
                'servico' => '21',
                'empresa' => $this->empresa,
                'data' => $payload['data'] ?? '',
            ])
        );
    }

    public function cadastrarAgendaEnfermeiro(array $payload): array
    {
        if ($this->mock) {
            $response = [
                'success' => true,
                'message' => 'Consulta de enfermeiro cadastrada.',
                'id' => 99902,
                'horaAgendada' => '08:00',
                'horaComparecer' => '08:00',
                'raw' => $payload,
            ];
            $this->logMockCall('cadastrar_agenda_enfer', '/apiPostEnfermeiro.php', $payload, $response);

            return $response;
        }

        return $this->normalizeWriteOperation(
            $this->postJson('cadastrar_agenda_enfer', '/apiPostEnfermeiro.php', [
                'nome' => $payload['nome'] ?? '',
                'cpf' => $payload['cpf'] ?? '',
                'telefone' => $payload['telefone'] ?? '',
                'servico' => '23',
                'empresa' => $this->empresa,
                'data' => $payload['data'] ?? '',
            ])
        );
    }

    public function cadastrarAgendaDentista(array $payload): array
    {
        if ($this->mock) {
            $response = [
                'success' => true,
                'message' => 'Consulta de dentista cadastrada.',
                'id' => 99903,
                'raw' => $payload,
            ];
            $this->logMockCall('cadastrar_agenda_dent', '/api_agendar_dentista.php', $payload, $response);

            return $response;
        }

        return $this->normalizeWriteOperation(
            $this->postJson('cadastrar_agenda_dent', '/api_agendar_dentista.php', [
                'nome' => $payload['nome'] ?? '',
                'cpf' => $payload['cpf'] ?? '',
                'telefone' => $payload['telefone'] ?? '',
                'servico' => '19',
                'empresa' => $this->empresa,
                'data' => $payload['data'] ?? '',
                'hora' => $payload['hora'] ?? '',
            ])
        );
    }

    public function consultarCancelamentoAgendamento(string $cpf): array
    {
        if ($this->mock) {
            $response = [
                'agendamentos' => [
                    ['id' => 101, 'servico' => 'Dentista', 'data' => '25/03/2026', 'hora' => '13:00'],
                ],
            ];

            $this->logMockCall('consultar_cancelamento_agen', '/api_listar_agendamentos.php', ['cpf' => $cpf], $response);

            return $response;
        }

        return $this->normalizeAgendamentos(
            $this->getJson('consultar_cancelamento_agen', '/api_listar_agendamentos.php?cpf=' . urlencode($cpf), ['cpf' => $cpf])
        );
    }

    public function confirmarCancelamentoGeral(string $idCancelamento): array
    {
        if ($this->mock) {
            $response = ['success' => true, 'message' => 'Agendamento cancelado com sucesso. 😃', 'id' => $idCancelamento];
            $this->logMockCall('confimar_cancelamento_geral', '/api_cancelar_agendamento.php', ['id' => $idCancelamento], $response);

            return $response;
        }

        return $this->normalizeCancelOperation(
            $this->postJson(
                'confimar_cancelamento_geral',
                '/api_cancelar_agendamento.php?id=' . urlencode($idCancelamento),
                ['id' => $idCancelamento]
            )
        );
    }

    private function getJson(string $service, string $path, array $payload = []): array
    {
        $response = $this->httpClient->get($this->baseUrl . $path);
        $body = is_array($response['json']) ? $response['json'] : ['raw' => $response['body']];

        $this->logIntegration($service, 'GET', $path, $payload, $response);

        return $body;
    }

    private function postJson(string $service, string $path, array $payload): array
    {
        $response = $this->httpClient->post($this->baseUrl . $path, $payload);
        $body = is_array($response['json']) ? $response['json'] : ['raw' => $response['body']];

        $this->logIntegration($service, 'POST', $path, $payload, $response);

        return $body;
    }

    private function logMockCall(string $service, string $path, array $payload, array $response): void
    {
        $method = str_starts_with($service, 'consultar') ? 'GET' : 'POST';

        $this->integrationLogService?->log([
            'service' => $service,
            'endpoint' => $this->baseUrl . $path,
            'phone' => $this->extractPhone($payload),
            'cpf' => $this->extractCpf($payload),
            'request_payload' => [
                'http_method' => $method,
                'empresa' => $this->empresa,
                'mock' => true,
                'request' => $payload,
            ],
            'response_payload' => [
                'status' => 200,
                'json' => $response,
            ],
            'status_code' => 200,
        ]);
    }

    private function logIntegration(string $service, string $method, string $path, array $payload, array $response): void
    {
        $this->integrationLogService?->log([
            'service' => $service,
            'endpoint' => $this->baseUrl . $path,
            'phone' => $this->extractPhone($payload),
            'cpf' => $this->extractCpf($payload),
            'request_payload' => [
                'http_method' => $method,
                'empresa' => $this->empresa,
                'mock' => false,
                'request' => $payload,
            ],
            'response_payload' => [
                'status' => $response['status'] ?? null,
                'body' => $response['body'] ?? null,
                'json' => $response['json'] ?? null,
            ],
            'status_code' => $response['status'] ?? null,
        ]);
    }

    private function extractPhone(array $payload): ?string
    {
        $value = $payload['telefone'] ?? $payload['phone'] ?? null;

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return preg_replace('/\D+/', '', $value) ?: null;
    }

    private function extractCpf(array $payload): ?string
    {
        $value = $payload['cpf'] ?? $payload['cns_cpf'] ?? null;

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return preg_replace('/\D+/', '', $value) ?: null;
    }

    private function normalizeAgendaDias(array $response): array
    {
        $dias = $response['data']['dias'] ?? $response['dias'] ?? [];

        if (!is_array($dias)) {
            $dias = [];
        }

        $datas = array_values(array_filter(array_map(function (mixed $dia): ?string {
            if (!is_string($dia) || trim($dia) === '') {
                return null;
            }

            if (preg_match('/^\d{2}\/\d{2}\/\d{4}/', $dia, $matches) === 1) {
                return $matches[0];
            }

            return trim($dia);
        }, $dias)));

        return [
            'datas' => $datas,
            'raw' => $response,
        ];
    }

    private function normalizeHorariosDentista(array $response): array
    {
        $horarios = $response['data']['horarios'] ?? $response['horarios'] ?? [];
        $data = $response['data']['data'] ?? $response['data'] ?? null;

        if (!is_array($horarios)) {
            $horarios = [];
        }

        return [
            'data' => is_string($data) ? $data : null,
            'horarios' => array_values(array_filter(array_map(
                fn (mixed $horario): ?string => is_string($horario) && trim($horario) !== '' ? trim($horario) : null,
                $horarios
            ))),
            'raw' => $response,
        ];
    }

    private function normalizeAgendamentos(array $response): array
    {
        $agendamentos = $response['agendamentos'] ?? $response['data']['agendamentos'] ?? [];
        $bloquear = $response['bloquear_por_servico'] ?? $response['data']['bloquear_por_servico'] ?? [
            'medico' => false,
            'dentista' => false,
            'enfermeiro' => false,
        ];

        if (!is_array($agendamentos)) {
            $agendamentos = [];
        }

        if (!is_array($bloquear)) {
            $bloquear = [
                'medico' => false,
                'dentista' => false,
                'enfermeiro' => false,
            ];
        }

        return [
            'agendamentos' => $agendamentos,
            'bloquear_por_servico' => array_merge(
                ['medico' => false, 'dentista' => false, 'enfermeiro' => false],
                $bloquear
            ),
            'raw' => $response,
        ];
    }

    private function normalizeWriteOperation(array $response): array
    {
        $success = $this->extractWriteSuccess($response);
        $message = $this->extractMessage($response, $success ? 'Agendamento realizado com sucesso.' : 'Não foi possível confirmar o agendamento agora.');
        $id = $response['id'] ?? ($response['data']['id'] ?? null);
        $horaAgendada = $response['horaAgendada'] ?? ($response['data']['horaAgendada'] ?? null);
        $horaComparecer = $response['horaComparecer'] ?? ($response['data']['horaComparecer'] ?? null);

        return [
            'success' => $success,
            'message' => $message,
            'id' => is_scalar($id) ? (string) $id : null,
            'horaAgendada' => is_string($horaAgendada) ? trim($horaAgendada) : null,
            'horaComparecer' => is_string($horaComparecer) ? trim($horaComparecer) : null,
            'raw' => $response,
        ];
    }

    private function normalizeCancelOperation(array $response): array
    {
        $status = strtolower((string) ($response['status'] ?? ''));
        $success = $status === 'deleted'
            || ($response['success'] ?? null) === true
            || ($response['sucesso'] ?? null) === true;

        return [
            'success' => $success,
            'message' => $this->extractMessage($response, $success ? 'Cancelamento realizado com sucesso.' : 'Não foi possível confirmar o cancelamento agora.'),
            'status' => $status !== '' ? $status : null,
            'raw' => $response,
        ];
    }

    private function extractWriteSuccess(array $response): bool
    {
        if (($response['success'] ?? null) === true || ($response['sucesso'] ?? null) === true) {
            return true;
        }

        $status = strtolower((string) ($response['status'] ?? ''));
        if (in_array($status, ['success', 'created', 'ok', 'agendado'], true)) {
            return true;
        }

        $id = $response['id'] ?? ($response['data']['id'] ?? null);

        return is_scalar($id) && trim((string) $id) !== '' && $status !== 'error' && $status !== 'failed';
    }

    private function extractMessage(array $response, string $default): string
    {
        $message = $response['mensagem'] ?? $response['message'] ?? ($response['data']['mensagem'] ?? ($response['data']['message'] ?? null));

        return is_string($message) && trim($message) !== '' ? trim($message) : $default;
    }
}
