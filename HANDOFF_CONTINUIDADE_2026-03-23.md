# Handoff de Continuidade - Robo Agendamento PSF02

## Objetivo deste documento

Este arquivo existe para permitir que outra IA assuma o projeto sem perder contexto tecnico, operacional e funcional.

Ele registra:

- o que ja foi implementado
- como a arquitetura esta organizada
- quais integracoes estao reais
- quais correcoes de fluxo ja foram feitas
- o que foi testado
- o que ainda falta
- quais cuidados precisam ser respeitados antes de executar novas acoes

## Resumo executivo

O projeto ja saiu do estado de prototipo e hoje opera em modo real.

Estado atual:

- OpenAI real ativa
- Evolution real ativa
- agenda real ativa
- persistencia SQL ativa no MySQL
- fallback JSON ainda existe como contingencia
- logs de integracao ativos
- sessao com TTL configuravel
- endpoint administrativo de sessao criado

O robo hoje consegue:

- receber mensagens
- interpretar intencao e entidades
- manter contexto de conversa
- agendar medico
- agendar dentista
- agendar enfermeiro
- consultar agendamentos
- cancelar agendamentos
- enviar respostas pelo WhatsApp

O que ainda falta com mais peso:

- fila/debounce de mensagens
- audio
- imagem
- testes automatizados
- tratamento completo de charset/acentos no banco
- observabilidade mais detalhada por estado/confianca

## Arquitetura atual

Fluxo principal:

1. A mensagem entra por [webhook.php](/C:/xampp/htdocs/producao/roboAgendamento/public/webhook.php)
2. O payload e normalizado por [MessageNormalizer.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/MessageNormalizer.php)
3. A sessao e carregada por [SessionService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/SessionService.php)
4. A interpretacao passa pela camada de IA:
   - [OpenAiConversationInterpreter.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/OpenAiConversationInterpreter.php)
   - [FallbackConversationInterpreter.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/FallbackConversationInterpreter.php)
   - [ResilientConversationInterpreter.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/ResilientConversationInterpreter.php)
5. O fluxo e decidido em [AiOrchestrator.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/AiOrchestrator.php)
6. As tools sao expostas por [AgendaToolRegistry.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Agenda/AgendaToolRegistry.php)
7. As chamadas externas sao feitas por [AgendaService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Agenda/AgendaService.php)
8. As respostas podem ser enviadas pela Evolution por [WhatsAppService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/WhatsAppService.php)

## Principio de arquitetura adotado

O projeto foi construido em modelo hibrido.

Isso significa:

- a OpenAI ajuda a entender a mensagem
- o PHP continua dono do fluxo
- o PHP escolhe quando chamar tool
- o PHP aplica regras criticas
- o PHP executa a acao real

Essa decisao foi tomada para proteger regras de negocio sensiveis:

- nao inventar data e horario
- nao cancelar sem confirmacao
- nao repetir perguntas desnecessariamente
- nao deixar o modelo executar escrita sozinho
- manter previsibilidade do fluxo

Em resumo:

- IA entende
- backend valida
- backend executa

## Arquivos centrais

### Entrada

- [webhook.php](/C:/xampp/htdocs/producao/roboAgendamento/public/webhook.php)
- [WebhookController.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Controller/WebhookController.php)
- [MessageNormalizer.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/MessageNormalizer.php)

### Conversa

- [ConversationService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/ConversationService.php)
- [AiOrchestrator.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/AiOrchestrator.php)
- [ConversationResultDTO.php](/C:/xampp/htdocs/producao/roboAgendamento/src/DTO/ConversationResultDTO.php)

### IA

- [OpenAIClient.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/OpenAIClient.php)
- [OpenAiConversationInterpreter.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/OpenAiConversationInterpreter.php)
- [FallbackConversationInterpreter.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/FallbackConversationInterpreter.php)
- [ResilientConversationInterpreter.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/ResilientConversationInterpreter.php)

### Agenda

- [AgendaToolRegistry.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Agenda/AgendaToolRegistry.php)
- [AgendaService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Agenda/AgendaService.php)

### Persistencia e logs

- [DatabaseConnectionFactory.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/DatabaseConnectionFactory.php)
- [PdoSessionRepository.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/PdoSessionRepository.php)
- [PdoMessageLogRepository.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/PdoMessageLogRepository.php)
- [PdoIntegrationLogRepository.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/PdoIntegrationLogRepository.php)
- [JsonSessionRepository.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/JsonSessionRepository.php)
- [MessageLogService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/MessageLogService.php)
- [IntegrationLogService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/IntegrationLogService.php)
- [Logger.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Logging/Logger.php)

### Operacao

- [session_admin.php](/C:/xampp/htdocs/producao/roboAgendamento/public/session_admin.php)
- [integration_logs.php](/C:/xampp/htdocs/producao/roboAgendamento/public/integration_logs.php)
- [WhatsAppService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/WhatsAppService.php)

## Persistencia atual

Hoje a persistencia principal esta no MySQL.

Banco:

- `robo_agendamento`

Porta confirmada no ambiente:

- `3307`

Tabelas criadas:

- `sessions`
- `message_logs`
- `message_queue`
- `integration_logs`

Migrations presentes:

