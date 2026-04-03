# Analise: triagem por casos de uso da UBS antes do agendamento medico

## 1. Objetivo desta analise

Avaliacao tecnica de como encaixar no robo atual o seguinte comportamento:

1. Quando o usuario pedir `agendar medico`, o robo lista opcoes numeradas com casos de uso da UBS.
2. O usuario escolhe uma opcao.
3. Se a opcao estiver dentro do escopo aceito, o robo continua o agendamento.
4. Se estiver fora desse escopo, o robo orienta a falar com a recepcao.

Base usada nesta analise:

- imagem enviada com lista `UBS x UPA`
- implementacao atual do robo em PHP
- regras de restricao existentes
- testes automatizados atuais

## 2. Regra de negocio desejada para a triagem

A dinamica desejada nao e usar toda a imagem diretamente como destino do fluxo.

A regra desejada ficou assim:

### 2.1 Quando o usuario escolher consulta medica

O robo deve perguntar qual e a necessidade, listando estes itens:

1. consultas de rotina
2. exames laboratoriais
3. infeccoes
4. troca de sondas
5. vacinas
6. medicao de pressao arterial
7. diarreia e dores leves
8. Outros atendimentos

### 2.2 Se o usuario escolher `Outros atendimentos`

O robo deve perguntar:

`Qual seria sua necessidade?`

A partir da resposta livre do usuario, o sistema deve classificar em uma destas tres saidas:

- segue com agendamento medico
- orienta comparecimento no PSF sem agendamento
- orienta procurar Pronto Atendimento (PA)

### 2.3 Se o usuario informar algo de urgencia

Se a necessidade informada for algo como:

- parada cardiaca
- crise convulsiva
- AVC
- infarto / dor no peito
- reacao alergica grave
- falta de ar intensa
- acidentes graves
- ferimentos graves
- fraturas
- queimaduras graves
- febre acima de 39

O robo deve informar que o usuario deve se encaminhar ao `Pronto Atendimento (PA)`, porque nao e situacao para agendamento no PSF.

### 2.4 Se o usuario informar algo que nao precisa agendamento

Se a necessidade for algo como:

- renovacao de receitas
- aplicacao de medicacao de uso continuo
- curativos
- retirada de pontos
- tratamento de unha encravada

O robo deve orientar que `nao precisa de agendamento` e que basta comparecer no PSF entre `07:00` as `11:00` e `13:00` as `17:00`;, com atendimento conforme a disponibilidade do local.

### 2.5 Objetivo da dinamica

Essa dinamica cria uma triagem antes de abrir o fluxo de agendamento, para evitar:

- agendar casos que deveriam ir ao PA
- agendar casos que podem ser resolvidos presencialmente sem agenda
- usar agenda medica para demandas fora da proposta do fluxo

## 3. Como o robo funciona hoje

Hoje o robo trabalha por `tipo de servico`, nao por `motivo clinico`.

Os fluxos nativos sao:

- agendar medico
- agendar dentista
- agendar enfermeiro
- consultar agendamentos
- cancelar agendamento

Pontos tecnicos ja prontos:

- menu numerado inicial ja existe em [AiOrchestrator.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\AiOrchestrator.php#L1172)
- mapeamento de respostas `1, 2, 3, 4, 5` ja existe em [AiOrchestrator.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\AiOrchestrator.php#L1712)
- sessao ja guarda estado e contexto livre, o que facilita criar uma nova etapa de triagem em [SessionDTO.php](C:\xampp\htdocs\producao\roboAgendamento\src\DTO\SessionDTO.php#L5) e na tabela [001_create_sessions.sql](C:\xampp\htdocs\producao\roboAgendamento\database\migrations\001_create_sessions.sql#L1)
- existe mecanismo de regras de restricao por texto em [RestrictionRuleService.php](C:\xampp\htdocs\producao\roboAgendamento\src\Service\RestrictionRuleService.php#L7) e [006_create_restriction_rules.sql](C:\xampp\htdocs\producao\roboAgendamento\database\migrations\006_create_restriction_rules.sql#L1)
- existe cobertura de testes para menu, restricoes e conversa em [tests/run.php](C:\xampp\htdocs\producao\roboAgendamento\tests\run.php)

Conclusao tecnica: o robo ja tem a estrutura certa para receber uma etapa extra antes do CPF. Isso reduz bastante a dificuldade.

## 4. O principal cuidado de negocio

Aqui esta o ponto mais importante da analise:

A imagem fala de `onde procurar atendimento`, mas o robo atual faz `agendamento por profissional`.

Essas duas coisas nao sao iguais.

Exemplos:

- `renovacao de receitas` pode fazer sentido para medico
- `consulta de rotina` pode fazer sentido para medico
- `infeccoes` pode fazer sentido para medico
- `diarreia e dores leves` pode fazer sentido para medico
- `vacinas` normalmente nao deveria cair em `agendar medico`
- `curativos` normalmente tende mais a enfermagem/procedimento
- `retirada de pontos` normalmente tende mais a enfermagem/procedimento
- `troca de sondas` normalmente tende mais a enfermagem/procedimento
- `medicao de pressao arterial` normalmente nao deveria abrir agenda medica
- `aplicacao de medicacao de uso continuo` pode ser procedimento, nao consulta medica

Ou seja:

Se todas as opcoes da coluna UBS forem colocadas dentro de `agendar medico`, existe risco real de encaminhar para agenda medica casos que nao deveriam ir para medico.

## 5. Dificuldade real no sistema atual

## Classificacao resumida

### Cenario A: dinamica especifica pedida para consulta medica

Quando o usuario escolher `Consulta medica`, o robo mostra a lista:

1. consultas de rotina
2. exames laboratoriais
3. infeccoes
4. troca de sondas
5. vacinas
6. medicao de pressao arterial
7. diarreia e dores leves
8. Outros atendimentos

Regra:

- se escolher um item da lista, o sistema aplica a regra definida para aquele item
- se escolher `Outros atendimentos`, o robo pergunta `Qual seria sua necessidade?`
- com base na resposta, o robo classifica em `agenda`, `comparecimento sem agendamento` ou `PA`

Dificuldade: **media**

Estimativa: **6 a 12 horas** de implementacao + testes

Por que continua viavel:

- quase nao mexe nas integracoes
- nao exige novas APIs
- aproveita o menu numerado e o controle de etapa que ja existem
- a triagem pode ficar toda no `AiOrchestrator`

Por que ficou um pouco mais complexa que a versao enxuta:

- agora existe classificacao em tres destinos, e nao apenas `agenda x recepcao`
- a opcao `Outros atendimentos` exige leitura de texto livre
- sera preciso definir regra clara para cada item listado

### Cenario B: mesma dinamica, mas com classificacao semantica mais ampla em texto livre

Regra:

- alem das opcoes numeradas, o sistema tenta entender variacoes de linguagem do usuario
- por exemplo, reconhecer `tontura forte`, `dor no peito`, `falta de ar`, `tirar pontos`, `pegar receita`, `trocar sonda`

Dificuldade: **media para alta**

Estimativa: **1 a 2 dias**

Por que sobe a dificuldade:

- a opcao `Outros atendimentos` deixa de ser apenas menu e passa a depender de interpretacao textual
- sera preciso tratar sinonimos e erros de digitacao
- o risco de classificacao errada aumenta se a regra nao for bem fechada

### Cenario C: triagem completa por motivo, com roteamento por destino

Regra:

- o robo pergunta o motivo
- dependendo da resposta, ele manda para:
- medico
- enfermeiro
- dentista
- recepcao
- orientacao urgente / PA

Dificuldade: **media para alta**

Estimativa: **2 a 5 dias**

Por que e o desenho mais forte:

- fica alinhado com a logica real da imagem
- reduz encaminhamento errado para medico
- permite separar melhor o que e consulta, procedimento e urgencia

Mas exige:

- definicao de matriz de roteamento
- ajustes de conversa
- mais testes
- possivelmente aprovacao operacional da unidade

## 6. Onde a mudanca entraria no codigo

### Arquivo principal

[AiOrchestrator.php](C:\xampp\htdocs\producao\roboAgendamento\src\Domain\Conversation\AiOrchestrator.php)

Pontos mais provaveis de alteracao:

- `startSchedulingFlow()` para inserir uma etapa antes do CPF quando o servico for `medico`
- `handleScheduling()` para tratar uma nova etapa como `awaiting_medical_reason`
- uma etapa adicional para `awaiting_other_medical_need`, quando o usuario escolher `Outros atendimentos`
- classificacao da resposta livre para decidir entre:
  - seguir agendamento
  - orientar comparecimento no PSF sem agenda
  - orientar procura do PA
- `menuMessage()` e/ou `askForServiceChoice()` apenas se quiser mudar textos globais
- `detectMenuIntentFromText()` se quiser aceitar respostas numeradas adicionais

### Sessao

[SessionDTO.php](C:\xampp\htdocs\producao\roboAgendamento\src\DTO\SessionDTO.php)

Provavelmente nao precisa migration nova, porque os dados podem ser salvos em `context`, por exemplo:

- `context.medical_reason_code`
- `context.medical_reason_label`
- `context.medical_triage_outcome`
- `context.other_medical_need_text`

### Regras de restricao

[RestrictionRuleService.php](C:\xampp\htdocs\producao\roboAgendamento\src\Service\RestrictionRuleService.php)

Pode continuar existindo, mas nao resolve sozinho esse caso, porque hoje ele faz apenas:

- bateu palavra-chave
- responde texto fixo

Ele nao conduz conversa por etapas nem entende opcao escolhida dentro de um subfluxo.

### Testes

[tests/run.php](C:\xampp\htdocs\producao\roboAgendamento\tests\run.php)

Seria importante adicionar testes para:

- entrar em `agendar_medico` e mostrar a lista de itens definida
- escolher um item que deve seguir para agenda
- escolher `Outros atendimentos` e receber a pergunta `Qual seria sua necessidade?`
- informar texto de urgencia e receber orientacao para PA
- informar texto de atendimento sem agenda e receber orientacao para comparecer no PSF das `07:00` as `10:00`
- mandar texto livre em vez de numero
- mandar opcao invalida repetidas vezes
- garantir que `dentista` e `enfermeiro` nao quebraram

## 7. Impacto tecnico esperado

## O que nao precisa mudar

- integracoes de agenda
- endpoints de medico, enfermeiro e dentista
- tabela de sessoes
- controller / webhook

## O que precisa mudar

- logica de orquestracao da conversa
- mensagens de orientacao
- testes automatizados

## 8. Riscos

### Risco 1: alguns itens da lista nao combinam naturalmente com agenda medica

Esse e o maior risco funcional.

Itens como `vacinas`, `medicao de pressao arterial` e `troca de sondas` podem nao representar agenda medica classica, entao a regra precisa ser validada com a operacao.

### Risco 2: urgencia indo para recepcao ou para agenda

Se o usuario escrever algo que parece caso de PA, o melhor nao e mandar ligar na recepcao nem abrir agenda.

O mais seguro e devolver uma orientacao curta de urgencia, por exemplo:

- "Esse quadro pode precisar de atendimento imediato. Procure o Pronto Atendimento (PA)."

Mesmo que essa parte fique fora da primeira versao, vale registrar como recomendacao.

### Risco 3: lista grande demais no WhatsApp

Se forem usados todos os itens da imagem em uma lista longa, a experiencia pode ficar pesada.

Para WhatsApp, tende a funcionar melhor:

- lista curta
- agrupamento por categorias
- opcao `Outro assunto`

## 9. Recomendacao pratica

A recomendacao mais segura para o que ja existe hoje e:

### Fase 1

Adicionar uma etapa nova apenas no fluxo `agendar_medico`, com a lista de itens definida para consulta medica:

1. consultas de rotina
2. exames laboratoriais
3. infeccoes
4. troca de sondas
5. vacinas
6. medicao de pressao arterial
7. diarreia e dores leves
8. Outros atendimentos

Comportamento:

- itens previstos seguem a regra decidida pela unidade
- `Outros atendimentos` abre pergunta livre
- se a resposta livre indicar urgencia, orientar PA
- se a resposta livre indicar atendimento sem agenda, orientar comparecimento no PSF entre `07:00` e `10:00`
- se a resposta livre indicar caso agendavel, seguir fluxo normal

### Fase 2

Se quiser evoluir depois, transformar a triagem em roteamento por destino:

- medico
- enfermeiro
- dentista
- recepcao
- urgencia / PA

## 10. Parecer final de dificuldade

Se a ideia for fazer exatamente esta dinamica:

- ao entrar em consulta medica, mostrar a lista de itens definida
- permitir `Outros atendimentos`
- perguntar `Qual seria sua necessidade?`
- classificar entre agenda, atendimento sem agenda ou PA

entao a dificuldade no sistema atual e:

**Media**

O sistema ja tem boa parte da base pronta:

- menu numerado
- controle de sessao por etapa
- reconhecimento de respostas curtas
- suporte a fallback textual
- testes de conversa

O ponto que mais pesa nao e tecnico. E de regra de negocio:

**definir exatamente o destino de cada item e como classificar a resposta livre de `Outros atendimentos`**

## 11. Estimativa objetiva

### Se fizer exatamente a dinamica pedida

- desenvolvimento: **6 a 12 horas**
- testes e ajuste fino de texto: **2 a 4 horas**
- total: **1 a 2 dias**

### Se quiser ampliar para mais variacoes textuais e sinonimos

- desenvolvimento: **1 a 2 dias**
- alinhamento de negocio e ajustes: **0,5 a 1 dia**
- total: **2 a 3 dias**

### Se quiser fazer roteamento completo por destino correto

- desenvolvimento: **2 a 5 dias**
- validacao com operacao: **1 dia**
- total: **3 a 6 dias**

## 12. Minha recomendacao final

Vale muito a pena implementar, porque o codigo atual comporta essa triagem bem.

O desenho agora ficou mais consistente, porque nao depende mais de tratar tudo como agenda medica.

O melhor custo-beneficio hoje e:

1. criar a triagem de `consulta medica` com os itens definidos
2. incluir a opcao `Outros atendimentos`
3. classificar a resposta entre `agenda`, `comparecimento sem agendamento` e `PA`
4. em fase seguinte, transformar isso em roteamento ainda mais inteligente por profissional
