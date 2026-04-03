# Documento Tecnico - Replicacao do Robo para PSF01 e PSF03

## Objetivo
Este documento descreve apenas o necessario para replicar o robo de agendamento atual, hoje configurado para o PSF02, em outras unidades como PSF01 e PSF03.

## Conclusao importante
No estado atual, este projeto deve ser replicado com **uma instalacao por unidade**.

Nao e seguro colocar PSF01, PSF02 e PSF03 funcionando juntos na mesma instancia sem refatoracao, porque hoje o sistema trabalha com:
- uma unica configuracao ativa de WhatsApp por execucao
- uma unica configuracao ativa de agenda por execucao
- sessoes identificadas apenas por `phone`
- logs do monitor identificados apenas por `phone`

Em outras palavras: o projeto ja aceita `WHATSAPP_PROFILE=psf01|psf02|psf03`, mas isso serve para escolher **um perfil por deploy**, nao para operar varias unidades ao mesmo tempo na mesma execucao.

## Modelo recomendado de replicacao
Criar uma publicacao separada para cada unidade.

Exemplo:
- PSF01: `C:/xampp/htdocs/producao/roboAgendamento-psf01`
- PSF02: projeto atual
- PSF03: `C:/xampp/htdocs/producao/roboAgendamento-psf03`

Cada unidade deve ter:
- propria URL
- proprio `.env`
- propria configuracao de WhatsApp
- propria configuracao de agenda
- preferencialmente proprio banco ou, no minimo, tabelas isoladas por base separada
- proprio token administrativo

## Alteracoes obrigatorias por unidade

### 1. Infra e URL da unidade
Para cada unidade, definir:
- dominio ou subdominio proprio do robo
- webhook proprio apontando para a URL publicada da unidade
- painel de monitor proprio da unidade

Campos a alterar no `.env`:
- `APP_NAME`
- `APP_URL`
- `CHAT_URL` se houver front separado por unidade

## 2. Configuracao do WhatsApp da unidade
Cada unidade precisa usar sua propria instancia Evolution e sua propria chave.

Campos obrigatorios no `.env`:
- `WHATSAPP_PROFILE`
- `WHATSAPP_BASE_URL`
- `WHATSAPP_INSTANCE`
- `WHATSAPP_API_KEY`

Ou, seguindo o modelo ja existente:
- `WHATSAPP_PROFILE=psf01` ou `psf03`
- `WHATSAPP_INSTANCE_PSF01` / `WHATSAPP_API_KEY_PSF01`
- `WHATSAPP_INSTANCE_PSF03` / `WHATSAPP_API_KEY_PSF03`

Observacao:
- o sistema resolve a instancia e a chave a partir de `WHATSAPP_PROFILE`
- isso funciona para escolher um perfil por deploy
- isso nao transforma a aplicacao em multiunidade simultanea

## 3. Configuracao da agenda da unidade
Cada unidade precisa apontar para sua API e empresa corretas.

Campos obrigatorios no `.env`:
- `AGENDA_BASE_URL`
- `AGENDA_EMPRESA`

Validar para cada unidade:
- URL base da API da agenda
- codigo da empresa da unidade
- compatibilidade dos endpoints usados pelo robo

## 4. Banco e isolamento de dados
Recomendacao obrigatoria para replicacao segura:
- usar um banco separado por unidade

Campos a definir por unidade:
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- ou o conjunto `DB_LOCAL_*` / `DB_SERVER_*`, conforme o ambiente

Motivo:
- a tabela `sessions` usa `phone` como unico identificador unico
- a tabela `message_logs` nao tem coluna de unidade
- a tabela `message_monitor_conversations` tambem nao tem coluna de unidade

Se duas unidades compartilharem a mesma base hoje, o mesmo telefone pode misturar:
- contexto da conversa
- historico do monitor
- status de lida/nao lida

## 5. Sessao em fallback JSON
Se o banco cair e o sistema usar fallback JSON, cada unidade precisa ter arquivo proprio.

Campo a definir por unidade:
- `SESSION_STORE_PATH`

Exemplo:
- PSF01: `storage/data/sessions-psf01.json`
- PSF03: `storage/data/sessions-psf03.json`

## 6. Tokens e acesso administrativo
Gerar tokens proprios para cada unidade.

Campos obrigatorios:
- `INTEGRATION_DEBUG_TOKEN`
- `SESSION_ADMIN_TOKEN`

## 7. Textos e identidade da unidade
Hoje ainda existem textos fixos do PSF02 dentro do codigo. Para replicar corretamente, isso precisa ser adaptado.

Itens que precisam virar configuracao por unidade:
- nome da unidade
- sigla da unidade
- telefone da recepcao
- nome exibido nas mensagens iniciais e finais

