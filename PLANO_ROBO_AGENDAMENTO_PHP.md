# Plano de Migracao do Robo de Agendamento para PHP

## 1. Objetivo

Migrar o fluxo atual do n8n para uma aplicacao PHP propria, mantendo o comportamento do atendimento do PSF02 e melhorando:

- controle do fluxo
- manutencao
- testes
- observabilidade
- rastreio de progresso da implementacao

O sistema final devera:

- receber mensagens do WhatsApp
- tratar texto, audio e imagem
- manter sessao por usuario
- executar fluxos de agendamento, consulta e cancelamento
- integrar com as APIs ja existentes de agenda
- responder o usuario via WhatsApp
- permitir acompanhamento claro do desenvolvimento

## 2. Escopo Funcional

### 2.1 Funcionalidades obrigatorias

- Webhook de entrada de mensagens
- Normalizacao de payload recebido
- Processamento de texto
- Processamento de audio com transcricao
- Processamento de imagem com leitura assistida por IA
- Memoria de sessao por numero
- Coleta e reaproveitamento de CPF, nome e telefone
- Fluxo de agendar medico
- Fluxo de agendar dentista
- Fluxo de agendar enfermeiro
- Fluxo de consultar agendamentos
- Fluxo de cancelar agendamento
- Bloqueio por status de ausente por servico
- Regras de confirmacao antes de cancelar
- Envio de mensagens de volta ao WhatsApp
- Logs tecnicos e funcionais

### 2.2 Fora do escopo da primeira entrega

- Painel administrativo
- Dashboard visual
- Reagendamento automatico
- Multiunidade
- Multiempresa
- Analise estatistica de conversas

## 3. Integracoes Mapeadas

As APIs existentes usadas pelo fluxo atual sao:

### 3.1 Consultas de agenda

- `dia_medica_livre.php`
- `dia_dentista_livre.php`
- `dia_enfermeira_livre.php`
- `horario_livre_dentista.php`
- `api_listar_agendamentos.php`

### 3.2 Cadastro de agendamentos

- `api_consulta_medica_psf2.php`
- `apiPostEnfermeiro.php`
- `api_agendar_dentista.php`

### 3.3 Cancelamento

- `api_cancelar_agendamento.php`

## 4. Arquitetura Proposta

```text
WhatsApp Webhook
  -> WebhookController
  -> MessageNormalizer
  -> Debounce/Queue
  -> SessionService
  -> Intent/Entity Extraction
  -> Conversation State Machine
  -> AgendaService
  -> ResponseFormatter
  -> WhatsAppService
  -> Logs
```

## 5. Estrutura Inicial do Projeto

```text
/public
  webhook.php

/src
  /Controller
  /Core
  /DTO
  /Domain
    /Agenda
    /Conversation
  /Infrastructure
    /Http
    /Persistence
  /Service

/config
/database
/storage/logs
/tests
```

## 6. Modelagem de Modulos

### 6.1 Entrada

Responsabilidades:

- receber webhook
- validar payload minimo
- identificar numero, tipo de mensagem, conteudo e midia
- registrar log bruto

Arquivos previstos:

- `WebhookController.php`
- `IncomingMessageDTO.php`

### 6.2 Normalizacao

Responsabilidades:

- unificar texto, audio e imagem em uma entrada textual
- padronizar campos como telefone, nome e tipo
- limpar caracteres problematicos

Arquivos previstos:

- `MessageNormalizer.php`
- `AudioTranscriptionService.php`
- `ImageReaderService.php`

### 6.3 Sessao e memoria

Responsabilidades:

- guardar CPF, nome, telefone
- guardar fluxo atual e etapa atual
- guardar data/hora escolhidas
- registrar ultimo contexto do usuario

Arquivos previstos:

- `SessionService.php`
- `SessionRepository.php`
- `SessionDTO.php`

### 6.4 Orquestrador

Responsabilidades:

- identificar intencao
- decidir proxima etapa
- impedir saltos invalidos
- aplicar regras de negocio

Arquivos previstos:

- `ConversationService.php`
- `StateMachine.php`
- `IntentDetector.php`
- `PromptBuilder.php`
- `ResponseFormatter.php`

