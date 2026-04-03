# Documentacao das alteracoes de 03/04/2026

## Objetivo do dia

As alteracoes feitas em 03/04/2026 tiveram como foco principal desacoplar o robo da Evolution, preparar o projeto para operar com mais de um provider de WhatsApp e adicionar suporte ao provider PAPI sem perder compatibilidade com o fluxo atual.

Em paralelo, o projeto passou a registrar o provider da conversa no estado, na fila, nos logs e no monitor. Tambem foi adicionado um identificador externo de mensagem na fila para deduplicacao. No fim do dia, houve uma correcao adicional no envio para evitar perda de emoji e caracteres acentuados no provider PAPI.

## Resumo executivo

O que mudou em alto nivel:

- o envio de WhatsApp deixou de depender de um unico contrato fixo e passou a trabalhar com providers de saida
- o webhook passou a identificar e normalizar mensagens de entrada por provider
- o DTO de entrada ganhou metadados tecnicos para mixed mode e rastreabilidade
- a fila com debounce passou a trabalhar por `provider + phone`
- a fila passou a ignorar mensagens repetidas com base em `external_message_id`
- sessoes, logs e monitor agora suportam o campo `provider`
- o monitor de mensagens foi adaptado para exibir e operar por provider
- foram criados DTOs estruturados para respostas com texto, botoes e contato
- foi incluido suporte ao provider PAPI para texto, botoes e contato
- foi corrigido um problema de normalizacao que trocava emoji e alguns caracteres validos por `?`

## Documentos criados ou atualizados no dia

Os documentos de apoio produzidos em 03/04/2026 foram:

- `MIGRACAO_PAPI_WHATSAPP.md`
- `ARQUITETURA_AGNOSTICA_WHATSAPP.md`
- `PLANO_MIGRACAO_PROVIDER_AGNOSTICO.md`
- este arquivo: `DOCUMENTACAO_ALTERACOES_2026-04-03.md`

Esses documentos se complementam assim:

- `MIGRACAO_PAPI_WHATSAPP.md`: analise de migracao para PAPI
- `ARQUITETURA_AGNOSTICA_WHATSAPP.md`: desenho da arquitetura desacoplada por provider
- `PLANO_MIGRACAO_PROVIDER_AGNOSTICO.md`: plano em fases
- este documento: leitura consolidada do que efetivamente foi implementado em 03/04/2026

## Banco de dados

### Campos novos criados

#### Tabela `message_logs`

Migration: `database/migrations/009_add_provider_to_message_logs.sql`

Campo novo:

- `provider VARCHAR(20) NOT NULL DEFAULT 'evolution'`

Indice novo:

- `idx_message_logs_provider_phone_created (provider, phone, created_at, id)`

Objetivo:

- separar historico por provider
- permitir consultas e monitoramento por conversa sem misturar Evolution e PAPI

#### Tabela `message_queue`

Migrations:

- `database/migrations/010_add_provider_to_message_queue.sql`
- `database/migrations/013_add_external_message_id_to_message_queue.sql`

Campos novos:

- `provider VARCHAR(20) NOT NULL DEFAULT 'evolution'`
- `external_message_id VARCHAR(191) NULL`

Indices novos:

- `idx_message_queue_provider_phone_processed_created (provider, phone, processed, created_at, id)`
- `idx_message_queue_provider_phone_external_message_id (provider, phone, external_message_id)`

Objetivo:

- segmentar a fila por provider
- impedir que mensagens do mesmo telefone em providers diferentes entrem na mesma consolidacao
- permitir deduplicacao por id externo do webhook

#### Tabela `message_monitor_conversations`

Migration: `database/migrations/011_add_provider_to_message_monitor_conversations.sql`

Campo novo:

- `provider VARCHAR(20) NOT NULL DEFAULT 'evolution'`

Mudanca estrutural:

- a chave primaria deixou de ser so `phone`
- a chave primaria passou a ser `(provider, phone)`

Objetivo:

- manter conversas distintas por provider dentro do monitor

#### Tabela `sessions`

Migration: `database/migrations/012_add_provider_to_sessions.sql`

Campo novo:

- `provider VARCHAR(20) NOT NULL DEFAULT 'evolution'`

Mudanca estrutural:

- a chave primaria deixou de ser so `phone`
- a chave primaria passou a ser `(provider, phone)`

Objetivo:

- impedir colisao de sessao entre providers diferentes para o mesmo numero

## Arquitetura nova de WhatsApp

### Nova pasta criada

Foi criada a pasta:

- `src/Service/WhatsApp/`

Arquivos principais dentro dela:

- `AbstractOutboundProvider.php`
- `EvolutionOutboundProvider.php`
- `PapiOutboundProvider.php`
- `EvolutionIncomingWebhookNormalizer.php`
- `PapiIncomingWebhookNormalizer.php`
- `IncomingWebhookNormalizerInterface.php`
- `IncomingWebhookProviderResolver.php`
- `IncomingWebhookProviderResolverInterface.php`
- `OutboundProviderInterface.php`

### O que essa arquitetura resolve

Antes:

- o projeto tratava envio e entrada quase sempre como se tudo fosse Evolution
- o webhook nao tinha uma camada clara de resolucao de provider
- o envio dependia de um servico central acoplado ao contrato anterior

Depois:

- o provider de entrada pode ser resolvido por request
- cada provider tem seu normalizer de entrada
- cada provider pode ter sua propria implementacao de envio
- o fluxo interno trabalha com DTOs comuns
- a resposta sai pelo mesmo provider da mensagem recebida

## Resolucao de provider no webhook

Arquivos envolvidos:

- `src/Service/MessageNormalizer.php`
- `src/Service/WhatsApp/IncomingWebhookProviderResolver.php`
- `src/Service/WhatsApp/EvolutionIncomingWebhookNormalizer.php`
- `src/Service/WhatsApp/PapiIncomingWebhookNormalizer.php`

Comportamento novo:

- o `MessageNormalizer` agora resolve qual provider tratar
- essa resolucao pode considerar:
  - provider padrao da configuracao
  - query string de hint
  - header com hint de provider
  - formato do payload recebido
- quando `mixed_webhook_mode` estiver ativo, o sistema pode receber mais de um provider no mesmo endpoint

Regras implementadas:

- provider padrao: configurado em `config/services.php`
- hint de query: `provider` por padrao
- hint de header: `X-WhatsApp-Provider`, `x-whatsapp-provider`, `X-Provider`, `x-provider`
- fallback por shape do payload:
  - payload com cara de PAPI resolve para `papi`
  - payload com cara de Evolution resolve para `evolution`

## DTOs novos e enriquecidos

### `IncomingMessageDTO`

Arquivo:

- `src/DTO/IncomingMessageDTO.php`

Campos adicionados:

- `provider`
- `remoteJid`
- `instanceId`
- `externalMessageId`
- `interactivePayload`

Impacto:

- o webhook passou a carregar dados suficientes para mixed mode
- logs, fila e envio conseguem saber por qual provider a mensagem entrou
- respostas interativas passam a ter espaco reservado no contrato interno

### `AssistantReplyDTO`

Arquivo novo:

- `src/DTO/AssistantReplyDTO.php`

Objetivo:

- estruturar respostas internas com suporte a tipos diferentes

Tipos previstos:

- `text`
- `buttons`
- `contact`

### `OutgoingMessageDTO`

Arquivo novo:

- `src/DTO/OutgoingMessageDTO.php`

Objetivo:

- padronizar o que cada provider de saida recebe para enviar ao usuario

### `ConversationResultDTO`

Arquivo:

- `src/DTO/ConversationResultDTO.php`

Mudanca principal:

- passou a aceitar `replyPayload`
- quando nao ha payload estruturado, ainda faz fallback para texto simples

### `SessionDTO`

Arquivo:

- `src/DTO/SessionDTO.php`

Mudanca principal:

- passou a carregar `provider`
- a fabrica e a serializacao foram ajustadas para mixed mode

## Servico de envio de WhatsApp

Arquivo principal:

- `src/Service/WhatsAppService.php`

Mudancas importantes:

- passou a operar com providers registrados em memoria
- ganhou `sendText`, `sendButtons`, `sendContact` orientados a `OutgoingMessageDTO`
- `sendReply` agora escolhe o provider com base no `IncomingMessageDTO`
- respostas estruturadas com `AssistantReplyDTO` agora podem ser enviadas sem acoplar o dominio ao provider externo

### Provider PAPI

Arquivo:

- `src/Service/WhatsApp/PapiOutboundProvider.php`

Capacidades implementadas:

- envio de texto via `/send-text`
- envio de botoes via `/send-buttons`
- envio de contato via `/send-contact`
- envio de presence via `/presence`
- conversao de telefone para `jid`
- validacao das regras de botoes

Regras de botoes implementadas:

- aceita ate 3 botoes
- nao mistura `quick_reply` com botoes CTA
- exige campos obrigatorios por tipo

## Webhook e fluxo de conversa

Arquivos envolvidos:

- `public/webhook.php`
- `src/Controller/WebhookController.php`
- `src/Service/ConversationService.php`
- `src/Domain/Conversation/AiOrchestrator.php`

Mudancas relevantes:

- o bootstrap do webhook passou a montar `WhatsAppService` pela configuracao nova
- `AudioTranscriptionService` agora recebe base URL e API key por provider
- o controller registra `provider`, `instance_id`, `external_message_id` e `interactive_payload` nos logs
- o envio automatico responde usando o provider da mensagem recebida
- o fallback de audio tambem respeita o provider de entrada

## Fila, debounce e deduplicacao

Arquivos envolvidos:

- `src/Service/DebounceQueueService.php`
- `src/Infrastructure/Persistence/PdoMessageQueueRepository.php`
- `src/Infrastructure/Persistence/MessageQueueRepositoryInterface.php`
- `src/Infrastructure/Persistence/NullMessageQueueRepository.php`

Mudancas principais:

- a fila agora usa `provider + phone` como contexto de conversa
- o lock da fila tambem usa `provider + phone`
- a deduplicacao por mensagem externa foi adicionada com `external_message_id`
- mensagens repetidas podem ser ignoradas antes do enfileiramento
- o batch da fila passou a guardar metadados de consolidacao no payload

Resultados praticos:

- menos risco de processar o mesmo webhook duas vezes
- menos risco de misturar conversas iguais vindas de providers diferentes
- melhor rastreabilidade de consolidacao na resposta e nos logs

## Persistencia de sessoes

Arquivos envolvidos:

- `src/Infrastructure/Persistence/PdoSessionRepository.php`
- `src/Infrastructure/Persistence/JsonSessionRepository.php`
- `src/Infrastructure/Persistence/SessionRepositoryInterface.php`
- `src/Service/SessionService.php`

Mudancas principais:

- sessao passou a ser buscada e salva por `provider + phone`
- `SessionDTO` foi adaptado para mixed mode
- o fallback JSON tambem foi ajustado para carregar o provider

Resultado:

- um mesmo telefone pode existir em mais de um provider sem sobrescrever a mesma sessao

## Logs e monitor de mensagens

Arquivos envolvidos:

- `src/Infrastructure/Persistence/PdoMessageLogRepository.php`
- `src/Infrastructure/Persistence/MessageLogRepositoryInterface.php`
- `src/Infrastructure/Persistence/NullMessageLogRepository.php`
- `src/Service/MessageLogService.php`
- `public/message_monitor.php`
- `public/message_monitor_api.php`

Mudancas principais:

- `provider` passou a ser gravado em `message_logs`
- o estado do monitor foi adaptado para chave composta `(provider, phone)`
- funcoes auxiliares do monitor passaram a reconhecer provider explicitamente
- chaves de conversa agora podem ser compostas no formato `provider:phone`
- o status de envio foi mantido compativel com `sent`, `multi_sent`, `skipped` e `error`

Resultado:

- o painel deixa de confundir duas conversas do mesmo telefone em providers diferentes
- logs de entrada e saida passam a carregar provider de forma nativa

## Configuracao e ambiente

Arquivo:

- `config/services.php`

Mudancas principais:

- suporte a provider padrao com `WHATSAPP_DEFAULT_PROVIDER`
- suporte a mixed webhook mode com `WHATSAPP_MIXED_WEBHOOK_MODE`
- suporte a hint de query com `WHATSAPP_PROVIDER_QUERY_KEY`
- configuracao separada para:
  - Evolution
  - PAPI
- suporte a profile com resolucao de variaveis por sufixo
- configuracoes compartilhadas de split de mensagem
- parametros especificos da PAPI como `validate_number`

Arquivo atualizado:

- `.env.example`

Impacto:

- o projeto ficou preparado para selecionar provider sem alterar codigo
- a configuracao nova suporta tanto ambiente simples quanto modo misto

## Testes

Arquivo principal alterado:

- `tests/run.php`

Escopo observado no arquivo:

- repositorios em memoria atualizados para provider e external message id
- suporte a locks por `provider + phone`
- suporte a DTOs novos
- suporte a resolver de provider e fluxo multi-provider
- validacoes para camada nova de envio e fila

## Correcao feita no fim do dia: caracteres quebrados no PAPI

Arquivo:

- `src/Service/WhatsApp/AbstractOutboundProvider.php`

Problema observado:

- mensagens validas com emoji, acentos e alguns caracteres especiais estavam sendo tratadas como se estivessem com encoding quebrado
- durante essa tentativa de reparo, caracteres validos eram convertidos para `?`
- no WhatsApp isso aparecia visualmente como `�`

Causa:

- a heuristica de `looksLikeMojibake()` era ampla demais e detectava falsos positivos em texto UTF-8 valido

Correcao aplicada:

- a deteccao de mojibake foi restringida para somente casos com evidencia real de texto corrompido
- o provider passou a preservar corretamente emoji e caracteres validos durante `normalizeText()`

Impacto pratico:

- mensagens como `📢`, `📍`, `✅`, `💙`, `👉` e o caractere `–` deixam de ser substituidas
- o chunking da mensagem continua funcionando sem destruir o conteudo

## Arquivos principais alterados em 03/04/2026

Lista agrupada por area:

### Documentacao

- `MIGRACAO_PAPI_WHATSAPP.md`
- `ARQUITETURA_AGNOSTICA_WHATSAPP.md`
- `PLANO_MIGRACAO_PROVIDER_AGNOSTICO.md`
- `DOCUMENTACAO_ALTERACOES_2026-04-03.md`

### Configuracao e bootstrap

- `config/services.php`
- `.env.example`
- `public/webhook.php`

### DTOs

- `src/DTO/IncomingMessageDTO.php`
- `src/DTO/ConversationResultDTO.php`
- `src/DTO/SessionDTO.php`
- `src/DTO/AssistantReplyDTO.php`
- `src/DTO/OutgoingMessageDTO.php`

### Controller e dominio

- `src/Controller/WebhookController.php`
- `src/Service/ConversationService.php`
- `src/Domain/Conversation/AiOrchestrator.php`
- `src/Service/AudioTranscriptionService.php`

### WhatsApp e normalizacao

- `src/Service/MessageNormalizer.php`
- `src/Service/WhatsAppService.php`
- `src/Service/WhatsApp/AbstractOutboundProvider.php`
- `src/Service/WhatsApp/EvolutionIncomingWebhookNormalizer.php`
- `src/Service/WhatsApp/EvolutionOutboundProvider.php`
- `src/Service/WhatsApp/PapiIncomingWebhookNormalizer.php`
- `src/Service/WhatsApp/PapiOutboundProvider.php`
- `src/Service/WhatsApp/IncomingWebhookProviderResolver.php`
- `src/Service/WhatsApp/IncomingWebhookNormalizerInterface.php`
- `src/Service/WhatsApp/IncomingWebhookProviderResolverInterface.php`
- `src/Service/WhatsApp/OutboundProviderInterface.php`

### Fila, sessoes e persistencia

