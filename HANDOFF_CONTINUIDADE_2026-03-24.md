# Handoff de Continuidade - Robo Agendamento PSF02

## Objetivo

Este handoff consolida o estado atual do projeto em 24/03/2026, incluindo:

- configuracao de producao
- arquitetura atual
- principais correcoes feitas nesta rodada
- comportamento validado por testes e por logs reais
- arquivos que precisam ser enviados ao servidor
- riscos e proximos passos

Este documento complementa os handoffs anteriores e deve ser considerado a referencia mais atual.

## Estado atual resumido

O projeto esta operando com:

- PHP em producao
- OpenAI ativa para interpretacao
- Evolution ativa para envio de mensagens
- persistencia SQL ativa para sessao, logs e fila
- agenda real integrada
- fallback local ainda existente para interpretacao e sessao

O robo hoje consegue:

- mostrar menu inicial
- agendar medico
- agendar dentista
- agendar enfermeiro
- consultar agendamentos
- cancelar agendamentos
- manter sessao por telefone
- validar CPF
- trocar CPF/paciente dentro do fluxo
- reaproveitar contexto recente da conversa

## Producao

Configuracoes de producao ajustadas no projeto:

- robo: `https://robo.agendaclique.com.br`
- chat: `https://chat.agendaclique.com.br`
- banco remoto configurado no `.env`
- persistencia SQL confirmada por log

Webhook esperado:

- `https://robo.agendaclique.com.br/webhook.php`
- se o host nao apontar direto para `public`, usar `https://robo.agendaclique.com.br/public/webhook.php`

Tela de acompanhamento:

- `message_monitor.php` exige token de acesso quando acessado externamente
- a URL efetiva depende de o host apontar ou nao direto para `public`

Observacao importante:

- o projeto foi mantido com a logica principal no backend PHP
- a IA interpreta, mas nao executa operacoes reais sozinha

## Arquitetura atual

Fluxo principal:

1. Entrada em [webhook.php](C:\xampp\htdocs\producao\roboAgendamento\public\webhook.php)
2. Normalizacao em [MessageNormalizer.php](C:\xampp\htdocs\producao\roboAgendamento\src\Service\MessageNormalizer.php)
3. Carregamento e persistencia de sessao em [ConversationService.php](C:\xampp\htdocs\producao\roboAgendamento\src\Service\ConversationService.php) e `SessionService`
4. Interpretacao pela camada resiliente:
   - [OpenAiConversationInterpreter.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\OpenAiConversationInterpreter.php)
   - [FallbackConversationInterpreter.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\FallbackConversationInterpreter.php)
   - [ResilientConversationInterpreter.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\ResilientConversationInterpreter.php)
5. Decisao de fluxo em [AiOrchestrator.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\AiOrchestrator.php)
6. Chamada de tools via [AgendaToolRegistry.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Agenda\AgendaToolRegistry.php)
7. Integracao real com agenda em [AgendaService.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Agenda\AgendaService.php)
8. Envio de resposta por [WhatsAppService.php](C:\xampp\htdocs\producao\roboAgendamento\src\Service\WhatsAppService.php)
9. Debounce/fila por [DebounceQueueService.php](C:\xampp\htdocs\producao\roboAgendamento\src\Service\DebounceQueueService.php)

## Principios adotados

O projeto segue um modelo hibrido:

- a OpenAI ajuda a entender intencao e entidades
- o PHP continua dono do fluxo e das regras criticas
- a execucao real de agenda e cancelamento continua controlada pelo backend

Essa decisao foi mantida para proteger regras sensiveis, como:

- nao assumir dados inexistentes
- nao cancelar sem confirmacao
- nao permitir que o modelo escreva diretamente na agenda
- impedir que uma conversa fique sem contexto ou misture pacientes

## Persistencia e concorrencia

Persistencia principal:

- MySQL

Repositorios relevantes:

- [PdoSessionRepository.php](C:\xampp\htdocs\producao\roboAgendamento\src\Infrastructure\Persistence\PdoSessionRepository.php)
- [PdoMessageQueueRepository.php](C:\xampp\htdocs\producao\roboAgendamento\src\Infrastructure\Persistence\PdoMessageQueueRepository.php)
- [PdoMessageLogRepository.php](C:\xampp\htdocs\producao\roboAgendamento\src\Infrastructure\Persistence\PdoMessageLogRepository.php)
- [PdoIntegrationLogRepository.php](C:\xampp\htdocs\producao\roboAgendamento\src\Infrastructure\Persistence\PdoIntegrationLogRepository.php)

