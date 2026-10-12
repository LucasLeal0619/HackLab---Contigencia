# Banco de Dados — PostgreSQL

## 1. Princípios

- PostgreSQL é a fonte de verdade do HackLab, em todos os ambientes.
- Tabelas no schema `hacklab` (nunca `public`), via `search_path`.
- A estrutura é criada só por migrations Laravel. Nada de tabela criada manualmente nem pelo Dashboard do Supabase.
- IDs internos independem da plataforma externa.
- IDs externos são armazenados para sincronização.
- Usar `bigint` (`$table->id()`) ou UUID/ULID conforme decisão inicial do projeto; não misturar estratégias sem motivo. PostgreSQL não tem `unsigned`.
- Colunas `json` das tabelas abaixo usam `jsonb` (`$table->jsonb()`).
- Usar `timestampTz` quando o instante importa (check-ins, auditoria, sincronização).
- Todas as FKs relevantes devem possuir índice.
- Timestamps em UTC no backend; converter no frontend.
- Usar enums PHP/constantes de domínio quando útil, mas manter persistência evolutiva.
- Não guardar token externo em tabela sem necessidade; preferir secret em `.env`.

## 1.1 Ambientes e bancos

| Banco | Ambiente (`APP_ENV`) | Onde |
|---|---|---|
| `hacklab_dev` | `local` | container `postgres` do `docker-compose.yml` |
| `hacklab_test` | `testing` | mesmo container, banco separado. É apagado e recriado pelos testes |
| `postgres` (schema `hacklab`) | `production` | Supabase |

- PostgreSQL 17 no Docker, mesma versão major dos projetos novos do Supabase.
- `hacklab_dev` é criado pelo `POSTGRES_DB` do compose. `hacklab_test` e o schema `hacklab` (nos dois bancos) são criados por `docker/postgres/init/01-init.sh` na primeira inicialização do volume.
- O backend recusa conexões fora da regra (`App\Support\Database\DatabaseEnvironmentGuard`):
  - fora de `production`, nunca um host do Supabase (`*.supabase.co`, `*.supabase.com`);
  - em `testing`, só bancos terminados em `_test`;
  - em `production`, só com `sslmode` `require`, `verify-ca` ou `verify-full`.

### Conexão local (Docker)

`back/.env`:

```text
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1        # dentro do container o compose sobrescreve para "postgres"
DB_PORT=5442             # porta publicada só em 127.0.0.1; dentro do Docker é 5432
DB_DATABASE=hacklab_dev
DB_USERNAME=hacklab
DB_PASSWORD=hacklab
DB_SEARCH_PATH=hacklab
DB_SSLMODE=disable       # container local, sem SSL
```

### Conexão de produção (Supabase)

Só nas variáveis de ambiente do Render. Nunca no Git.

```text
DB_CONNECTION=pgsql
DB_URL=postgresql://postgres.[PROJECT-REF]:[SENHA-PERCENT-ENCODED]@aws-[N]-[REGIAO].pooler.supabase.com:5432/postgres
DB_SEARCH_PATH=hacklab
DB_SSLMODE=require
```

- `DB_URL` = string do **Session pooler**, copiada do botão **Connect** do projeto. Não usar o Transaction pooler (6543).
- Caracteres reservados na senha (`&`, `#`, `?`, `@`, `/`, espaço) devem ser percent-encoded.
- O schema `hacklab` precisa existir antes do primeiro `migrate`. O SQL está em `infraestrutura.md` §3.1.

### Validação

```bash
docker compose exec app php artisan hacklab:db-check
```

Mostra ambiente, host, versão do servidor, banco, usuário, `search_path`/schema ativo, se a conexão usa SSL e quantas migrations foram aplicadas. Falha se o schema configurado não existir.

### Testes automatizados

- Rodam em PostgreSQL, no banco `hacklab_test` do Docker. Não usar SQLite.
- `phpunit.xml` força `DB_CONNECTION=pgsql` e `DB_DATABASE=hacklab_test`. Credenciais em `back/.env.testing` (fora do Git; modelo em `back/.env.testing.example`).
- Com `APP_ENV=testing`, o Laravel lê só o `.env.testing`. O `.env` não é carregado.
- Testes que tocam o banco usam `RefreshDatabase`. O guard impede que apontem para `hacklab_dev` ou para o Supabase.

```bash
docker compose exec app php artisan test
```

## 2. Modelo conceitual

```text
Person
├── User?
├── Participant?
├── Juror?
├── CompanyRepresentative*
└── EventRegistration*

Event
├── EventDay*
├── EventRegistration*
├── Team*
├── Challenge*
└── VotingSession*

EventIntegration (EVEN3)
├── ExternalTicketType*
├── EventRegistration*  (Person)
│   └── AttendanceRecord*
├── IntegrationSyncRun*
└── IntegrationWebhookReceipt*

Sector
├── User*
├── Task*
└── Occurrence*

Juror
└── JurorTeamAssignment*

VotingSession
├── VotingSessionTeam*     (candidatas)
├── VotingEligibility*     (User VOTER elegível)
└── PublicVote*            (append-only)

Event
└── ResultPublication*     (versões; no máximo 1 PUBLISHED)

Task
└── TaskSector*

Occurrence
└── OccurrenceSector*
```

## 3. Tabelas principais

### people

```text
id
full_name
email nullable/index
phone nullable
document nullable
status
created_at
updated_at
```

Observações:
- e-mail não é identidade absoluta;
- pode haver pessoa sem e-mail;
- duplicidade deve ser tratada de forma controlada.

### users

