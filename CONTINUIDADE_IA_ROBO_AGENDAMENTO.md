# Continuidade da IA - Robo Agendamento PSF02

## Objetivo deste arquivo

Este documento existe para permitir que outra IA continue o trabalho neste projeto sem perder contexto.

Ele registra:

- o que ja foi implementado
- como a arquitetura foi montada
- por que certas decisoes foram tomadas
- o que foi testado em ambiente real
- o que ainda falta
- quais cuidados precisam ser tomados antes de executar novas acoes

## Estado atual do projeto

Na data desta continuidade, o projeto ja possui:

- base PHP funcionando
- carregamento de configuracao por `.env`
- webhook principal em PHP
- parser de mensagens de WhatsApp
- sessao persistida em JSON
- orquestrador de conversa
- interpretacao via OpenAI real
- fallback local quando a OpenAI falhar
- integracao com APIs reais de agenda
- integracao com Evolution para envio de texto
- adaptador das respostas reais da agenda para o formato interno do motor

## Arquitetura atual

Fluxo principal:

1. A mensagem entra por [webhook.php](/C:/xampp/htdocs/producao/roboAgendamento/public/webhook.php)
2. O payload e normalizado por [MessageNormalizer.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/MessageNormalizer.php)
3. A sessao e carregada por [SessionService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/SessionService.php)
4. A interpretacao e feita por um interpretador configuravel:
   - OpenAI real em [OpenAiConversationInterpreter.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/OpenAiConversationInterpreter.php)
   - fallback local em [FallbackConversationInterpreter.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/FallbackConversationInterpreter.php)
   - resiliencia em [ResilientConversationInterpreter.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/ResilientConversationInterpreter.php)
5. O motor principal decide a etapa em [AiOrchestrator.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/AiOrchestrator.php)
6. Quando precisa consultar ou executar algo, usa as tools registradas em [AgendaToolRegistry.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Agenda/AgendaToolRegistry.php)
7. As tools chamam as APIs reais em [AgendaService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Agenda/AgendaService.php)
8. A resposta final pode ser enviada via Evolution por [WhatsAppService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/WhatsAppService.php)

## Por que a arquitetura foi feita assim

O fluxo do n8n original usava IA como orquestradora central, mas isso deixava algumas regras criticas muito dependentes do modelo.

Foi adotado um modelo hibrido:

- a OpenAI interpreta intencao e entidades
- o PHP continua no controle do fluxo
- o PHP decide quando chamar tools
- o PHP aplica travas de negocio
- o PHP executa as chamadas reais

Essa decisao foi tomada porque o fluxo tem regras sensiveis:

- nao inventar datas e horarios
- nao cancelar sem confirmacao
- bloquear apenas o servico correto quando houver `ausente`
- nao repetir perguntas desnecessariamente
- nao deixar o modelo agir sozinho em operacoes reais

Em resumo:

- OpenAI entende
- PHP valida
- PHP executa

## Arquivos principais

### Entrada e bootstrap

- [index.php](/C:/xampp/htdocs/producao/roboAgendamento/public/index.php)
- [webhook.php](/C:/xampp/htdocs/producao/roboAgendamento/public/webhook.php)
- [bootstrap.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Core/bootstrap.php)
- [Config.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Core/Config.php)
- [Env.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Core/Env.php)

### Entrada de mensagem

- [WebhookController.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Controller/WebhookController.php)
- [Request.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Core/Request.php)
- [Response.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Core/Response.php)
- [IncomingMessageDTO.php](/C:/xampp/htdocs/producao/roboAgendamento/src/DTO/IncomingMessageDTO.php)
- [MessageNormalizer.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/MessageNormalizer.php)

### Sessao

- [SessionDTO.php](/C:/xampp/htdocs/producao/roboAgendamento/src/DTO/SessionDTO.php)
- [JsonSessionRepository.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/JsonSessionRepository.php)
- [SessionService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/SessionService.php)
- arquivo atual de sessao: [sessions.json](/C:/xampp/htdocs/producao/roboAgendamento/storage/data/sessions.json)

