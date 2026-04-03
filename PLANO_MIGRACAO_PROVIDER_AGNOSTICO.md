# Plano de Migracao para Servico WhatsApp Agnostico de Provider

Objetivo:

- desacoplar o fluxo de negocio dos providers WhatsApp
- suportar Evolution e PAPI no mesmo projeto
- permitir escolher o provider por mensagem recebida
- garantir a regra: entrou por um provider, responde pelo mesmo provider

## Principio arquitetural

O fluxo nao deve conhecer Evolution nem PAPI.

O provider deve ser somente a borda de transporte:

1. recebe o webhook
2. identifica o provider
3. normaliza para DTO interno
4. entrega ao fluxo
5. recebe a resposta interna do fluxo
6. traduz para a API externa correta
7. envia ao usuario

Fluxo alvo:

```text
Webhook -> Resolver Provider -> Normalizer do Provider -> Fluxo Interno -> Reply Interno -> Sender do Mesmo Provider -> Usuario
```

## Resultado esperado ao final

- Evolution e PAPI coexistem no mesmo codigo
- o fluxo interno fica unico
- webhook pode operar em modo misto
- envio manual do painel consegue escolher provider
- logs, fila e monitor passam a registrar provider
- respostas interativas ficam prontas para entrar sem acoplar o dominio

## Estrategia geral

Migrar em camadas, sem trocar tudo de uma vez.

Ordem recomendada:

1. preparar contratos internos
2. desacoplar saida
3. desacoplar entrada
4. ajustar estado, fila e logs para mixed mode
5. habilitar botoes
6. fazer rollout controlado

## Fase 0 - Preparacao e congelamento de contratos atuais

Objetivo:

- mapear claramente o comportamento atual para evitar regressao

Entregas:

- consolidar docs criadas:
  - `MIGRACAO_PAPI_WHATSAPP.md`
  - `ARQUITETURA_AGNOSTICA_WHATSAPP.md`
- registrar payloads reais de:
  - webhook Evolution de texto
  - webhook Evolution de audio
  - webhook PAPI de texto
  - webhook PAPI de audio
  - webhook PAPI de clique em botao
- catalogar respostas reais de:
  - envio texto Evolution
  - envio texto PAPI
  - envio botoes PAPI

Checklist:

- ter amostras reais de payload
- ter exemplos de erro por provider
- confirmar como diferenciar provider no webhook

Criterio de aceite:

- existe material suficiente para implementar normalizers sem adivinhacao

## Fase 1 - Criar os contratos internos agnosticos

Objetivo:

- definir a fronteira entre fluxo e provider

Entregas principais:

- criar `IncomingMessageDTO` enriquecido com provider
- criar `AssistantReplyDTO`
- criar `OutgoingMessageDTO`
- criar interfaces:
  - `IncomingWebhookProviderResolverInterface`
  - `IncomingWebhookNormalizerInterface`
  - `WhatsAppOutboundProviderInterface`
  - `WhatsAppMessengerInterface`

Direcao tecnica:

- manter compatibilidade com o fluxo atual inicialmente
- permitir `reply` textual continuar funcionando durante a transicao

Arquivos impactados:

- `src/DTO/IncomingMessageDTO.php`
- `src/DTO/ConversationResultDTO.php`
- novos DTOs em `src/DTO`
- novas interfaces em `src/Service/WhatsApp` ou namespace equivalente

Criterio de aceite:

- o dominio consegue trabalhar com DTOs internos sem depender de endpoint externo

## Fase 2 - Desacoplar a saida primeiro

Objetivo:

- permitir enviar por Evolution ou PAPI sem mexer ainda profundamente no webhook

Entregas:

- criar providers de saida:
  - `EvolutionOutboundProvider`
  - `PapiOutboundProvider`
- criar facade de envio:
  - `WhatsAppMessenger`
- mover logica de `src/Service/WhatsAppService.php` para providers concretos
- manter `sendText` funcionando por compatibilidade durante a transicao

Regras:

- Evolution suporta pelo menos `text`
- PAPI suporta `text`
- PAPI pode preparar suporte a `buttons`, mesmo que ainda nao fique ativo no fluxo

Arquivos impactados:

- `src/Service/WhatsAppService.php`
- `public/webhook.php`
- `public/message_monitor_api.php`
- `tests/run.php`

Criterio de aceite:

- envio manual e envio automatico conseguem mandar texto pelo provider configurado
- provider da saida pode ser trocado sem alterar o fluxo