- `src/Service/DebounceQueueService.php`
- `src/Service/SessionService.php`
- `src/Service/MessageLogService.php`
- `src/Infrastructure/Persistence/PdoMessageQueueRepository.php`
- `src/Infrastructure/Persistence/PdoSessionRepository.php`
- `src/Infrastructure/Persistence/PdoMessageLogRepository.php`
- `src/Infrastructure/Persistence/JsonSessionRepository.php`
- `src/Infrastructure/Persistence/MessageQueueRepositoryInterface.php`
- `src/Infrastructure/Persistence/SessionRepositoryInterface.php`
- `src/Infrastructure/Persistence/MessageLogRepositoryInterface.php`
- `src/Infrastructure/Persistence/NullMessageQueueRepository.php`
- `src/Infrastructure/Persistence/NullMessageLogRepository.php`

### Monitor e operacao

- `public/message_monitor.php`
- `public/message_monitor_api.php`

### Banco

- `database/migrations/009_add_provider_to_message_logs.sql`
- `database/migrations/010_add_provider_to_message_queue.sql`
- `database/migrations/011_add_provider_to_message_monitor_conversations.sql`
- `database/migrations/012_add_provider_to_sessions.sql`
- `database/migrations/013_add_external_message_id_to_message_queue.sql`

### Testes

- `tests/run.php`

## Impacto funcional esperado

Depois dessas alteracoes, o sistema passa a ter estas capacidades:

- responder pelo mesmo provider que recebeu a mensagem
- operar com Evolution e PAPI no mesmo codigo
- aceitar mixed mode no webhook quando habilitado
- manter conversa, fila e monitor por provider
- deduplicar webhooks repetidos por `external_message_id`
- preparar o fluxo para mensagens interativas e contato
- registrar mais contexto tecnico para suporte e auditoria

## Pendencias e observacoes

Pontos que ainda merecem homologacao completa em ambiente real:

- validar mixed mode com trafego real de Evolution e PAPI no mesmo endpoint
- validar respostas interativas da PAPI ponta a ponta em producao
- confirmar audio e download de midia para todos os formatos reais de payload
- rodar migrations no banco produtivo antes de depender totalmente dos novos campos
- validar o monitor com historico ja existente para conversas antigas sem provider populado

## Recomendacao operacional

Antes de subir tudo em ambiente definitivo:

1. aplicar as migrations `009` a `013`
2. revisar as variaveis de ambiente de Evolution e PAPI
3. decidir se o ambiente vai operar em provider unico ou `mixed_webhook_mode`
4. homologar webhook, envio manual, audio e fila
5. acompanhar os logs com foco em `provider`, `external_message_id` e `send_result`

## Roteiro de subida para producao do fluxo atual

Este roteiro considera o estado atual do projeto em 03/04/2026, incluindo:

- suporte a `evolution` e `papi`
- fila e sessao separadas por `provider + phone`
- cancelamento com confirmacao por botoes
- gatilho `humano` para envio do contato da recepcao
- correcao de encoding no envio pela PAPI
- tela administrativa de configuracao do `.env`

### 1. Preparacao do codigo no servidor

Publicar no servidor todos os arquivos alterados neste ciclo, com atencao especial para:

- `public/webhook.php`
- `public/message_monitor.php`
- `public/message_monitor_api.php`
- `public/settings_admin.php`
- `src/Controller/WebhookController.php`
- `src/Domain/Conversation/AiOrchestrator.php`
- `src/Domain/Conversation/IntentDetector.php`
- `src/Service/ConversationService.php`
- `src/Service/MessageNormalizer.php`
- `src/Service/WhatsAppService.php`
- `src/Service/EnvFileManager.php`
- toda a pasta `src/Service/WhatsApp/`
- `config/services.php`
- migrations `009` a `013`

Se o deploy for manual, confirmar que nenhum arquivo da pasta `src/Service/WhatsApp/` ficou de fora.

### 2. Banco de dados

Aplicar obrigatoriamente as migrations:

1. `database/migrations/009_add_provider_to_message_logs.sql`
2. `database/migrations/010_add_provider_to_message_queue.sql`
3. `database/migrations/011_add_provider_to_message_monitor_conversations.sql`
4. `database/migrations/012_add_provider_to_sessions.sql`
5. `database/migrations/013_add_external_message_id_to_message_queue.sql`

Conferencias minimas depois da execucao:

- a tabela `message_logs` precisa ter a coluna `provider`
- a tabela `message_queue` precisa ter as colunas `provider` e `external_message_id`
- a tabela `message_monitor_conversations` precisa ter a coluna `provider`
- a tabela `sessions` precisa ter a coluna `provider`

Sem essas migrations, o fluxo novo nao deve ser considerado pronto para producao.

### 3. Variaveis de ambiente obrigatorias

Revisar o arquivo `.env` real do servidor.

Campos mais importantes para operacao:

- `APP_ENV`
- `APP_DEBUG`
- `APP_URL`
- `CHAT_URL`
- `WHATSAPP_PROVIDER`
- `WHATSAPP_DEFAULT_PROVIDER`
- `WHATSAPP_PROFILE`
- `WHATSAPP_MIXED_WEBHOOK_MODE`
- `WHATSAPP_PROVIDER_QUERY_KEY`
- `WHATSAPP_BASE_URL`
- `WHATSAPP_INSTANCE`
- `WHATSAPP_API_KEY`
- `WHATSAPP_PAPI_BASE_URL`
- `WHATSAPP_PAPI_INSTANCE`
- `WHATSAPP_PAPI_API_KEY`
- `WHATSAPP_PAPI_SEND_ENABLED`
- `WHATSAPP_PAPI_INTERACTIVE_ENABLED`
- `WHATSAPP_PAPI_TYPING_ENABLED`
- `AI_API_KEY`
- `AI_MODEL`
- `AGENDA_BASE_URL`
- `AGENDA_EMPRESA`
- `DB_*`

Se a unidade for controlada por profile, revisar tambem:

- `WHATSAPP_INSTANCE_PSF01`, `WHATSAPP_INSTANCE_PSF02`, `WHATSAPP_INSTANCE_PSF03`
- `WHATSAPP_API_KEY_PSF01`, `WHATSAPP_API_KEY_PSF02`, `WHATSAPP_API_KEY_PSF03`
- `WHATSAPP_PAPI_INSTANCE_PSF01`, `WHATSAPP_PAPI_INSTANCE_PSF02`, `WHATSAPP_PAPI_INSTANCE_PSF03`
- `WHATSAPP_PAPI_API_KEY_PSF01`, `WHATSAPP_PAPI_API_KEY_PSF02`, `WHATSAPP_PAPI_API_KEY_PSF03`

### 4. Como subir em producao usando PAPI

Para operar exclusivamente em PAPI, a configuracao recomendada e:

- `WHATSAPP_PROVIDER=papi`
- `WHATSAPP_DEFAULT_PROVIDER=papi`
- `WHATSAPP_MIXED_WEBHOOK_MODE=false`
- `WHATSAPP_PROFILE=psf02` ou o profile correspondente
- `WHATSAPP_PAPI_BASE_URL=` endpoint real da PAPI
- `WHATSAPP_PAPI_INSTANCE=` instancia base ou por profile
- `WHATSAPP_PAPI_API_KEY=` chave real da PAPI
- `WHATSAPP_PAPI_SEND_ENABLED=true`
- `WHATSAPP_PAPI_INTERACTIVE_ENABLED=true`
- `WHATSAPP_PAPI_TYPING_ENABLED=true`

Se o profile ativo for `psf02`, conferir especialmente:

- `WHATSAPP_PAPI_INSTANCE_PSF02`
- `WHATSAPP_PAPI_API_KEY_PSF02`

O valor efetivo usado pelo sistema sera o da configuracao por profile quando ela existir.

### 5. Como subir em producao usando Evolution

Para operar exclusivamente em Evolution, a configuracao recomendada e:

- `WHATSAPP_PROVIDER=evolution`
- `WHATSAPP_DEFAULT_PROVIDER=evolution`
- `WHATSAPP_MIXED_WEBHOOK_MODE=false`
- `WHATSAPP_PROFILE=psf02` ou o profile correspondente
- `WHATSAPP_BASE_URL=` endpoint real da Evolution
- `WHATSAPP_INSTANCE=` instancia base ou por profile
- `WHATSAPP_API_KEY=` chave real da Evolution
- `WHATSAPP_SEND_ENABLED=true`
- `WHATSAPP_INTERACTIVE_ENABLED=false` se a Evolution nao estiver usando botoes nativos
- `WHATSAPP_TYPING_ENABLED=true`