- [001_create_sessions.sql](/C:/xampp/htdocs/producao/roboAgendamento/database/migrations/001_create_sessions.sql)
- [002_create_message_logs.sql](/C:/xampp/htdocs/producao/roboAgendamento/database/migrations/002_create_message_logs.sql)
- [003_create_message_queue.sql](/C:/xampp/htdocs/producao/roboAgendamento/database/migrations/003_create_message_queue.sql)
- [004_create_integration_logs.sql](/C:/xampp/htdocs/producao/roboAgendamento/database/migrations/004_create_integration_logs.sql)
- [005_add_identifiers_to_integration_logs.sql](/C:/xampp/htdocs/producao/roboAgendamento/database/migrations/005_add_identifiers_to_integration_logs.sql)

Comportamento atual:

- tenta usar SQL primeiro
- se SQL falhar, ainda pode cair para JSON em contingencia

## Sessao, TTL e limpeza

Ja existe controle de expiração de sessao.

Configuracao:

- `SESSION_TTL_MINUTES` no [`.env`](/C:/xampp/htdocs/producao/roboAgendamento/.env)

Comportamento:

- a sessao expira por `last_interaction_at`
- quando expira, o fluxo do usuario recomeça do zero

Ja existe endpoint administrativo para sessao:

- [session_admin.php](/C:/xampp/htdocs/producao/roboAgendamento/public/session_admin.php)

Acoes disponiveis:

- `status`
- `reset`
- `purge`

O `purge` apaga:

- sessao
- historico de mensagens
- fila
- logs de integracao

## Integracoes reais mapeadas

### Leitura

- `GET /dia_medica_livre.php`
- `GET /dia_dentista_livre.php`
- `GET /dia_enfermeira_livre.php`
- `GET /horario_livre_dentista.php?data=DD/MM/AAAA`
- `GET /api_listar_agendamentos.php?cpf=...`

### Escrita

- `POST /api_consulta_medica_psf2.php`
- `POST /apiPostEnfermeiro.php`
- `POST /api_agendar_dentista.php`
- `POST /api_cancelar_agendamento.php`

## Tools expostas no backend

As tools usadas pelo fluxo PHP estao em [AgendaToolRegistry.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Agenda/AgendaToolRegistry.php).

Principais:

- `consultar_agendamento_ausente`
- `consultar_agenda_medico`
- `consultar_agenda_enfer`
- `consultar_agenda_dent`
- `consultar_horario_dentista`
- `cadastrar_agenda_medico`
- `cadastrar_agenda_enfer`
- `cadastrar_agenda_dent`
- `consultar_cancelamento_agen`
- `confimar_cancelamento_geral`

Observacao:

- o nome `confimar_cancelamento_geral` esta com grafia antiga e foi mantido por compatibilidade com o fluxo anterior

## Estado funcional implementado

Ja funciona:

- menu inicial
- identificacao de servico por texto curto
- pedido de servico quando o usuario fala so `agendar`
- coleta e reaproveitamento de CPF
- coleta de nome
- coleta de telefone
- consulta de datas para medico
- consulta de datas para enfermeiro
- consulta de datas e horarios para dentista
- listagem de agendamentos
- cancelamento por CPF
- confirmacao antes de cancelar
- bloqueio por ausencia no servico correto
- envio de resposta pelo WhatsApp

## Correcoes importantes ja feitas

### 1. Escolha de servico quando o usuario fala genericamente

Caso corrigido:

- `quero agendar`
- `agendar consulta`
- `quero marcar`

Comportamento atual:

- o robo pergunta qual servico deseja agendar

Arquivo principal:

- [AiOrchestrator.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/AiOrchestrator.php)

### 2. Troca de servico no inicio do fluxo

Caso corrigido:

- usuario estava em um fluxo e dizia algo como `nao dentista`

Comportamento atual:

- antes do CPF, o fluxo consegue trocar o servico

### 3. Encerramento de conversa

Casos tratados:

- `sair`
- `menu`
- `voltar`
- `encerrar`
- `cancelar atendimento`

Comportamento atual:

- encerra o fluxo atual
- limpa o estado de conversa
- volta ao menu quando o usuario retomar

### 4. Cancelamento com um unico agendamento

Bug anterior:

- quando havia 1 agendamento, o `sim` podia fazer o fluxo repetir a listagem

Comportamento atual:

- se so existir 1 agendamento, pergunta diretamente se deseja cancelar
- `sim` cancela
- `nao` aborta

### 5. Cancelamento de bloqueio por ausencia

Bug anterior:

- ao responder `sim` no bloqueio por ausencia, o robo apenas enviava uma frase de transicao e nao executava o cancelamento nem continuava o agendamento

Comportamento atual:

- guarda o `id` bloqueado
- executa `confimar_cancelamento_geral`
- em seguida consulta novas datas
- continua o agendamento automaticamente

### 6. Humanizacao das respostas

Foi ajustado o tom das mensagens em [AiOrchestrator.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/AiOrchestrator.php).

Objetivo:

- reduzir secura
- deixar a conversa mais natural
- manter respostas objetivas

### 7. Divisao de mensagens no WhatsApp

Bug anterior:

- mensagens com `\n\n` eram quebradas e depois reagrupadas, indo como uma unica mensagem

Comportamento atual:

- cada bloco separado por linha em branco vira um chunk proprio
- textos grandes ainda podem ser subdivididos por tamanho

Arquivo principal:

- [WhatsAppService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/WhatsAppService.php)

Configuracoes relacionadas:

- `WHATSAPP_SPLIT_MESSAGES`
- `WHATSAPP_SPLIT_MAX_LENGTH`
- `WHATSAPP_SPLIT_DELAY_MS`

### 8. Confirmacao final de Medico

A confirmacao de medico nao deve mostrar o horario quebrado de atendimento como se fosse o horario de comparecimento.

Regra implementada:

- `07:00`, `07:15`, `07:30`, `07:45` -> `Hora para comparecer: 07:00`
- `08:00`, `08:15`, `08:30`, `08:45` -> `Hora para comparecer: 08:00`
- `09:00`, `09:15`, `09:30`, `09:45` -> `Hora para comparecer: 09:00`

Ou seja:

- para Medico, o sistema usa a hora agendada como base e normaliza para `HH:00`
- para Medico, a confirmacao mostra apenas `Hora para comparecer`
- para Dentista, continua mostrando `Hora`

Arquivo principal:

- [AiOrchestrator.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/AiOrchestrator.php)

## Logs e auditoria

### Log principal

Arquivo:

- [app.log](/C:/xampp/htdocs/producao/roboAgendamento/storage/logs/app.log)

Hoje ja registra:

- mensagem recebida
- reply final
- intent
- tool calls
- resultado de envio pelo WhatsApp

### Logs de integracao

Tabela:

- `integration_logs`

Endpoint de consulta:

- [integration_logs.php](/C:/xampp/htdocs/producao/roboAgendamento/public/integration_logs.php)

Campos relevantes:

- `service`
- `endpoint`
- `request_payload`
- `response_payload`
- `status_code`
- `phone`
- `cpf`

Uso importante:

- verificar qual tool foi chamada
- verificar qual endpoint real foi usado
- provar para qual `empresa` o cadastro foi enviado

### Como verificar a empresa do agendamento

Quando um cadastro ocorre, o `request_payload` em `integration_logs` guarda a carga usada.

Para confirmar a empresa:

- abrir o ultimo registro de `cadastrar_agenda_medico`, `cadastrar_agenda_enfer` ou `cadastrar_agenda_dent`
- olhar `request_payload`
- verificar o campo `empresa`

## O que ja foi testado em ambiente real

### OpenAI

Ja houve chamada real com retorno do interpretador.

### Evolution

Ja houve envio real de mensagem pelo WhatsApp via Evolution.

### Agenda real

Ja houve leitura real de:

- medico
- dentista
- enfermeiro
- horarios de dentista
- consulta de agendamentos por CPF

### Escrita real

Ja houve:

- cancelamento real de agendamento
- agendamentos reais em testes controlados de fluxo

Conclusao:

- este ambiente nao deve ser tratado como sandbox inofensivo

## Riscos e pendencias abertas

### 1. Fila e debounce ainda nao existem

Esta e uma das maiores pendencias.

Hoje:

- se o usuario manda varias mensagens em sequencia, elas entram uma a uma
- o robo nao consolida essas mensagens em uma entrada unica

Isso pode causar:

- saltos de etapa
- respostas prematuras
- ruido na UX

O que falta implementar:

- gravar cada mensagem em `message_queue`
- esperar pequena janela, por exemplo 3 a 5 segundos
- juntar mensagens pendentes do mesmo numero
- processar o texto consolidado
- marcar como processadas

Arquivos candidatos:

- novo `DebounceService.php`
- uso da tabela `message_queue`
- adaptacao em [webhook.php](/C:/xampp/htdocs/producao/roboAgendamento/public/webhook.php) e [ConversationService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/ConversationService.php)

### 2. Audio ainda nao implementado

Falta:

- baixar audio
- transcrever
- injetar texto transcrito no fluxo

### 3. Imagem ainda nao implementada

Falta:

- baixar imagem
- interpretar conteudo relevante
- filtrar imagem fora de escopo

### 4. Charset/acentos ainda precisam de ajuste

Ja houve erro real no log:

- `Incorrect string value` em `message_logs.normalized_text`

Impacto:

- mensagens com certos acentos podem quebrar gravacao de log

Observacao:

- parte das respostas foi mantida em ASCII por seguranca operacional

Recomendacao:

- revisar charset/collation do banco
- garantir `utf8mb4` ponta a ponta
- revisar conexao PDO e collation das tabelas

### 5. Observabilidade ainda pode melhorar

Seria util registrar tambem:

- `ai_source`
- `ai_confidence`
- estado anterior e novo estado
- fallback usado ou nao
- duracao por tool

### 6. Testes automatizados ainda faltam

Hoje a validacao e principalmente:

- smoke tests
- testes manuais
- validacao por log

Ainda faltam:

- testes unitarios
- testes de integracao
- testes E2E estruturados

## Como operar e investigar

### Ver o log principal em tempo real

Comando:

```powershell
Get-Content C:\xampp\htdocs\producao\roboAgendamento\storage\logs\app.log -Wait
```

### Ver requests chegando pelo tunel

URL local do ngrok:

- `http://127.0.0.1:4040`

### Ver sessao atual no banco

Consulta util:

```sql
SELECT phone, current_flow, current_step, selected_service, pending_action, context_json, updated_at
FROM sessions
ORDER BY updated_at DESC;
```

### Ver historico de mensagens