### 6.5 Agenda

Responsabilidades:

- consultar datas livres
- consultar horario de dentista
- cadastrar agendamento
- consultar agendamentos por CPF
- cancelar agendamento
- validar bloqueio por ausente

Arquivos previstos:

- `AgendaService.php`
- `MedicoService.php`
- `DentistaService.php`
- `EnfermeiroService.php`
- `CancelamentoService.php`

### 6.6 Saida

Responsabilidades:

- enviar mensagem ao usuario
- aplicar pausas entre mensagens, se necessario
- centralizar integracao com WhatsApp/Evolution

Arquivos previstos:

- `WhatsAppService.php`

## 7. Banco de Dados

### 7.1 Tabela `sessions`

Finalidade:

- guardar o estado da conversa por numero

Campos sugeridos:

- `id`
- `phone`
- `cpf`
- `nome`
- `telefone`
- `current_flow`
- `current_step`
- `selected_service`
- `selected_date`
- `selected_time`
- `pending_action`
- `context_json`
- `last_interaction_at`
- `created_at`
- `updated_at`

### 7.2 Tabela `message_logs`

Finalidade:

- historico tecnico e funcional

Campos sugeridos:

- `id`
- `phone`
- `direction`
- `message_type`
- `raw_payload`
- `normalized_text`
- `created_at`

### 7.3 Tabela `message_queue`

Finalidade:

- juntar mensagens seguidas antes de processar

Campos sugeridos:

- `id`
- `phone`
- `message_text`
- `message_type`
- `processed`
- `created_at`

### 7.4 Tabela `integration_logs`

Finalidade:

- rastrear chamadas HTTP externas

Campos sugeridos:

- `id`
- `service`
- `endpoint`
- `request_payload`
- `response_payload`
- `status_code`
- `created_at`

## 8. Maquina de Estados

Estados sugeridos:

- `idle`
- `awaiting_menu_choice`
- `awaiting_cpf`
- `awaiting_name`
- `awaiting_phone`
- `awaiting_identity_confirmation`
- `awaiting_date_choice`
- `awaiting_time_choice`
- `awaiting_cancellation_choice`
- `awaiting_cancellation_confirmation`
- `completed`

Fluxos principais:

- `agendar_medico`
- `agendar_dentista`
- `agendar_enfermeiro`
- `consultar_agendamentos`
- `cancelar_agendamento`

## 9. Regras Criticas a Reproduzir

- Nunca inventar data ou horario
- Nunca responder horario sem retorno da API
- Nunca cancelar sem confirmacao textual
- Nunca pedir dados ja informados na mesma sessao
- Permitir dados enviados fora de ordem
- Bloquear apenas o servico com `bloquear_por_servico = true`
- Encaminhar assuntos fora do escopo para a recepcao
- Reiniciar o atendimento apos finalizacao

## 10. Estrategia de IA

A IA sera usada de forma controlada.

### 10.1 A IA pode

- detectar intencao
- extrair CPF, nome, telefone, data e hora citados pelo usuario
- transcrever audio
- ler imagem quando necessario

### 10.2 A IA nao deve

- consultar agenda por conta propria
- inventar datas ou horarios
- cancelar ou agendar sem validacao do PHP
- decidir regras de negocio sozinha

## 11. Plano de Execucao

## Fase 0. Levantamento e homologacao tecnica

Objetivo:

- confirmar formato real dos payloads e respostas externas

Entregaveis:

- mapa final de payload do WhatsApp
- mapa final das respostas das APIs de agenda
- lista de credenciais e variaveis de ambiente

Atividades:

- revisar payload real de entrada
- revisar autenticacao da API de envio de mensagens
- testar todos os endpoints de agenda manualmente
- documentar respostas esperadas

Criterios de aceite:

- saber exatamente quais campos chegam do WhatsApp
- saber exatamente quais campos retornam das APIs
- ter todos os endpoints e parametros confirmados

## Fase 1. Base da aplicacao

Objetivo:

- preparar a fundacao do projeto

Entregaveis:

- estrutura de pastas
- bootstrap da aplicacao
- `.env`
- configuracao HTTP
- logger

Atividades:

- criar `composer.json`
- configurar autoload PSR-4
- criar classe de configuracao
- criar cliente HTTP base
- criar logger

Criterios de aceite:

- projeto sobe localmente
- configuracoes sao lidas por ambiente
- logs sao gravados corretamente

## Fase 2. Webhook e parser de entrada

Objetivo:

- receber e registrar mensagens do WhatsApp

Entregaveis:

- endpoint `webhook.php`
- parser do payload
- gravacao de logs de entrada

Atividades:

- criar controller de webhook
- mapear `remoteJid`, mensagem, tipo e midia
- persistir payload bruto

Criterios de aceite:

- o endpoint recebe payload sem erro
- texto simples e registrado corretamente

## Fase 3. Sessao e persistencia

Objetivo:

- controlar o estado de cada conversa

Entregaveis:

- tabelas principais
- repositorios
- servico de sessao

Atividades:

- criar migrations SQL
- criar `SessionRepository`
- criar `SessionService`
- implementar leitura e atualizacao por telefone

Criterios de aceite:

- ao receber mensagem, a sessao do numero e criada ou atualizada
- o sistema recupera dados previamente informados

## Fase 4. Normalizacao de mensagens

Objetivo:

- unificar diferentes tipos de entrada em texto processavel

Entregaveis:

- normalizador de texto
- pipeline para audio
- pipeline para imagem
- limpeza de caracteres

Atividades:

- tratar texto puro
- baixar midia
- transcrever audio
- interpretar imagem

Criterios de aceite:

- texto, audio e imagem viram entrada textual utilizavel

## Fase 5. Fila e debounce

Objetivo:

- juntar mensagens consecutivas do mesmo usuario

Entregaveis:

- tabela de fila
- servico de enfileiramento
- worker ou rotina de consolidacao

Atividades:

- registrar mensagens pendentes
- aplicar janela de espera
- concatenar mensagens antes do processamento

Criterios de aceite:

- varias mensagens curtas do mesmo usuario viram uma entrada unica

## Fase 6. Integracao com agenda

Objetivo:

- encapsular toda comunicacao com a agenda externa

Entregaveis:

- servico unico de agenda
- metodos de consulta, cadastro e cancelamento

Atividades:

- criar `AgendaService`
- implementar chamadas dos endpoints
- padronizar respostas em DTOs internos

Criterios de aceite:

- consultas e cadastros funcionam de forma isolada
- respostas externas sao convertidas para formato interno padrao

## Fase 7. Motor de conversa

Objetivo:

- reproduzir as regras do fluxo do n8n com previsibilidade

Entregaveis:

- maquina de estados
- roteamento por intencao
- templates de resposta

Atividades:

- criar estados e transicoes
- aplicar regras de CPF, nome, telefone
- aplicar bloqueio por ausente
- aplicar confirmacoes obrigatorias

Criterios de aceite:

- o sistema percorre corretamente cada fluxo
- nao pede informacao duplicada
- nao executa acao sem confirmacao quando exigido

## Fase 8. IA controlada

Objetivo:

- usar IA apenas onde ela agrega sem comprometer o controle

Entregaveis:

- deteccao de intencao
- extracao de entidades
- leitura de audio/imagem integrada

Atividades:

- definir prompts tecnicos
- implementar camada de extracao estruturada
- validar fallbacks quando a IA nao tiver confianca

Criterios de aceite:

- a IA ajuda no entendimento, mas o PHP continua no controle do fluxo

## Fase 9. Saida e entrega ao WhatsApp

Objetivo:

- responder o usuario com seguranca

Entregaveis:

- servico de envio
- formatador de respostas
- controle de multiplas mensagens, se necessario

Atividades:

- integrar com endpoint de envio
- criar padroes de mensagem
- registrar logs de saida

Criterios de aceite:

- mensagens chegam ao usuario corretamente

## Fase 10. Testes, homologacao e go-live

Objetivo:

- validar o sistema antes de substituir o n8n

Entregaveis:

- testes automatizados
- roteiro de testes manuais
- checklist de producao