```text
id
person_id FK unique
role_id FK
sector_id FK nullable        (entra na Fase 2, junto com a tabela sectors)
email unique                 (sempre minúsculo; check no banco)
password                     (hash padrão do Laravel)
status                       (ACTIVE | INACTIVE; check no banco)
email_verified_at nullable
last_login_at nullable
remember_token nullable
created_at
updated_at
```

Implementado na Fase 1 sem `sector_id`: a coluna e a FK chegam na migration da Fase 2, para não depender de tabela inexistente.

Usuário não é apagado: é inativado (`status = INACTIVE`), o que encerra as sessões abertas e bloqueia o acesso na próxima requisição. O sistema recusa inativar ou rebaixar o último Administrador ativo.

### roles

Seed inicial:

```text
ADMINISTRATOR
MANAGER
EDITOR
CONSULTANT
JUROR
VOTER
```

Campos:

```text
id
code unique
name
description nullable
```

### permissions

```text
id
code unique
name
description nullable
```

### role_permission

```text
role_id
permission_id
primary key(role_id, permission_id)
```

Policies ainda devem aplicar escopo por setor.

Permissões iniciais (Fase 1), definidas em `App\Domain\Users\Enums\PermissionCode`:

| Permissão | Administrador | Gestor | Editor | Consultor | Jurado | Votante |
|---|---|---|---|---|---|---|
| `people.view` | ✓ | ✓ | ✓ | ✓ | | |
| `people.manage` | ✓ | | | | | |
| `users.view` | ✓ | | | | | |
| `users.manage` | ✓ | | | | | |
| `roles.view` | ✓ | | | | | |
| `audit_logs.view` (era `audit.view` até a Fase 7) | ✓ | | | | | |
| `events.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `events.manage` | ✓ | | | | | |
| `sectors.view` | ✓ | ✓ | ✓ | ✓ | | |
| `sectors.manage` | ✓ | ✓ | | | | |
| `meetings.view` | ✓ | ✓ | ✓ | ✓ | | |
| `meetings.manage` | ✓ | ✓ | | | | |
| `classes.view` | ✓ | ✓ | ✓ | ✓ | | |
| `classes.manage` | ✓ | | | | | |
| `participants.view` | ✓ | ✓ | ✓ | ✓ | | |
| `participants.manage` | ✓ | | | | | |
| `teams.view` | ✓ | ✓ | ✓ | ✓ | | |
| `teams.manage` | ✓ | | | | | |
| `companies.view` | ✓ | ✓ | ✓ | ✓ | | |
| `companies.manage` | ✓ | | | | | |
| `challenges.view` | ✓ | ✓ | ✓ | ✓ | | |
| `challenges.manage` | ✓ | | | | | |
| `tasks.view` / `occurrences.view` | ✓ | ✓ | ✓ | ✓ | | |
| `tasks.comment` / `occurrences.comment` | ✓ | ✓ | ✓ | ✓ | | |
| `tasks.create` / `occurrences.create` | ✓ | ✓ | ✓ | | | |
| `tasks.operate` / `occurrences.operate` | ✓ | ✓ | ✓ | | | |
| `tasks.route` / `occurrences.route` | ✓ | ✓ | | | | |
| `jurors.view` / `jurors.manage` | ✓ | | | | | |
| `evaluation_criteria.view` | ✓ | | | | ✓ | |
| `evaluation_criteria.manage` | ✓ | | | | | |
| `evaluations.progress.view` | ✓ | ✓ | | ✓ | | |
| `evaluations.all.view` | ✓ | | | | | |
| `evaluations.own` | ✓ | | | | ✓ | |
| `evaluations.request_revision` | ✓ | | | | | |
| `voting_sessions.view` / `voting_sessions.manage` | ✓ | | | | | |
| `voting_eligibility.manage` | ✓ | | | | | |
| `voting_aggregates.view` | ✓ | | | | | |
| `votes.cast` | | | | | | ✓ |
| `results.preview` / `results.publish` | ✓ | | | | | |
| `results.view_published` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `integrations.view` | ✓ | | | ✓ | | |
| `integrations.manage` / `integrations.sync` | ✓ | | | | | |
| `event_registrations.view` / `attendance.view` | ✓ | | | ✓ | | |

Materializar participantes a partir das inscrições exige `integrations.manage` **e** `participants.manage`.

O Administrador recebe todas as permissões **exceto `votes.cast`**. `votes.cast` ainda exige, na Policy, conta `ACTIVE`, perfil `VOTER` e elegibilidade na sessão; o status `OPEN` é verificado sob lock no momento do voto.

Qualquer usuário vê a própria conta e o próprio cadastro de pessoa. Novas permissões entram junto com os módulos que as usam.

**Escopo (Fase 2):** a Policy combina permissão + escopo. Quem tem setor (Gestor, Editor) só alcança o próprio setor; quem não tem setor (Administrador, Consultor) tem alcance global, limitado pelas permissões. Criar setor, ativar/inativar setor e gerenciar evento e reunião geral exigem alcance global.

Turmas, participantes e equipes (Fase 3) e empresas e desafios (Fase 4) são globais ao evento: só permissão, sem escopo setorial e sem `sector_id`.

Pendências e ocorrências (Fase 5): permissão **e** participação do setor. `operate` sozinho (Editor) = status, concluir/resolver, reabrir; `operate` + `route` (Gestor, Administrador) = também conteúdo, prioridade, prazo, responsável individual, envolvidos e criação para outro setor; `route` = encaminhar. Ver/comentar exige o setor ser origem, responsável ou envolvido; operar e encaminhar exigem ser o responsável atual. Gerar pendência a partir de ocorrência = `tasks.create` + `occurrences.route` + setor relacionado.

### events

```text
id
name
description nullable
location nullable
start_date
end_date
status
external_provider nullable
external_event_id nullable
created_at
updated_at
```

Implementado na Fase 2 sem `external_provider`/`external_event_id`: o vínculo com a plataforma externa fica em `event_integrations` (Fase 9). Status `PLANNED | ACTIVE | FINISHED | CANCELLED` (check) e `end_date >= start_date` (check). Nome, datas e quantidade de dias vêm do cadastro; o código não assume 3 dias.

### event_days

```text
id
event_id FK
day_number
date
label
start_time nullable
end_time nullable
unique(event_id, day_number)
unique(event_id, date)
```

Checks: `day_number >= 1` e `end_time > start_time` quando ambos existem. O backend recusa dia fora do período do evento e alteração de datas do evento que deixaria dias de fora.

### classes

Turmas dos participantes.

```text
id
event_id FK
name
active
created_at
updated_at
```

Não hardcode Breno/Rafael/Clara no schema.

Implementado na Fase 3 (model `SchoolClass`, porque `class` é palavra reservada no PHP). Nome único por evento sem diferença de caixa; `unique(id, event_id)` como alvo de FK composta. Turma inativa continua existindo (histórico) e mantém seus participantes, mas não recebe novos.

### participants

Somente alunos do Hackathon.

```text
id
event_id FK
person_id FK
class_id FK nullable
status
notes nullable
created_at
updated_at

