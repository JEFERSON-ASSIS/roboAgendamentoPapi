# Arquitetura Agnostica para WhatsApp Providers

Documento de analise do projeto para suportar mais de um provider de WhatsApp no mesmo codigo, com foco em:

- Evolution
- PAPI

Objetivo principal:

- permitir escolher qual provider usar
- manter o projeto preparado para conviver com os dois
- suportar o caso em que o mesmo endpoint de webhook receba chamadas de providers diferentes

## Resposta curta

Sim, e possivel ter as duas formas no projeto e escolher qual seguir.

E sim: a analise seguiu exatamente esse caminho conceitual:

- o fluxo de negocio fica desacoplado de Evolution e PAPI
- o provider so faz papel de adapter de borda
- se entrar pela PAPI, tudo de transporte fica na PAPI
- se entrar pela Evolution, tudo de transporte fica na Evolution
- o fluxo interno nao deve saber qual API externa esta sendo usada, exceto por um identificador tecnico quando necessario para roteamento de resposta

Mas existem dois cenarios diferentes:

### Cenario A - um provider ativo por ambiente

Exemplo:

- homologacao usa Evolution
- producao usa PAPI

Nesse caso, `WHATSAPP_PROVIDER` ja ajuda e o trabalho e mais simples.

### Cenario B - o mesmo webhook recebe Evolution e PAPI

Exemplo:

- a mesma URL `public/webhook.php` e configurada em duas plataformas diferentes
- uma chamada chega da Evolution
- outra chamada chega da PAPI

Nesse caso, um `WHATSAPP_PROVIDER` global nao resolve sozinho.

Precisamos de uma arquitetura com duas decisoes separadas:

1. resolver qual provider recebeu o webhook de entrada
2. resolver qual provider usar para responder aquela conversa

Esse segundo cenario e totalmente viavel, mas pede uma camada agnostica de entrada e saida.

## Modelo conceitual correto

O desenho ideal e este:

1. o webhook recebe a chamada
2. o sistema identifica qual provider fez a chamada
3. o adapter desse provider normaliza o payload para um DTO interno comum
4. o fluxo processa a mensagem sem conhecer Evolution ou PAPI
5. o fluxo devolve uma resposta interna padronizada
6. o sistema entrega essa resposta para o mesmo provider da entrada
7. o provider traduz a resposta interna para a API externa correta e envia ao usuario

Em outras palavras:

- provider nao contem regra de negocio
- provider nao decide fluxo
- provider nao monta regra de agenda
- provider apenas adapta entrada e saida

Fluxo resumido:

```text
Webhook -> Resolver Provider -> Normalizer do Provider -> Fluxo Interno -> Reply Interno -> Sender do Mesmo Provider -> Usuario
```

## Regra de ouro

Para nao misturar responsabilidades, a regra deve ser:

- se a mensagem entrou pela PAPI, a resposta sai pela PAPI
- se a mensagem entrou pela Evolution, a resposta sai pela Evolution

Ou seja:

- o provider da entrada define o provider da saida daquela interacao
- o fluxo apenas recebe um DTO comum e devolve um DTO comum

## Diagnostico atual do projeto

Hoje o projeto esta parcialmente preparado para provider configuravel, mas na pratica ainda esta acoplado ao padrao da Evolution em varios pontos.

## Mapa de impacto no projeto

### 1. Configuracao

Arquivos:

- `config/services.php`
- `.env.example`

Situacao atual:

- existe `WHATSAPP_PROVIDER`
- existem `WHATSAPP_BASE_URL`, `WHATSAPP_INSTANCE`, `WHATSAPP_API_KEY`
- existem perfis por instancia, como `WHATSAPP_INSTANCE_PSF01`

Problema:

- o provider existe na configuracao, mas nao muda o comportamento de fato
- a estrutura atual representa uma unica estrategia de envio por processo

Impacto para arquitetura agnostica:

- ampliar configuracao para suportar providers diferentes
- permitir configuracao por profile
- opcionalmente permitir roteamento por request

Exemplo de direcao:

```php
'whatsapp' => [
    'default_provider' => env('WHATSAPP_PROVIDER', 'evolution'),
    'mixed_webhook_mode' => filter_var(env('WHATSAPP_MIXED_WEBHOOK_MODE', false), FILTER_VALIDATE_BOOL),
    'providers' => [
        'evolution' => [
            'base_url' => env('WHATSAPP_EVOLUTION_BASE_URL', ''),
            'instance' => env('WHATSAPP_EVOLUTION_INSTANCE', ''),
            'api_key' => env('WHATSAPP_EVOLUTION_API_KEY', ''),
        ],
        'papi' => [
            'base_url' => env('WHATSAPP_PAPI_BASE_URL', ''),
            'instance' => env('WHATSAPP_PAPI_INSTANCE', ''),
            'api_key' => env('WHATSAPP_PAPI_API_KEY', ''),
        ],
    ],
]
```

## 2. Envio de mensagens

Arquivo central:

- `src/Service/WhatsAppService.php`

Situacao atual:

- uma unica classe faz tudo
- assume endpoint de texto da Evolution
- assume endpoint de presence da Evolution
- assume campo `number`
- nao conhece `send-buttons`

Impacto:

- esse e o principal ponto a ser quebrado em interfaces

Proposta:

separar em contratos:

```php
interface WhatsAppOutboundProviderInterface
{
    public function providerName(): string;
    public function isEnabled(): bool;
    public function send(OutgoingMessageDTO $message): array;
}
```

E criar implementacoes:

- `EvolutionOutboundProvider`
- `PapiOutboundProvider`

Tambem vale criar um DTO de saida:

```php
class OutgoingMessageDTO
{
    public function __construct(
        public readonly string $phone,
        public readonly string $type,
        public readonly ?string $text = null,
        public readonly ?string $footer = null,
        public readonly array $buttons = [],
        public readonly array $meta = []
    ) {
    }
}
```

Isso permite:

- mandar texto por um provider
- mandar botoes por outro
- manter o controller sem saber detalhes da API externa

## 3. Webhook de entrada

Arquivos principais:

- `public/webhook.php`
- `src/Controller/WebhookController.php`
- `src/Service/MessageNormalizer.php`

Situacao atual:

- o controller recebe JSON generico
- o normalizer assume formato parecido com Evolution
- ha leitura direta de:
  - `body.data`
  - `data.key.remoteJid`
  - `data.pushName`
  - `data.message`
  - `audioMessage`, `imageMessage`, `videoMessage`

Problema:

- isso nao e agnostico
- se PAPI enviar payload diferente, o fluxo quebra na normalizacao

Esse e o maior impacto do projeto inteiro.

## 4. Audio e download de midia

Arquivo:

- `src/Service/AudioTranscriptionService.php`

Situacao atual:

- usa `mediaUrl`
- resolve URL relativa com `WHATSAPP_BASE_URL`
- baixa midia com header `apikey`

Problema:

- isso pressupoe a forma de autenticacao e download do provider atual
- se PAPI devolver outro formato, a transcricao pode falhar

Impacto:

- audio tambem precisa ficar por provider

Proposta:

criar um contrato especifico para recuperar midia:

```php
interface WhatsAppMediaProviderInterface
{
    public function fetchMediaBinary(IncomingMessageDTO $message): array;
}
```

Ou entao delegar isso ao adapter de webhook/provider.

## 5. Debounce e fila

Arquivos:

- `src/Service/DebounceQueueService.php`
- `src/Infrastructure/Persistence/PdoMessageQueueRepository.php`

Situacao atual:

- a fila agrupa mensagens por telefone
- resgata `pushName` do payload assumindo `body.data.pushName`
- guarda o payload bruto

Problema:

- em mixed mode, duas mensagens do mesmo telefone vindas de providers diferentes podem acabar sendo tratadas como a mesma origem
- o parser do `pushName` continua acoplado ao padrao atual

Impacto:

- a chave da fila talvez precise considerar provider + telefone
- o `IncomingMessageDTO` precisa carregar o provider

Exemplo:

```php
lock_name = provider + ':' + phone
```

Sem isso, voce pode ter colisao operacional se os dois providers trafegarem a mesma conversa.

## 6. Logs e monitor de mensagens

Arquivos:

- `src/Service/MessageLogService.php`
- `src/Infrastructure/Persistence/PdoMessageLogRepository.php`
- `public/message_monitor.php`
- `public/message_monitor_api.php`

Situacao atual:

- o log grava telefone, direcao, tipo, payload bruto e texto normalizado
- o monitor tenta extrair nome usando:
  - `push_name`
  - `pushName`
  - `payload.body.pushName`
  - `payload.body.data.pushName`

