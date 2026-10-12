# Regras de Negócio

## 1. Terminologia

### Pessoa
Qualquer indivíduo conhecido pelo HackLab.

### Inscrito
Pessoa com inscrição no evento, normalmente originada de plataforma externa.

### Participante
Aluno que efetivamente participa do Hackathon, integra equipe, desenvolve solução e apresenta resultado.

### Usuário
Pessoa com credencial de acesso ao HackLab.

### Jurado
Pessoa habilitada internamente para avaliar equipes atribuídas.

### Votante
Usuário habilitado para voto público.

Esses conceitos não são sinônimos.

## 2. Participantes

- Contagem é dinâmica.
- Não assumir 60 alunos.
- Não assumir 10 equipes de 6.
- Tamanho preferido de equipe pode ser 6, mas não é regra rígida.
- Turmas podem ser Breno/Rafael/Clara ou outras definidas no banco.
- Status possíveis devem suportar ao menos: disponível, indisponível, desistente.
- Mudança de disponibilidade não deve reembaralhar automaticamente equipes já formadas.
- Ajustes manuais são permitidos.
- Participante sem turma é permitido; turma inativa não recebe novos participantes.
- A mesma pessoa não pode ser dois participantes do mesmo evento.

## 3. Equipes

- Equipe possui participantes.
- Participante não pode estar simultaneamente em duas equipes ativas.
- Formação deve tentar equilibrar turmas quando possível.
- Não forçar equilíbrio impossível.
- Equipe pode ser vinculada a desafio.
- Equipe não pertence a turma: reúne alunos de várias turmas.
- Só participante `AVAILABLE` entra em equipe (adicionar ou mover); `UNAVAILABLE`/`WITHDRAWN` mantém o vínculo que já tinha.
- Remover e mover são ações explícitas e preservam o histórico.
- Formação automática ainda não existe; quando existir, usa as mesmas operações do `TeamService`.

## 4. Perfis e setores

Perfis:

```text
Administrador
Gestor
Editor
Consultor
Jurado
Votante
```

### Administrador
Visão global.

### Gestor
Um setor principal.
Pode gerenciar demandas do próprio setor e encaminhar demandas para outro setor.

### Editor
Um setor principal.
Pode atuar dentro do setor com menos poderes que Gestor.

### Consultor
Acompanhamento/orientação transversal.

### Jurado
Somente avaliações atribuídas e funcionalidades necessárias ao perfil.

### Votante
Somente votação e experiência pública autorizada. Vota apenas nas sessões em que foi declarado elegível (§11.2).

## 5. Pendência

Pendência = algo que precisa ser feito.

Campos conceituais:
- referência legível (`PEN-0001`), gerada pelo backend;
- título;
- descrição;
- setor de origem (nunca muda);
- setor responsável (único; muda só por encaminhamento);
- setores envolvidos adicionais;
- prioridade (`LOW`, `MEDIUM`, `HIGH`, `URGENT`);
- status (`PENDING`, `IN_PROGRESS`, `COMPLETED`);
- prazo;
- responsável individual opcional (usuário ativo do setor responsável);
- histórico.

Concluir é operação própria (preenche `resolved_at`). Reabrir leva para `IN_PROGRESS` e limpa `resolved_at`. Não há exclusão.

### 5.1 Quem cria

- Administrador: escolhe origem e responsável entre setores ativos.
- Gestor: origem = próprio setor; responsável = próprio setor ou outro setor ativo; pode informar envolvidos.
- Editor: origem e responsável = próprio setor; pode se atribuir como responsável individual.
- Consultor, Jurado, Votante: não criam.

### 5.2 Quem opera

Princípio: **origem/envolvido acompanha e conversa; o responsável atual controla a execução.**

- Gestor do setor responsável: altera título, descrição, prioridade, prazo, status, responsável individual e envolvidos; encaminha; conclui/reabre.
- Editor do setor responsável: comenta, altera status, conclui/reabre. Não encaminha, não muda envolvidos, não atribui outra pessoa, não muda prioridade/prazo.
- Gestor/Editor de setor só de origem ou envolvido: visualiza e comenta.
- Administrador: tudo. Consultor: visualiza e comenta qualquer demanda.

## 6. Ocorrência

Ocorrência = algo que aconteceu.

