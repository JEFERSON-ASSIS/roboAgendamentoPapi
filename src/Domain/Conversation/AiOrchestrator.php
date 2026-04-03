<?php

namespace App\Domain\Conversation;

use App\DTO\ConversationResultDTO;
use App\DTO\FlowRecoveryDecisionDTO;
use App\DTO\IncomingMessageDTO;
use App\DTO\SessionDTO;
use App\Domain\Agenda\AgendaToolRegistry;
use App\Infrastructure\Logging\Logger;
use App\Service\CpfValidator;
use App\Service\RestrictionRuleService;

class AiOrchestrator
{
    public function __construct(
        private readonly ConversationInterpreterInterface $interpreter,
        private readonly AgendaToolRegistry $toolRegistry,
        private readonly Logger $logger,
        private readonly RestrictionRuleService $restrictionRuleService,
        private readonly FlowRecoveryServiceInterface $flowRecoveryService
    ) {
    }

    public function handle(IncomingMessageDTO $message, SessionDTO $session): ConversationResultDTO
    {
        $hadReusableSession = $this->hasReusableSessionContext($session);
        $analysis = $this->interpreter->analyze($message, $session);
        $session = $this->mergeSessionData($session, $analysis->entities);
        $intent = $this->normalizeIntent($analysis->intent, $message->message, $session);
        $isReactionAcknowledgement = $this->isReactionAcknowledgementMessage($message->message);

        if ($session->currentFlow === 'completed' && !$this->isShortAck($message->message) && !$this->isCourtesyMessage($message->message) && !$isReactionAcknowledgement) {
            $session = SessionDTO::createEmpty($session->phone);
            $session = $this->mergeSessionData($session, $analysis->entities);
        }

        if (($this->isShortAck($message->message) || $this->isCourtesyMessage($message->message) || $isReactionAcknowledgement) && $session->currentFlow === 'completed') {
            return new ConversationResultDTO($isReactionAcknowledgement ? 'Obrigado.' : 'Tudo certo. Se precisar de ajuda de novo, e so me chamar.', $session, $intent, $analysis->entities);
        }

        if ($this->shouldExitCurrentConversation($message->message, $session)) {
            $session = $this->resetConversationState($session);

            return new ConversationResultDTO(
                'Tudo bem. Encerrei este atendimento por aqui. Quando quiser, é só mandar uma nova mensagem que eu recomeço com você.',
                $session,
                'exit_conversation',
                $analysis->entities
            );
        }

        $restrictionRule = $this->restrictionRuleService->match($message->message);
        if ($restrictionRule !== null) {
            $this->logger->info('Regra de restricao aplicada', [
                'rule_id' => $restrictionRule['id'] ?? null,
                'rule_name' => $restrictionRule['name'] ?? 'sem_nome',
                'match_type' => $restrictionRule['match_type'] ?? 'contains',
                'trigger_value' => $restrictionRule['trigger_value'] ?? '',
                'phone' => $session->phone,
            ]);

            return new ConversationResultDTO(
                (string) ($restrictionRule['response_message'] ?? 'Desculpe, mas esse tipo de atendimento não é feito por aqui.'),
                $session,
                'restricted_request',
                $analysis->entities
            );
        }

        if ($this->shouldAttemptFlowRecovery($message->message, $session, $intent)) {
            $recoveryDecision = $this->flowRecoveryService->decide($message, $session);

            if ($this->shouldApplyFlowRecovery($recoveryDecision, $session, $intent)) {
                return $this->applyFlowRecoveryDecision($session, $recoveryDecision, $analysis->entities);
            }
        }

        if ($intent === 'change_cpf' && $session->currentFlow !== 'idle') {
            return $this->handleCpfChangeIntent($message, $session, $analysis->entities);
        }

        if ($session->currentFlow === 'idle') {
            if ($isReactionAcknowledgement) {
                return new ConversationResultDTO(
                    'Obrigado.',
                    $session,
                    'external_reminder_confirmation',
                    $analysis->entities
                );
            }

            if ($this->isExternalReminderConfirmationMessage($message->message)) {
                return new ConversationResultDTO(
                    'Perfeito. Obrigado pela confirmacao. Se precisar de algo mais, e so me chamar.',
                    $session,
                    'external_reminder_confirmation',
                    $analysis->entities
                );
            }

            if ($intent === 'choose_service') {
                return new ConversationResultDTO($this->askForServiceChoice(), $session, $intent, $analysis->entities);
            }

            if ($intent === 'menu' || $intent === 'unknown') {
                return new ConversationResultDTO(
                    $this->menuMessage($session, $hadReusableSession),
                    $session,
                    $intent,
                    $analysis->entities
                );
            }

            if ($intent === 'consultar_agendamentos') {
                $session = $session->with(['current_flow' => 'consultar_agendamentos', 'current_step' => 'awaiting_cpf']);
                return $this->handleConsultaAgendamentos($message, $session, $intent, $analysis->entities);
            }

            if ($intent === 'cancelar_agendamento') {
                $session = $session->with(['current_flow' => 'cancelar_agendamento', 'current_step' => 'awaiting_cpf']);
                return $this->handleCancelamento($message, $session, $intent, $analysis->entities);
            }

            if ($this->isSchedulingIntent($intent)) {
                return $this->startSchedulingFlow($intent, $session, $analysis->entities);
            }
        }

        if ($session->pendingAction === 'confirm_cancel_blocked') {
            return $this->handleBlockedConfirmation($message, $session, $intent, $analysis->entities);
        }

        if ($session->currentFlow === 'cancelar_agendamento') {
            return $this->handleCancelamento($message, $session, $intent, $analysis->entities);
        }

        if ($session->currentFlow === 'consultar_agendamentos') {
            return $this->handleConsultaAgendamentos($message, $session, $intent, $analysis->entities);
        }

        if ($this->isSchedulingFlow($session->currentFlow)) {
            if ($intent === 'choose_service') {
                return new ConversationResultDTO($this->askForServiceChoice(), $session, $intent, $analysis->entities);
            }

            if ($this->isSchedulingIntent($intent) && $this->canSwitchService($session)) {
                return $this->startSchedulingFlow($intent, $session, $analysis->entities, true);
            }

            return $this->handleScheduling($message, $session, $intent, $analysis->entities);
        }

        return new ConversationResultDTO(
            $this->menuMessage($session, $hadReusableSession),
            $session,
            $intent,
            $analysis->entities
        );
    }