unique(event_id, person_id)
```

Status sugeridos:

```text
AVAILABLE
UNAVAILABLE
WITHDRAWN
```

Implementado na Fase 3:
- status com check no banco e enum `ParticipantStatus`;
- FK composta `(class_id, event_id) → classes(id, event_id)`: a turma é sempre do mesmo evento;
- `unique(id, event_id)` como alvo da FK composta de `team_members`;
- identidade (nome, e-mail, telefone) só em `people`, nunca duplicada aqui;
- mudar o status **não** altera o vínculo com equipe.

### teams

```text
id
event_id FK
name
code nullable
challenge_id FK nullable
status
created_at
updated_at
```

Implementado na Fase 3 **sem `challenge_id`** (entra na Fase 4, junto com `challenges`). Status `ACTIVE | INACTIVE` (check). Nome e código únicos por evento sem diferença de caixa. Equipe não pertence a turma. Equipe inativa não recebe membros e só é inativada sem membros ativos.

### team_members

```text
id
team_id FK
participant_id FK
joined_at nullable
left_at nullable
active
```

Regra: um participante só pode ter um vínculo ativo de equipe por evento.

Implementado na Fase 3, com `event_id` na tabela para as garantias no banco:
- `unique(participant_id) where active`: no máximo um vínculo ativo por participante (o participante já é de um único evento);
- FKs compostas `(team_id, event_id) → teams` e `(participant_id, event_id) → participants`: equipe e participante do mesmo evento;
- check `(active and left_at is null) or (not active and left_at is not null)` e `left_at >= joined_at`;
- vínculos nunca são apagados: remover = `active=false` + `left_at`; mover = encerrar o antigo e abrir o novo na mesma transação (um único log `PARTICIPANT_TEAM_CHANGED`);
- sem tamanho fixo de equipe.

### companies

Empresa interna do HackLab, de um único evento.

```text
id
event_id FK
name
legal_name nullable
document nullable
segment nullable
type                 PARTICIPANT | PARTNER | SPONSOR | SUPPORT | OTHER
description nullable
email nullable
phone nullable
website nullable
status               DRAFT | CONFIRMED | INACTIVE
created_at
updated_at
```

- Nome único por evento sem diferença de caixa; `unique(id, event_id)` como alvo de FK composta.
- `document` normalizado (só letras e dígitos, maiúsculas, já compatível com o CNPJ alfanumérico) e único por evento quando informado.
- "Aguardando desafio" e "Com desafio" **não** são status: são derivados dos desafios.
- Sem DELETE físico. `INACTIVE` não recebe novos desafios nem representantes ativos; desafios e representantes existentes permanecem.
- Nunca criada automaticamente a partir de inscrição externa (Even3).

### company_representatives

Representante = Person vinculada à empresa. Nome/e-mail ficam em `people`.

```text
id
company_id FK
person_id FK
title nullable
notes nullable
is_primary
active
created_at
updated_at
unique(company_id, person_id)
```

- No máximo um representante principal **ativo** por empresa (índice único parcial).
- Pode ser uma Person existente ou criada na mesma transação. Não cria User.
- Sem DELETE físico: `active = false`. Desativar também remove a marca de principal.
- Marcar um segundo principal ativo é recusado (422); a troca é explícita.
- Empresa em `DRAFT` pode não ter representante.

### challenges

Desafio do evento, com ou sem empresa (desafio institucional/Senac não precisa de empresa fictícia).

```text
id
event_id FK
company_id FK nullable
title
problem
objective nullable
requirements nullable
restrictions nullable
expected_outcome nullable
notes nullable
status               DRAFT | RECEIVED | UNDER_REVIEW | APPROVED | DISTRIBUTED | IN_DEVELOPMENT | FINISHED
created_at
updated_at
```

- FK composta `(company_id, event_id) → companies(id, event_id)`; `unique(id, event_id)` como alvo da FK de `teams`.
- O banco garante só valores válidos de status; o `ChallengeService` garante a semântica:
  - `DISTRIBUTED` e `IN_DEVELOPMENT` exigem equipe;
  - equipe vinculada só com `DISTRIBUTED`, `IN_DEVELOPMENT` ou `FINISHED`;
  - `FINISHED` mantém o vínculo da equipe (histórico).
- Empresa inativa não recebe desafio novo; desafio existente não é apagado nem desvinculado quando a empresa é inativada.
- Sem DELETE físico e sem status "excluído".

### teams.challenge_id (Fase 4)

Fonte **única** da relação desafio ↔ equipe (não existe `challenges.team_id`).

```text
teams.challenge_id FK nullable → challenges
```

- 1 equipe → no máximo 1 desafio; 1 desafio → no máximo 1 equipe (`unique(challenge_id)`; nulos não conflitam).
- FK composta `(challenge_id, event_id) → challenges(id, event_id)`: equipe e desafio do mesmo evento.
- Equipe inativa não recebe desafio novo.
- Distribuir, mover e retirar são operações próprias (`PATCH /challenges/{challenge}/team`), transacionais e com um único log.
- Se no futuro várias equipes puderem disputar o mesmo desafio, basta remover a unicidade de `teams.challenge_id`.

### sectors

```text
id
event_id FK
name
description nullable
active
created_at
updated_at
```

Nome único por evento sem diferença de caixa (`unique(event_id, lower(name))`) e `unique(id, event_id)` como alvo de FKs compostas. Setor inativo não recebe novos vínculos nem reuniões; só é inativado sem Gestor/Editor ativo vinculado.

### users.sector_id (Fase 2)

```text
users.sector_id FK nullable → sectors
```

Regra no banco (trigger `users_sector_matches_role`): Gestor (`MANAGER`) e Editor (`EDITOR`) **exigem** setor; os demais perfis **não têm** setor. Ao trocar de Gestor/Editor para outro perfil o setor é removido (auditado). Usuários não pertencem a um evento: o evento vem do setor.

### meetings

```text
id
event_id FK
sector_id FK nullable
title
description nullable
scheduled_at
location nullable
status
created_by_user_id FK
created_at
updated_at
```

Reunião geral: `sector_id` nulo. FK composta `(sector_id, event_id) → sectors(id, event_id)`: o setor é sempre do mesmo evento da reunião. Status `SCHEDULED | DONE | CANCELLED` (check).

## 4. Pendências

### tasks

Pendência = algo que precisa ser feito.

```text
id
event_id FK
reference            PEN-0001... gerada pelo banco (sequence), nunca pelo cliente
title
description
origin_sector_id FK          nunca muda
responsible_sector_id FK     muda só por encaminhamento
assigned_user_id FK nullable
priority             LOW | MEDIUM | HIGH | URGENT
status               PENDING | IN_PROGRESS | COMPLETED
due_at nullable
source_occurrence_id FK nullable   imutável; não é unique (uma ocorrência gera várias)
created_by_user_id FK
resolved_at nullable         preenchido em COMPLETED; volta a null na reabertura
created_at
updated_at
```

- FKs compostas com `event_id`: origem, responsável e `source_occurrence_id` são do mesmo evento.
- `unique(id, event_id)` como alvo das FKs compostas de `task_sectors`.
- Trigger: `reference`, `origin_sector_id`, `source_occurrence_id` e `event_id` não mudam depois da criação.
- Trigger: com a pendência aberta, `assigned_user_id` precisa ser usuário ativo do setor responsável.
- Sem DELETE.

### task_sectors

Somente setores **adicionais** envolvidos (não repete origem nem responsável).

```text
task_id FK
sector_id FK
event_id
primary key(task_id, sector_id)
```

FKs compostas `(task_id, event_id) → tasks` e `(sector_id, event_id) → sectors`: setor envolvido é do mesmo evento.

### task_interactions

Histórico contextual da pendência (não é auditoria). Somente inserção (trigger).

```text
id
task_id FK
user_id FK nullable
type      CREATED | UPDATED | COMMENT | STATUS_CHANGED | FORWARDED | ASSIGNEE_CHANGED | PRIORITY_CHANGED
          | DUE_CHANGED | SECTOR_ADDED | SECTOR_REMOVED | COMPLETED | RESOLVED | REOPENED | TASK_GENERATED
