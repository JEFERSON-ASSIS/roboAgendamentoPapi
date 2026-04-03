# Migracao para a API PAPI WhatsApp

Documento criado a partir da analise do codigo atual deste projeto e dos endpoints informados da PAPI:

- `POST /api/instances/:id/send-text`
- `POST /api/instances/:id/send-buttons`
- Docs: <https://papi.i7ai.com.br/docs.html>

## Objetivo

Levantar o que precisa mudar para o robo passar a enviar mensagens pela PAPI, com possibilidade de escolher entre:

- texto simples
- mensagem com botoes

## Resumo executivo

Hoje o projeto esta acoplado ao padrao da Evolution para envio. O ponto central disso fica em `src/Service/WhatsAppService.php`, que chama:

- `/chat/sendPresence/{instance}`
- `/message/sendText/{instance}`

Para comecar a usar a PAPI, o minimo necessario no envio e:

1. trocar o endpoint de envio de texto para `POST /api/instances/:id/send-text`
2. converter o numero atual para o formato `jid`
3. adaptar a validacao de sucesso e erro para o retorno da PAPI
4. decidir o que fazer com o recurso de "digitando" (`sendPresence`), porque ele hoje esta no fluxo e nao foi mapeado nos trechos da PAPI enviados
5. preparar uma camada para escolher entre envio de texto e envio com botoes

Se a mudanca envolver tambem os webhooks de entrada da PAPI, o impacto sobe bastante, porque o parser atual foi escrito pensando no payload da Evolution.

## Onde o projeto esta acoplado hoje

### 1. Servico de envio

Arquivo principal: `src/Service/WhatsAppService.php`

Dependencias atuais:

- exige `base_url`, `instance` e `api_key`
- envia texto por `/message/sendText/{instance}`
- envia "digitando" por `/chat/sendPresence/{instance}`
- envia o numero no campo `number`
- divide mensagens grandes em partes

Impacto:

- a PAPI usa outro path
- a PAPI usa `jid` em vez de `number`
- o retorno esperado muda

### 2. Configuracao

Arquivo: `config/services.php`

Ja existe a chave `WHATSAPP_PROVIDER`, mas hoje ela nao altera o comportamento do envio. Na pratica, o sistema continua assumindo o padrao da Evolution.

Isso e bom para a migracao, porque da para aproveitar essa configuracao e finalmente fazer a selecao real do provider.

### 3. Fluxo automatico do webhook

Arquivo: `src/Controller/WebhookController.php`

O robo chama `sendText()` em pelo menos estes cenarios:

- resposta normal do assistente
- fallback quando chega audio e nao da para transcrever

Ou seja: mudar o contrato do envio impacta o fluxo automatico inteiro.

### 4. Envio manual no painel

Arquivo: `public/message_monitor_api.php`

O painel de monitoramento tambem usa o mesmo `WhatsAppService`. Entao, ao migrar o servico, o envio manual acompanha a mudanca.

Se quisermos permitir escolher "texto" ou "botoes" manualmente, esse arquivo tambem precisa mudar.

### 5. Testes

Arquivo: `tests/run.php`

Ja existem testes verificando:

- divisao de mensagens
- chamada de presence
- URL `/message/sendText/...`

Eles vao precisar ser ajustados para o contrato novo ou separados por provider.

## Diferencas principais entre o envio atual e a PAPI

| Tema | Hoje no projeto | PAPI |
| --- | --- | --- |
| Endpoint de texto | `/message/sendText/{instance}` | `/api/instances/:id/send-text` |
| Numero destino | `number` com digitos | `jid` no formato `5511999999999@s.whatsapp.net` |
| Presence | `/chat/sendPresence/{instance}` | nao confirmado nos trechos enviados |
| Sucesso | qualquer 2xx | JSON com `success: true` e metadados |
| Erro conhecido | excecao generica por HTTP | erro de numero nao registrado com payload especifico |

## Mudancas minimas para comecar a usar a PAPI

### 1. Padronizar telefone para `jid`

Hoje o sistema trabalha com telefone so em digitos, por exemplo:

- `5566999999999`

Para a PAPI, o envio precisa virar:

- `5566999999999@s.whatsapp.net`

Recomendacao:

- criar um helper unico para converter telefone em `jid`
- usar esse helper tanto no envio automatico quanto no envio manual

Exemplo de regra:

```php
private function toJid(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?: '';

    if ($digits === '') {
        throw new RuntimeException('Telefone invalido para envio.');
    }

    return $digits . '@s.whatsapp.net';
}
```

### 2. Adaptar o envio de texto