    private function handleScheduling(IncomingMessageDTO $message, SessionDTO $session, string $intent, array $entities): ConversationResultDTO
    {
        $toolCalls = [];
        $service = (string) $session->selectedService;
        $context = $session->context;

        if ($session->cpf !== null) {
            $context = $this->forgetStepAttempts($context, 'awaiting_cpf');
        }

        if ($session->nome !== null) {
            $context = $this->forgetStepAttempts($context, 'awaiting_name');
        }

        if ($session->telefone !== null) {
            $context = $this->forgetStepAttempts($context, 'awaiting_phone');
        }

        if ($session->selectedDate !== null) {
            $context = $this->forgetStepAttempts($context, 'awaiting_date_choice');
        }

        if ($session->selectedTime !== null) {
            $context = $this->forgetStepAttempts($context, 'awaiting_time_choice');
        }

        $session = $session->with(['context' => $context]);

        if ($session->cpf === null) {
            return $this->buildCpfStepPromptResult(
                $message,
                $session->with(['current_step' => 'awaiting_cpf']),
                $intent,
                $entities,
                $toolCalls,
                $this->askForCpf($service)
            );
        }

        if ($session->nome === null) {
            return $this->buildStepPromptResult(
                $message,
                $session->with(['current_step' => 'awaiting_name']),
                $intent,
                $entities,
                $toolCalls,
                'awaiting_name',
                'Perfeito. Agora me diga seu nome completo, por favor.',
                ['Jeferson Assis']
            );
        }

        if ($session->telefone === null) {
            return $this->buildStepPromptResult(
                $message,
                $session->with(['current_step' => 'awaiting_phone']),
                $intent,
                $entities,
                $toolCalls,
                'awaiting_phone',
                'Obrigada. Agora me informe seu telefone com DDD, por favor.',
                ['5566996553735']
            );
        }

        if ($session->currentStep === 'awaiting_date_choice' && $session->selectedDate === null) {
            return $this->buildStepPromptResult(
                $message,
                $session,
                $intent,
                $entities,
                $toolCalls,
                'awaiting_date_choice',
                'Me diga uma das datas que eu te mostrei, por favor.',
                $this->formatOptionExamples($session->context['available_dates'] ?? [], 3)
            );
        }

        if ($service === 'dentista' && $session->currentStep === 'awaiting_time_choice' && $session->selectedTime === null) {
            return $this->buildStepPromptResult(
                $message,
                $session,
                $intent,
                $entities,
                $toolCalls,
                'awaiting_time_choice',
                'Me diga um dos horários que eu te mostrei, por favor.',
                $this->formatOptionExamples($session->context['available_times'] ?? [], 3)
            );
        }

        if ($session->currentStep === 'awaiting_date_choice' && $session->selectedDate !== null) {
            if ($service === 'dentista') {
                $toolCalls[] = ['tool' => 'consultar_horario_dentista', 'args' => ['data_selecionada' => $session->selectedDate]];
                $horarios = $this->toolRegistry->call('consultar_horario_dentista', ['data_selecionada' => $session->selectedDate]);
                $this->logger->info('Tool executada', end($toolCalls));

                $session = $session->with([
                    'current_step' => 'awaiting_time_choice',
                    'context' => $this->forgetStepAttempts(array_merge($session->context, ['available_times' => $horarios['horarios'] ?? []]), 'awaiting_date_choice'),
                ]);

                return new ConversationResultDTO(
                    $this->formatTimesMessage($session->selectedDate, $horarios['horarios'] ?? []),
                    $session,
                    $intent,
                    $entities,
                    $toolCalls
                );
            }

            $toolName = $service === 'medico' ? 'cadastrar_agenda_medico' : 'cadastrar_agenda_enfer';
            $payload = [
                'cpf' => $session->cpf,
                'nome' => $session->nome,
                'telefone' => $session->telefone,
                'data' => $session->selectedDate,
            ];
            $toolCalls[] = ['tool' => $toolName, 'args' => $payload];
            $toolResult = $this->toolRegistry->call($toolName, $payload);
            $this->logger->info('Tool executada', end($toolCalls));

            if (!$this->isSuccessfulWriteResult($toolResult)) {
                $session = $session->with([
                    'current_step' => 'awaiting_date_choice',
                    'context' => $this->forgetStepAttempts($session->context, 'awaiting_date_choice'),
                ]);

                return new ConversationResultDTO(
                    $this->writeFailureMessage($service, $toolResult),
                    $session,
                    $intent,
                    $entities,
                    $toolCalls
                );
            }

            $verification = $this->verifyAppointmentCreation($session->cpf, $service, $session->selectedDate, null, $toolCalls);
            $context = $this->forgetStepAttempts($session->context, 'awaiting_date_choice');
            if (!$verification['confirmed']) {
                $context = array_merge($context, ['pending_manual_review' => true, 'last_write_result' => $toolResult]);
                $this->logger->warning('Agendamento criado sem confirmacao imediata na verificacao final.', [
                    'service' => $service,
                    'phone' => $session->phone,
                    'cpf' => $session->cpf,
                    'selected_date' => $session->selectedDate,
                    'selected_time' => null,
                    'tool_result' => $toolResult,
                    'verification' => $verification,
                ]);
            }

            if (in_array($service, ['medico', 'enfermeiro'], true)) {
                $horaComparecer = $this->extractHoraComparecer($toolResult);
                $horaAgendada = $this->extractHoraAgendada($toolResult);
                $agendamentoConfirmado = is_array($verification['agendamento'] ?? null)
                    ? $verification['agendamento']
                    : [];

                if ($horaComparecer === null && $agendamentoConfirmado !== []) {
                    $horaComparecer = $this->extractHoraComparecerFromAppointment($agendamentoConfirmado);
                }

                if ($horaAgendada === null && $agendamentoConfirmado !== []) {
                    $horaAgendada = $this->extractHoraAgendadaFromAppointment($agendamentoConfirmado);
                }

                if ($horaComparecer !== null) {
                    $context['hora_comparecer'] = $horaComparecer;
                }

                if ($horaAgendada !== null) {
                    $context['hora_agendada'] = $horaAgendada;
                }
            }

            $session = $session->with([
                'current_flow' => 'completed',
                'current_step' => 'completed',
                'context' => $context,
            ]);
            return new ConversationResultDTO($this->successMessage($service, $session), $session, $intent, $entities, $toolCalls);
        }

        if ($service === 'dentista' && $session->currentStep === 'awaiting_time_choice' && $session->selectedTime !== null) {
            $payload = [
                'cpf' => $session->cpf,
                'nome' => $session->nome,
                'telefone' => $session->telefone,
                'data' => $session->selectedDate,
                'hora' => $session->selectedTime,
            ];
            $toolCalls[] = ['tool' => 'cadastrar_agenda_dent', 'args' => $payload];
            $toolResult = $this->toolRegistry->call('cadastrar_agenda_dent', $payload);
            $this->logger->info('Tool executada', end($toolCalls));

            if (!$this->isSuccessfulWriteResult($toolResult)) {
                $session = $session->with([
                    'current_step' => 'awaiting_time_choice',
                    'context' => $this->forgetStepAttempts($session->context, 'awaiting_time_choice'),
                ]);

                return new ConversationResultDTO(
                    $this->writeFailureMessage($service, $toolResult),
                    $session,
                    $intent,
                    $entities,
                    $toolCalls
                );
            }

            $verification = $this->verifyAppointmentCreation($session->cpf, $service, $session->selectedDate, $session->selectedTime, $toolCalls);
            $context = $this->forgetStepAttempts($session->context, 'awaiting_time_choice');
            if (!$verification['confirmed']) {
                $context = array_merge($context, ['pending_manual_review' => true, 'last_write_result' => $toolResult]);
                $this->logger->warning('Agendamento criado sem confirma??o imediata na verifica??o final.', [
                    'service' => $service,
                    'phone' => $session->phone,
                    'cpf' => $session->cpf,
                    'selected_date' => $session->selectedDate,
                    'selected_time' => $session->selectedTime,
                    'tool_result' => $toolResult,
                    'verification' => $verification,
                ]);
            }

            $session = $session->with([
                'current_flow' => 'completed',
                'current_step' => 'completed',
                'context' => $context,
            ]);
            return new ConversationResultDTO($this->successMessage($service, $session), $session, $intent, $entities, $toolCalls);
        }
        $toolCalls[] = ['tool' => 'consultar_agendamento_ausente', 'args' => ['cpf' => $session->cpf]];
        $ausente = $this->toolRegistry->call('consultar_agendamento_ausente', ['cpf' => $session->cpf]);
        $this->logger->info('Tool executada', end($toolCalls));

        if (($ausente['bloquear_por_servico'][$service] ?? false) === true) {
            $agendamento = $ausente['agendamentos'][0] ?? [];
            $session = $session->with(['pending_action' => 'confirm_cancel_blocked']);
            $context = $session->context;
            $context['blocked_cancel_id'] = (string) ($agendamento['id'] ?? '');

            return new ConversationResultDTO(
                "Encontrei um agendamento anterior com status de *Ausente* para este serviço.\n\n" .
                'Serviço: ' . ($agendamento['servico'] ?? ucfirst($service)) . "\n" .
                'Data: ' . ($agendamento['data'] ?? '-') . "\n" .
                'Hora: ' . ($agendamento['hora'] ?? '-') . "\n\nSe você quiser, posso cancelar esse agendamento para tentar um novo.\n\nResponda:\n\n*Sim* ou *Não*",
                $session->with(['context' => $context]),
                $intent,
                $entities,
                $toolCalls
            );
        }


        $agendaTool = match ($service) {
            'medico' => 'consultar_agenda_medico',
            'enfermeiro' => 'consultar_agenda_enfer',
            default => 'consultar_agenda_dent',
        };

        $toolCalls[] = ['tool' => $agendaTool, 'args' => []];
        $agenda = $this->toolRegistry->call($agendaTool);
        $this->logger->info('Tool executada', end($toolCalls));

        $datas = $agenda['datas'] ?? [];
        $session = $session->with([
            'current_step' => 'awaiting_date_choice',
            'context' => $this->forgetStepAttempts(array_merge($session->context, ['available_dates' => $datas]), 'awaiting_date_choice'),
        ]);

        return new ConversationResultDTO($this->formatDatesMessage($service, $datas), $session, $intent, $entities, $toolCalls);
    }