message nullable
metadata jsonb nullable
created_at
```

## 5. Ocorrências

### occurrences

Ocorrência = algo que aconteceu.

```text
id
event_id FK
reference            OCO-0001... gerada pelo banco (sequence)
title
description
category             TECHNOLOGY | INFRASTRUCTURE | PRODUCTION | PARTICIPANT | TEAM | COMPANY | ORGANIZATION | OTHER
origin_sector_id FK          nunca muda
responsible_sector_id FK     muda só por encaminhamento
assigned_user_id FK nullable
priority             LOW | MEDIUM | HIGH | URGENT
status               OPEN | IN_PROGRESS | RESOLVED
event_day_id FK nullable     pode ficar fora dos dias oficiais
occurred_at nullable
location nullable
team_id FK nullable
notes nullable
resolution nullable          preservada na reabertura; versões anteriores ficam no histórico
created_by_user_id FK
resolved_at nullable
created_at
updated_at
```

- FKs compostas com `event_id`: origem, responsável, dia (`event_days(id, event_id)`) e equipe (`teams(id, event_id)`).
- Categoria com check no banco (lista fechada; nova categoria = nova migration).
- Mesmos triggers da pendência (campos imutáveis e responsável individual coerente). Sem DELETE.

### occurrence_sectors

Mesmo padrão de `task_sectors` (somente envolvidos adicionais, com `event_id` e FKs compostas).

### occurrence_interactions

Mesmo padrão de `task_interactions` (somente inserção).

### Usuário atribuído

Usuário com pendência/ocorrência **aberta** atribuída não pode mudar de setor nem ser inativado (aplicação + trigger em `users`) até as demandas serem reatribuídas ou fechadas.

## 6. Jurados e avaliações

Separação obrigatória:

```text
Person
  ├── User?                  credencial de acesso
  ├── CompanyRepresentative? vínculo com empresa
  └── Juror?                 papel de domínio no Hackathon