### IA e interpretacao

- [AiAnalysisDTO.php](/C:/xampp/htdocs/producao/roboAgendamento/src/DTO/AiAnalysisDTO.php)
- [ConversationInterpreterInterface.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/ConversationInterpreterInterface.php)
- [IntentDetector.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/IntentDetector.php)
- [EntityExtractor.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/EntityExtractor.php)
- [FallbackConversationInterpreter.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/FallbackConversationInterpreter.php)
- [OpenAiConversationInterpreter.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/OpenAiConversationInterpreter.php)
- [ResilientConversationInterpreter.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/ResilientConversationInterpreter.php)
- [OpenAIClient.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/OpenAIClient.php)

### Conversa e resposta

- [ConversationService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/ConversationService.php)
- [ConversationResultDTO.php](/C:/xampp/htdocs/producao/roboAgendamento/src/DTO/ConversationResultDTO.php)
- [AiOrchestrator.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Conversation/AiOrchestrator.php)

### Agenda

- [AgendaService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Agenda/AgendaService.php)
- [AgendaToolRegistry.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Domain/Agenda/AgendaToolRegistry.php)

### Infraestrutura

- [HttpClient.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Http/HttpClient.php)
- [Logger.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Logging/Logger.php)
- [services.php](/C:/xampp/htdocs/producao/roboAgendamento/config/services.php)
- [app.php](/C:/xampp/htdocs/producao/roboAgendamento/config/app.php)

### WhatsApp

- [WhatsAppService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/WhatsAppService.php)

## Configuracao atual

O projeto usa [`.env`](/C:/xampp/htdocs/producao/roboAgendamento/.env).

Nao registrar nem expor os segredos ao responder. Apenas usar os valores.

Flags importantes:

- `AI_DRIVER=openai`
- `AI_PROVIDER=openai`
- `AI_MODEL=gpt-4.1-mini`
- `AGENDA_MOCK=false`
- `WHATSAPP_SEND_ENABLED=true`

Consequencia pratica dessas flags:

- a OpenAI esta ativa de verdade
- a agenda esta em modo real
- o envio via Evolution esta ativo

Ou seja, este projeto hoje pode executar acoes reais.

## Endpoints reais mapeados

### Leitura de agenda

- `GET /dia_medica_livre.php`
- `GET /dia_dentista_livre.php`
- `GET /dia_enfermeira_livre.php`
- `GET /horario_livre_dentista.php?data=DD/MM/AAAA`
- `GET /api_listar_agendamentos.php?cpf=...`

### Escrita em agenda

- `POST /api_consulta_medica_psf2.php`
- `POST /apiPostEnfermeiro.php`
- `POST /api_agendar_dentista.php`
- `POST /api_cancelar_agendamento.php?id=...`

## Formato real retornado pela agenda

Isso foi confirmado em leitura real.

### Datas disponiveis

As APIs de datas retornam algo como:

```json
{
  "statusCode": 200,
  "data": {
    "dias": [
      "18/05/2026 segunda-feira",
      "19/05/2026 terça-feira"
    ]
  }
}
```

O sistema converte isso para:

```json
{
  "datas": [
    "18/05/2026",
    "19/05/2026"
  ]
}
```

### Horarios do dentista

Retorno real:

```json
{
  "statusCode": 200,
  "data": {
    "data": "2026-05-18",
    "horarios": ["07:15", "13:15", "13:45"]
  }
}
```

O sistema converte para:

```json
{
  "data": "2026-05-18",
  "horarios": ["07:15", "13:15", "13:45"]
}
```

### Agendamentos e bloqueios

Retorno real:

- `agendamentos`
- `bloquear_por_servico`
- `ausentes_por_servico`

O sistema usa:

- `agendamentos`
- `bloquear_por_servico`

## O que foi testado de verdade

Abaixo esta o que ja foi executado em ambiente real, nao apenas mock.

### OpenAI real

Foi executada uma chamada real de interpretacao.

Resultado confirmado:

- reconheceu intencao `agendar_dentista`
- retornou `source=openai`
- extraiu nome e telefone