Campos conceituais:
- referência legível (`OCO-0001`);
- título, descrição e categoria;
- setor de origem, setor responsável e envolvidos adicionais;
- responsável individual opcional;
- prioridade;
- status (`OPEN`, `IN_PROGRESS`, `RESOLVED`);
- dia do evento (opcional), data/hora, local, equipe (opcional);
- observações e solução;
- histórico.

Resolver é operação própria e exige solução. Reabrir volta para `OPEN`, limpa `resolved_at` e mantém a solução; uma nova solução substitui o resumo, e a anterior fica no histórico.

Criação e operação seguem as mesmas regras da pendência (§5.1 e §5.2), com "resolver" no lugar de "concluir".

Uma ocorrência pode gerar uma ou várias pendências vinculadas (Administrador ou Gestor de setor relacionado; para o Gestor, a origem é o próprio setor). A ligação é imutável.

## 7. Visibilidade intersetorial

Um setor visualiza:
- itens originados por ele;
- itens pelos quais é responsável;
- itens nos quais aparece como envolvido.

Setores não relacionados não visualizam (nem aparecem na listagem: a query já aplica a visibilidade).

Administrador visualiza tudo. Consultor visualiza tudo, sem poder operacional estrutural. Jurado e Votante não acessam pendências e ocorrências.

### 7.1 Encaminhamento

Operação própria, com motivo obrigatório. Ao encaminhar:
- o responsável anterior passa a envolvido (exceto se for a origem, que já vê a demanda);
- o novo responsável sai da lista de envolvidos, se estava nela;
- a origem nunca muda;
- o responsável individual é limpo;
- tudo numa transação, com um único registro de auditoria.

Encaminha: Administrador (qualquer demanda) e Gestor do setor responsável atual. Destino precisa ser setor ativo do mesmo evento.

### 7.2 Setor inativo

Setor responsável por demanda aberta não pode ser inativado (encaminhe antes). Ser origem ou envolvido não bloqueia. Setor inativo não recebe nova demanda, não vira envolvido e não recebe encaminhamento.

## 8. Histórico e interação

Pendências e ocorrências possuem histórico contextual.

Registrar:
- criação;
- comentário;
- encaminhamento;
- alteração de responsável;
- prioridade;
- prazo;
- status;
- inclusão/remoção de setor;
- conclusão/resolução;
- reabertura;
- geração de pendência.

Histórico é somente inserção (sem editar/apagar). Não é chat genérico: é contextual à demanda.

Histórico de colaboração não substitui Auditoria: alteração estrutural gera interação **e** auditoria; comentário gera só interação.

## 9. Auditoria

Auditoria é global e somente leitura.

Apenas Administrador acessa a visão global (`audit_logs.view`). Gestor, Editor, Consultor, Jurado e Votante não acessam o módulo (não há escopo setorial: o módulo simplesmente não existe para eles).

Registrar ações relevantes, não cliques de interface.

### 9.1 O que a auditoria responde (Fase 8)

Quem fez, o quê, quando, em qual módulo, sobre qual registro, em qual evento, qual era o perfil/setor do ator naquele momento, o que havia antes, o que ficou depois, de qual IP/User-Agent.

- **Somente inserção.** Nenhum log é editado ou apagado (model + trigger). Não há POST/PATCH/DELETE de auditoria: logs nascem só do `AuditLogger`, dentro das operações de negócio.
- **Fotografia histórica.** Nome, e-mail, perfil e setor do ator e o rótulo da entidade são gravados no momento da ação. Se Lucas era Editor/Produção às 10:00 e virou Gestor/Infraestrutura às 12:00, o log das 10:00 continua mostrando Editor/Produção. Equipe renomeada de "Alpha" para "Beta": logs antigos continuam "Alpha".
- **Evento** é o do objeto auditado. Ações globais (login, usuário, pessoa) ficam sem evento.
- **Um log semântico por operação** (mover participante = `PARTICIPANT_TEAM_CHANGED`; encaminhar = `TASK_FORWARDED`; lista de equipes do jurado = `JUROR_ASSIGNMENTS_CHANGED`; publicar = `RESULTS_PUBLISHED`).
- **Não vira auditoria:** consultas (inclusive consultar a própria auditoria), cliques, filtros, comentários de pendência/ocorrência (só interação), rascunho de avaliação, voto individual e retry do voto, visualização de resultado. Não existe `PUBLIC_VOTE_CAST`.
- **Nunca no log:** segredos (senha, tokens, cookies, CSRF, chaves), notas e comentários de jurado, vínculo eleitor → equipe.
- `changes` (diferença antes/depois) é calculado na consulta, nunca gravado.
- Sem purge, retenção automática ou exportação nesta versão.