Problema:

- o monitor assume shape de payload atual
- o status de envio tambem assume contratos da classe atual
- nao existe coluna explicita para provider

Impacto:

- para o projeto virar realmente agnostico, o log precisa registrar provider
- idealmente tambem registrar instance/profile/canal

Campos recomendados no futuro:

- `provider`
- `provider_instance`
- `external_message_id`
- `transport_type`

Mesmo que isso comece so no payload JSON, o ideal a medio prazo e virar coluna.

## 7. Resultado da conversa

Arquivos:

- `src/DTO/ConversationResultDTO.php`
- `src/Service/ConversationService.php`
- `src/Domain/Conversation/AiOrchestrator.php`

Situacao atual:

- toda resposta do assistente e uma string em `reply`

Problema:

- isso funciona para texto
- nao representa bem botoes, lista interativa ou outros tipos

Impacto:

- se voce quiser escolher entre texto e botoes sem gambiarra, esse contrato precisa subir de nivel

Proposta:

trocar o conceito de:

- `reply: string`

para algo como:

```php
class AssistantReplyDTO
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $text = null,
        public readonly ?string $footer = null,
        public readonly array $buttons = [],
        public readonly array $meta = []
    ) {
    }
}
```

Entao `ConversationResultDTO` passaria a carregar:

- `replyText` para compatibilidade
- ou um `replyPayload`

## 8. Testes

Arquivos:

- `tests/run.php`
- `tests/README.md`

Situacao atual:

- existem testes de split de mensagens
- existem testes de presence
- existem testes acoplados a URLs da Evolution
- existe teste que cita explicitamente prioridade da `mediaUrl` da Evolution

Impacto:

- os testes precisam ser reorganizados por provider
- parte do comportamento atual continua valido
- parte deve virar teste de adapter/provider

Estrutura recomendada:

- testes do dominio conversacional sem provider
- testes do normalizer Evolution
- testes do normalizer PAPI
- testes do sender Evolution
- testes do sender PAPI

## O ponto central para ficar agnostico

O projeto precisa separar claramente 3 responsabilidades:

1. identificar de qual provider veio a mensagem
2. normalizar entrada para um DTO interno comum
3. enviar saida usando o provider correto

Hoje essas camadas ainda estao misturadas.

## Arquitetura recomendada

### Camada 1 - Resolver de provider de entrada

Criar algo como:

```php
interface IncomingWebhookProviderResolverInterface
{
    public function resolve(array $payload, array $headers = [], array $query = []): string;
}
```

Fontes possiveis para resolver:

- query string, ex: `?provider=evolution`
- header customizado
- segredo/token por provider
- assinatura do payload
- campos tipicos do body

### Importante

Se o mesmo endpoint for usado por dois providers ao mesmo tempo, a melhor estrategia e:

1. tentar identificar por query param ou header configurado
2. usar deteccao por shape do payload apenas como fallback

Porque detectar so pelo JSON e fragil.

## Estrategias praticas para o mesmo webhook

### Estrategia recomendada

Usar a mesma URL base, mas com hint por query:

- `https://seusite.com/public/webhook.php?provider=evolution`
- `https://seusite.com/public/webhook.php?provider=papi`

Vantagens:

- continua sendo o mesmo webhook no projeto
- elimina ambiguidade
- simplifica suporte e debugging

### Estrategia alternativa

Usar token diferente por provider:

- `X-Webhook-Token: evo-123`
- `X-Webhook-Token: papi-456`

### Estrategia menos segura

Detectar apenas por shape do payload.

So vale como fallback.

## Camada 2 - Normalizers por provider

Criar um contrato:

```php
interface IncomingWebhookNormalizerInterface
{
    public function supports(array $payload, array $headers = [], array $query = []): bool;
    public function normalize(array $payload, array $headers = [], array $query = []): IncomingMessageDTO;
}
```

Implementacoes sugeridas:

- `EvolutionIncomingWebhookNormalizer`
- `PapiIncomingWebhookNormalizer`

Assim, `MessageNormalizer.php` deixa de ser uma classe unica acoplada a um provider e vira uma fachada ou factory.

## Camada 3 - DTO interno comum de entrada

O `IncomingMessageDTO` atual precisa crescer um pouco.