    private function handleCpfChangeIntent(IncomingMessageDTO $message, SessionDTO $session, array $entities): ConversationResultDTO
    {
        $flow = $session->currentFlow;
        $incomingCpf = $this->extractValidCpfFromEntities($entities);

        if ($flow === 'idle' || $flow === 'completed') {
            $session = $this->prepareSessionForCpfChange($session, 'consultar_agendamentos');

            return new ConversationResultDTO(
                "Me envie o CPF da pessoa que voce quer atender agora, por favor.\n\nExemplo valido:\n- 111.111.111-11",
                $session,
                'change_cpf',
                $entities
            );
        }

        if ($incomingCpf === null) {
            $session = $this->prepareSessionForCpfChange($session, $flow);

            return new ConversationResultDTO(
                "Tudo bem. Me envie o CPF da outra pessoa para eu continuar por aqui.\n\nExemplo valido:\n- 111.111.111-11",
                $session,
                'change_cpf',
                $entities
            );
        }

        $session = $this->switchSessionCpf($session, $incomingCpf, $flow);

        if ($flow === 'consultar_agendamentos') {
            return $this->handleConsultaAgendamentos($message, $session, 'consultar_agendamentos', $entities);
        }

        if ($flow === 'cancelar_agendamento') {
            return $this->handleCancelamento($message, $session, 'cancelar_agendamento', $entities);
        }

        if ($this->isSchedulingFlow($flow)) {
            return $this->handleScheduling($message, $session, $flow, $entities);
        }

        return new ConversationResultDTO(
            "Atualizei a pessoa do atendimento. Me diga como posso continuar.",
            $session,
            'change_cpf',
            $entities
        );
    }

    private function handleConsultaAgendamentos(IncomingMessageDTO $message, SessionDTO $session, string $intent, array $entities): ConversationResultDTO
    {
        $text = trim((string) $message->message);
        $normalizedText = $this->normalizeText($text);
        $incomingCpf = $this->extractValidCpfFromEntities($entities);

        if ($this->isCpfChangeRequest($text, $intent)) {
            if ($incomingCpf !== null) {
                $session = $this->switchSessionCpf($session, $incomingCpf, 'consultar_agendamentos');
            } else {
                $session = $this->prepareSessionForCpfChange($session, 'consultar_agendamentos');

                return new ConversationResultDTO(
                    "Sem problema. Me envie o CPF que voce quer consultar agora.\n\nExemplo valido:\n- 111.111.111-11",
                    $session,
                    'change_cpf',
                    $entities
                );
            }
        } elseif ($session->currentStep === 'awaiting_lookup_retry' && $incomingCpf !== null && $incomingCpf !== $session->cpf) {
            $session = $this->switchSessionCpf($session, $incomingCpf, 'consultar_agendamentos');
        }

        if ($session->cpf === null) {
            return $this->buildCpfStepPromptResult(
                $message,
                $session->with(['current_flow' => 'consultar_agendamentos', 'current_step' => 'awaiting_cpf']),
                $intent,
                $entities,
                [],
                'Para eu consultar certinho, me informe seu CPF, por favor.'
            );
        }

        if (
            $session->currentStep === 'awaiting_lookup_retry'
            && $text !== ''
            && ($this->isDisputingLookupResult($text) || $this->isGreetingMessage($text))
        ) {
            return new ConversationResultDTO(
                "Ainda nao encontrei agendamentos para esse CPF.\n\nSe quiser, me envie outro CPF, a data aproximada do atendimento ou digite *Menu* para recomecar.",
                $session,
                $intent,
                $entities
            );
        }

        $toolCalls = [['tool' => 'consultar_agendamento_ausente', 'args' => ['cpf' => $session->cpf]]];
        $result = $this->toolRegistry->call('consultar_agendamento_ausente', ['cpf' => $session->cpf]);
        $this->logger->info('Tool executada', end($toolCalls));
        $agendamentos = $result['agendamentos'] ?? [];

        if ($agendamentos === []) {
            $session = $session->with([
                'current_flow' => 'consultar_agendamentos',
                'current_step' => 'awaiting_lookup_retry',
                'context' => array_merge(
                    $this->forgetTransientConversationContext($this->forgetStepAttempts($session->context, 'awaiting_cpf')),
                    [
                        'last_lookup_flow' => 'consultar_agendamentos',
                        'last_lookup_status' => 'empty',
                    ]
                ),
            ]);

            return new ConversationResultDTO(
                "No momento, nao encontrei agendamentos vinculados a esse CPF.\n\n" .
                "Se quiser, voce pode me enviar outro CPF, pedir para *trocar CPF*, informar a data aproximada do atendimento ou falar com a recepcao: (66) 9 9204-0540.",
                $session,
                $intent,
                $entities,
                $toolCalls
            );
        }

        $linhas = [];
        foreach ($agendamentos as $agendamento) {
            $linhas[] = ($agendamento['servico'] ?? 'Servico') . ' - ' . ($agendamento['data'] ?? '-') . ' - ' . ($agendamento['hora'] ?? '-');
        }

        $session = $session->with([
            'current_flow' => 'completed',
            'current_step' => 'completed',
            'context' => $this->forgetTransientConversationContext($session->context),
        ]);

        return new ConversationResultDTO("Encontrei estes agendamentos para voce:\n" . implode("\n", $linhas), $session, $intent, $entities, $toolCalls);
    }

    private function handleCancelamento(IncomingMessageDTO $message, SessionDTO $session, string $intent, array $entities): ConversationResultDTO
    {
        $text = trim((string) $message->message);
        $normalizedText = $this->normalizeText($text);
        $incomingCpf = $this->extractValidCpfFromEntities($entities);

        if ($this->isCpfChangeRequest($text, $intent)) {
            if ($incomingCpf !== null) {
                $session = $this->switchSessionCpf($session, $incomingCpf, 'cancelar_agendamento');
            } else {
                $session = $this->prepareSessionForCpfChange($session, 'cancelar_agendamento');

                return new ConversationResultDTO(
                    "Tudo bem. Me envie o CPF correto para eu localizar o agendamento.\n\nExemplo valido:\n- 111.111.111-11",
                    $session,
                    'change_cpf',
                    $entities
                );
            }
        } elseif ($session->currentStep === 'awaiting_lookup_retry' && $incomingCpf !== null && $incomingCpf !== $session->cpf) {
            $session = $this->switchSessionCpf($session, $incomingCpf, 'cancelar_agendamento');
        }

        if ($session->cpf === null) {
            return $this->buildCpfStepPromptResult(
                $message,
                $session->with(['current_flow' => 'cancelar_agendamento', 'current_step' => 'awaiting_cpf']),
                $intent,
                $entities,
                [],
                'Claro. Para eu localizar o agendamento, me informe seu CPF.'
            );
        }

        if ($session->currentStep === 'awaiting_lookup_retry') {
            if ($intent === 'consultar_agendamentos' || $this->isDisputingLookupResult($text)) {
                $consultaSession = $session->with([
                    'current_flow' => 'consultar_agendamentos',
                    'current_step' => 'awaiting_cpf',
                    'pending_action' => null,
                    'context' => $this->forgetTransientConversationContext($session->context),
                ]);

                $consultaResult = $this->handleConsultaAgendamentos($message, $consultaSession, 'consultar_agendamentos', $entities);

                return new ConversationResultDTO(
                    "Entendi. Vou conferir todos os seus agendamentos para te ajudar melhor.\n\n" . $consultaResult->reply,
                    $consultaResult->session,
                    'consultar_agendamentos',
                    $entities,
                    $consultaResult->toolCalls
                );
            }

            if ($text !== '') {
                return new ConversationResultDTO(
                    $this->buildCancellationRetryGuidance($session),
                    $session,
                    $intent,
                    $entities
                );
            }
        }

        if ($session->pendingAction === 'confirm_cancelamento' && in_array($normalizedText, ['sim', 's'], true)) {
            $id = (string) ($session->context['cancel_id'] ?? '');
            $toolCalls = [['tool' => 'confimar_cancelamento_geral', 'args' => ['idCancelamento' => $id]]];
            $result = $this->toolRegistry->call('confimar_cancelamento_geral', ['idCancelamento' => $id]);
            $this->logger->info('Tool executada', end($toolCalls));

            $session = $session->with([
                'current_flow' => 'completed',
                'current_step' => 'completed',
                'pending_action' => null,
                'context' => $this->forgetTransientConversationContext($session->context),
            ]);
            return new ConversationResultDTO((string) ($result['message'] ?? 'Pronto. O cancelamento foi realizado com sucesso.'), $session, $intent, $entities, $toolCalls);
        }

        if ($session->pendingAction === 'confirm_cancelamento' && in_array($normalizedText, ['nao', 'n'], true)) {
            $session = $session->with([
                'pending_action' => null,
                'current_flow' => 'idle',
                'current_step' => 'awaiting_menu_choice',
                'context' => $this->forgetTransientConversationContext($session->context),
            ]);
            return new ConversationResultDTO('Tudo bem. Nao cancelei nada por aqui.', $session, $intent, $entities);
        }

        if ($session->pendingAction === 'confirm_cancelamento') {
            return new ConversationResultDTO(
                $this->buildContextualStepReminder(
                    $session,
                    'Para confirmar o cancelamento, me responda apenas com *Sim* ou *Nao*.',
                    ['Sim', 'Nao']
                ),
                $session->with(['current_step' => 'awaiting_cancellation_confirmation']),
                $intent,
                $entities
            );
        }

        if ($session->currentStep === 'awaiting_cancellation_choice') {
            if (preg_match('/\b\d+\b/', $text, $matches) === 1) {
                $id = $matches[0];
                $session = $session->with([
                    'pending_action' => 'confirm_cancelamento',
                    'current_step' => 'awaiting_cancellation_confirmation',
                    'context' => array_merge($this->forgetTransientConversationContext($session->context), ['cancel_id' => $id]),
                ]);

                return new ConversationResultDTO("Antes de continuar, preciso confirmar:\n\nDeseja mesmo cancelar esse agendamento?\n\n*Sim* ou *Nao*", $session, $intent, $entities);
            }

            return new ConversationResultDTO(
                $this->buildCancellationChoiceGuidance($session->context['available_cancel_ids'] ?? [], $session),
                $session,
                $intent,
                $entities
            );
        }

        $toolCalls = [['tool' => 'consultar_cancelamento_agen', 'args' => ['cpf' => $session->cpf]]];
        $result = $this->toolRegistry->call('consultar_cancelamento_agen', ['cpf' => $session->cpf]);
        $this->logger->info('Tool executada', end($toolCalls));

        $agendamentos = $result['agendamentos'] ?? [];
        if ($agendamentos === []) {
            $session = $session->with([
                'current_flow' => 'cancelar_agendamento',
                'current_step' => 'awaiting_lookup_retry',
                'context' => array_merge(
                    $this->forgetTransientConversationContext($this->forgetStepAttempts($session->context, 'awaiting_cpf')),
                    [
                        'last_lookup_flow' => 'cancelar_agendamento',
                        'last_lookup_status' => 'empty',
                    ]
                ),
            ]);

            return new ConversationResultDTO(
                "No momento, nao encontrei agendamentos disponiveis para cancelamento.\n\n" .
                $this->buildCancellationRetryGuidance($session),
                $session,
                $intent,
                $entities,
                $toolCalls
            );
        }

        if (count($agendamentos) === 1) {
            $agendamento = $agendamentos[0];
            $id = (string) ($agendamento['id'] ?? '');
            $session = $session->with([
                'current_step' => 'awaiting_cancellation_confirmation',
                'pending_action' => 'confirm_cancelamento',
                'context' => array_merge($this->forgetTransientConversationContext($session->context), ['cancel_id' => $id]),
            ]);

            return new ConversationResultDTO(
                'Encontrei este agendamento:' . "\n\n" .
                'ID: ' . ($agendamento['id'] ?? '-') . "\n" .
                'Servico: ' . ($agendamento['servico'] ?? '-') . "\n" .
                'Data: ' . ($agendamento['data'] ?? '-') . "\n" .
                'Hora: ' . ($agendamento['hora'] ?? '-') . "\n\n" .
                'Deseja cancelar esse agendamento?' . "\n\n*Sim* ou *Nao*",
                $session,
                $intent,
                $entities,
                $toolCalls
            );
        }

        $linhas = [];
        $availableIds = [];
        foreach ($agendamentos as $agendamento) {
            $availableIds[] = (string) ($agendamento['id'] ?? '');
            $linhas[] = 'ID: ' . ($agendamento['id'] ?? '-') . "\nServico: " . ($agendamento['servico'] ?? '-') . "\nData: " . ($agendamento['data'] ?? '-') . "\nHora: " . ($agendamento['hora'] ?? '-');
        }

        $session = $session->with([
            'current_step' => 'awaiting_cancellation_choice',
            'context' => array_merge($this->forgetTransientConversationContext($session->context), ['available_cancel_ids' => $availableIds]),
        ]);
        return new ConversationResultDTO(implode("\n\n", $linhas) . "\n\n" . $this->buildCancellationChoiceGuidance($availableIds, $session), $session, $intent, $entities, $toolCalls);
    }