Hoje existem referencias fixas em codigo para:
- `UBS Vida Nova (PSF02)`
- `PSF02`
- telefone da recepcao `(66) 9 9204-0540`

## 8. Prompts de IA por unidade
Os prompts da IA ainda citam explicitamente o PSF02.

Isso precisa ser ajustado para cada unidade nos componentes que interpretam e recuperam fluxo.

## Alteracoes de codigo obrigatorias antes de subir PSF01 e PSF03

### A. Tornar a identidade da unidade configuravel
Criar configuracao por `.env` para:
- nome da unidade
- sigla da unidade
- telefone da recepcao

Sugestao de novas variaveis:
- `UNIT_NAME`
- `UNIT_CODE`
- `UNIT_RECEPTION_PHONE`

## B. Substituir hardcodes atuais
Arquivos com alteracao obrigatoria:
- `config/app.php`
- `src/Domain/Conversation/AiOrchestrator.php`
- `src/Domain/Conversation/OpenAiConversationInterpreter.php`
- `src/Domain/Conversation/OpenAiFlowRecoveryService.php`

O que retirar do hardcode:
- `Robo Agendamento PSF02`
- `UBS Vida Nova (PSF02)`
- `PSF02`
- `(66) 9 9204-0540`

## C. Revisar endpoint medico fixo do PSF02
Hoje o agendamento medico usa endpoint fixo com nome do PSF02.

Arquivo com alteracao obrigatoria:
- `src/Domain/Agenda/AgendaService.php`

Ponto critico:
- existe uso explicito de `/api_consulta_medica_psf2.php`

Antes de replicar para PSF01 e PSF03, e necessario confirmar se:
- cada unidade possui endpoint medico diferente
- ou se existe um endpoint unico que muda apenas por `empresa`

Se o endpoint mudar por unidade, isso tambem precisa virar configuracao no `.env` ou em `config/services.php`.

## Checklist minimo para subir uma nova unidade

### PSF01
1. Duplicar o projeto em pasta propria.
2. Criar `.env` proprio do PSF01.
3. Definir `WHATSAPP_PROFILE=psf01`.
4. Configurar `WHATSAPP_BASE_URL`, `WHATSAPP_INSTANCE_PSF01` e `WHATSAPP_API_KEY_PSF01`.
5. Configurar `AGENDA_BASE_URL` e `AGENDA_EMPRESA` do PSF01.
6. Apontar webhook da Evolution do PSF01 para a URL do novo deploy.
7. Usar banco proprio do PSF01.
8. Usar `SESSION_STORE_PATH` proprio do PSF01.
9. Gerar novos tokens administrativos.
10. Ajustar textos da unidade e prompts para PSF01.
11. Validar o endpoint medico do PSF01.

### PSF03
1. Duplicar o projeto em pasta propria.
2. Criar `.env` proprio do PSF03.
3. Definir `WHATSAPP_PROFILE=psf03`.
4. Configurar `WHATSAPP_BASE_URL`, `WHATSAPP_INSTANCE_PSF03` e `WHATSAPP_API_KEY_PSF03`.
5. Configurar `AGENDA_BASE_URL` e `AGENDA_EMPRESA` do PSF03.
6. Apontar webhook da Evolution do PSF03 para a URL do novo deploy.
7. Usar banco proprio do PSF03.
8. Usar `SESSION_STORE_PATH` proprio do PSF03.
9. Gerar novos tokens administrativos.
10. Ajustar textos da unidade e prompts para PSF03.
11. Validar o endpoint medico do PSF03.

## O que nao e necessario alterar se a replicacao for por unidade separada
Nao e necessario mexer agora em:
- estrutura das tabelas, se cada unidade tiver banco proprio
- logica principal de fluxo de conversa
- monitor de mensagens, desde que cada unidade tenha sua propria base
- selecao de perfil WhatsApp ja existente em `config/services.php`

## O que precisaria mudar se voce quisesse uma unica instalacao para varias unidades
Esse cenario nao e o recomendado agora. Para suportar multiunidade real na mesma instalacao, seria necessario no minimo:
- identificar a unidade em cada webhook recebido
- salvar unidade em sessoes e logs
- mudar chave de sessao de `phone` para `unit + phone`
- mudar monitor para filtrar por unidade
- escolher instancia de envio dinamicamente por mensagem
- escolher agenda dinamicamente por unidade
- remover todos os textos fixos do PSF02

## Resumo executivo
Para replicar em PSF01 e PSF03 com menor risco, o caminho correto e:
- uma copia do projeto por unidade
- um `.env` por unidade
- um banco por unidade
- uma instancia WhatsApp por unidade
- uma agenda por unidade
- ajuste dos hardcodes de identidade da unidade
- validacao do endpoint medico especifico da unidade