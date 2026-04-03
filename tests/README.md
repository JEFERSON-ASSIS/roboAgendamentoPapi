# Testes

## Como rodar

```powershell
php tests/run.php
```

## O que esta coberto

- bootstrap com UTF-8 ativo
- prioridade da `mediaUrl` da Evolution no audio
- regras de restricao por texto
- debounce/fila sem misturar usuarios
- divisao de mensagens no WhatsApp
- orientacao de usuario perdido apos repeticao invalida na mesma etapa
- nao confirmar sucesso de agendamento sem pos-validacao final