Fallback ainda existente:

- [JsonSessionRepository.php](C:\xampp\htdocs\producao\roboAgendamento\src\Infrastructure\Persistence\JsonSessionRepository.php)

Modelo atual de contexto:

- chave da conversa: `phone`
- identificacao do paciente atendido dentro da conversa: `cpf`

Decisao importante:

- nao migrar a sessao para chave principal por CPF
- manter sessao e fila por telefone
- tratar CPF como documento do paciente ativo dentro da conversa

## Melhorias implementadas nesta rodada

### 1. Correcao do payload de digitando

Arquivo:

- [WhatsAppService.php](C:\xampp\htdocs\producao\roboAgendamento\src\Service\WhatsAppService.php)

Mudanca:

- `presence` e `delay` passaram a ser enviados na raiz do payload do `sendPresence`
- antes estavam dentro de `options`, o que quebrava na Evolution

Efeito esperado:

- o indicador de digitando volta a funcionar com a Evolution

### 2. Memoria do ultimo turno da conversa

Arquivo:

- [ConversationService.php](C:\xampp\htdocs\producao\roboAgendamento\src\Service\ConversationService.php)

Mudanca:

- a sessao passou a guardar a ultima mensagem do usuario
- a ultima resposta do assistente
- ultimo intent detectado
- ultimo fluxo e etapa

Objetivo:

- ajudar a recuperar contexto em respostas confusas ou retomadas de conversa

### 3. Fluxos menos engessados e mais resilientes

Arquivo principal:

- [AiOrchestrator.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\AiOrchestrator.php)

Melhorias:

- recuperacao de fluxo em respostas confusas
- orientacao de etapa sem repetir mensagens longas toda hora
- cancelamento e consulta mais naturais
- contestacao de `nao encontrei` redirecionando melhor
- retomada da conversa sem respostas secas demais

### 4. Troca de CPF e troca de paciente

Arquivos:

- [AiOrchestrator.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\AiOrchestrator.php)
- [IntentDetector.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\IntentDetector.php)
- [OpenAiConversationInterpreter.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\OpenAiConversationInterpreter.php)

Melhorias:

- frases como `trocar cpf`, `mudar cpf`, `nao e esse cpf`, `outra pessoa`, `meu filho` entram em `change_cpf`
- quando troca de paciente, nome e telefone anteriores sao limpos
- a IA pode devolver explicitamente `change_cpf`
- o fallback local tambem reconhece essa intencao

### 5. Validacao real de CPF

Arquivos:

- [CpfValidator.php](C:\xampp\htdocs\producao\roboAgendamento\src\Service\CpfValidator.php)
- [EntityExtractor.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\EntityExtractor.php)
- [AiOrchestrator.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\AiOrchestrator.php)

Comportamento atual:

- rejeita CPF incompleto
- rejeita CPF com numeros a mais
- rejeita sequencia repetida
- rejeita CPF com digito verificador invalido
- so salva o CPF quando for valido

### 6. Correcao do bug de CPF antigo vencendo o CPF novo

Arquivo:

- [AiOrchestrator.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\AiOrchestrator.php)

Bug observado em log real:

- o usuario enviava um novo CPF valido
- o sistema continuava consultando o CPF antigo salvo na sessao

Causa:

- `mergeSessionData()` priorizava sempre o CPF ja salvo na sessao

Correcao aplicada:

- quando o fluxo estiver em `awaiting_cpf` ou `awaiting_lookup_retry`, um novo CPF valido pode substituir o CPF anterior
- ao trocar o CPF nessa etapa, nome e telefone tambem sao limpos para evitar mistura de pacientes

### 7. Pedido generico de agendamento nao assume mais Medico

Arquivo:

- [AiOrchestrator.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\AiOrchestrator.php)

Problema observado:

- mensagens como `ola / quero / agende` acabavam indo direto para `agendar_medico`

Correcao aplicada:

- termos genericos como `agendar`, `agende`, `marcar`, `marque`, `consulta`, `agendamento` passam a cair em `choose_service` quando nao houver servico explicito
- o robo passa a perguntar o servico em vez de assumir Medico