```

Juror ≠ User ≠ CompanyRepresentative ≠ categoria externa JUROR. O vínculo User ↔ Juror é `users.person_id = jurors.person_id` (sem `user_id` em `jurors`). Criar um não cria o outro.

### jurors

```text
id
event_id FK
person_id FK
company_id FK nullable     só contexto; nunca define equipes
status                     ACTIVE | INACTIVE
notes nullable
created_at
updated_at

unique(event_id, person_id)
```

- FK composta `(company_id, event_id) → companies`; `unique(id, event_id)` como alvo de FKs compostas.
- Identidade (nome, e-mail, telefone, cargo) só em `people`. Sem DELETE.
- O Resource devolve um resumo derivado da conta (`has_account`, `account_status`, `account_role`, `access_ready`), sem coluna.

### juror_team_assignments

Atribuição sempre explícita (nunca por empresa, desafio, representante, categoria externa, turma ou setor).

```text
id
event_id FK
juror_id FK
team_id FK
status                     ACTIVE | REVOKED
assigned_by_user_id FK
assigned_at
revoked_by_user_id FK nullable
revoked_at nullable
created_at
updated_at

unique(juror_id, team_id)  reativar reutiliza o registro
```

- FKs compostas com `event_id` para jurado e equipe. Check: `REVOKED` ⇔ `revoked_at` preenchido.
- Nova atribuição exige jurado e equipe ativos. Revogar não apaga avaliação.
- Gestão em lote: `PUT /jurors/{juror}/assignments` com a lista final de equipes (um único log).

### evaluation_criteria

Numéricos nesta fase (sem conversão de conceito).

```text
id
event_id FK
name                       único por evento sem diferença de caixa
description nullable
min_score decimal
max_score decimal          > min_score
weight decimal             > 0 (pesos não precisam somar 100)
sort_order                 >= 1
active
created_at
updated_at
```

Travamento: depois que existe qualquer avaliação do evento (inclusive `DRAFT`), não se cria critério nem se altera faixa, peso, ordem ou ativo. Só nome e descrição continuam editáveis (texto, sem mudar o significado). Sem DELETE.

### evaluations

```text
id
event_id FK
juror_id FK
team_id FK
status                     DRAFT | SUBMITTED | REVISION_REQUESTED
comments nullable
submitted_at nullable
revision_reason nullable
revision_requested_at nullable
revision_requested_by_user_id FK nullable
created_at
updated_at

unique(juror_id, team_id)
```

- FK `(juror_id, team_id) → juror_team_assignments(juror_id, team_id)`: só existe avaliação de atribuição existente. FKs compostas com `event_id` para jurado e equipe.
- Check: `SUBMITTED` exige `submitted_at`.
- Trigger: avaliação `SUBMITTED` não muda comentário nem volta para `DRAFT`; jurado, equipe e evento não mudam.
- Criada só quando o jurado salva pela primeira vez (não há avaliações vazias pré-criadas).

### evaluation_scores

```text
id
event_id FK
evaluation_id FK
criterion_id FK
score decimal
comment nullable
created_at
updated_at

unique(evaluation_id, criterion_id)
```

- FKs compostas com `event_id` para avaliação e critério.
- Trigger: `min_score <= score <= max_score` do critério; notas de avaliação `SUBMITTED` não são inseridas, alteradas nem apagadas.

### Cálculo (não persistido)

```text
normalizado(critério) = (score - min_score) / (max_score - min_score)
percentual(avaliação) = 100 * Σ(normalizado * peso) / Σ(peso)      critérios ativos
resultado técnico(equipe) = média dos percentuais das avaliações SUBMITTED com atribuição ACTIVE
```

Sem arredondar intermediários (a API apresenta com duas casas). Não é o resultado final do Hackathon e não usa voto público.

## 7. Votação pública e publicação de resultados (Fase 7)

Três domínios independentes, sem score combinado:

```text
AVALIAÇÃO TÉCNICA   jurados → resultado técnico (Fase 6, derivado, não persistido)
VOTAÇÃO PÚBLICA     votantes elegíveis → Escolha do Público (contagem da sessão selecionada)
PUBLICAÇÃO          snapshot imutável e versionado → resultado oficial
```

### voting_sessions

```text
id
event_id FK
name                       único por evento sem diferença de caixa
status                     DRAFT | OPEN | CLOSED | CANCELLED
opened_at nullable
closed_at nullable
cancelled_at nullable
cancel_reason nullable
created_by_user_id FK
created_at
updated_at
```

- `unique(id, event_id)` como alvo das FKs compostas; `unique(event_id, lower(name))`.
- **No máximo uma sessão `OPEN` por evento**: índice único parcial `(event_id) where status = 'OPEN'`.
- Checks de coerência: `DRAFT` sem `opened_at`/`closed_at`; `OPEN` com `opened_at` e sem `closed_at`; `CLOSED` com `opened_at <= closed_at`; `CANCELLED` com `cancelled_at`.
- Trigger: transições só `DRAFT → OPEN`, `DRAFT → CANCELLED`, `OPEN → CLOSED`, `OPEN → CANCELLED` (só sem votos). `CLOSED` e `CANCELLED` são terminais (nada muda). Nome só muda em `DRAFT`. `event_id`, `created_by_user_id` e `opened_at` não mudam. Abrir exige ao menos 1 candidata e 1 elegível. Sem DELETE (trigger).
- Sessão fechada nunca reabre: repetir votação = nova sessão.

### voting_session_teams (candidatas)

```text
id
event_id
voting_session_id
team_id
created_at