Se o profile ativo for `psf02`, conferir especialmente:

- `WHATSAPP_INSTANCE_PSF02`
- `WHATSAPP_API_KEY_PSF02`

### 6. Webhook em producao

Depois de publicar, validar se o provedor esta chamando:

- `public/webhook.php`

Checagens necessarias:

- a URL configurada no provider aponta para o dominio correto de producao
- o metodo HTTP aceito pelo provedor esta correto
- o payload chega inteiro ao webhook
- no caso de PAPI, os botoes e contatos continuam chegando corretamente
- no caso de mixed mode, o parametro definido em `WHATSAPP_PROVIDER_QUERY_KEY` distingue corretamente o provider

### 7. Tela administrativa

A tela de configuracao fica em:

- `public/settings_admin.php`

Requisitos para funcionar em producao:

- o servidor precisa conseguir ler o arquivo `.env`
- o servidor precisa conseguir gravar no arquivo `.env`
- a sessao PHP precisa estar funcionando
- definir preferencialmente `SETTINGS_ADMIN_USERNAME` e `SETTINGS_ADMIN_PASSWORD`

Se `SETTINGS_ADMIN_PASSWORD` estiver vazio, a tela usa `SESSION_ADMIN_TOKEN` como fallback de senha.

### 8. Smoke test minimo apos subir

Depois do deploy, fazer estes testes reais no WhatsApp:

1. enviar `oi`
2. confirmar que o menu principal aparece com botoes
3. clicar em `Agendar consulta`
4. enviar `cancelar`
5. confirmar que o fluxo de cancelamento pede o ID correto
6. informar um ID valido e confirmar que aparecem os botoes `Sim` e `Nao`
7. enviar `humano`
8. confirmar que o contato da recepcao chega corretamente
9. validar uma mensagem com acento e emoji para confirmar que nao houve regressao de encoding na PAPI
10. validar uma consulta comum para garantir que agenda, sessao e fila continuam operando

### 9. Validacao no monitor e nos logs

Depois do smoke test, revisar:

- `public/message_monitor.php`
- arquivo configurado em `LOG_PATH`

Pontos para observar:

- `provider` correto nas conversas
- respostas saindo pelo mesmo provider de entrada
- ausencia de erros de encoding
- ausencia de duplicidade inesperada na fila
- `external_message_id` sendo preenchido quando o payload trouxer esse identificador

### 10. Checklist final de liberacao

Antes de considerar o fluxo liberado em producao, confirmar:

1. codigo publicado completo
2. migrations `009` a `013` aplicadas
3. `.env` revisado no servidor
4. webhook apontando para producao
5. smoke test executado com sucesso
6. monitor exibindo `provider` corretamente
7. cancelamento funcionando por texto e por botoes
8. gatilho `humano` funcionando
9. mensagens com acento e emoji sem quebrar
10. tela `settings_admin.php` acessivel e salvando corretamente

### 11. Recomendacao de go-live

O fluxo atual pode ser colocado em producao desde que:

- as migrations estejam aplicadas
- o `.env` do servidor esteja consistente com o provider escolhido
- o webhook esteja configurado corretamente
- o smoke test real passe sem erro

Sem essas validacoes finais no ambiente real, a recomendacao nao e chamar o deploy de concluido.

## Conclusao

O trabalho de 03/04/2026 mudou o projeto de uma integracao concentrada em um unico formato de WhatsApp para uma base preparada para multiplos providers.

A mudanca mais importante foi estrutural:

- provider passou a ser parte do contexto da conversa
- fila, sessao, log e monitor passaram a conhecer esse contexto
- o webhook e o envio foram desacoplados por adapters

A mudanca mais importante no banco foi a entrada do campo `provider` em tabelas centrais e do campo `external_message_id` na `message_queue`.

A correcao final do dia garantiu que o provider PAPI preserve corretamente o texto em UTF-8 ao enviar mensagens com emoji e acentos.