### 8. Debounce mais robusto

Arquivos:

- [DebounceQueueService.php](C:\xampp\htdocs\producao\roboAgendamento\src\Service\DebounceQueueService.php)
- [webhook.php](C:\xampp\htdocs\producao\roboAgendamento\public\webhook.php)
- [.env](C:\xampp\htdocs\producao\roboAgendamento\.env)
- [.env.example](C:\xampp\htdocs\producao\roboAgendamento\.env.example)

Problema observado em log real:

- mesmo com debounce habilitado, o lote era processado cedo demais
- mensagens seguintes chegavam como novos lotes
- havia warnings de lock e respostas duplicadas

Correcao aplicada:

- o debounce deixou de ser apenas uma espera fixa
- agora existe uma espera por `quiet window`, tentando aguardar a conversa assentar antes de montar o lote
- defaults alinhados para uso real:
  - `MESSAGE_DEBOUNCE_WINDOW_MS=3000`
  - `MESSAGE_QUEUE_LOCK_WAIT_SECONDS=20`

Observacao operacional:

- em producao, o usuario informou usar `MESSAGE_DEBOUNCE_WINDOW_MS=3500`
- isso e valido, mas sozinho nao resolvia com a logica antiga
- a melhora real depende da nova versao de [DebounceQueueService.php](C:\xampp\htdocs\producao\roboAgendamento\src\Service\DebounceQueueService.php)

### 9. Confirmacao avulsa de lembrete e leitura de sessao pela IA

Arquivos:

- [AiOrchestrator.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\AiOrchestrator.php)
- [OpenAiConversationInterpreter.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\OpenAiConversationInterpreter.php)
- [OpenAiFlowRecoveryService.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\OpenAiFlowRecoveryService.php)

Melhorias:

- respostas avulsas de cron/lembrete como `ok`, `obrigado`, `confirmo`, `confirma`, `CONFIMO`, `vou sim`, `ele vai` passam a ser tratadas como confirmacao leve quando a conversa estiver em `idle`
- como nao existe API de confirmacao, o robo apenas agradece e encerra de forma natural
- `cancelar` e `cancela` continuam fora dessa regra e entram no fluxo normal de cancelamento pedindo CPF
- a OpenAI passou a receber mais contexto da sessao para interpretar a resposta:
  - `current_flow`
  - `current_step`
  - `pending_action`
  - `last_assistant_reply`
- o `flow recovery` ganhou a acao `clarify`
- `clarify` significa: a resposta nao combinou com a etapa pendente, mas tambem nao houve troca clara de fluxo
- quando isso acontece, o robo orienta objetivamente o que ainda falta para continuar, sem perder a etapa atual

Exemplo pratico:

- se a etapa atual for escolher horario e o usuario mandar `esse mesmo`, a IA pode marcar `clarify`
- o robo responde algo como “Ainda estou aguardando um dos horarios mostrados” e continua em `awaiting_time_choice`

## Logs reais analisados

Foram analisados logs reais externos para validar o comportamento.

Conclusoes importantes:

- SQL estava ativo
- `sendPresence` estava correto apos ajuste
- houve caso real de CPF novo sendo ignorado por causa da sessao
- houve lote `ola\nquero\nagende` seguido de `dentista`, com correcao posterior por flow recovery
- houve warnings de lock na fila
- houve resposta repetida em fluxo de servico, indicando debounce insuficiente na logica anterior

## Testes automatizados

Arquivo:

- [run.php](C:\xampp\htdocs\producao\roboAgendamento\tests\run.php)

Estado ao final desta rodada:

- `22 passou, 0 falhou`

Coberturas relevantes adicionadas ou mantidas:

- validacao de CPF invalido e incompleto
- troca de CPF apos consulta vazia
- `change_cpf` reconhecido pela IA
- troca de paciente limpando dados anteriores
- pedido generico como `agende` caindo em `choose_service`
- substituicao do CPF antigo por novo CPF valido enquanto o robo aguarda CPF
- debounce sem mistura de telefones
- envio de `sendPresence`
- confirmacao avulsa de lembrete agradecendo sem API
- `cancelar` avulso entrando no fluxo e pedindo CPF
- `flow recovery` com `clarify` quando a resposta nao bate com a etapa pendente

## Arquivos principais para subir no servidor

Se o objetivo for manter o comportamento atual em producao, os arquivos mais importantes desta rodada sao:

- [AiOrchestrator.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\AiOrchestrator.php)
- [DebounceQueueService.php](C:\xampp\htdocs\producao\roboAgendamento\src\Service\DebounceQueueService.php)
- [webhook.php](C:\xampp\htdocs\producao\roboAgendamento\public\webhook.php)
- [OpenAiConversationInterpreter.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\OpenAiConversationInterpreter.php)
- [OpenAiFlowRecoveryService.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\OpenAiFlowRecoveryService.php)
- [.env](C:\xampp\htdocs\producao\roboAgendamento\.env)
- [.env.example](C:\xampp\htdocs\producao\roboAgendamento\.env.example)

Se tambem quiser manter a base de testes sincronizada:

- [run.php](C:\xampp\htdocs\producao\roboAgendamento\tests\run.php)

## Configuracoes recomendadas de fila no servidor

Recomendacao atual:

- `MESSAGE_DEBOUNCE_ENABLED=true`
- `MESSAGE_DEBOUNCE_WINDOW_MS=3500`
- `MESSAGE_QUEUE_LOCK_WAIT_SECONDS=20`

Se a fila ainda mostrar duplicidade em producao, revisar novamente os logs antes de aumentar mais a janela.



Selecao de perfil da Evolution implementada:

- o projeto agora aceita `WHATSAPP_PROFILE=psf01|psf02|psf03`
- a resolucao da instancia e da `apikey` e feita em [services.php](C:\xampp\htdocs\producao\roboAgendamento\config\services.php)
- variaveis suportadas no `.env`:
- `WHATSAPP_INSTANCE_PSF01` / `WHATSAPP_API_KEY_PSF01`
- `WHATSAPP_INSTANCE_PSF02` / `WHATSAPP_API_KEY_PSF02`
- `WHATSAPP_INSTANCE_PSF03` / `WHATSAPP_API_KEY_PSF03`
- se `WHATSAPP_PROFILE` estiver vazio, o sistema continua aceitando `WHATSAPP_INSTANCE` e `WHATSAPP_API_KEY` diretamente como fallback
## Pontos que ainda merecem atencao

Mesmo com as melhorias, os seguintes pontos ainda merecem observacao em producao:

- debounce precisa ser validado com mais logs reais apos deploy
- flow recovery ainda pode tomar decisoes subotimas em mensagens muito curtas ou muito fora de contexto
- a nova acao `clarify` depende de boa interpretacao da IA, entao vale monitorar logs reais apos deploy
- mensagens com acentos podem aparecer estranhas em alguns logs, embora a conversa final esteja melhor normalizada
- a fila ainda depende do comportamento de lock do MySQL e da latencia real do host
- o projeto ainda nao possui uma abstracao formal de provider de WhatsApp para futura troca com Cloud API

## Proximos passos recomendados

Curto prazo:

- subir os arquivos desta rodada
- testar em producao um caso real de mensagens fracionadas: `ola`, `quero`, `agende`, `dentista`
- validar no log se o lote sai consolidado e sem duplicidade
- validar um caso real de troca de CPF com CPF novo valido
- validar respostas reais de lembrete como `ok`, `confirmo`, `CONFIMO`, `vou sim` e `cancelar`

Medio prazo:

- criar uma abstracao de provider para WhatsApp
- preparar suporte formal a Cloud API
- avaliar uso de lista interativa no lugar de menu textual, se a plataforma escolhida suportar isso bem
- expandir testes com conversas reais anonymizadas

## Observacao final

O projeto ja esta em um estado util de producao, mas ainda esta em fase de refinamento fino de conversa e concorrencia.

A parte mais importante desta rodada foi:

- parar de assumir Medico em pedido generico
- corrigir a troca de CPF antigo por novo CPF valido
- melhorar a logica real do debounce para conversas fragmentadas de WhatsApp

Se uma nova IA assumir daqui para frente, este arquivo deve ser lido junto com:

- [HANDOFF_CONTINUIDADE_2026-03-23.md](C:\xampp\htdocs\producao\roboAgendamento\HANDOFF_CONTINUIDADE_2026-03-23.md)
- [CONTINUIDADE_IA_ROBO_AGENDAMENTO.md](C:\xampp\htdocs\producao\roboAgendamento\CONTINUIDADE_IA_ROBO_AGENDAMENTO.md)