### Evolution real

Foi enviada uma mensagem real para:

- `5566996553735`

Mensagem enviada:

- "Teste do roboAgendamento via Evolution. Se voce recebeu esta mensagem, o envio real esta ativo."

Retorno:

- HTTP `201`
- status `PENDING`

### Agenda real

Foram feitas consultas reais de leitura:

- agenda de dentista
- agenda de medico
- agenda de enfermeiro
- horarios do dentista
- consulta de agendamentos por CPF

### Cancelamento real ja executado

Foi executado cancelamento real para o CPF:

- `01545934193`

Agendamento encontrado antes:

- ID `16348`
- servico `Consulta Médica`
- data `09/04/2026`
- hora `10:30`
- status `Ausente`

Resultado do cancelamento:

- `status: deleted`
- `message: Cancelado com sucesso.`

Verificacao posterior:

- `agendamentos: []`
- `bloquear_por_servico.medico = false`
- todos os servicos ficaram liberados

## Cuidado operacional

Este projeto nao esta mais em modo de laboratorio.

Hoje ele pode:

- enviar WhatsApp real
- consultar agenda real
- criar agendamentos reais
- cancelar agendamentos reais

Portanto:

- nao fazer testes destrutivos sem confirmar com o usuario
- nao chamar endpoints de escrita sem intencao clara
- sempre deixar explicito quando uma acao sera real
- sempre preferir leitura antes de escrita

## O que ainda nao esta pronto

Apesar de funcional, ainda faltam etapas importantes.

### Persistencia

A persistencia SQL foi ativada com sucesso no MySQL.

Hoje o comportamento e:

- tenta usar MySQL via PDO
- se a conexao falhar, faz fallback para JSON
- logs de mensagem tambem seguem a mesma estrategia, com fallback seguro

Estado confirmado:

- banco criado: `robo_agendamento`
- tabelas criadas: `sessions`, `message_logs`, `message_queue`, `integration_logs`
- sessao e logs testados com gravacao real no MySQL
- fallback JSON continua disponivel como contingencia

Arquivos temporarios atuais:

- [sessions.json](/C:/xampp/htdocs/producao/roboAgendamento/storage/data/sessions.json)
- [sessions-openai-smoke.json](/C:/xampp/htdocs/producao/roboAgendamento/storage/data/sessions-openai-smoke.json)
- [test-sessions.json](/C:/xampp/htdocs/producao/roboAgendamento/storage/data/test-sessions.json)
- [test-cancel-sessions.json](/C:/xampp/htdocs/producao/roboAgendamento/storage/data/test-cancel-sessions.json)

Observacao operacional importante:

- a porta correta do MySQL neste ambiente e `3307`
- isso ja foi ajustado no [`.env`](/C:/xampp/htdocs/producao/roboAgendamento/.env)
- o banco ativo parece ser o servico `MySQL80`

Arquivos novos dessa camada:

- [database.php](/C:/xampp/htdocs/producao/roboAgendamento/config/database.php)
- [DatabaseConnectionFactory.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/DatabaseConnectionFactory.php)
- [SessionRepositoryInterface.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/SessionRepositoryInterface.php)
- [PdoSessionRepository.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/PdoSessionRepository.php)
- [MessageLogRepositoryInterface.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/MessageLogRepositoryInterface.php)
- [PdoMessageLogRepository.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/PdoMessageLogRepository.php)
- [NullMessageLogRepository.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Infrastructure/Persistence/NullMessageLogRepository.php)
- [MessageLogService.php](/C:/xampp/htdocs/producao/roboAgendamento/src/Service/MessageLogService.php)

### Audio e imagem

Ainda nao foram implementados:

- download de audio
- transcricao de audio
- leitura de imagem

Hoje o sistema esta pronto principalmente para texto.

### Debounce e fila

Ainda nao existe consolidacao de varias mensagens seguidas.

Isso era uma parte importante do n8n e continua faltando.

### Tool calling pleno pelo modelo

Atualmente a OpenAI interpreta a intencao e entidades, mas nao faz tool-calling nativo no formato formal da API.