Hoje o payload enviado e equivalente a:

```json
{
  "number": "5566999999999",
  "text": "Mensagem"
}
```

Na PAPI, precisa ficar equivalente a:

```json
{
  "jid": "5566999999999@s.whatsapp.net",
  "text": "Mensagem",
  "validateNumber": true
}
```

Pontos importantes:

- decidir se `validateNumber` vai ficar fixo como `true` ou configuravel por ambiente
- adaptar a leitura do retorno para verificar `success`
- tratar o erro de numero nao registrado sem quebrar o fluxo inteiro

### 3. Rever o recurso de "digitando"

O projeto hoje pode mandar `sendPresence` antes do texto.

Como esse endpoint nao apareceu no material da PAPI enviado, a recomendacao inicial e:

- manter `WHATSAPP_TYPING_ENABLED=false` durante a migracao
- procurar depois se a PAPI tem endpoint equivalente

Se nao tiver, a migracao deve ignorar essa etapa no provider PAPI.

### 4. Aproveitar `WHATSAPP_PROVIDER`

A melhor forma de migrar sem quebrar tudo e parar de tratar "WhatsApp" como um provider unico.

Recomendacao:

- criar uma interface de envio
- manter uma implementacao para Evolution
- criar uma implementacao nova para PAPI
- escolher a implementacao com base em `WHATSAPP_PROVIDER`

Exemplo de desenho:

```php
interface WhatsAppSenderInterface
{
    public function isEnabled(): bool;
    public function sendText(string $phone, string $text): array;
    public function sendButtons(string $phone, string $text, array $buttons, ?string $footer = null): array;
}
```

Estrutura sugerida:

- `src/Service/WhatsApp/EvolutionWhatsAppService.php`
- `src/Service/WhatsApp/PapiWhatsAppService.php`
- `src/Service/WhatsApp/WhatsAppServiceFactory.php`

Isso reduz risco porque permite migrar por ambiente.

### 5. Ajustar os testes

No minimo, precisamos cobrir:

- telefone para `jid`
- envio de texto pela rota nova
- tratamento de `success: true`
- tratamento de numero nao registrado
- comportamento quando o typing estiver desligado no provider PAPI

## E possivel escolher entre texto ou botao?

Sim. Tecnicamente e viavel e faz sentido preparar isso agora.

A recomendacao e nao forcar tudo em `sendText()`. O ideal e subir um nivel da API interna do projeto e trabalhar com "tipo de mensagem".

Exemplo de contrato interno:

```php
[
  'type' => 'text',
  'text' => 'Ola, tudo bem?'
]
```

ou

```php
[
  'type' => 'buttons',
  'text' => 'Escolha uma opcao:',
  'footer' => 'Atendimento automatico',
  'buttons' => [
    ['type' => 'quick_reply', 'displayText' => 'Agendar', 'id' => 'agendar'],
    ['type' => 'quick_reply', 'displayText' => 'Cancelar', 'id' => 'cancelar']
  ]
]
```

## Regras importantes para botoes

Com base no material enviado da PAPI:

- `quick_reply` funciona no Web se enviar apenas quick replies
- CTAs (`cta_copy`, `cta_url`, `cta_call`) podem coexistir entre si
- nao misturar `quick_reply` com CTA se a compatibilidade com WhatsApp Web for importante
- quick reply deve respeitar o limite maximo de 3 botoes

Entao o projeto precisa de uma validacao antes de chamar a PAPI.

Regra sugerida:

1. se houver qualquer `quick_reply`, todos os botoes devem ser `quick_reply`
2. se houver CTA, nao permitir `quick_reply` junto
3. limitar `quick_reply` a 3 itens

## O que precisa mudar para realmente suportar botoes

### Envio

Criar um metodo novo no provider PAPI para:

- `POST /api/instances/:id/send-buttons`

Payload esperado:

```json
{
  "jid": "5566999999999@s.whatsapp.net",
  "text": "Escolha uma opcao:",
  "footer": "Atendimento automatico",
  "buttons": [
    { "type": "quick_reply", "displayText": "Sim", "id": "btn_sim" },
    { "type": "quick_reply", "displayText": "Nao", "id": "btn_nao" }
  ]
}
```

### Painel manual

`public/message_monitor_api.php` e a UI do monitor precisariam ganhar:

- seletor de tipo: `texto` ou `botoes`
- campos dinamicos para `footer`
- editor de lista de botoes
- validacao de combinacao permitida

### Fluxo automatico do robo

Se o robo for mandar botoes automaticamente, o `ConversationService` ou o orquestrador precisa deixar de retornar apenas string de resposta.