```sql
SELECT id, phone, direction, message_type, normalized_text, created_at
FROM message_logs
ORDER BY id DESC;
```

### Ver tools executadas

No log principal, procurar por:

- `tool_calls`
- `Tool executada`

### Ver integracoes externas recentes

```sql
SELECT id, service, endpoint, status_code, phone, cpf, created_at
FROM integration_logs
ORDER BY id DESC;
```

## Regras de seguranca para outra IA

- nunca executar cancelamento sem confirmacao explicita do usuario
- nunca executar agendamento real sem deixar claro que a acao sera real
- sempre preferir leitura antes de escrita
- nao expor segredos do [`.env`](/C:/xampp/htdocs/producao/roboAgendamento/.env)
- nao assumir que ambiente esta em mock
- tratar o projeto como ambiente operacional
- antes de testar algo destrutivo, explicar exatamente o que sera enviado

## Ordem recomendada de continuidade

### Prioridade 1

- implementar fila/debounce usando `message_queue`
- estabilizar consolidacao de mensagens

### Prioridade 2

- resolver charset/utf8mb4 do banco e logs

### Prioridade 3

- melhorar observabilidade de IA e transicoes

### Prioridade 4

- implementar audio

### Prioridade 5

- implementar imagem

### Prioridade 6

- criar testes automatizados

## Proposta concreta para fila/debounce

Implementacao sugerida:

1. Ao entrar no webhook, salvar a mensagem em `message_queue`
2. Nao processar imediatamente se houver outra do mesmo numero muito recente
3. Esperar janela curta configuravel, por exemplo `3` segundos
4. Buscar todas as mensagens pendentes do numero
5. Juntar em uma unica string na ordem de chegada
6. Processar uma vez so no fluxo principal
7. Marcar as linhas como processadas

Campos uteis adicionais:

- `batch_id`
- `processed_at`
- `locked_at`

Riscos a considerar:

- concorrencia entre requests
- dupla resposta
- lock por telefone

## Relacao com o plano macro

O plano macro continua em:

- [PLANO_ROBO_AGENDAMENTO_PHP.md](/C:/xampp/htdocs/producao/roboAgendamento/PLANO_ROBO_AGENDAMENTO_PHP.md)

Este handoff complementa o plano.

Diferenca pratica:

- o plano e o mapa de execucao
- este arquivo e o retrato fiel do estado atual do projeto

## Atualizacao: regras de restricao vindas do banco

Foi criada uma camada de restricoes dinamicas lidas do MySQL.

Arquivos novos:

- [RestrictionRuleRepositoryInterface.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/RestrictionRuleRepositoryInterface.php)
- [NullRestrictionRuleRepository.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/NullRestrictionRuleRepository.php)
- [PdoRestrictionRuleRepository.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/PdoRestrictionRuleRepository.php)
- [RestrictionRuleService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/RestrictionRuleService.php)
- [006_create_restriction_rules.sql](/C:/xampp/htdocs/producao/roboAgendamento/database/migrations/006_create_restriction_rules.sql)

Tabela criada:

- `restriction_rules`

Campos principais:

- `name`
- `match_type`
- `trigger_value`
- `response_message`
- `is_active`
- `priority`

Tipos de match suportados:

- `contains`
- `exact`
- `regex`

Integracao no fluxo:

- [webhook.php](/C:/xampp/htdocs/producao/roboAgendamento/public/webhook.php) agora injeta `RestrictionRuleService`
- [AiOrchestrator.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/AiOrchestrator.php) consulta essas regras no inicio do `handle()`

Comportamento:

- se uma regra ativa casar com a mensagem do usuario, o robo responde com `response_message`
- a sessao atual e mantida
- o fluxo principal nao continua naquela mensagem
- isso serve para assuntos que o robo nao deve atender

Exemplo de uso no banco:

```sql
INSERT INTO restriction_rules (name, match_type, trigger_value, response_message, is_active, priority)
VALUES ('Receita medica', 'contains', 'receita', 'Esse tipo de atendimento nao e feito por aqui. Para isso, fale com a recepcao da unidade.', 1, 10);
```

## Atualizacao: camada de flow recovery com IA

Foi adicionada uma segunda camada de IA para destravar a conversa quando o usuario muda de assunto, agradece, encerra naturalmente ou tenta trocar de fluxo no meio do atendimento.

Arquivos novos:

- [FlowRecoveryDecisionDTO.php](/C:/xampp/htdocs/producao/roboAgendamento/src/DTO/FlowRecoveryDecisionDTO.php)
- [FlowRecoveryServiceInterface.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/FlowRecoveryServiceInterface.php)
- [NullFlowRecoveryService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/NullFlowRecoveryService.php)
- [OpenAiFlowRecoveryService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/OpenAiFlowRecoveryService.php)

Integracao:

- [webhook.php](/C:/xampp/htdocs/producao/roboAgendamento/public/webhook.php) agora instancia a camada de recovery junto com o interpretador principal
- [AiOrchestrator.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/AiOrchestrator.php) consulta essa camada antes dos roteamentos rigidos quando existe um fluxo ativo

Objetivo:

- evitar que o robo fique preso em fluxos anteriores
- permitir troca de fluxo com mais naturalidade
- encerrar com elegancia quando o usuario so agradece ou confirma

Acoes suportadas pelo recovery:

- `continue`
- `complete`
- `menu`
- `switch_flow`

`switch_flow` hoje pode apontar para:

- `agendar_medico`
- `agendar_dentista`
- `agendar_enfermeiro`
- `consultar_agendamentos`
- `cancelar_agendamento`

Heuristica atual:

- so tenta recovery quando existe fluxo ativo
- tenta recovery para `unknown`
- tenta recovery para agradecimentos e encerramentos leves
- tenta recovery quando o usuario pede outro fluxo no meio do atual
- aplica a decisao apenas com confianca minima de `0.55`

Correcoes praticas entregues junto com essa camada:

- `consultar_agendamentos` agora encerra em `completed` depois de responder
- mensagens como `obrigado`, `obrigada`, `valeu`, `blz`, `entendi` deixam de reabrir fluxo concluido
- troca de fluxo no meio da conversa pode redirecionar para outro atendimento em vez de repetir a resposta anterior

Smoke test local validado:

- consulta de agendamentos seguida de `obrigado` nao repete mais a consulta
- troca de fluxo de `consultar_agendamentos` para `agendar_dentista` funciona com recovery

## Atualizacao: transcricao de audio para texto

A camada de audio foi implementada para converter mensagens de voz em texto antes de entrar no fluxo principal.

Arquivos novos:

- [AudioTranscriptionService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/AudioTranscriptionService.php)

Arquivos alterados:

- [OpenAIClient.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/OpenAIClient.php)
- [WebhookController.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Controller/WebhookController.php)
- [webhook.php](/C:/xampp/htdocs/producao/roboAgendamento/public/webhook.php)
- [services.php](/C:/xampp/htdocs/producao/roboAgendamento/config/services.php)
- [.env.example](/C:/xampp/htdocs/producao/roboAgendamento/.env.example)

Como funciona agora:

1. O webhook normaliza a mensagem
2. Se `message_type=audio`, o controller chama `AudioTranscriptionService`
3. O servico tenta obter o audio por `base64` ou `mediaUrl`
4. O audio e enviado para `POST /audio/transcriptions` da OpenAI
5. O texto transcrito volta para `IncomingMessageDTO.message`
6. O restante do fluxo continua como se o usuario tivesse digitado aquilo

Configuracoes novas:

- `AI_TRANSCRIPTION_MODEL`
- `AI_TRANSCRIPTION_LANGUAGE`

Defaults atuais:

- modelo: `gpt-4o-mini-transcribe`
- idioma: `pt`

Comportamento de fallback:

- se a transcricao falhar, o robo responde pedindo o texto digitado
- se a camada de transcricao nao estiver habilitada, audio tambem cai em fallback claro

Formato suportado pelo servico:

- `audioMessage.base64`
- `audioMessage.fileBase64`
- `audioMessage.mediaBase64`
- `audioMessage.data`
- `audioMessage.url` ou `audioMessage.mediaUrl`

Observacao importante:

- o servico tenta baixar a midia usando o `WHATSAPP_API_KEY` quando necessario
- isso foi pensado para compatibilidade com a Evolution

Validacao feita:

- `php -l` em todos os arquivos novos/alterados
- smoke test local com audio fake em base64, retornando texto transcrito para o DTO

Pendencia ainda aberta:

- falta validar com um payload real de audio vindo da Evolution para confirmar se o `mediaUrl` real exige algum ajuste adicional
- entao a parte de codigo esta pronta, mas a homologacao real do audio ainda depende de um teste com mensagem de voz verdadeira no WhatsApp
## Atualizacao: fila, debounce e isolamento por telefone

Foi implementada a camada de fila/debounce para reduzir respostas prematuras e impedir mistura de contexto entre usuarios em concorrencia.

Arquivos novos:

- [MessageQueueRepositoryInterface.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/MessageQueueRepositoryInterface.php)
- [NullMessageQueueRepository.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/NullMessageQueueRepository.php)
- [PdoMessageQueueRepository.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/PdoMessageQueueRepository.php)
- [QueuedMessageBatchDTO.php](/C:/xampp/htdocs/producao/roboAgendamento/src/DTO/QueuedMessageBatchDTO.php)
- [DebounceQueueService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/DebounceQueueService.php)
- [007_alter_message_queue_for_debounce.sql](/C:/xampp/htdocs/producao/roboAgendamento/database/migrations/007_alter_message_queue_for_debounce.sql)

Arquivos alterados:

- [WebhookController.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Controller/WebhookController.php)
- [webhook.php](/C:/xampp/htdocs/producao/roboAgendamento/public/webhook.php)
- [services.php](/C:/xampp/htdocs/producao/roboAgendamento/config/services.php)
- [.env.example](/C:/xampp/htdocs/producao/roboAgendamento/.env.example)

Objetivo tecnico:

- juntar mensagens enviadas em sequencia pelo mesmo telefone
- impedir que duas requests do mesmo usuario processem a sessao ao mesmo tempo
- permitir concorrencia entre usuarios diferentes sem mistura de contexto

Estrategia adotada:

- toda mensagem entra em `message_queue`
- o backend usa `GET_LOCK` do MySQL por telefone
- o lock e por numero, nao global
- depois de adquirir o lock, o sistema espera a janela de debounce
- entao busca todas as mensagens pendentes daquele telefone
- concatena o texto em ordem de chegada
- processa a sessao uma unica vez
- marca aquelas linhas como processadas
- libera o lock