Atividades:

- testes unitarios
- testes de integracao
- testes E2E com payload real
- validacao de casos extremos

Criterios de aceite:

- fluxos criticos validados ponta a ponta
- erros conhecidos tratados com fallback

## 12. Estrategia de Testes

### 12.1 Testes unitarios

Cobrir:

- extracao de CPF, nome e telefone
- deteccao de intencao
- mudanca de estados
- formatacao de resposta
- bloqueio por ausente

Ferramenta sugerida:

- PHPUnit

### 12.2 Testes de integracao

Cobrir:

- chamadas aos endpoints externos
- persistencia de sessao
- processamento do webhook

### 12.3 Testes end-to-end

Cobrir:

- agendamento medico completo
- agendamento dentista completo
- consulta de agendamentos
- cancelamento completo
- audio
- imagem

### 12.4 Testes de regressao

Cobrir:

- usuario informa tudo em uma unica mensagem
- usuario manda mensagens separadas
- usuario responde fora de ordem
- usuario pede horario da tarde
- usuario agradece apos finalizacao
- usuario tenta assunto fora do escopo

## 13. Casos de Teste de Negocio

### 13.1 Agendar medico

- usuario escolhe medico
- informa CPF
- sistema busca dados
- confirma nome/telefone
- verifica ausente
- consulta datas
- usuario escolhe data
- sistema agenda
- sistema envia confirmacao

### 13.2 Agendar dentista

- usuario escolhe dentista
- recebe aviso de dor
- informa CPF
- sistema verifica ausente
- consulta datas
- usuario escolhe data
- sistema consulta horarios
- usuario escolhe horario
- sistema agenda

### 13.3 Cancelamento

- usuario informa CPF
- sistema lista agendamentos
- usuario escolhe ID
- sistema pede confirmacao
- usuario confirma
- sistema cancela

### 13.4 Bloqueio por ausente

- usuario quer um servico bloqueado
- sistema informa o bloqueio somente do servico correspondente
- sistema para completamente ate resposta do usuario

## 14. Riscos e Mitigacoes

### Risco 1. Formato do payload real divergir do JSON do n8n

Mitigacao:

- capturar payloads reais antes da implementacao definitiva

### Risco 2. APIs externas retornarem formatos inconsistentes

Mitigacao:

- criar adaptadores e validacao defensiva

### Risco 3. IA interpretar incorretamente uma intencao

Mitigacao:

- manter maquina de estados e validacoes em PHP

### Risco 4. Midia exigir fluxo especial de download

Mitigacao:

- testar audio e imagem com payload real antes da fase final

### Risco 5. Conversa ficar duplicada ou corrida

Mitigacao:

- implementar debounce e controle por sessao/lock

## 15. Criterios de Pronto por Etapa

Uma etapa so sera considerada concluida quando tiver:

- codigo implementado
- logs minimos
- teste unitario ou de integracao correspondente
- evidencias de teste manual quando aplicavel
- documentacao curta do que foi entregue

## 16. Quadro de Acompanhamento

Status padrao:

- `Nao iniciado`
- `Em andamento`
- `Bloqueado`
- `Concluido`

### Acompanhamento macro

| Etapa | Nome | Status | Responsavel | Dependencias | Observacoes |
|---|---|---|---|---|---|
| 0 | Levantamento tecnico | Nao iniciado | Codex | Nenhuma | Confirmar payloads reais |
| 1 | Base da aplicacao | Concluido | Codex | 0 | Composer, config, logs criados e validados |
| 2 | Webhook e parser | Concluido | Codex | 1 | Entrada funcionando com parser, validacao e logs |
| 3 | Sessao e persistencia | Concluido | Codex | 1, 2 | Persistencia SQL ativa no MySQL com fallback seguro para JSON |
| 4 | Normalizacao de mensagens | Nao iniciado | Codex | 2, 3 | Texto, audio, imagem |
| 5 | Fila e debounce | Nao iniciado | Codex | 2, 3 | Junta mensagens |
| 6 | Integracao com agenda | Em andamento | Codex | 1 | Tool registry pronto com mock local e cliente HTTP real preparado |
| 7 | Motor de conversa | Em andamento | Codex | 3, 6 | Fluxos iniciais de agendamento e cancelamento funcionando |
| 8 | IA controlada | Em andamento | Codex | 4, 7 | OpenAI integrada com fallback local resiliente |
| 9 | Saida WhatsApp | Em andamento | Codex | 2, 7 | Evolution preparada com envio opcional por configuracao |
| 10 | Testes e homologacao | Nao iniciado | Codex | 4 a 9 | Validacao ponta a ponta |