## 10. Jurados

Regra obrigatória:

```text
Jurado
→ equipes atribuídas explicitamente
```

Não usar:
- empresa do jurado;
- desafio da empresa;
- representante de empresa;
- categoria externa;
- turma ou setor;

para determinar automaticamente avaliações.

Um jurado pode:
- não possuir empresa;
- não possuir conta de acesso (Juror ≠ User);
- ser a mesma Person de um representante de empresa;
- avaliar várias equipes.

Uma equipe pode:
- ter vários jurados.

Sem atribuições:

```text
Nenhuma avaliação atribuída a você no momento.
```

Para avaliar pelo sistema: Person + Juror ACTIVE + User ACTIVE da mesma Person com permissão de avaliar + atribuição ACTIVE. Ser Administrador não cria identidade de jurado.

Revogar uma atribuição preserva a avaliação (histórico); ela só deixa de contar enquanto a atribuição estiver revogada. Reativar volta a contar. Jurado inativado continua com as atribuições ativas (esperadas no progresso) até o Administrador revogá-las explicitamente.

### 10.1 Avaliação

- Critérios numéricos (faixa e peso próprios). Estrutura trava na primeira avaliação do evento; só nome/descrição continuam editáveis.
- Rascunho (`DRAFT`) pode ser parcial. Só o próprio jurado, com atribuição ativa, escreve. Um jurado nunca vê a avaliação de outro.
- Envio (`SUBMITTED`) exige nota válida em todos os critérios ativos e trava a avaliação.
- Correção: o Administrador **não altera nota**. Ele solicita revisão (`REVISION_REQUESTED`, motivo obrigatório); o próprio jurado corrige e reenvia.
- Percentual da avaliação = média ponderada das notas normalizadas pela faixa de cada critério.
- Resultado técnico da equipe = média dos percentuais das avaliações enviadas com atribuição ativa (consulta administrativa, não persistida; congelado só no snapshot da publicação, §11.7).
- Com resultado publicado, atribuições e solicitação de revisão ficam travadas até a publicação ser revogada (§11.7).

### 10.2 Quem vê o quê

- Jurado: só a própria avaliação.
- Administrador: todas (sem editar notas).
- Gestor e Consultor: só progresso agregado, sem notas nem comentários.
- Editor e Votante: sem acesso.

## 11. Avaliação técnica, voto público e publicação (Fase 7)

São três domínios independentes:

```text
AVALIAÇÃO TÉCNICA   Jurados → Resultado Técnico
VOTAÇÃO PÚBLICA     Votantes elegíveis → Escolha do Público
PUBLICAÇÃO          snapshot imutável e versionado → resultado oficial
```

- **Resultado Técnico** = média das avaliações técnicas válidas (regra da Fase 6: percentuais das avaliações `SUBMITTED` com atribuição `ACTIVE`).
- **Escolha do Público** = quantidade de votos válidos da sessão oficial selecionada.
- **Não existe score combinado.** Sem 70/30, 60/40, bônus por voto, conversão de voto em nota ou outra fórmula híbrida, até existir regra oficial documentada.
- O HackLab anuncia 1º/2º/3º lugar técnico **e** a Escolha do Público. Podem coincidir ou não.
- Nenhum voto entra no cálculo técnico; nenhuma nota técnica entra no cálculo popular.

### 11.1 Sessão de votação

- Pertence a um evento. Nome único por evento sem diferença de caixa.
- Status: `DRAFT → OPEN → CLOSED`; `DRAFT → CANCELLED`. Sem exclusão física.
- O evento pode ter várias sessões (testes, históricas), mas **no máximo uma `OPEN`** (aplicação + índice único parcial).
- Em `DRAFT` o Administrador altera nome, equipes candidatas e eleitores elegíveis. Depois de aberta, tudo isso trava: nada entra ou sai durante a votação.
- Abrir exige: ao menos 1 candidata, ao menos 1 elegível, nenhuma outra sessão `OPEN` no evento, todas as candidatas ainda `ACTIVE`, todos os elegíveis ainda `ACTIVE` com perfil `VOTER`. Abertura é transacional (`status = OPEN`, `opened_at = now()`).
- Fechar é operação própria (`OPEN → CLOSED`, `closed_at = now()`). **Sessão fechada nunca reabre.** Repetir votação por problema real = criar nova sessão; a anterior fica preservada e nunca entra no resultado oficial se não for selecionada.
- Cancelar: livre em `DRAFT`. Em `OPEN`, só sem nenhum voto e com motivo obrigatório (auditado). Sessão com votos nunca é cancelada. Sessão `CANCELLED` nunca recebe votos nem entra em resultado.