Garantia principal:

- dois usuarios diferentes podem falar ao mesmo tempo sem se afetarem
- duas mensagens do mesmo usuario nao processam a sessao em paralelo
- o contexto continua isolado por `phone`

Novas configuracoes:

- `MESSAGE_DEBOUNCE_ENABLED`
- `MESSAGE_DEBOUNCE_WINDOW_MS`
- `MESSAGE_QUEUE_LOCK_WAIT_SECONDS`

Valores atuais:

- debounce habilitado
- janela de debounce: `2500ms`
- espera de lock por telefone: `12s`

Novas colunas em `message_queue`:

- `media_url`
- `payload_json`
- `processed_at`

Comportamento no controller:

- se a request atual for a responsavel pelo lote, ela processa e responde normalmente
- se outra request do mesmo telefone ja tiver processado aquele lote, a request atual devolve apenas status de fila, sem duplicar resposta no WhatsApp

Smoke test local validado:

- mensagem 1: `quero agendar`
- mensagem 2: `dentista`
- lote final processado: `quero agendar\ndentista`
- `queue_status=batched`
- `batch_parts=2`

Observacao operacional:

- a protecao forte depende do MySQL estar ativo, porque o lock por telefone usa `GET_LOCK`
- sem banco disponivel, o sistema faz fallback e perde essa protecao de concorrencia da fila
- portanto, para operacao real com muitos usuarios simultaneos, o banco deve permanecer disponivel
## Ajuste adicional: URL direta de audio da Evolution (.oga)

Foi identificado em log real que a Evolution envia URL direta de storage para o audio, por exemplo com extensao `.oga` em dominio de storage assinado, e nao uma URL do endpoint principal do WhatsApp.

Implicacoes praticas:

- o servico de audio deve usar essa URL direta quando ela vier absoluta
- nao deve tentar remontar a URL a partir do `WHATSAPP_BASE_URL` nesse caso
- o arquivo `.oga` precisa ser normalizado para formato aceito pela OpenAI

Ajustes aplicados:

- [AudioTranscriptionService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/AudioTranscriptionService.php)
  - normaliza extensoes `oga` e `opus` para `ogg`
  - trata `application/ogg` como `audio/ogg`
- [OpenAIClient.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/OpenAIClient.php)
  - passa MIME explicito compativel para transcricao de audio
- [HttpClient.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Http/HttpClient.php)
  - segue redirecionamentos e aceita conteudo comprimido no download

Hipotese mais provavel para o erro anterior:

- a OpenAI recebeu um arquivo `.oga` ou MIME nao ideal e interpretou como corrompido/nao suportado

Status:

- compatibilidade com URL direta de storage foi reforcada
- ainda falta um novo teste real com audio da Evolution para confirmar que essa correcao resolve o caso em producao
## Atualizacao adicional: testes, UTF-8 e endurecimento das tools de escrita

### Testes automatizados leves

Foi criada uma suite de smoke tests sem dependencia externa em:

- [run.php](/C:/xampp/htdocs/producao/roboAgendamento/tests/run.php)
- [README.md](/C:/xampp/htdocs/producao/roboAgendamento/tests/README.md)

Comando de execucao:

`powershell
php tests/run.php
`

Cobertura atual dessa suite:

- bootstrap com UTF-8 ativo
- prioridade da mediaUrl da Evolution no audio
- regras de restricao por texto
- debounce/fila sem mistura entre usuarios
- divisao de mensagens no WhatsApp

Status validado localmente:

- 5/5 testes passando

### UTF-8 e acentos

Foram aplicados ajustes de base para reduzir problemas de charset em producao:

- [bootstrap.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Core/bootstrap.php)
- [DatabaseConnectionFactory.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/DatabaseConnectionFactory.php)
- [Response.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Core/Response.php)
- [Logger.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Logging/Logger.php)
- [HttpClient.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Http/HttpClient.php)

Objetivo desses ajustes:

- forcar UTF-8 na aplicacao
- reforcar utf8mb4 no MySQL
- evitar quebra de json_encode com bytes invalidos
- melhorar logs e respostas JSON
- capturar headers HTTP de resposta para diagnostico de encoding

Observacao honesta:

- se um provedor externo devolver texto ja corrompido na origem, ainda pode aparecer ruido no log
- mas a base da aplicacao agora esta mais preparada para acentos corretos

### Audio: causa raiz identificada em log real

Os logs reais mostraram que o erro mais forte do audio nao era apenas extensao/MIME.

Causa encontrada:

- o sistema estava usando udioMessage.url
- essa URL apontava para mmg.whatsapp.net ... .enc
- esse conteudo vinha criptografado
- por isso o arquivo salvo nao tinha assinatura OggS e a OpenAI rejeitava como corrompido/nao suportado

Correcao aplicada:

- [MessageNormalizer.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/MessageNormalizer.php) agora prioriza message.mediaUrl
- o resumo de webhook em [WebhookController.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Controller/WebhookController.php) tambem evidencia essa URL correta

Status atual do audio:

- a causa raiz anterior foi corrigida no codigo
- ainda vale 1 teste real final para homologar com a mediaUrl correta da Evolution em producao

### Endurecimento das tools de escrita

Foi identificado um risco importante no fluxo anterior:

- bastava a chamada HTTP retornar algo sem excecao para o robo responder gendamento realizado
- isso podia gerar falso positivo se a API respondesse 200 sem sucesso real de negocio

Ajustes aplicados:

- [AgendaService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Agenda/AgendaService.php) agora normaliza operacoes de escrita com campo padrao success
- [AiOrchestrator.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/AiOrchestrator.php) agora so confirma sucesso quando success=true

Cobertura dessa protecao:

- cadastrar_agenda_medico
- cadastrar_agenda_enfer
- cadastrar_agenda_dent
- confimar_cancelamento_geral

Novo comportamento:

- se a tool de escrita falhar, o robo nao diz que agendou/cancelou
- ele informa que nao conseguiu confirmar
- deixa claro que nenhuma vaga foi reservada ou alterada
- orienta nova tentativa ou recepcao

### Leitura real do status atual para PRD

Pontos que ja estao fortes:

- sessao por telefone com isolamento
- fila/debounce por telefone com lock no MySQL
- logs e auditoria de integracao
- tool calling controlado pelo backend
- restricoes por banco
- flow recovery com IA
- testes leves automatizados

Pontos que ainda merecem fechamento antes de producao com mais tranquilidade:

- homologacao final do audio apos a correcao da mediaUrl
- imagem, se for requisito obrigatorio do go-live
- consolidacao dos documentos antigos que ainda estao desatualizados

## Atualizacao adicional: usuario perdido e confirmacao segura de escrita

Foram implementados dois reforcos diretamente em [AiOrchestrator.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/AiOrchestrator.php).

### 1. Orientacao para usuario perdido por etapa

Objetivo:

- evitar repeticao seca da mesma pergunta
- ajudar o usuario a entender exatamente o que precisa mandar
- reduzir travamento quando a pessoa responde algo fora do esperado

Como funciona agora:

- o fluxo passa a contar tentativas invalidas por etapa em `context.step_attempts`
- isso vale para:
  - CPF
  - nome
  - telefone
  - data
  - horario
- na primeira tentativa invalida, o robo repete a orientacao normal
- a partir da segunda tentativa invalida na mesma etapa, o robo muda o tom para algo como `Vamos por partes`
- junto com isso, ele mostra exemplos validos da etapa atual
- o contador daquela etapa e limpo automaticamente quando o dado correto entra

Exemplos praticos:

- se o usuario estiver parado na data e responder `nao sei`, `ok`, `qualquer uma`
- o robo continua na mesma etapa, mas passa a orientar com exemplos das datas disponiveis
- isso deixa o atendimento mais humano sem soltar o controle do backend

Observacao arquitetural:

- a camada OpenAI continua ajudando na interpretacao
- a humanizacao e a condução da conversa continuam principalmente no backend, em `AiOrchestrator`
- isso foi intencional para manter previsibilidade em regras criticas

### 2. Pos-validacao antes de anunciar sucesso no agendamento

Objetivo:

- impedir falso positivo do tipo `Agendamento realizado` quando a API de escrita respondeu algo positivo, mas o cadastro nao apareceu na verificacao final

Como funciona agora:

1. o backend chama a tool de escrita (`cadastrar_agenda_medico`, `cadastrar_agenda_enfer` ou `cadastrar_agenda_dent`)
2. so continua se `success=true`
3. depois disso, faz uma verificacao extra chamando `consultar_agendamento_ausente`
4. tenta localizar o agendamento criado por servico, data e, quando existir, horario
5. so responde com sucesso final se localizar esse agendamento na verificacao

Novo comportamento quando a verificacao final falha:

- o robo nao diz que agendou
- ele responde que houve retorno inicial positivo, mas que o agendamento ainda nao foi localizado na verificacao final
- a sessao vai para `completed` com `context.pending_manual_review=true`
- isso evita reenvio cego e evita afirmar uma reserva nao confirmada

Impacto operacional:

- reduz risco de o usuario acreditar que a vaga esta garantida quando ainda nao esta comprovada
- deixa o sistema mais seguro para PRD, principalmente em hospedagem compartilhada e integrações externas mais instaveis

### Testes adicionados para essas duas melhorias

A suite de [run.php](/C:/xampp/htdocs/producao/roboAgendamento/tests/run.php) agora cobre tambem:

- orientacao de usuario perdido apos repeticao invalida na mesma etapa
- nao confirmar sucesso de agendamento sem pos-validacao

Status local validado:

- 7/7 testes passando

## Leitura pratica do estado atual apos essas melhorias

Hoje o robo esta mais forte em tres frentes:

- conversa mais guiada para usuario confuso
- ferramentas de escrita mais seguras
- cobertura automatizada um pouco melhor para regressao

Se outra IA for continuar daqui, os proximos degraus mais valiosos passam a ser:

- homologacao real final do audio apos a correcao da mediaUrl da Evolution
- consolidacao dos documentos antigos que ainda descrevem estado defasado
- opcionalmente ampliar a condução humanizada para mais cenarios de duvida do usuario
## Atualizacao final: hora_comparecer, digitando da Evolution e cuidados de continuidade

Esta secao prevalece sobre trechos antigos deste arquivo que ainda mencionam estado anterior.

### Hora de comparecimento para Medico e Enfermeiro

A regra atual em [AiOrchestrator.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/AiOrchestrator.php) e:

- `medico` usa `context.hora_comparecer` com fallback para `context.hora_agendada`
- `enfermeiro` usa a mesma logica de `medico`
- `dentista` continua usando `selectedTime`

Fluxo atual:

1. O cadastro retorna em [AgendaService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Agenda/AgendaService.php)
2. O orquestrador salva `hora_comparecer` e `hora_agendada` no `context` quando o servico e `medico` ou `enfermeiro`
3. A confirmacao final usa essa hora de comparecimento para montar a frase inicial do agendamento

Mensagem atual esperada para `medico` e `enfermeiro`:

- `Pronto, seu agendamento foi realizado. Chegue no PSF02 ate as HH:MM.`

Observacao:

- para `medico` e `enfermeiro`, a mensagem nao depende mais do bloco `Hora:` para descobrir a hora principal de comparecimento
- o valor principal vem direto de `hora_comparecer` com fallback seguro para `hora_agendada`

### Digitando da Evolution

Foi implementado suporte ao status de digitacao em [WhatsAppService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/WhatsAppService.php).

Comportamento atual:

- antes de enviar texto, o sistema pode chamar `POST /chat/sendPresence/{instance}`
- usa `presence: composing`
- depois envia a mensagem normal por `POST /message/sendText/{instance}`

Configuracoes novas em [services.php](/C:/xampp/htdocs/producao/roboAgendamento/config/services.php), [`.env.example`](/C:/xampp/htdocs/producao/roboAgendamento/.env.example) e [`.env`](/C:/xampp/htdocs/producao/roboAgendamento/.env):

- `WHATSAPP_TYPING_ENABLED`
- `WHATSAPP_TYPING_DELAY_MS`
- `WHATSAPP_TYPING_EACH_CHUNK`

Estado atual no ambiente:

- `WHATSAPP_TYPING_ENABLED=true`
- `WHATSAPP_TYPING_DELAY_MS=1200`
- `WHATSAPP_TYPING_EACH_CHUNK=true`

Semantica das flags:

- `WHATSAPP_TYPING_ENABLED=true`: ativa o digitando
- `WHATSAPP_TYPING_DELAY_MS`: quanto tempo a Evolution deve manter o status `composing`
- `WHATSAPP_TYPING_EACH_CHUNK=true`: se a resposta for dividida em varias partes, envia `digitando` antes de cada parte

Se quiser desligar rapidamente em producao:

```env
WHATSAPP_TYPING_ENABLED=false
```

### Testes automatizados atuais

A suite em [run.php](/C:/xampp/htdocs/producao/roboAgendamento/tests/run.php) agora esta com 8 testes passando.

Cobertura atual:

- bootstrap UTF-8
- prioridade da `mediaUrl` da Evolution no audio
- regras de restricao
- debounce sem mistura de usuarios
- divisao de mensagens no WhatsApp
- envio de `digitando` antes do texto
- orientacao para usuario perdido em etapa invalida repetida
- confirmacao de sucesso com sinalizacao interna de revisao quando a verificacao final ainda nao encontrou o registro

Status local mais recente:

- `8 passou, 0 falhou`

### Risco importante para continuidade: edicoes via PowerShell e acentos

Durante esta etapa, houve varios incidentes de edicao em que acentos viraram `?` por causa de interacao entre scripts e encoding do terminal.

Licao pratica para a proxima IA:

- nao confiar apenas no `Get-Content` do PowerShell para verificar acentos
- preferir validar o conteudo real do arquivo com pequenos scripts PHP e `json_encode(..., JSON_UNESCAPED_UNICODE)`
- se precisar regravar arquivo por script, garantir UTF-8 sem BOM
- sempre rodar `php -l` depois de qualquer edicao no [AiOrchestrator.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/AiOrchestrator.php)
- sempre rodar a suite em [run.php](/C:/xampp/htdocs/producao/roboAgendamento/tests/run.php) depois das alteracoes

Cheque tecnico util:

```powershell
php -l C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\AiOrchestrator.php
php C:\xampp\htdocs\producao\roboAgendamento\tests\run.php
```

### Arquivos mais sensiveis neste ponto do projeto

Os arquivos que concentram maior risco de regressao agora sao:

- [AiOrchestrator.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/AiOrchestrator.php)
- [WhatsAppService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/WhatsAppService.php)
- [AgendaService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Agenda/AgendaService.php)
- [webhook.php](/C:/xampp/htdocs/producao/roboAgendamento/public/webhook.php)
- [run.php](/C:/xampp/htdocs/producao/roboAgendamento/tests/run.php)

### Recomendaçao operacional imediata

Antes de homologar no WhatsApp real:

1. Reiniciar Apache/XAMPP para evitar cache/opcache antigo
2. Testar um agendamento de `medico`
3. Testar um agendamento de `enfermeiro`
4. Testar um agendamento de `dentista`
5. Observar se o `digitando` aparece antes da resposta
6. Conferir `app.log` e `integration_logs`

### Estado resumido para outra IA assumir daqui

Hoje o sistema esta assim:

- fluxo principal funcional
- `hora_comparecer` aplicado em `medico` e `enfermeiro`
- Evolution com `digitando` implementado
- testes automatizados locais em `8/8`
- ainda exige cuidado alto com edicoes de texto/acentos no `AiOrchestrator.php`