unique(voting_session_id, team_id)   também alvo da FK de public_votes
```

- FKs compostas `(voting_session_id, event_id) → voting_sessions` e `(team_id, event_id) → teams`: candidata é do mesmo evento da sessão.
- Trigger: só entra/sai com a sessão em `DRAFT`; equipe precisa estar `ACTIVE`; sem UPDATE.
- Nunca inferida por desafio, empresa, jurado ou posição técnica.

### voting_eligibilities

```text
id
event_id
voting_session_id
user_id FK
created_by_user_id FK
created_at

unique(voting_session_id, user_id)   também alvo da FK de public_votes
```

- FK composta `(voting_session_id, event_id) → voting_sessions`.
- Trigger: só entra/sai com a sessão em `DRAFT`; o usuário precisa estar `ACTIVE` com perfil `VOTER`; sem UPDATE.
- Não cria conta nem transforma Person em User. Ter perfil `VOTER` não basta: a elegibilidade é explícita por sessão (o HackLab suporta vários eventos e ainda não há `event_registrations`; na Fase 9 as inscrições poderão alimentar esta tabela).

### public_votes

```text
id
event_id
voting_session_id
user_id FK
team_id
cast_at
created_at

unique(voting_session_id, user_id)   1 usuário → 1 voto → por sessão
```

- FKs compostas: `(voting_session_id, event_id) → voting_sessions`; `(voting_session_id, team_id) → voting_session_teams` (a equipe é candidata da sessão); `(voting_session_id, user_id) → voting_eligibilities` (o usuário é elegível); `(team_id, event_id) → teams`.
- **Append-only**: trigger recusa `UPDATE` e `DELETE` (o model também). Voto é definitivo.
- Trigger de inserção: sessão `OPEN` (lida com `FOR SHARE`) e usuário `ACTIVE` com perfil `VOTER` no instante do voto.
- Um voto = exatamente uma equipe. Sem nota, sem ranking de várias equipes, sem peso.
- **Sigilo na aplicação, não anonimato criptográfico**: `user_id` é necessário para garantir voto único. Nenhuma API devolve a relação eleitor → equipe (só o próprio eleitor vê o próprio voto), e `audit_logs` nunca registra a escolha. Quem tem acesso direto ao PostgreSQL consegue consultar a tabela.

### result_publications

```text
id
event_id FK
voting_session_id          sessão pública selecionada (CLOSED)
version                    1, 2, 3... por evento
status                     PUBLISHED | REVOKED
snapshot jsonb             resultado calculado e congelado no momento da publicação
published_by_user_id FK
published_at
revoked_by_user_id FK nullable
revoked_at nullable
revoke_reason nullable
created_at
updated_at

unique(event_id, version)
```

- **No máximo uma publicação `PUBLISHED` por evento**: índice único parcial `(event_id) where status = 'PUBLISHED'`.
- FK composta `(voting_session_id, event_id) → voting_sessions`.
- Checks: `version >= 1`; `REVOKED` ⇔ `revoked_at`, `revoked_by_user_id` e `revoke_reason` preenchidos.
- Trigger: a sessão selecionada precisa estar `CLOSED` na inserção; `snapshot`, `version`, evento, sessão e dados de publicação nunca mudam; a única transição é `PUBLISHED → REVOKED`; `REVOKED` é terminal; sem DELETE.
- Corrigir = revogar (motivo obrigatório) e publicar a versão seguinte. A versão anterior continua armazenada.
- Pré-condições verificadas pelo `ResultPublicationService` (sob lock do evento): sessão `CLOSED` do evento, nenhuma `PUBLISHED`, nenhuma atribuição `ACTIVE` sem avaliação `SUBMITTED` e **ao menos 1 avaliação técnica válida** (`SUBMITTED` + atribuição `ACTIVE`).

Snapshot (somente agregados):

```text
event { id, name }
version, published_at
voting_session { id, name, opened_at, closed_at }
technical { teams[ { team, technical_score, technical_score_exact, valid_evaluations, rank, tied } ], has_ties }
popular   { teams[ { team, vote_count, percentage, rank, tied } ], total_votes, has_ties }
turnout   { eligible_voters, votes_cast, turnout_percent }
rules     { technical: "juror_evaluations", popular: "one_person_one_vote", combined: false }
```

Nunca entram no snapshot: identidade dos votantes, notas individuais dos jurados, comentários dos jurados.

### Trava técnica enquanto há publicação

Com uma publicação `PUBLISHED` no evento, triggers recusam:
- inserir ou alterar `juror_team_assignments` (atribuir, revogar, reativar);
- mudar o `status` de `evaluations` (ex.: solicitar revisão).

A aplicação aplica a mesma regra (422 com mensagem) antes de chegar ao banco. Revogar a publicação libera as operações.

### Auditoria da votação e da publicação

Só operações administrativas: `VOTING_SESSION_CREATED`, `VOTING_SESSION_UPDATED`, `VOTING_CANDIDATES_CHANGED`, `VOTING_ELIGIBILITY_CHANGED`, `VOTING_SESSION_OPENED`, `VOTING_SESSION_CLOSED`, `VOTING_SESSION_CANCELLED`, `RESULTS_PUBLISHED`, `RESULTS_PUBLICATION_REVOKED`. Não existe `PUBLIC_VOTE_CAST`: registrar "usuário X votou na equipe Y" criaria outra tabela cruzando identidade e escolha.

### Cálculos (não persistidos fora do snapshot)

```text
técnico(equipe)   = média dos percentuais das avaliações SUBMITTED com atribuição ACTIVE (regra da Fase 6)
                    comparado em aritmética decimal (bcmath, 30 casas), nunca pelo valor arredondado exibido