### 11.2 Candidatas e eleitores

- Candidatas são explícitas por sessão: equipes `ACTIVE` do mesmo evento. Nunca inferidas por desafio, empresa, jurado ou posição técnica.
- Eleitor válido = User `ACTIVE` + perfil `VOTER` + elegibilidade explícita na sessão. Ter perfil `VOTER` não basta.
- Elegibilidade não cria conta nem transforma Person em User. "Quem fez check-in pode votar" **não** é regra automática (presença externa só chega na Fase 9).

### 11.3 Voto

- 1 usuário → 1 voto → por sessão, em exatamente uma equipe. Sem nota, ranking de várias equipes, múltiplos votos ou peso.
- **Voto é definitivo**: não muda de equipe, não é apagado, não se vota de novo (sem PATCH/DELETE; banco recusa UPDATE/DELETE). A interface deve pedir confirmação antes do envio.
- No momento do voto, revalidar: sessão `OPEN`, User `ACTIVE`, perfil `VOTER`, elegível na sessão, equipe candidata na sessão.
- Retry idêntico (mesma sessão, usuário e equipe) é idempotente: devolve o voto existente. Tentar outra equipe → 422.
- Requisições simultâneas do mesmo usuário: exatamente uma vence, exatamente um registro existe, a outra recebe erro previsível (ou o voto existente, se for a mesma equipe).
- Inativar o usuário depois de votar não invalida o voto (histórico não muda retroativamente).

### 11.4 Sigilo

- O banco guarda `user_id` para garantir voto único: é **sigilo na aplicação**, não anonimato criptográfico.
- Nenhuma API administrativa devolve eleitor → equipe, nem para o Administrador. APIs administrativas devolvem só: total de elegíveis, total de votos, participação e (depois de fechada) contagem agregada por equipe.
- A listagem de elegíveis mostra só se a pessoa já votou, nunca em quem.
- `audit_logs` não registra a escolha individual (nem `PUBLIC_VOTE_CAST`). Só operações administrativas são auditadas.

### 11.5 Contagem e resultado popular

- Com a sessão `OPEN`, **ninguém** recebe contagem por equipe, nem o Administrador. O Administrador vê só participação ("87 de 120 votos recebidos, 72,5%").
- Contagem por equipe só depois de `CLOSED`: `vote_count`, `percentage` e `rank`.
- `percentage = 100 * votos da equipe / total de votos válidos` (0 se não houver votos). Participação usa elegíveis; percentual de voto usa votos dados.

### 11.6 Empates

- Posição = 1 + quantidade de equipes com valor estritamente maior. Empate de primeiro lugar: `1, 1, 3`.
- Popular: mesma quantidade de votos = empate. Técnico: mesmo resultado em precisão completa (aritmética decimal, não o valor arredondado exibido) = empate.
- Sem desempate artificial: ID, ordem de cadastro, hora do voto, nome, nota técnica (para o popular) ou voto (para o técnico). Ordem alfabética só para apresentação.

### 11.7 Publicação oficial

- Prévia (só Administrador, depois que a sessão escolhida estiver `CLOSED`): ranking técnico, ranking popular, completude das avaliações, participação, empates e bloqueios.
- Publicar exige: sessão do mesmo evento, `CLOSED`; **pelo menos 1 avaliação técnica válida** (`SUBMITTED` com atribuição `ACTIVE`) — sem isso a completude passaria por vacuidade e o snapshot sairia sem resultado técnico. Não há mínimo de jurados nem cobertura por equipe. Um evento "somente voto popular", se um dia existir, será modalidade explícita, nunca consequência de não haver avaliações; avaliações técnicas completas (nenhuma atribuição `ACTIVE` sem avaliação `SUBMITTED`, inclusive de jurado inativo — para não contar, o Administrador revoga a atribuição explicitamente); nenhuma publicação `PUBLISHED` no evento.
- Publicar grava um **snapshot imutável e versionado** (só agregados: sem identidade de votantes, notas individuais ou comentários de jurados). O que foi anunciado não muda sozinho.
- Enquanto houver publicação `PUBLISHED`, ficam bloqueadas as operações que mudariam o resultado técnico: alterar atribuições jurado ↔ equipe e solicitar revisão de avaliação.
- Correção: revogar (motivo obrigatório; `PUBLISHED → REVOKED`), corrigir e publicar a versão seguinte. A versão anterior continua armazenada. Problema na votação pública: nova sessão, nunca editar ou apagar votos.