    private function handleBlockedConfirmation(IncomingMessageDTO $message, SessionDTO $session, string $intent, array $entities): ConversationResultDTO
    {
        $text = $this->normalizeText((string) $message->message);

        if (in_array($text, ['nao', 'n'], true)) {
            return new ConversationResultDTO('Tudo bem. Mantive o agendamento como está. Se quiser, posso te ajudar com outra coisa depois.', $session->with(['current_flow' => 'idle', 'current_step' => 'awaiting_menu_choice', 'pending_action' => null]), $intent, $entities);
        }

        if (in_array($text, ['sim', 's'], true)) {
            $cancelId = (string) ($session->context['blocked_cancel_id'] ?? '');
            $toolCalls = [];

            if ($cancelId !== '') {
                $toolCalls[] = ['tool' => 'confimar_cancelamento_geral', 'args' => ['idCancelamento' => $cancelId]];
                $this->toolRegistry->call('confimar_cancelamento_geral', ['idCancelamento' => $cancelId]);
                $this->logger->info('Tool executada', end($toolCalls));
            }

            $session = $session->with([
                'pending_action' => null,
                'context' => array_merge($session->context, ['blocked_cancel_id' => null]),
            ]);

            return $this->continueSchedulingAfterBlockedCancellation($session, $intent, $entities, $toolCalls);
        }

        return new ConversationResultDTO('Para eu continuar, me responda apenas com Sim ou Não.', $session, $intent, $entities);
    }

    private function continueSchedulingAfterBlockedCancellation(SessionDTO $session, string $intent, array $entities, array $toolCalls = []): ConversationResultDTO
    {
        $service = (string) $session->selectedService;
        $agendaTool = match ($service) {
            'medico' => 'consultar_agenda_medico',
            'enfermeiro' => 'consultar_agenda_enfer',
            default => 'consultar_agenda_dent',
        };

        $toolCalls[] = ['tool' => $agendaTool, 'args' => []];
        $agenda = $this->toolRegistry->call($agendaTool);
        $this->logger->info('Tool executada', end($toolCalls));

        $datas = $agenda['datas'] ?? [];
        $session = $session->with([
            'current_step' => 'awaiting_date_choice',
            'context' => array_merge($session->context, ['available_dates' => $datas]),
        ]);

        $reply = "Pronto. Já liberei esse bloqueio para você.\n\n" . $this->formatDatesMessage($service, $datas);

        return new ConversationResultDTO($reply, $session, $intent, $entities, $toolCalls);
    }

    private function buildCancellationRetryGuidance(SessionDTO $session): string
    {
        return "Se voce quiser, posso conferir seus agendamentos gerais para te ajudar melhor.\n\nDigite *4* ou escreva *consultar meus agendamentos*.\nSe preferir, me envie outro CPF ou a data aproximada do atendimento.";
    }

    private function buildCancellationChoiceGuidance(array $availableIds, ?SessionDTO $session = null): string
    {
        $reply = 'Para eu continuar, me diga o *ID* do agendamento que voce deseja cancelar.';

        if ($session === null) {
            $examples = $this->formatOptionExamples($availableIds);

            if ($examples !== []) {
                $reply .= "\n\nExemplo valido:\n" . implode("\n", $examples);
            }

            return $reply;
        }

        return $this->buildContextualStepReminder($session, $reply, $availableIds);
    }

    private function buildContextualStepReminder(SessionDTO $session, string $mainPrompt, array $examples = [], bool $includeRestartHint = false): string
    {
        $parts = [];

        if ($includeRestartHint) {
            $stepDescription = $this->describeCurrentStep($session);

            if ($stepDescription !== null) {
                $parts[] = 'Estamos na etapa de ' . $stepDescription . '.';
            }
        }

        $parts[] = $mainPrompt;

        $formattedExamples = $this->formatOptionExamples($examples);
        if ($formattedExamples !== []) {
            $parts[] = "Exemplo valido:\n" . implode("\n", $formattedExamples);
        }

        if ($includeRestartHint) {
            $parts[] = 'Se preferir, eu tambem posso recomecar. Basta digitar Sair.';
        }

        return implode("\n\n", $parts);
    }