### Checklist operacional por etapa

#### Etapa 0. Levantamento tecnico

- [ ] Confirmar payload real do WhatsApp
- [ ] Confirmar metodo de autenticacao para envio
- [ ] Testar manualmente todas as APIs de agenda
- [ ] Documentar respostas e erros esperados

#### Etapa 1. Base da aplicacao

- [x] Criar `composer.json`
- [x] Configurar autoload
- [x] Criar `public/webhook.php`
- [x] Criar config `.env`
- [x] Criar logger
- [x] Criar cliente HTTP

#### Etapa 2. Webhook e parser

- [x] Receber payload POST
- [x] Validar campos minimos
- [x] Extrair numero e tipo de mensagem
- [x] Registrar log de entrada

#### Etapa 3. Sessao e persistencia

- [x] Criar SQL das tabelas
- [x] Implementar repositorios
- [x] Criar servico de sessao
- [x] Recuperar dados por numero
- [x] Preparar camada PDO com fallback seguro
- [x] Ativar banco real e validar gravacao de sessao/log

#### Etapa 4. Normalizacao

- [ ] Tratar texto puro
- [ ] Baixar audio
- [ ] Transcrever audio
- [ ] Baixar imagem
- [ ] Interpretar imagem

#### Etapa 5. Debounce

- [ ] Enfileirar mensagens
- [ ] Aplicar janela de espera
- [ ] Consolidar mensagens
- [ ] Marcar processadas

#### Etapa 6. Agenda

- [x] Implementar consulta de medico
- [x] Implementar consulta de enfermeiro
- [x] Implementar consulta de dentista
- [x] Implementar horario dentista
- [x] Implementar cadastro medico
- [x] Implementar cadastro enfermeiro
- [x] Implementar cadastro dentista
- [x] Implementar listar agendamentos
- [x] Implementar cancelamento

#### Etapa 7. Motor de conversa

- [x] Menu inicial
- [x] Coleta de CPF
- [x] Confirmacao de nome e telefone
- [x] Bloqueio por ausente
- [x] Fluxo medico
- [x] Fluxo enfermeiro
- [x] Fluxo dentista
- [x] Fluxo consulta
- [x] Fluxo cancelamento

#### Etapa 8. IA controlada

- [x] Detectar intencao
- [x] Extrair entidades
- [x] Integrar OpenAI real
- [ ] Tratar baixa confianca

#### Etapa 9. Saida

- [x] Enviar texto
- [x] Registrar resposta enviada
- [x] Tratar falha de envio

#### Etapa 10. Testes

- [ ] Criar testes unitarios
- [ ] Criar testes de integracao
- [ ] Validar cenarios E2E
- [ ] Executar checklist de homologacao

## 17. Definicao de Progresso

Para sabermos "onde estamos" em qualquer momento, vamos usar esta regra:

- `0%`: nada iniciado
- `25%`: estrutura da etapa pronta
- `50%`: funcionalidade principal implementada
- `75%`: testes principais passando
- `100%`: etapa validada e documentada

## 18. Modo de Trabalho Recomendado

Seguiremos por etapas curtas.

Em cada etapa:

1. implementar
2. testar
3. atualizar o status no plano
4. validar o que falta
5. iniciar a proxima etapa

## 19. Proximo Passo Recomendado

Comecar pela `Etapa 0` e `Etapa 1`, porque elas preparam o projeto inteiro e eliminam risco cedo.

Ordem imediata sugerida:

1. confirmar payloads reais e variaveis de ambiente
2. criar base do projeto PHP
3. criar webhook funcional
4. criar persistencia de sessao