### 11.8 Quem vê o quê

| Perfil | Antes da publicação | Depois da publicação |
|---|---|---|
| Administrador | sessões, participação; contagem por equipe só com a sessão `CLOSED`; prévia | snapshot oficial + histórico de versões |
| Gestor, Consultor | só o progresso agregado da Fase 6 | snapshot oficial |
| Editor | nada de votação/resultado | snapshot oficial |
| Jurado | só as próprias avaliações | snapshot oficial |
| Votante | sessões em que é elegível, candidatas, se já votou, o próprio voto (sem contagem) | snapshot oficial |

Publicação anônima (sem login) não existe nesta fase; pode ser habilitada conscientemente depois.

## 12. Empresas

Empresa é entidade interna e pertence a um evento.

Pode possuir:
- nome, razão social, documento, segmento e tipo (`PARTICIPANT`, `PARTNER`, `SPONSOR`, `SUPPORT`, `OTHER`);
- descrição;
- contatos;
- representantes (Persons);
- desafios.

Status: `DRAFT`, `CONFIRMED`, `INACTIVE`. "Aguardando desafio" e "Com desafio" são situações derivadas dos desafios, não status.

Empresa não é apagada: é inativada. Inativa não recebe novos desafios nem representantes ativos; o histórico permanece.

Pessoa inscrita externamente como `Empresa / Convidado` não cria automaticamente uma Empresa.

Ela pode ser posteriormente vinculada como representante.

Representante de empresa **não** vira Jurado automaticamente, e a empresa **não** define quais equipes um Jurado avalia.

## 12.1 Desafios

- Desafio pertence ao evento; empresa é opcional (desafio institucional).
- Fluxo: Rascunho (`DRAFT`) → Recebido (`RECEIVED`) → Em análise (`UNDER_REVIEW`) → Aprovado (`APPROVED`) → Distribuído (`DISTRIBUTED`) → Em desenvolvimento (`IN_DEVELOPMENT`) → Finalizado (`FINISHED`).
- Nesta versão: uma equipe tem no máximo um desafio e um desafio vai para no máximo uma equipe.
- Distribuir um desafio aprovado vincula a equipe e muda o status para `DISTRIBUTED` na mesma operação.
- A equipe destino não pode já ter outro desafio (422, sem sobrescrever).
- Mover um desafio distribuído para outra equipe é atômico: libera a anterior e vincula a nova.
- Retirar a equipe de um desafio `DISTRIBUTED` faz ele voltar para `APPROVED`.
- Em `IN_DEVELOPMENT` ou `FINISHED`, mover ou retirar a equipe exige primeiro mudar o status explicitamente.
- Desafio pode existir sem equipe durante preparação e análise.

## 13. Integração de inscrição (Fase 9)

Entradas sugeridas na Even3:

```text
Participante
Organização
Empresa / Convidado
Jurado
Público
```

A plataforma externa pode usar outros nomes. O HackLab mapeia **por id da entrada**, manualmente:

```text
external_ticket_id → internal_category (PARTICIPANT | ORGANIZATION | COMPANY_GUEST | JUROR | PUBLIC)
```

- Entrada nova nasce sem categoria até o Administrador mapear. Mapeamento pode ser removido (`null`) e é auditado.
- Renomear a entrada na Even3 não perde o mapeamento. Entrada que some de uma leitura completa fica inativa (histórico preservado).
- O texto da categoria do participante só é associado a uma entrada quando corresponde a **exatamente uma** entrada conhecida; ambíguo ou desconhecido fica sem entrada, nunca escolhido arbitrariamente.
- Vínculo explícito Event ↔ evento externo (`PUT /events/{event}/integrations/even3`), conferido na Even3 antes de ativar. Um evento externo alimenta um único evento interno.
- Nesta versão: um token da Even3 por deployment (a chave é por evento).

## 14. Sincronização de pessoas

A sincronização traz todos os inscritos do evento, não apenas Participantes.