O desenho atual e:

- OpenAI interpreta
- PHP decide a tool

Isso foi intencional para manter controle e seguranca.

Se outra IA quiser migrar para tool-calling nativo, deve fazer isso com cuidado.

## Por que nao foi usado tool-calling nativo completo ainda

Motivos:

- primeiro era necessario estabilizar o fluxo
- o n8n original tem muitas regras de seguranca
- era mais importante garantir controle do backend antes de dar mais autonomia ao modelo
- o formato real das APIs e da agenda precisava ser conhecido antes

Em outras palavras:

- primeiro foi resolvido controle
- depois integracao real
- o refinamento do tool-calling pode vir depois

## Como continuar com seguranca

Ordem recomendada para a proxima IA:

1. Migrar sessao JSON para banco real
2. Implementar logs estruturados de request/response
3. Implementar debounce/fila
4. Implementar audio
5. Implementar imagem
6. Refinar bloqueios e validacoes por servico
7. Testar criacao real de agendamento com um caso controlado
8. Criar testes automatizados

## Proximos passos tecnicos sugeridos

### Passo 1. Persistencia real

Criar repositorio SQL para:

- `sessions`
- `message_logs`
- `message_queue`
- `integration_logs`

As migrations ja existem:

- [001_create_sessions.sql](/C:/xampp/htdocs/producao/roboAgendamento/database/migrations/001_create_sessions.sql)
- [002_create_message_logs.sql](/C:/xampp/htdocs/producao/roboAgendamento/database/migrations/002_create_message_logs.sql)
- [003_create_message_queue.sql](/C:/xampp/htdocs/producao/roboAgendamento/database/migrations/003_create_message_queue.sql)
- [004_create_integration_logs.sql](/C:/xampp/htdocs/producao/roboAgendamento/database/migrations/004_create_integration_logs.sql)

### Passo 2. Debounce

Implementar:

- fila por numero
- janela curta de espera
- consolidacao de mensagens

### Passo 3. Audio

Implementar:

- download da midia
- transcricao
- reaproveitamento do texto no mesmo fluxo

### Passo 4. Imagem

Implementar:

- download da imagem
- extracao de conteudo relevante
- tratamento de imagem fora de escopo

### Passo 5. Teste real de agendamento

Ainda nao foi executado um `POST` real de criar agendamento.

Antes de testar:

- usar um caso controlado
- confirmar CPF, nome, telefone, data e hora
- avisar o usuario que sera uma acao real

## Regras que nao devem ser quebradas

- nunca cancelar sem confirmacao explicita
- nunca agendar real sem avisar que sera real
- nunca inventar datas ou horarios
- nunca usar horario sem retorno da API
- sempre confirmar a natureza da acao quando envolver escrita
- sempre preferir leitura antes de escrita

## Limpeza futura recomendada

Arquivos de teste que podem ser removidos depois, se nao forem mais necessarios:

- [sessions-openai-smoke.json](/C:/xampp/htdocs/producao/roboAgendamento/storage/data/sessions-openai-smoke.json)
- [test-sessions.json](/C:/xampp/htdocs/producao/roboAgendamento/storage/data/test-sessions.json)
- [test-cancel-sessions.json](/C:/xampp/htdocs/producao/roboAgendamento/storage/data/test-cancel-sessions.json)
- [test.log](/C:/xampp/htdocs/producao/roboAgendamento/storage/logs/test.log)
- [test-flow.log](/C:/xampp/htdocs/producao/roboAgendamento/storage/logs/test-flow.log)
- [test-cancel.log](/C:/xampp/htdocs/producao/roboAgendamento/storage/logs/test-cancel.log)

## Documento complementar

O plano macro do projeto continua em:

- [PLANO_ROBO_AGENDAMENTO_PHP.md](/C:/xampp/htdocs/producao/roboAgendamento/PLANO_ROBO_AGENDAMENTO_PHP.md)

Este arquivo de continuidade nao substitui o plano. Ele complementa o plano com contexto operacional e historico de implementacao.