Hoje ele tem:

- `phone`
- `messageType`
- `message`
- `mediaUrl`
- `pushName`
- `payload`

Para mixed mode, o ideal e incluir:

- `provider`
- `instanceId`
- `remoteJid`
- `senderName`
- `externalMessageId`
- `interactivePayload`

Exemplo:

```php
class IncomingMessageDTO
{
    public function __construct(
        public readonly string $provider,
        public readonly string $phone,
        public readonly ?string $remoteJid,
        public readonly string $messageType,
        public readonly ?string $message,
        public readonly ?string $mediaUrl,
        public readonly ?string $pushName,
        public readonly ?string $instanceId,
        public readonly ?string $externalMessageId,
        public readonly array $interactivePayload,
        public readonly array $payload
    ) {
    }
}
```

## Camada 4 - Resolver de provider de saida

O envio deve ser decidido com base em regra clara.

Opcoes de roteamento:

### Opcao 1 - responder pelo mesmo provider que recebeu

Melhor opcao para mixed mode.

Fluxo:

- webhook chega da Evolution
- DTO fica com `provider = evolution`
- resposta sai por Evolution

- webhook chega da PAPI
- DTO fica com `provider = papi`
- resposta sai por PAPI

### Opcao 2 - forcar provider por configuracao do profile

Bom para ambiente simples.

### Opcao 3 - decidir por telefone/canal/tenant

Mais avancado, util se o mesmo projeto atender multiplas unidades.

## Camada 5 - Sender unificado

Criar um facade:

```php
interface WhatsAppMessengerInterface
{
    public function sendReply(IncomingMessageDTO $incoming, AssistantReplyDTO $reply): array;
    public function sendManual(string $provider, OutgoingMessageDTO $message): array;
}
```

Esse facade:

- escolhe provider correto
- registra logs de forma consistente
- padroniza retorno de sucesso/falha

## Como ficam texto e botoes

Para ficar agnostico, o projeto nao deve pensar em "qual endpoint chamar", e sim em "qual tipo de mensagem quer enviar".

Tipos iniciais recomendados:

- `text`
- `buttons`

Com isso:

- Evolution pode suportar so `text`
- PAPI pode suportar `text` e `buttons`

Se o provider nao suportar um tipo, ele pode:

- recusar com erro controlado
- ou aplicar fallback para texto

## Como lidar com botoes sem quebrar o dominio

O dominio conversacional nao deve conhecer a API da PAPI.

Ele so deve dizer:

- resposta tipo texto
- resposta tipo botoes

Exemplo:

```php
new AssistantReplyDTO(
    type: 'buttons',
    text: 'Escolha uma opcao:',
    footer: 'Atendimento automatico',
    buttons: [
        ['type' => 'quick_reply', 'displayText' => 'Agendar', 'id' => 'agendar'],
        ['type' => 'quick_reply', 'displayText' => 'Cancelar', 'id' => 'cancelar'],
    ]
)
```

O adapter/provider decide como transformar isso na API externa.

## Impacto especifico do mesmo webhook em duas APIs

Esse e o trecho mais importante da sua pergunta.

Se duas APIs diferentes consumirem o mesmo webhook, o projeto precisa deixar de ser "configurado uma vez no bootstrap" e passar a ser "resolvido por request".

Hoje em `public/webhook.php` os servicos sao montados uma vez com base em:

- `services.whatsapp.base_url`
- `services.whatsapp.instance`
- `services.whatsapp.api_key`

Isso funciona para um provider fixo.

Nao funciona bem para mixed mode simultaneo.

Para mixed mode, a montagem precisa considerar o request atual.

Exemplo de fluxo novo:

1. `Request::capture()`
2. resolver provider de entrada
3. montar normalizer desse provider
4. normalizar payload
5. montar sender do mesmo provider
6. processar conversa
7. responder usando o sender resolvido

Ou seja: a resolucao do provider precisa acontecer antes da criacao do sender.

## Arquivos mais impactados

### Alto impacto

- `public/webhook.php`
- `src/Controller/WebhookController.php`
- `src/Service/MessageNormalizer.php`
- `src/Service/WhatsAppService.php`
- `src/Service/AudioTranscriptionService.php`
- `src/DTO/IncomingMessageDTO.php`
- `src/DTO/ConversationResultDTO.php`
- `src/Service/ConversationService.php`
- `public/message_monitor_api.php`