    private function buildFlowRecoveryClarifyMessage(SessionDTO $session): string
    {
        return match ($session->currentStep) {
            'awaiting_cpf' => $this->buildContextualStepReminder($session, 'Para eu continuar, me envie o CPF do paciente.', ['111.111.111-11']),
            'awaiting_name' => $this->buildContextualStepReminder($session, 'Para eu continuar, me diga o nome completo do paciente.', ['Jeferson Assis']),
            'awaiting_phone' => $this->buildContextualStepReminder($session, 'Para eu continuar, me informe o telefone com DDD.', ['5566996553735']),
            'awaiting_date_choice' => $this->buildContextualStepReminder($session, 'Me diga uma das datas que eu te mostrei, por favor.', $session->context['available_dates'] ?? []),
            'awaiting_time_choice' => $this->buildContextualStepReminder($session, 'Me diga um dos horarios que eu te mostrei, por favor.', $session->context['available_times'] ?? []),
            'awaiting_cancellation_choice' => $this->buildCancellationChoiceGuidance($session->context['available_cancel_ids'] ?? [], $session),
            'awaiting_cancellation_confirmation' => $this->buildContextualStepReminder($session, 'Para confirmar o cancelamento, me responda apenas com Sim ou Nao.', ['Sim', 'Nao']),
            'awaiting_lookup_retry' => $session->currentFlow === 'cancelar_agendamento'
                ? $this->buildContextualStepReminder($session, 'Se quiser, me envie o CPF correto, diga a data aproximada ou escreva consultar meus agendamentos.', ['consultar meus agendamentos', '111.111.111-11'])
                : $this->buildContextualStepReminder($session, 'Se quiser, me envie outro CPF ou me diga a data aproximada do atendimento.', ['111.111.111-11']),
            default => $this->buildContextualStepReminder($session, 'Para eu seguir certinho, responda o que esta pendente nesta etapa.', [], true),
        };
    }

    private function describeCurrentStep(SessionDTO $session): ?string
    {
        return match ($session->currentStep) {
            'awaiting_cpf' => 'informar seu CPF',
            'awaiting_name' => 'informar seu nome completo',
            'awaiting_phone' => 'informar seu telefone com DDD',
            'awaiting_date_choice' => 'escolher a data do atendimento',
            'awaiting_time_choice' => 'escolher o horario do atendimento',
            'awaiting_cancellation_choice' => 'escolher o ID do agendamento para cancelar',
            'awaiting_cancellation_confirmation' => 'confirmar se deseja cancelar o agendamento',
            'awaiting_lookup_retry' => $session->currentFlow === 'cancelar_agendamento'
                ? 'confirmar se existe um agendamento para cancelar'
                : 'conferir seus agendamentos',
            'awaiting_menu_choice' => 'escolher uma opcao do menu',
            default => null,
        };
    }

    private function extractLastAssistantReplyCue(SessionDTO $session, int $limit = 160): ?string
    {
        $reply = trim((string) ($session->context['last_assistant_reply'] ?? ''));

        if ($reply === '') {
            return null;
        }

        $reply = preg_replace('/\s+/', ' ', $reply) ?? $reply;

        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($reply, 0, $limit, '...');
        }