votos(equipe)     = contagem de public_votes da sessão selecionada
percentual voto   = 100 * votos da equipe / total de votos válidos (0 se não houver votos)
participação      = 100 * votos / eleitores elegíveis da sessão
posição           = 1 + quantidade de equipes com valor estritamente maior (empates: 1, 1, 3)
```

Sem desempate artificial (ID, cadastro, hora do voto, nome, nota técnica). Ordem alfabética só para apresentação, mantendo a mesma posição.

## 8. Integração Even3 (Fase 9)

Formatos externos e PoC em `integracao-even3.md`. Nenhuma tabela guarda token ou segredo.

### events.timezone (Fase 9)

```text
events.timezone nullable   IANA (ex.: America/Sao_Paulo); validado pelo backend
```

Necessário para associar check-ins a `event_days`. Nunca deduzido de cidade/UF.

### event_integrations

```text
id
event_id FK
provider                   EVEN3
external_event_id          id do evento na plataforma (texto)
external_event_title nullable   último título lido (só exibição)
api_version                V2
status                     ACTIVE | DISABLED
last_connection_checked_at nullable
last_sync_at nullable
last_sync_status nullable  SUCCESS | PARTIAL | FAILED
last_error_code nullable
last_error_at nullable
created_at
updated_at

unique(event_id, provider)
unique(provider, external_event_id)    o mesmo evento externo não alimenta dois eventos internos
unique(id, event_id)                   alvo das FKs compostas
```

### external_ticket_types

```text
id
event_id
event_integration_id
external_ticket_id         prices[].ticket_id da V2 (identidade estável)
external_name              título atual da entrada
internal_category nullable PARTICIPANT | ORGANIZATION | COMPANY_GUEST | JUROR | PUBLIC
active                     false quando some de uma leitura completa (nunca apagada)
last_seen_at nullable
created_at
updated_at

unique(event_integration_id, external_ticket_id)
```

- FK composta `(event_integration_id, event_id) → event_integrations`.
- Nova entrada nasce sem `internal_category`. Renomear na Even3 só muda `external_name`.

### event_registrations

```text
id
event_id
event_integration_id
person_id FK
external_attendee_id nullable        attendees[].id da V2
external_person_id nullable          pessoa.id do webhook
external_registration_id nullable    inscricao.id do webhook
external_ticket_type_id FK nullable
external_category_label nullable     texto da categoria como veio da origem
status                     PENDING | CONFIRMED | CANCELLED | UNKNOWN
status_changed_at nullable           base para não aplicar webhook mais antigo
check_in_code nullable
registered_at nullable
source_name
source_badge_name nullable
source_email nullable
source_phone nullable
source_document nullable
identity_mismatch          e-mail/documento da origem ≠ cadastro interno
last_seen_at nullable
last_synced_at nullable
created_at
updated_at
```

- Índices únicos parciais (valores não nulos): `(event_integration_id, external_attendee_id)` e `(event_integration_id, external_registration_id)`.
- Sem unique por pessoa: a mesma Person pode ter várias inscrições externas na integração (índice `(event_integration_id, person_id)` só para consulta). A identidade da inscrição são os IDs externos; documento/e-mail só associam a Person.
- FKs compostas com `event_id` para integração e entrada; `unique(id, event_id)` como alvo de `attendance_records`.
- Ausência numa sincronização nunca muda o status.

### attendance_records

```text
id
event_id
event_integration_id
event_registration_id
event_day_id nullable      derivado do horário + events.timezone; nulo se fora dos dias
kind                       EVENT | ACTIVITY
external_activity_id nullable
checked_in_at nullable     só com horário de check-in explícito na origem
observed_at                instante observado (created do webhook)
source                     WEBHOOK | API
webhook_receipt_id FK nullable
created_at

unique(webhook_receipt_id) quando não nulo   um check-in por disparo
```

- FKs compostas com `event_id` para inscrição e dia (`event_days` ganha `unique(id, event_id)`).
- Vários registros no mesmo dia são permitidos. Presença diária é derivada (ao menos um registro no `EventDay`).

### integration_sync_runs

```text
id
event_integration_id
scope                      EVENT | TICKETS | ATTENDEES | ALL
trigger                    MANUAL | SCHEDULED | RECONCILIATION
status                     RUNNING | SUCCESS | PARTIAL | FAILED
started_by_user_id FK nullable
started_at
finished_at nullable
duration_ms nullable
counters jsonb
warnings jsonb nullable    [{ code, external_id, message }] — sem payload externo nem dados pessoais completos
error_code nullable
error_message nullable     mensagem segura (nunca token)
created_at
updated_at

unique(event_integration_id) where status = 'RUNNING'    um sync por integração
```

### integration_webhook_receipts

```text
id
event_integration_id nullable   nulo quando o evento do payload não é de nenhuma integração
external_delivery_id            id único do disparo (Even3)
event_type_id nullable
event_type_name
payload_hash                    sha256 do corpo (sem guardar o payload)
status                          RECEIVED | PROCESSED | IGNORED | FAILED
received_at
processed_at nullable
error_code nullable
error_message nullable
created_at
updated_at