### Medio impacto

- `src/Service/DebounceQueueService.php`
- `src/Infrastructure/Persistence/PdoMessageQueueRepository.php`
- `src/Service/MessageLogService.php`
- `src/Infrastructure/Persistence/PdoMessageLogRepository.php`
- `public/message_monitor.php`
- `config/services.php`
- `.env.example`

### Baixo impacto

- partes do dominio de agenda
- infraestrutura HTTP generica
- componentes de OpenAI que nao dependem do provider

## O que pode continuar igual

Boa noticia: varias partes do projeto podem continuar quase intactas se criarmos uma fronteira boa.

Essas partes tendem a continuar:

- `AgendaService`
- `AiOrchestrator` na regra de negocio principal
- validacao de CPF
- sessao de conversa
- persistencia principal

O segredo e normalizar bem entrada e saida.

## Riscos reais

### 1. Provider errado responder a conversa

Se mixed mode nao for bem resolvido, a mensagem pode entrar por um provider e sair por outro.

Mitigacao:

- responder pelo provider do `IncomingMessageDTO`

### 2. Colisao na fila por telefone

Se lock e agregacao forem so por telefone, mensagens do mesmo numero em providers diferentes podem se misturar.

Mitigacao:

- usar chave composta `provider + phone`

### 3. Falha em clique de botao

Se o normalizer nao tratar o payload real do clique, o usuario aperta o botao e o fluxo quebra.

Mitigacao:

- implementar normalizer por provider
- guardar payload real de homologacao

### 4. Audio parar de funcionar

Se a PAPI nao usar o mesmo contrato de midia, a transcricao pode falhar silenciosamente.

Mitigacao:

- adapter de midia por provider

### 5. Monitor exibir dados errados

O painel hoje adivinha nome e status a partir do payload.

Mitigacao:

- registrar `provider` explicitamente nos logs
- padronizar `send_result`

## Estrategia de implementacao recomendada

### Fase 1 - Provider agnostico so para saida

Objetivo:

- manter dominio quase intacto
- permitir Evolution ou PAPI no envio

Passos:

1. criar DTO de saida
2. criar interface de sender
3. separar provider Evolution e PAPI
4. manter `MessageNormalizer` atual temporariamente

Bom para:

- comecar rapido
- trocar `send-text`

## Fase 2 - Webhook agnostico

Objetivo:

- permitir entrada por Evolution e PAPI

Passos:

1. criar resolver de provider por request
2. criar normalizers por provider
3. enriquecer `IncomingMessageDTO` com `provider`
4. alterar bootstrap do webhook para montar sender por request

## Fase 3 - Mixed mode completo

Objetivo:

- aceitar as duas APIs no mesmo endpoint com seguranca

Passos:

1. definir estrategia oficial de resolucao:
   - query param
   - token/header
   - fallback por payload
2. ajustar debounce e locks para `provider + phone`
3. ajustar logs e monitor para provider explicito

## Fase 4 - Respostas interativas

Objetivo:

- permitir texto ou botoes de forma nativa

Passos:

1. criar `AssistantReplyDTO`
2. adaptar `ConversationResultDTO`
3. implementar `send-buttons` na PAPI
4. manter fallback para texto em providers sem suporte
5. suportar clique de botao no normalizer

## Recomendacao tecnica final

Sim, vale muito a pena fazer o projeto ficar agnostico.

Mas eu recomendo pensar em duas dimensoes diferentes:

- agnostico de saida
- agnostico de entrada

Se voce fizer so a saida agnostica, ja consegue escolher Evolution ou PAPI para enviar.

Se voce quer que o mesmo webhook seja usado pelas duas APIs ao mesmo tempo, ai precisa dar o passo completo e tornar a entrada tambem agnostica, com resolver por request e normalizers separados.

## Recomendacao pratica para o seu caso

Para o cenario que voce descreveu, eu seguiria esta ordem:

1. criar arquitetura de sender por provider
2. criar resolver de provider no webhook
3. fazer `IncomingMessageDTO` carregar `provider`
4. responder sempre pelo mesmo provider da entrada
5. ajustar fila para `provider + phone`
6. ajustar logs/monitor para exibir provider
7. so depois subir botoes e respostas interativas

Essa e a forma mais segura de ficar agnostico sem quebrar o robo atual.