        return strlen($reply) > $limit ? substr($reply, 0, $limit - 3) . '...' : $reply;
    }

    private function isConfusedFollowUp(?string $message): bool
    {
        $text = $this->normalizeText((string) $message);

        if ($text === '') {
            return false;
        }

        if ($this->isGreetingMessage($text) || $this->isDisputingLookupResult($text)) {
            return true;
        }

        $directMatches = [
            'nao sei',
            'nao entendi',
            'entendi nao',
            'como assim',
            'pode explicar',
            'qual mesmo',
            'talvez',
            'hein',
        ];

        if (in_array($text, $directMatches, true)) {
            return true;
        }

        return str_contains($text, 'nao entendi') || str_contains($text, 'qual') || str_contains($text, 'explica');
    }

    private function isDisputingLookupResult(?string $message): bool
    {
        $text = $this->normalizeText((string) $message);

        if ($text === '') {
            return false;
        }

        $phrases = [
            'mas eu tenho',
            'eu tenho',
            'tenho sim',
            'mas tem',
            'tem sim',
            'acho que tenho',
            'acho que tem',
            'confere',
            'conferir',
            'verifica',
            'verificar',
            'como assim',
            'nao era isso',
            'nao e isso',
        ];

        foreach ($phrases as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function isGreetingMessage(?string $message): bool
    {
        return in_array($this->normalizeText((string) $message), ['oi', 'ola', 'bom dia', 'boa tarde', 'boa noite'], true);
    }

    private function isCpfChangeRequest(?string $message, ?string $intent = null): bool
    {
        if ($intent === 'change_cpf') {
            return true;
        }

        $text = $this->normalizeText((string) $message);

        if ($text === '') {
            return false;
        }

        $phrases = [
            'trocar cpf',
            'mudar cpf',
            'alterar cpf',
            'outro cpf',
            'cpf diferente',
            'novo cpf',
            'nao e esse cpf',
            'nao e meu cpf',
            'quero trocar cpf',
            'quero mudar cpf',
            'outro paciente',
            'outra pessoa',
            'meu filho',
            'minha filha',
            'meu marido',
            'minha esposa',
            'minha mae',
            'meu pai',
            'para outra pessoa',
        ];

        foreach ($phrases as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function prepareSessionForCpfChange(SessionDTO $session, string $flow): SessionDTO
    {
        return $session->with([
            'cpf' => null,
            'nome' => null,
            'telefone' => null,
            'current_flow' => $flow,
            'current_step' => 'awaiting_cpf',
            'pending_action' => null,
            'context' => $this->forgetTransientConversationContext($this->forgetStepAttempts($session->context, 'awaiting_cpf')),
        ]);
    }

    private function switchSessionCpf(SessionDTO $session, string $cpf, string $flow): SessionDTO
    {
        return $session->with([
            'cpf' => $cpf,
            'nome' => null,
            'telefone' => null,
            'current_flow' => $flow,
            'current_step' => 'awaiting_cpf',
            'pending_action' => null,
            'context' => $this->forgetTransientConversationContext($this->forgetStepAttempts($session->context, 'awaiting_cpf')),
        ]);
    }

    private function forgetTransientConversationContext(array $context): array
    {
        unset($context['last_lookup_flow'], $context['last_lookup_status'], $context['available_cancel_ids'], $context['cancel_id']);

        return $context;
    }

    private function startSchedulingFlow(string $intent, SessionDTO $session, array $entities, bool $isSwitch = false): ConversationResultDTO
    {
        $service = $this->intentToService($intent);
        $session = $session->with([
            'current_flow' => $intent,
            'nome' => null,
            'current_step' => 'awaiting_cpf',
            'selected_service' => $service,
            'selected_date' => null,
            'selected_time' => null,
            'pending_action' => null,
            'context' => [],
        ]);

        $prefix = $service === 'dentista'
            ? "Se estiver com dor, pode vir à unidade sem agendamento prévio.\n\n"
            : '';

        $reply = $isSwitch
            ? 'Perfeito. Vamos seguir com ' . $this->displayServiceName($service) . ".\n\n" . $this->askForCpf($service)
            : $prefix . $this->askForCpf($service);

        if (!$isSwitch && $service === 'dentista') {
            $reply = $prefix . $this->askForCpf($service);
        }

        return new ConversationResultDTO($reply, $session, $intent, $entities);
    }

    private function mergeSessionData(SessionDTO $session, array $entities): SessionDTO
    {
        $context = $session->context;
        $incomingCpf = $this->extractValidCpfFromEntities($entities);
        $incomingNome = $entities['nome'] ?? null;

        if (($entities['date'] ?? null) !== null && in_array($session->currentStep, ['awaiting_date_choice'], true)) {
            $availableDates = $context['available_dates'] ?? [];
            if ($availableDates === [] || in_array($entities['date'], $availableDates, true)) {
                $context['selected_date_source'] = 'interpreter';
                $session = $session->with(['selected_date' => $entities['date']]);
            }
        }

        if (($entities['time'] ?? null) !== null && in_array($session->currentStep, ['awaiting_time_choice'], true)) {
            $availableTimes = $context['available_times'] ?? [];
            if ($availableTimes === [] || in_array($entities['time'], $availableTimes, true)) {
                $context['selected_time_source'] = 'interpreter';
                $session = $session->with(['selected_time' => $entities['time']]);
            }
        }

        if ($this->shouldReplaceSessionCpf($session, $incomingCpf)) {
            $session = $session->with([
                'cpf' => $incomingCpf,
                'nome' => null,
                'telefone' => null,
            ]);
        }


        if (!$this->shouldAutoFillSchedulingName($session)) {
            $incomingNome = null;
        }
        return $session->with([
            'cpf' => $session->cpf ?? $incomingCpf,
            'nome' => $session->nome ?? $incomingNome,
            'telefone' => $session->telefone ?? ($entities['telefone'] ?? null),
            'context' => $context,
        ]);
    }

    private function shouldAutoFillSchedulingName(SessionDTO $session): bool
    {
        if (!$this->isSchedulingFlow($session->currentFlow)) {
            return true;
        }

        return $session->currentStep === 'awaiting_name';
    }

    private function shouldReplaceSessionCpf(SessionDTO $session, ?string $incomingCpf): bool
    {
        if ($incomingCpf === null) {
            return false;
        }

        if ($session->cpf === null) {
            return false;
        }

        if ($incomingCpf === $session->cpf) {
            return false;
        }

        if (!CpfValidator::isValid($session->cpf)) {
            return true;
        }

        return in_array($session->currentStep, ['awaiting_cpf', 'awaiting_lookup_retry'], true);
    }

    private function extractValidCpfFromEntities(array $entities): ?string
    {
        $cpf = $entities['cpf'] ?? null;

        if (!is_string($cpf) || trim($cpf) === '') {
            return null;
        }

        return CpfValidator::isValid($cpf) ? CpfValidator::sanitize($cpf) : null;
    }

    private function menuMessage(?SessionDTO $session = null, bool $preferShortGreeting = false): string
    {
        if ($preferShortGreeting) {
            return $this->returningMenuMessage($session);
        }

        return "Olá! Sou a assistente virtual da UBS Vida Nova (PSF02).\n\n" .
            "Posso te ajudar por aqui com *agendamentos*, *consulta dos seus horários* e *cancelamentos*.\n" .
            "Se você precisar falar com a recepção, o atendimento humano também continua disponível.\n\n" .
            "Escolha uma das opções abaixo:\n" .
            "1 - Agendar Médico\n" .
            "2 - Agendar Dentista\n" .
            "3 - Agendar Enfermeiro\n" .
            "4 - Consultar meus agendamentos\n" .
            "5 - Cancelar um agendamento\n\n" .
            "Para outros assuntos, fale com a recepção: (66) 9 9204-0540\n\n" .
            "Digite *Sair* a qualquer momento para encerrar este atendimento.";
    }

    private function returningMenuMessage(?SessionDTO $session = null): string
    {
        $name = $this->resolveGreetingName($session);
        $greeting = $name !== null
            ? "Olá, {$name}! Como posso te ajudar?"
            : "Olá! Como posso te ajudar?";

        return $greeting . "\n\n" .
            "Escolha uma das opções abaixo:\n" .
            "1 - Agendar Médico\n" .
            "2 - Agendar Dentista\n" .
            "3 - Agendar Enfermeiro\n" .
            "4 - Consultar meus agendamentos\n" .
            "5 - Cancelar um agendamento\n\n" .
            "Para outros assuntos, fale com a recepção: (66) 9 9204-0540\n\n" .
            "Digite *Sair* a qualquer momento para encerrar este atendimento.";
    }

    private function askForServiceChoice(): string
    {
        return "Claro. Qual serviço você quer agendar?\n\n1 - Médico\n2 - Dentista\n3 - Enfermeiro";
    }

    private function askForCpf(string $service): string
    {
        return 'Para eu continuar com o agendamento de ' . $this->displayServiceName($service) . ', me informe seu *CPF*, por favor.';
    }

    private function buildCpfStepPromptResult(
        IncomingMessageDTO $message,
        SessionDTO $session,
        string $intent,
        array $entities,
        array $toolCalls,
        string $defaultPrompt
    ): ConversationResultDTO {
        return $this->buildStepPromptResult(
            $message,
            $session,
            $intent,
            $entities,
            $toolCalls,
            'awaiting_cpf',
            $this->resolveCpfPrompt($message, $defaultPrompt),
            ['111.111.111-11']
        );
    }

    private function formatDatesMessage(string $service, array $dates): string
    {
        if ($dates === []) {
            return 'No momento, não encontrei datas disponíveis. Se preferir, você também pode falar com a recepção: (66) 9.9204-0540';
        }

        return 'Encontrei estas datas disponíveis para ' . $this->displayServiceName($service) . "\n- " . implode("\n- ", $dates) . "\n\nMe diga qual data você prefere, no formato DD/MM/AAAA.";
    }

    private function formatTimesMessage(string $date, array $times): string
    {
        if ($times === []) {
            return 'Não encontrei horários disponíveis para essa data. Se quiser, me diga outra data e eu verifico para você.';
        }

        return 'Estes são os horários disponíveis para ' . $date . "\n- " . implode("\n- ", $times) . "\n\nMe diga qual *horário* você prefere.";
    }

    private function successMessage(string $service, SessionDTO $session): string
    {
        $horaLinha = '';
        $horaComparecimento = null;

        if (in_array($service, ['medico', 'enfermeiro'], true)) {
            if ($service === 'enfermeiro') {
                $horaComparecimento = $session->context['hora_agendada']
                    ?? $session->context['hora_comparecer']
                    ?? $session->selectedTime
                    ?? null;
            } else {
                $horaComparecimento = $session->context['hora_comparecer']
                    ?? $session->context['hora_agendada']
                    ?? null;
            }

            if (is_string($horaComparecimento)) {
                $horaComparecimento = trim($horaComparecimento);
            }

            if ($horaComparecimento === '') {
                $horaComparecimento = null;
            }
        } else {
            $horaComparecimento = $session->selectedTime ?? 'A confirmar';
            $horaLinha = 'Hora: ' . $horaComparecimento . "\n";
        }

        $chegadaTexto = is_string($horaComparecimento) && trim($horaComparecimento) !== ''
            ? 'Pronto, seu agendamento foi realizado. Chegue no *PSF02* até às *' . $horaComparecimento . '*.'
            : 'Pronto, seu agendamento foi realizado.';

        return $chegadaTexto . "\n\n" .
            'Nome: ' . ($session->nome ?? '-') . "\n" .
            'CPF: ' . ($session->cpf ?? '-') . "\n" .
            'Telefone: ' . ($session->telefone ?? '-') . "\n" .
            'Serviço: ' . $this->displayServiceName($service) . "\n" .
            'Data: ' . ($session->selectedDate ?? '-') . "\n" .
            $horaLinha .
            "\nSe não puder comparecer, faça o *cancelamento com antecedência* para liberar a vaga para outra pessoa.\n" .
            "No dia do atendimento, *chegue com 10 minutos de antecedência*.\n\n" .
            "📢 ATENÇÃO, POPULAÇÃO DE VERA! COMEÇOU A VACINAÇÃO CONTRA A GRIPE💉\n\n" .
            "A Secretaria Municipal de Saúde de Vera informa que inicia hoje (02/04) a CAMPANHA DE VACINAÇÃO CONTRA A GRIPE.\n\n" .
            "📍 A vacina está disponível nos PSF 1 e PSF 2.\n\n" .
            "Neste primeiro momento, conforme orientação do Ministério da Saúde, a imunização será destinada aos grupos prioritários:\n\n" .
            "✅ Crianças de 6 meses a menores de 6 anos\n" .
            "✅ Pessoas com 60 anos ou mais\n" .
            "✅ Gestantes\n" .
            "✅ Puérperas (até 45 dias após o parto)\n" .
            "✅ Pessoas com comorbidades\n" .
            "✅ Trabalhadores da saúde\n" .
            "✅ Professores\n\n" .
            "💙 A vacinação é a forma mais eficaz de prevenir complicações causadas pela gripe.\n\n" .
            "👉 Secretaria Municipal de Saúde de Vera – Cuidando de você e da sua saúde.";
    }

    private function buildStepPromptResult(
        IncomingMessageDTO $message,
        SessionDTO $session,
        string $intent,
        array $entities,
        array $toolCalls,
        string $step,
        string $defaultPrompt,
        array $examples = []
    ): ConversationResultDTO {
        $context = $session->context;
        $stepAttempts = is_array($context['step_attempts'] ?? null) ? $context['step_attempts'] : [];
        $attempts = (int) ($stepAttempts[$step] ?? 0);

        if (trim((string) $message->message) !== '' && $session->currentStep === $step) {
            $attempts++;
        }

        $stepAttempts[$step] = $attempts;
        $context['step_attempts'] = $stepAttempts;
        $session = $session->with(['current_step' => $step, 'context' => $context]);

        $reply = $defaultPrompt;
        if ($attempts >= 2 || $this->isConfusedFollowUp($message->message)) {
            $prompt = $attempts >= 2 ? "Vamos por partes.\n\n" . $defaultPrompt : $defaultPrompt;
            $reply = $this->buildContextualStepReminder($session, $prompt, $examples, $attempts >= 2);
        }

        return new ConversationResultDTO($reply, $session, $intent, $entities, $toolCalls);
    }

    private function resolveCpfPrompt(IncomingMessageDTO $message, string $defaultPrompt): string
    {
        $analysis = CpfValidator::analyzeInput($message->message);

        if (($analysis['submitted'] ?? false) !== true || ($analysis['reason'] ?? null) === null) {
            return $defaultPrompt;
        }

        return match ($analysis['reason']) {
            'missing_digits' => 'Esse CPF parece incompleto. O CPF precisa ter 11 numeros. Pode me enviar novamente, por favor?',
            'extra_digits' => 'Esse CPF veio com numeros a mais. O CPF precisa ter 11 numeros. Pode conferir e me enviar de novo?',
            'repeated_digits' => 'Esse CPF nao e valido. Nao posso seguir com uma sequencia repetida. Confira e me envie novamente, por favor.',
            default => 'Esse CPF não passou na validação. Confira os numeros e me envie novamente, por favor.',
        };
    }

    private function forgetStepAttempts(array $context, string $step): array
    {
        if (!is_array($context['step_attempts'] ?? null)) {
            return $context;
        }

        unset($context['step_attempts'][$step]);

        if (($context['step_attempts'] ?? []) === []) {
            unset($context['step_attempts']);
        }

        return $context;
    }

    private function formatOptionExamples(array $options, int $limit = 3): array
    {
        $formatted = [];

        foreach (array_slice($options, 0, $limit) as $option) {
            if (!is_string($option) || trim($option) === '') {
                continue;
            }

            $formatted[] = '- ' . trim($option);
        }

        return $formatted;
    }

    private function isSuccessfulWriteResult(array $toolResult): bool
    {
        return ($toolResult['success'] ?? false) === true;
    }

    private function writeFailureMessage(string $service, array $toolResult): string
    {
        $baseMessage = trim((string) ($toolResult['message'] ?? ''));

        if ($baseMessage === '') {
            $baseMessage = 'Não consegui confirmar esse agendamento agora.';
        }

        return $baseMessage . "\n\nPara sua segurança, não vou dizer que a vaga ficou reservada. Se quiser, tente novamente daqui a pouco ou fale com a recepção.";
    }

    private function postWritePendingValidationMessage(string $service): string
    {
        return 'Recebi um retorno inicial positivo para o agendamento de ' . $this->displayServiceName($service) . ", mas ainda não consegui localizar esse agendamento na verificação final.\n\nPara sua segurança, não vou te dizer que ele está confirmado agora. Me chame novamente em alguns instantes para consultar ou fale com a recepção.";
    }

    private function verifyAppointmentCreation(?string $cpf, string $service, ?string $selectedDate, ?string $selectedTime, array &$toolCalls): array
    {
        if ($cpf === null || trim($cpf) === '') {
            return ['confirmed' => false, 'agendamento' => null];
        }

        $toolCalls[] = ['tool' => 'consultar_agendamento_ausente', 'args' => ['cpf' => $cpf]];
        $result = $this->toolRegistry->call('consultar_agendamento_ausente', ['cpf' => $cpf]);
        $this->logger->info('Tool executada', end($toolCalls));

        $agendamento = $this->findMatchingAppointment($result['agendamentos'] ?? [], $service, $selectedDate, $selectedTime);

        return [
            'confirmed' => $agendamento !== null,
            'agendamento' => $agendamento,
            'raw' => $result,
        ];
    }

    private function findMatchingAppointment(array $agendamentos, string $service, ?string $selectedDate, ?string $selectedTime): ?array
    {
        foreach ($agendamentos as $agendamento) {
            if (!is_array($agendamento)) {
                continue;
            }

            if (!$this->serviceMatchesAppointment($service, $agendamento)) {
                continue;
            }

            $dateMatches = $selectedDate === null || (string) ($agendamento['data'] ?? '') === $selectedDate;
            $hora = trim((string) ($agendamento['hora'] ?? ($agendamento['horaAgendada'] ?? '')));
            $timeMatches = $selectedTime === null || $hora === '' || $hora === $selectedTime;

            if ($dateMatches && $timeMatches) {
                return $agendamento;
            }
        }

        return null;
    }

    private function serviceMatchesAppointment(string $service, array $agendamento): bool
    {
        $appointmentService = $this->normalizeText((string) ($agendamento['servico'] ?? ''));

        return match ($service) {
            'medico' => str_contains($appointmentService, 'medic') || str_contains($appointmentService, 'consulta medica'),
            'enfermeiro' => str_contains($appointmentService, 'enferm'),
            default => str_contains($appointmentService, 'dent'),
        };
    }
    private function extractHoraComparecer(array $toolResult): ?string
    {
        $horaBase = $this->extractFirstHoraValue($toolResult, [
            'horaAgendada',
            'hora_agendada',
            'horaComparecer',
            'hora_comparecer',
            'hora',
            'horario',
        ]);

        if ($horaBase === null && is_array($toolResult['data'] ?? null)) {
            $horaBase = $this->extractFirstHoraValue($toolResult['data'], [
                'horaAgendada',
                'hora_agendada',
                'horaComparecer',
                'hora_comparecer',
                'hora',
                'horario',
            ]);
        }

        if ($horaBase === null || $horaBase === '') {
            return null;
        }

        return $this->normalizeHoraComparecer($horaBase);
    }

    private function extractHoraAgendada(array $toolResult): ?string
    {
        $horaAgendada = $this->extractFirstHoraValue($toolResult, [
            'horaAgendada',
            'hora_agendada',
            'hora',
            'horario',
        ]);

        if ($horaAgendada === null && is_array($toolResult['data'] ?? null)) {
            $horaAgendada = $this->extractFirstHoraValue($toolResult['data'], [
                'horaAgendada',
                'hora_agendada',
                'hora',
                'horario',
            ]);
        }

        if (!is_string($horaAgendada) || trim($horaAgendada) === '') {
            return null;
        }

        return trim($horaAgendada);
    }

    private function extractHoraComparecerFromAppointment(array $appointment): ?string
    {
        $hora = $this->extractFirstHoraValue($appointment, [
            'horaComparecer',
            'hora_comparecer',
            'horaChegada',
            'hora_chegada',
            'horaAgendada',
            'hora_agendada',
            'hora',
            'horario',
        ]);

        if ($hora === null) {
            return null;
        }

        return $this->normalizeHoraComparecer($hora);
    }

    private function extractHoraAgendadaFromAppointment(array $appointment): ?string
    {
        return $this->extractFirstHoraValue($appointment, [
            'horaAgendada',
            'hora_agendada',
            'hora',
            'horario',
        ]);
    }

    private function extractFirstHoraValue(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;

            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
    private function displayServiceName(string $service): string
    {
        return match ($service) {
            'medico' => 'Médico',
            'enfermeiro' => 'Enfermeiro',
            default => 'Dentista',
        };
    }
    private function normalizeHoraComparecer(string $hora): ?string
    {
        if (preg_match('/^(?<hour>\d{1,2}):(?<minute>\d{2})$/', trim($hora), $matches) !== 1) {
            return null;
        }

        $hour = str_pad((string) ((int) $matches['hour']), 2, '0', STR_PAD_LEFT);

        return $hour . ':00';
    }

    private function shouldAttemptFlowRecovery(?string $message, SessionDTO $session, string $intent): bool
    {
        if (in_array($session->currentFlow, ['idle', 'completed'], true)) {
            return false;
        }

        if ($this->isShortAck($message) || $this->isCourtesyMessage($message)) {
            return true;
        }

        if ($intent === 'unknown') {
            return true;
        }

        if ($this->isSchedulingIntent($intent) && $session->currentFlow !== $intent) {
            return true;
        }

        return in_array($intent, ['consultar_agendamentos', 'cancelar_agendamento'], true)
            && $session->currentFlow !== $intent;
    }

    private function shouldApplyFlowRecovery(FlowRecoveryDecisionDTO $decision, SessionDTO $session, string $intent): bool
    {
        if ($decision->action === 'continue' || $decision->confidence < 0.55) {
            return false;
        }

        if ($decision->action === 'switch_flow') {
            return $decision->targetIntent !== null
                && $decision->targetIntent !== ''
                && $decision->targetIntent !== $session->currentFlow
                && ($this->isSchedulingIntent($decision->targetIntent) || in_array($decision->targetIntent, ['consultar_agendamentos', 'cancelar_agendamento'], true));
        }

        return in_array($decision->action, ['complete', 'menu', 'clarify'], true);
    }

    private function applyFlowRecoveryDecision(SessionDTO $session, FlowRecoveryDecisionDTO $decision, array $entities): ConversationResultDTO
    {
        $this->logger->info('Flow recovery aplicado', [
            'action' => $decision->action,
            'target_intent' => $decision->targetIntent,
            'confidence' => $decision->confidence,
            'source' => $decision->source,
            'current_flow' => $session->currentFlow,
            'current_step' => $session->currentStep,
            'phone' => $session->phone,
        ]);

        if ($decision->action === 'complete') {
            $session = $session->with([
                'current_flow' => 'completed',
                'current_step' => 'completed',
                'pending_action' => null,
            ]);

            return new ConversationResultDTO(
                $decision->replyMessage ?? 'Tudo certo. Se quiser continuar depois, é só me chamar.',
                $session,
                'flow_recovery_complete',
                $entities
            );
        }

        if ($decision->action === 'clarify') {
            return new ConversationResultDTO(
                $decision->replyMessage ?? $this->buildFlowRecoveryClarifyMessage($session),
                $session,
                'flow_recovery_clarify',
                $entities
            );
        }

        if ($decision->action === 'menu') {
            $session = $this->resetConversationState($session);

            return new ConversationResultDTO(
                $decision->replyMessage ?? $this->menuMessage(),
                $session,
                'flow_recovery_menu',
                $entities
            );
        }

        if ($decision->action === 'switch_flow' && $decision->targetIntent !== null) {
            $session = $session->with([
                'current_flow' => 'idle',
                'current_step' => 'awaiting_menu_choice',
                'selected_service' => null,
                'selected_date' => null,
                'selected_time' => null,
                'pending_action' => null,
                'context' => [],
            ]);

            if ($this->isSchedulingIntent($decision->targetIntent)) {
                return $this->startSchedulingFlow($decision->targetIntent, $session, $entities, true);
            }

            if ($decision->targetIntent === 'consultar_agendamentos') {
                $session = $session->with(['current_flow' => 'consultar_agendamentos', 'current_step' => 'awaiting_cpf']);
                return $this->handleConsultaAgendamentos(new IncomingMessageDTO(phone: $session->phone, messageType: 'text', message: '', mediaUrl: null, pushName: null, payload: []), $session, $decision->targetIntent, $entities);
            }

            if ($decision->targetIntent === 'cancelar_agendamento') {
                $session = $session->with(['current_flow' => 'cancelar_agendamento', 'current_step' => 'awaiting_cpf']);
                return $this->handleCancelamento(new IncomingMessageDTO(phone: $session->phone, messageType: 'text', message: '', mediaUrl: null, pushName: null, payload: []), $session, $decision->targetIntent, $entities);
            }
        }

        return new ConversationResultDTO($this->menuMessage(), $session, 'flow_recovery_fallback', $entities);
    }

    private function normalizeIntent(string $intent, ?string $message, SessionDTO $session): string
    {
        $text = $this->normalizeText((string) $message);
        $menuIntent = $this->detectMenuIntentFromText($text);

        if ($menuIntent !== null) {
            return $menuIntent;
        }

        $serviceIntent = $this->detectServiceIntentFromText($text);

        if ($serviceIntent !== null) {
            return $serviceIntent;
        }

        if ($this->isGenericSchedulingRequest($text)) {
            return 'choose_service';
        }

        if ($intent === 'unknown' && $session->currentFlow === 'idle' && ($text === 'agendar' || $text === 'marcar')) {
            return 'choose_service';
        }

        return $intent;
    }

    private function detectMenuIntentFromText(string $text): ?string
    {
        if (preg_match('/^(1|2|3|4|5)(?:\s*[-.)])?(?:\s|$)/', $text, $matches) === 1) {
            $text = $matches[1];
        }

        return match ($text) {
            '1' => 'agendar_medico',
            '2' => 'agendar_dentista',
            '3' => 'agendar_enfermeiro',
            '4' => 'consultar_agendamentos',
            '5' => 'cancelar_agendamento',
            default => null,
        };
    }

    private function hasReusableSessionContext(SessionDTO $session): bool
    {
        if ($session->cpf !== null || $session->nome !== null || $session->telefone !== null) {
            return true;
        }

        $lastUserMessage = trim((string) ($session->context['last_user_message'] ?? ''));
        $lastAssistantReply = trim((string) ($session->context['last_assistant_reply'] ?? ''));

        return $lastUserMessage !== '' || $lastAssistantReply !== '';
    }

    private function resolveGreetingName(?SessionDTO $session = null): ?string
    {
        $name = trim((string) ($session?->nome ?? ''));

        if ($name === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $firstName = trim((string) ($parts[0] ?? ''));

        return $firstName !== '' ? $firstName : null;
    }

    private function detectServiceIntentFromText(string $text): ?string
    {
        if (preg_match('/\b(dentista|dente|odonto)\b/', $text) === 1) {
            return 'agendar_dentista';
        }

        if (preg_match('/\b(enfermeiro|enfermeira|enfermagem)\b/', $text) === 1) {
            return 'agendar_enfermeiro';
        }

        if (preg_match('/\b(medico|medica|consulta medica|clinico|clinica)\b/', $text) === 1) {
            return 'agendar_medico';
        }

        return null;
    }

    private function isGenericSchedulingRequest(string $text): bool
    {
        if (preg_match('/\b(agendar|agende|marcar|marque|consulta|consultas|agendamento)\b/', $text) !== 1) {
            return false;
        }

        return $this->detectServiceIntentFromText($text) === null;
    }

    private function shouldExitCurrentConversation(?string $message, SessionDTO $session): bool
    {
        $text = $this->normalizeText((string) $message);
        $hardExitCommands = [
            'cancelar atendimento',
            'encerrar',
            'encerrar atendimento',
            'sair',
            'sair da conversa',
            'parar',
            'deixa quieto',
            'pode deixar',
        ];

        if (in_array($text, $hardExitCommands, true)) {
            return true;
        }

        if (in_array($session->currentFlow, ['idle', 'completed'], true)) {
            return false;
        }

        if (in_array($text, ['menu', 'inicio', 'voltar'], true)) {
            return true;
        }

        if ($session->currentFlow === 'cancelar_agendamento') {
            return false;
        }

        return in_array($text, ['cancela', 'cancelar'], true);
    }
    private function resetConversationState(SessionDTO $session): SessionDTO
    {
        return $session->with([
            'current_flow' => 'idle',
            'current_step' => 'awaiting_menu_choice',
            'selected_service' => null,
            'selected_date' => null,
            'selected_time' => null,
            'pending_action' => null,
            'context' => [],
        ]);
    }

    private function canSwitchService(SessionDTO $session): bool
    {
        return $session->cpf === null && in_array($session->currentStep, ['awaiting_cpf', 'awaiting_menu_choice'], true);
    }

    private function isSchedulingIntent(string $intent): bool
    {
        return in_array($intent, ['agendar_medico', 'agendar_dentista', 'agendar_enfermeiro'], true);
    }

    private function isSchedulingFlow(string $flow): bool
    {
        return in_array($flow, ['agendar_medico', 'agendar_dentista', 'agendar_enfermeiro'], true);
    }

    private function intentToService(string $intent): string
    {
        return match ($intent) {
            'agendar_medico' => 'medico',
            'agendar_enfermeiro' => 'enfermeiro',
            default => 'dentista',
        };
    }

    private function isExternalReminderConfirmationMessage(?string $message): bool
    {
        $text = $this->normalizeText((string) $message);
        $sanitized = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));

        if ($sanitized === '') {
            return false;
        }

        if (in_array($sanitized, ['cancelar', 'cancela'], true)) {
            return false;
        }

        if (preg_match('/\bconfirm\w*\b/', $sanitized) === 1 || preg_match('/\bconfim\w*\b/', $sanitized) === 1) {
            return true;
        }

        $phrases = [
            'ok',
            'okay',
            'obg',
            'obrigado',
            'obrigada',
            'muito obrigado',
            'muito obrigada',
            'vou sim',
            'eu vou',
            'vou',
            'vai sim',
            'ele vai',
            'ela vai',
            'iremos',
            'estarei ai',
            'estarei la',
            'pode confirmar',
        ];

        return in_array($sanitized, $phrases, true);
    }
    private function isReactionAcknowledgementMessage(?string $message): bool
    {
        $text = trim((string) $message);

        if ($text === '' || preg_match('/[\\p{L}\\p{N}]/u', $text) === 1) {
            return false;
        }

        $compact = preg_replace('/[\\s\\p{Cf}\\x{FE0E}\\x{FE0F}\\x{200D}\\x{1F3FB}-\\x{1F3FF}]+/u', '', $text);

        if (!is_string($compact) || $compact === '') {
            return false;
        }

        return preg_match('/^[\\p{So}\\p{Sk}\\x{2600}-\\x{27BF}\\x{1F000}-\\x{1FAFF}]+$/u', $compact) === 1;
    }

    private function isShortAck(?string $message): bool
    {
        $text = $this->normalizeText((string) $message);

        return in_array($text, ['ok', 'obg', 'valeu', 'joia', 'certo', 'blz', 'beleza', 'entendi'], true);
    }

    private function isCourtesyMessage(?string $message): bool
    {
        $text = $this->normalizeText((string) $message);

        return in_array($text, ['obrigado', 'obrigada', 'muito obrigado', 'muito obrigada', 'agradeco', 'grato', 'grata'], true);
    }
    private function normalizeText(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            if (is_string($converted) && $converted !== '') {
                $text = $converted;
            }
        }

        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, [
            '’' => "'",
            '‘' => "'",
            '‚' => ',',
            '“' => '"',
            '”' => '"',
            '„' => '"',
            '–' => '-',
            '—' => '-',
            '…' => '...',
            '`' => "'",
        ]);

        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