unique(event_integration_id, external_delivery_id)
unique(external_delivery_id) where event_integration_id is null
```

Não guarda token nem payload completo.

## 9. Webhooks e sincronização

Ver `integration_sync_runs` e `integration_webhook_receipts` (§8). Webhook é atualização rápida; a sincronização pela API reconcilia.

## 10. Documentos

### documents

```text
id
event_id FK
sector_id FK nullable
title
description nullable
file_path
mime_type nullable
size nullable
uploaded_by_user_id FK
created_at
updated_at
```

## 11. Auditoria

### audit_logs

```text
id
actor_user_id FK nullable
actor_person_id FK nullable
action
module
entity_type nullable
entity_id nullable
description
before_data jsonb nullable
after_data jsonb nullable
ip_address nullable
user_agent nullable
created_at
```

Não permitir update/delete por endpoints normais.

Implementado na Fase 1:
- somente inserção, garantido em duas camadas: o model `AuditLog` recusa update/delete e um trigger no PostgreSQL (`audit_logs_no_update_delete`) recusa `UPDATE`/`DELETE`;
- `actor_user_id`/`actor_person_id` com `restrict`: usuários e pessoas são inativados, não apagados;
- `App\Domain\Audit\AuditLogger` remove de `before_data`/`after_data`, em qualquer nível, chaves com `password`, `token`, `secret`, `cookie`, `authorization`, `api_key` ou `remember`;
- ações auditadas na Fase 1: `LOGIN`, `LOGIN_FAILED`, `LOGIN_BLOCKED_INACTIVE`, `LOGOUT`, `USER_CREATED`, `USER_UPDATED`, `USER_ROLE_CHANGED`, `USER_ACTIVATED`, `USER_INACTIVATED`, `PERSON_CREATED`, `PERSON_UPDATED`.

### Enriquecimento (Fase 8)

Migration incremental (a da Fase 1 não muda; nenhuma segunda tabela de auditoria):

```text
event_id FK nullable            evento do objeto auditado (contexto inequívoco), nunca do ator
actor_name nullable             ┐
actor_email nullable            │
actor_role_code nullable        │ fotografia do ator NO MOMENTO da ação
actor_role_name nullable        │ (não muda se a pessoa trocar de perfil/setor/nome depois)
actor_sector_id FK nullable     │
actor_sector_name nullable      ┘
entity_label nullable           rótulo legível da entidade NO MOMENTO da ação ("Equipe Atlas")
```

- IDs (`actor_user_id`, `actor_person_id`, `actor_sector_id`, `event_id`) = identidade relacional; textos = snapshot histórico. Os dois convivem; nenhum substitui o outro.
- Administrador, Consultor, Jurado e Votante não têm setor: `actor_sector_*` nulos (nada é inventado).
- `actor_role_code` (ex.: `EDITOR`) acompanha `actor_role_name` (ex.: `Editor`) para filtrar por código estável.
- `event_id`: `Event` → o próprio id; entidade com coluna `event_id` → ela; `CompanyRepresentative` → evento da empresa. `User`, `Person` e login ficam globais (`null`). Nunca inferido pelo setor do ator.
- `entity_label`: inferido de `reference · title`, `name`, `title`, `full_name`, `email` ou `code` (ou de `auditLabel()` no model, ex.: participante → nome da pessoa), com fallback `Tipo #id`. Snapshot: renomear a entidade não muda logs antigos. Só apresentação, nunca regra de negócio.
- Logs anteriores à migration ficam com essas colunas nulas: a tabela é somente inserção e não há backfill (seria `UPDATE`).
- Índices novos: `(event_id, created_at)`, `(module, created_at)`, `(actor_user_id, created_at)`, `(actor_sector_id, created_at)`. Sem GIN em `before_data`/`after_data` (não há busca livre no JSON).
- Sem purge, retenção ou exportação nesta versão.

### Sanitização

`App\Domain\Audit\AuditSanitizer` (única implementação, usada pelo `AuditLogger` na gravação e, defensivamente, pelo detalhe da API) remove recursivamente chaves que contenham, ignorando caixa, `_` e `-`: `password`, `token`, `secret`, `cookie`, `authorization`, `apikey`/`api_key`, `remember`, `csrf`, `privatekey`/`private_key`, `accesskey`/`access_key`. `session` sozinho **não** é sensível (`voting_session_id` é legítimo).

## 12. Índices importantes

Criar índices para:
- `people.email`;
- `users.email`;
- `users.role_id`;
- `users.sector_id`;
- `participants(event_id, status)`;
- `team_members(team_id, active)`;
- `tasks(responsible_sector_id, status)`;
- `occurrences(responsible_sector_id, status)`;
- `event_registrations(event_id, person_id)`;
- IDs externos;
- `attendance_records(event_day_id, person_id)`;
- `audit_logs(created_at)`;
- `audit_logs(actor_user_id)`;
- `audit_logs(module, entity_type, entity_id)`.

## 13. Seed mínimo

Criar seed de desenvolvimento com:
- 1 evento;
- 3 dias;
- roles;
- permissions básicas;
- 1 Administrador;
- setores;
- turmas;
- participantes;
- equipes;
- empresas;
- desafios;
- jurados;
- assignments;
- pendências;
- ocorrências;
- inscrições externas simuladas;
- presença dos três dias;
- logs de auditoria.

Dados fictícios apenas.