## Fase 3 - Resolver provider por request no webhook

Objetivo:

- preparar o sistema para receber Evolution e PAPI no mesmo endpoint

Entregas:

- criar resolver de provider de entrada
- suportar estrategia preferencial de identificacao:
  - query param
  - header/token por provider
  - fallback por shape do payload

Recomendacao operacional:

- usar o mesmo `webhook.php`, mas com hint explicito:
  - `?provider=evolution`
  - `?provider=papi`

Arquivos impactados:

- `public/webhook.php`
- `src/Core/Request.php` se precisarmos enriquecer acesso a headers/query
- nova camada resolver em `src/Service/WhatsApp`

Criterio de aceite:

- o request e roteado com seguranca para o provider correto
- o bootstrap deixa de montar um sender unico fixo para todas as requisicoes

## Fase 4 - Criar normalizers de entrada por provider

Objetivo:

- fazer a entrada ficar agnostica

Entregas:

- `EvolutionIncomingWebhookNormalizer`
- `PapiIncomingWebhookNormalizer`
- remover dependencia direta do formato Evolution da classe atual de normalizacao
- transformar `MessageNormalizer` em facade, factory ou abandonar a classe atual

Regras:

- ambos devolvem o mesmo `IncomingMessageDTO`
- o fluxo recebe sempre o mesmo contrato

Campos minimos do DTO:

- `provider`
- `phone`
- `remoteJid`
- `messageType`
- `message`
- `mediaUrl`
- `pushName`
- `instanceId`
- `externalMessageId`
- `interactivePayload`
- `payload`

Arquivos impactados:

- `src/Service/MessageNormalizer.php`
- `src/Controller/WebhookController.php`
- `tests/run.php`

Criterio de aceite:

- o fluxo processa mensagem de Evolution e PAPI sem conhecer a origem

## Fase 5 - Fazer a resposta sair pelo mesmo provider da entrada

Objetivo:

- garantir coerencia de transporte

Entregas:

- `IncomingMessageDTO` passa a carregar o provider obrigatoriamente
- `WhatsAppMessenger` recebe o DTO de entrada e escolhe o outbound provider correspondente
- `WebhookController` para de chamar diretamente um sender fixo

Regra obrigatoria:

- entrou por Evolution, sai por Evolution
- entrou por PAPI, sai por PAPI

Arquivos impactados:

- `src/Controller/WebhookController.php`
- `src/Service/ConversationService.php`
- `public/webhook.php`

Criterio de aceite:

- mixed mode nao cruza provider na resposta

## Fase 6 - Ajustar audio e midia por provider

Objetivo:

- impedir quebra de transcricao e download de anexos

Entregas:

- abstrair download de midia por provider
- adaptar `AudioTranscriptionService` para consumir uma interface e nao detalhes do provider atual
- homologar audio por Evolution
- homologar audio por PAPI

Arquivos impactados:

- `src/Service/AudioTranscriptionService.php`
- nova camada de media provider
- `public/webhook.php`

Criterio de aceite:

- audio continua funcionando com os providers homologados

## Fase 7 - Ajustar fila, debounce e locks para mixed mode

Objetivo:

- impedir colisao entre providers

Entregas:

- chave da fila passa a considerar `provider + phone`
- lock passa a considerar `provider + phone`
- agregacao de mensagens respeita origem da conversa
- `pushName` deixa de ser lido de shape fixo do payload

Arquivos impactados:

- `src/Service/DebounceQueueService.php`
- `src/Infrastructure/Persistence/PdoMessageQueueRepository.php`
- `src/DTO/QueuedMessageBatchDTO.php` se necessario

Criterio de aceite:

- duas mensagens do mesmo telefone em providers diferentes nao se misturam

## Fase 8 - Ajustar logs, persistencia e monitor

Objetivo:

- dar visibilidade operacional ao provider usado

Entregas:

- registrar provider nos logs de entrada e saida
- registrar instance/profile quando aplicavel
- padronizar `send_result`
- atualizar monitor para:
  - mostrar provider
  - filtrar por provider no futuro
  - permitir envio manual por provider

Melhoria recomendada:

- adicionar coluna de provider em banco, em vez de depender so do payload JSON

Arquivos impactados:

- `src/Service/MessageLogService.php`
- `src/Infrastructure/Persistence/PdoMessageLogRepository.php`
- `public/message_monitor.php`
- `public/message_monitor_api.php`
- migrations ou scripts SQL, se existirem

Criterio de aceite:

- operacao consegue identificar facilmente por qual provider a conversa entrou e saiu

## Fase 9 - Adaptar o fluxo para respostas estruturadas

Objetivo:

- preparar o dominio para texto e botoes sem acoplamento

Entregas:

- `ConversationResultDTO` passa a aceitar resposta estruturada
- fluxo continua podendo devolver texto simples
- quando necessario, passa a devolver `buttons`

Exemplos de tipos iniciais:

- `text`
- `buttons`

Arquivos impactados:

- `src/DTO/ConversationResultDTO.php`
- `src/Service/ConversationService.php`
- `src/Domain/Conversation/AiOrchestrator.php`

Criterio de aceite:

- o fluxo gera resposta agnostica de provider

## Fase 10 - Implementar botoes no provider PAPI

Objetivo:

- habilitar respostas interativas sem quebrar Evolution

Entregas:

- implementar `send-buttons` na PAPI
- validar regras:
  - quick reply ate 3
  - nao misturar quick reply com CTA quando compatibilidade Web importar
- definir fallback para providers sem suporte

Arquivos impactados:

- provider PAPI de saida
- DTOs de resposta
- painel manual se o envio manual tambem oferecer botoes

Criterio de aceite:

- PAPI envia botoes corretamente
- Evolution nao quebra por nao suportar o mesmo tipo

## Fase 11 - Suportar clique de botao na entrada

Objetivo:

- fechar o ciclo das mensagens interativas

Entregas:

- normalizer PAPI interpreta clique de botao
- `interactivePayload` alimenta o fluxo
- criar mapping interno claro:
  - texto selecionado
  - id selecionado
  - tipo de interacao

Arquivos impactados:

- normalizer PAPI
- `IncomingMessageDTO`
- possivelmente `AiOrchestrator`

Criterio de aceite:

- o usuario clica no botao e o fluxo entende corretamente a resposta

## Fase 12 - Endurecimento operacional e rollout

Objetivo:

- subir com seguranca

Entregas:

- flags de rollout:
  - `WHATSAPP_MIXED_WEBHOOK_MODE`
  - `WHATSAPP_DEFAULT_PROVIDER`
  - `WHATSAPP_ENABLE_PAPI_BUTTONS`
- monitoramento de erro por provider
- logs de fallback e falha de normalizacao
- plano de rollback

Estrategia de rollout:

1. homologar Evolution no novo modelo
2. homologar PAPI no novo modelo
3. ativar mixed mode em ambiente controlado
4. ativar painel/operacao
5. ativar botoes por feature flag

Criterio de aceite:

- o sistema opera com os dois providers sem regressao relevante

## Backlog tecnico por tema

### Tema 1 - Configuracao

- reestruturar `config/services.php`
- ampliar `.env.example`
- separar credenciais por provider
- suportar profile e provider juntos

### Tema 2 - Borda de entrada

- provider resolver
- normalizers por provider
- identificacao segura por request

### Tema 3 - Borda de saida

- outbound providers
- messenger unificado
- retorno padronizado

### Tema 4 - Dominio

- reply estruturado
- fluxo sem dependencia de API externa

### Tema 5 - Operacao

- logs com provider
- monitor com provider
- fila com provider
- rollback e flags

## Criterios gerais de aceite do projeto

Ao final da migracao, tudo abaixo deve ser verdadeiro:

1. o fluxo nao conhece endpoint nem payload externo
2. o provider da entrada define o provider da saida
3. Evolution e PAPI funcionam no mesmo codigo
4. o mesmo webhook pode operar em mixed mode
5. audio continua funcional por provider homologado
6. fila nao mistura mensagens entre providers
7. logs e monitor mostram o provider correto
8. botoes entram como capacidade do provider, nao como regra do fluxo

## Ordem de execucao recomendada

Se for executar por sprints, esta e a melhor ordem:

### Sprint 1

- Fase 0
- Fase 1
- Fase 2

### Sprint 2

- Fase 3
- Fase 4
- Fase 5

### Sprint 3

- Fase 6
- Fase 7
- Fase 8

### Sprint 4

- Fase 9
- Fase 10
- Fase 11
- Fase 12

## Recomendacao final

Nao migrar direto para botoes ou direto para PAPI sem antes criar a fronteira correta entre fluxo e provider.

O ganho real aqui nao e apenas trocar endpoint.

O ganho real e este:

- um fluxo unico
- providers plugaveis
- entrada e saida desacopladas
- operacao segura em modo misto

Esse deve ser o norte da implementacao.