O HackLab:
1. localiza a inscrição já conhecida pelos IDs externos;
2. senão, associa por documento normalizado único, depois por e-mail normalizado único;
3. se documento e e-mail apontam para pessoas diferentes (ou são ambíguos): **conflito**, nada é vinculado nem sobrescrito;
4. senão, cria a `Person`;
5. cria/atualiza a `EventRegistration` — identificada pelos IDs externos; uma pessoa pode ter várias; inscrição da pessoa só é reaproveitada se for a única compatível (ambíguo = conflito) — e guarda os dados da origem (`source_*`);
6. preenche só campos vazios de uma `Person` existente (nunca sobrescreve cadastro interno);
7. classifica pela entrada externa;
8. não concede permissão, conta, perfil ou vínculo de domínio.

Status: confirmado/pendente/cancelado. Cancelamento só por informação explícita (webhook): **não aparecer na sincronização não cancela**, e `is_confirmed = false` da API não desfaz um cancelamento conhecido.

Participante interno (aluno) só nasce pela operação explícita e idempotente de materialização: inscrição confirmada + entrada `PARTICIPANT` → `Participant` `AVAILABLE` sem turma, se ainda não existir. Participante existente não muda; cancelamento externo não tira ninguém de equipe.

## 15. Jurado externo

Uma inscrição externa classificada como `JUROR` indica que a pessoa está inscrita no evento como jurado.

Isso não deve conceder sozinho:
- conta;
- senha;
- role;
- registro `Juror`;
- equipes atribuídas.

Esses vínculos são internos. O mesmo vale para `COMPANY_GUEST` (não cria empresa nem representante) e `ORGANIZATION` (não cria perfil).

## 16. Público e Votante

Público pode existir sem conta. Inscrição `PUBLIC` não cria `User`, perfil `VOTER` nem elegibilidade de votação.

Quando criar conta para votar:
- tentar vincular à pessoa já sincronizada;
- não duplicar pessoa;
- aplicar critérios de elegibilidade definidos pelo HackLab.

"Quem fez check-in pode votar" **não** é regra (nem automática nem implícita).

## 17. Organização externa vs perfil interno

Categoria externa `ORGANIZATION` não define o perfil.

Exemplo:

```text
Categoria externa: Organização
Perfil interno: Gestor
Setor: Produção
```

ou:

```text
Categoria externa: Organização
Perfil interno: Consultor
```

## 18. Presença

Na arquitetura atual:
- QR e credenciamento são feitos fora do HackLab (Even3);
- o HackLab recebe os check-ins (webhook) e consolida presença.

- Cada check-in é um registro (vários por dia permitidos); um disparo do webhook gera no máximo um.
- Presença por dia é derivada: existe ao menos um check-in naquele `EventDay`. Não existe booleano global de presença.
- O dia vem do horário convertido pelo timezone do evento (`events.timezone`, IANA). Fora dos dias cadastrados → sem dia + aviso; o HackLab nunca cria `EventDay` a partir de check-in.
- Horário exato do check-in só é preenchido quando a origem o informar explicitamente; o instante observado é guardado à parte.

## 19. Contas

Pessoa pode não possuir conta.

Conta deve ser criada somente quando houver necessidade de acesso ao HackLab.

## 20. Relatórios

Relatórios devem usar dados do banco, não DOM do frontend.

Jurados e voto público devem permanecer distinguíveis.

## 21. Regra de consistência

Sempre preferir:
- IDs internos;
- IDs externos persistidos;
- constraints no banco;
- transactions;
- operações idempotentes.

Evitar regras frágeis baseadas em texto.

## 22. Interface (Fase 10)

- A interface só oferece operações que existem na API. Não há exclusão de participante, equipe, empresa, desafio, pendência ou ocorrência: saída de participante é mudança explícita de status (`WITHDRAWN`) feita pelo usuário, nunca efeito de um botão "Excluir".
- Não há cadastro público nem convite de jurado pela interface: contas são criadas pelo Administrador (`/users`).
- O voto é definitivo e registrado só no backend; a interface não guarda nada sobre o voto no navegador. Repetir o mesmo voto devolve sucesso; tentar mudar mostra a mensagem do backend.
- O resultado exibido em painel/apresentação é sempre o publicado (`/results/published`); a prévia é só da gestão.
- Atas, decisões, manifestações, documentos, premiações e finanças não têm backend e ficam fora da interface até existirem.