Hoje a resposta do assistente e, na pratica, tratada como texto. Para enviar botoes, a resposta precisa aceitar um payload estruturado.

Exemplo:

```php
[
  'reply_type' => 'buttons',
  'reply_text' => 'Escolha uma opcao:',
  'reply_footer' => 'Atendimento automatico',
  'reply_buttons' => [
    ['type' => 'quick_reply', 'displayText' => 'Agendar', 'id' => 'agendar'],
    ['type' => 'quick_reply', 'displayText' => 'Remarcar', 'id' => 'remarcar']
  ]
]
```

## Ponto muito importante: resposta de botoes no webhook

Aqui esta o maior cuidado da migracao.

O parser atual de entrada em `src/Service/MessageNormalizer.php` reconhece:

- `conversation`
- `extendedTextMessage`
- `reactionMessage`
- `audioMessage`
- `imageMessage`
- `videoMessage`
- `documentMessage`
- `stickerMessage`

Ele nao trata explicitamente respostas de botoes, como acontece em muitos payloads de WhatsApp.

Entao, para "enviar botoes" e realmente usar a interacao completa, precisamos confirmar na PAPI como chega a resposta do usuario quando ele toca no botao.

Se vier algo como:

- `buttonsResponseMessage`
- `templateButtonReplyMessage`
- `interactiveResponseMessage`
- outro formato proprio da PAPI

o `MessageNormalizer` precisara ser ampliado.

Sem isso, pode acontecer o seguinte:

- o botao aparece e o usuario clica
- o webhook chega
- o sistema nao entende o tipo corretamente
- o fluxo conversacional falha ou cai como `unknown`

## Se a mudanca for so no envio, o escopo e menor

Se neste primeiro passo a ideia for apenas:

- manter o webhook atual como esta
- trocar apenas a rota de saida

entao o trabalho inicial fica mais simples:

1. criar provider PAPI para envio
2. ajustar `jid`
3. desligar typing no provider PAPI
4. homologar envio manual e envio automatico

Essa e a melhor estrategia para comecar.

## Outros pontos que podem ser afetados

### Audio e download de midia

`src/Service/AudioTranscriptionService.php` usa `WHATSAPP_BASE_URL` e `WHATSAPP_API_KEY` para baixar midia quando recebe `mediaUrl`.

Se a PAPI mudar:

- a forma de autenticar download
- o formato da `mediaUrl`
- o payload do webhook de audio

esse servico tambem precisara ser ajustado.

### Logs e monitoramento

Os logs de envio hoje guardam a resposta bruta do provider. Como o formato da PAPI muda, e bom revisar:

- mensagens de erro exibidas no painel
- criterio de status enviado/falhou
- leitura do retorno em `send_result`

## Proposta de implementacao em fases

### Fase 1 - Troca segura do envio de texto

Objetivo:

- fazer texto simples funcionar pela PAPI sem mexer ainda no comportamento conversacional

Passos:

1. criar provider PAPI
2. converter telefone para `jid`
3. usar `/api/instances/:id/send-text`
4. desligar `typing` para PAPI
5. ajustar testes
6. homologar pelo painel manual e pelo webhook automatico

### Fase 2 - Habilitar envio de botoes

Objetivo:

- permitir escolher entre texto e botoes

Passos:

1. criar metodo `sendButtons`
2. validar combinacoes de botoes
3. liberar no painel manual
4. ajustar logs do retorno

### Fase 3 - Suportar clique nos botoes no fluxo do robo

Objetivo:

- tratar corretamente a resposta do usuario

Passos:

1. capturar payload real de clique vindo da PAPI
2. atualizar `MessageNormalizer`
3. mapear o clique para intencao/acao no fluxo
4. criar testes com payload real

## Pendencias para confirmar na documentacao da PAPI

Antes da implementacao final, ainda vale confirmar no docs:

- nome exato do header de autenticacao
- se `:id` e o mesmo valor que hoje chamamos de `instance`
- formato exato da resposta de `send-buttons`
- se existe endpoint equivalente a `sendPresence`
- formato do webhook de entrada para texto, audio e clique em botao
- como funciona download de midia protegida

## Recomendacao final

A melhor forma de comecar e esta:

1. migrar primeiro apenas o envio de texto para a PAPI
2. fazer isso usando provider separado, sem apagar a logica atual
3. depois adicionar suporte a botoes
4. so entao adaptar o webhook para interpretar resposta de botoes com base em payload real

Essa ordem reduz risco, permite homologacao por etapas e aproveita a configuracao `WHATSAPP_PROVIDER` que o projeto ja possui.
