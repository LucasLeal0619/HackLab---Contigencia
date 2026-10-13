# Plano de Implementação

## Objetivo

Construir o backend sem bloquear o frontend e sem tentar implementar tudo de uma vez.

## Fase 0 — Fundação

- [x] inicializar Laravel em `back/`;
- [x] Docker Compose de desenvolvimento (Laravel + PostgreSQL 17);
- [x] configurar PostgreSQL (`hacklab_dev`, `hacklab_test`, schema `hacklab`);
- [x] validar conexão com `php artisan hacklab:db-check` antes de qualquer migration;
- [x] configurar `.env.example`;
- [x] configurar CORS;
- [x] configurar Sanctum;
- [x] criar `/api/v1/health`;
- [x] configurar testes em PostgreSQL (`hacklab_test`, `.env.testing`);
- [x] DB guard (testes só em `_test`; dev/testes nunca no Supabase; produção só com SSL);
- [ ] configurar seed (feito na Fase 1);
- [x] criar estrutura de módulos;
- [x] criar tratamento padrão de exceções JSON.

Critério de pronto:

```text
GET /api/v1/health
→ 200
```

e migrations executam do zero em `hacklab_dev` (PostgreSQL) e todos os testes passam em `hacklab_test`, tudo via Docker.

Revalidação obrigatória após a troca MySQL → PostgreSQL (09/10/2026): health, CORS, erros JSON, Sanctum, testes, DB guard, migrations padrão e Pint. **Não iniciar a Fase 1 antes disso.** Revalidada em 09/10/2026: 26 testes passando em `hacklab_test`, migrations do zero em `hacklab_dev`, health 200, CORS, cookie CSRF e Pint ok. Fase 0 aprovada e concluída em 09/10/2026.

~~Pendência: compilar a imagem Docker `app` numa rede sem o bloqueio do Fortinet.~~ **Resolvida em 09/10/2026:** a imagem `app` compilou em outra rede (PHP 8.3 com `pdo_pgsql`, `pgsql`, `intl`, `zip`, `bcmath`, `opcache`, `pcntl`). Validado no Docker: PostgreSQL saudável, API respondendo em `localhost:8000`, `hacklab:db-check` ok, 13 migrations aplicadas, 122 testes passando dentro do container e Pint ok. As camadas ficam em cache: na rede do Senac só é preciso refazer o build se o `back/Dockerfile` mudar.

## Fase 1 — Pessoas, autenticação e autorização

- [x] people;
- [x] users (vinculados a people; `sector_id` fica para a Fase 2);
- [x] roles;
- [x] permissions / role_permission;
- [x] login;
- [x] logout;
- [x] me;
- [x] policies;
- [x] seed dos 6 perfis;
- [x] auditoria inicial.

Concluída em 09/10/2026: 73 testes passando em `hacklab_test`, migrations do zero, seed idempotente, `db-check`, Pint, login/logout/me validados com cookies reais, nenhuma tabela em `public`, `hacklab_dev` intacto após os testes.

Endpoints entregues (`/api/v1`):

| Método | Rota | Acesso |
|---|---|---|
| POST | `/auth/login` | público (SPA, CSRF, 5 tentativas/min por e-mail+IP) |
| POST | `/auth/logout` | autenticado |
| GET | `/auth/me` | autenticado (inclui permissões) |
| GET | `/roles` | `roles.view` |
| GET/POST | `/people` | `people.view` / `people.manage` |
| GET/PATCH | `/people/{id}` | `people.view` ou a própria pessoa / `people.manage` |
| GET/POST | `/users` | `users.view` / `users.manage` |
| GET/PATCH | `/users/{id}` | `users.view` ou a própria conta / `users.manage` |
| PATCH | `/users/{id}/role` | `users.manage` |
| PATCH | `/users/{id}/status` | `users.manage` |

Todas as rotas autenticadas passam por `EnsureUserIsActive`: conta inativada perde o acesso na requisição seguinte.

Perfis:

```text
Administrador
Gestor
Editor
Consultor
Jurado
Votante
```

## Fase 2 — Evento e setores

- [x] events;
- [x] event_days;
- [x] sectors;
- [x] meetings;
- [x] users.sector_id (Gestor/Editor com setor obrigatório; demais sem setor);
- [x] escopo Gestor/Editor por setor;
- [x] permissões/policies da fase, auditoria, seeds e factories.

Concluída em 09/10/2026 (aguardando aprovação para commit): 122 testes passando em `hacklab_test`, 13 migrations do zero, seed idempotente, `db-check`, Pint, escopo do Gestor validado via HTTP com cookies reais, nenhuma tabela em `public`, `hacklab_dev` intacto após os testes.

Endpoints entregues (`/api/v1`):

| Método | Rota | Acesso |
|---|---|---|
| GET/POST | `/events` | `events.view` / `events.manage` + global |
| GET/PATCH | `/events/{event}` | `events.view` / `events.manage` + global |
| GET/POST | `/events/{event}/days` | `events.view` / `events.manage` + global |
| PATCH | `/events/{event}/days/{day}` | `events.manage` + global |
| GET/POST | `/events/{event}/sectors` | `sectors.view` (no escopo) / `sectors.manage` + global |
| GET/PATCH | `/sectors/{sector}` | `sectors.view` / `sectors.manage`, ambos no escopo |
| PATCH | `/sectors/{sector}/status` | `sectors.manage` + global |
| GET/POST | `/events/{event}/meetings` | `meetings.view` (gerais + escopo) / `meetings.manage` no setor; geral só global |
| GET/PATCH | `/meetings/{meeting}` | idem; mover de setor exige alcance no destino |
| PATCH | `/users/{user}/role` | `users.manage`; `sector_id` obrigatório ao virar Gestor/Editor |
| PATCH | `/users/{user}/sector` | `users.manage`; só Gestor/Editor |

## Fase 3 — Participantes e equipes

- [x] classes;
- [x] participants;
- [x] teams (sem `challenge_id`, que entra na Fase 4);
- [x] team_members (com histórico);
- [x] regras de status;
- [x] impedir membro ativo em múltiplas equipes (aplicação + índice único parcial no banco);
- [x] endpoints de ajuste manual (adicionar, mover, remover);
- [x] permissões/policies, auditoria, factories e seeds.

Concluída em 09/10/2026 (aguardando aprovação para commit): 154 testes passando em `hacklab_test` dentro do Docker, 16 migrations do zero, seed idempotente, `db-check`, Pint, movimentação validada via HTTP com cookies reais (um único log), nenhuma tabela em `public`, `hacklab_dev` intacto após os testes.

Endpoints entregues (`/api/v1`):

| Método | Rota | Acesso |
|---|---|---|
| GET/POST | `/events/{event}/classes` | `classes.view` / `classes.manage` |
| GET/PATCH | `/classes/{class}` | `classes.view` / `classes.manage` |
| PATCH | `/classes/{class}/status` | `classes.manage` |
| GET/POST | `/events/{event}/participants` | `participants.view` / `participants.manage`. Filtros: `class_id`, `status`, `has_team`, `team_id`, `search` |
| GET/PATCH | `/participants/{participant}` | `participants.view` (inclui histórico de equipes) / `participants.manage` |
| PATCH | `/participants/{participant}/team` | `teams.manage` — adiciona ou move |
| GET/POST | `/events/{event}/teams` | `teams.view` / `teams.manage` |
| GET/PATCH | `/teams/{team}` | `teams.view` (composição atual) / `teams.manage` |
| GET | `/teams/{team}/members` | `teams.view`; `?history=1` inclui vínculos encerrados |
| POST | `/teams/{team}/members` | `teams.manage` |
| DELETE | `/teams/{team}/members/{participant}` | `teams.manage` — encerra o vínculo |

Não implementar formação inteligente complexa antes do CRUD e regras básicas estarem estáveis.

## Fase 4 — Empresas e desafios

- [x] companies;
- [x] company_representatives;
- [x] challenges;
- [x] vínculo equipe/desafio (`teams.challenge_id`, 1:1);
- [x] permissões/policies, auditoria, seeds e testes.

Concluída em 09/10/2026 (aguardando aprovação para commit): 196 testes passando em `hacklab_test` dentro do Docker, 19 migrations do zero, seed idempotente, `db-check`, Pint, distribuição validada via HTTP com cookies reais, nenhuma tabela em `public`, `hacklab_dev` intacto após os testes.

Endpoints entregues (`/api/v1`):

| Método | Rota | Acesso |
|---|---|---|
| GET/POST | `/events/{event}/companies` | `companies.view` / `companies.manage`. Filtros: `status`, `type`, `search` (nome, razão social, documento) |
| GET/PATCH | `/companies/{company}` | `companies.view` (com representantes e resumo dos desafios) / `companies.manage` |
| PATCH | `/companies/{company}/status` | `companies.manage` |
| GET/POST | `/companies/{company}/representatives` | `companies.view` (`?active=`) / `companies.manage` |
| PATCH | `/company-representatives/{representative}` | `companies.manage` |
| PATCH | `/company-representatives/{representative}/status` | `companies.manage` |
| GET/POST | `/events/{event}/challenges` | `challenges.view` / `challenges.manage`. Filtros: `status`, `company_id`, `has_company`, `has_team`, `search` |
| GET/PATCH | `/challenges/{challenge}` | `challenges.view` (com empresa e equipe) / `challenges.manage` |
| PATCH | `/challenges/{challenge}/status` | `challenges.manage` |
| PATCH | `/challenges/{challenge}/team` | `challenges.manage` — distribui, move ou retira (`team_id: null`) |

`GET /events/{event}/teams` ganhou o filtro `has_challenge` e as equipes passaram a devolver o desafio.

Especificação fechada em 09/10/2026: ver `banco-de-dados.md` (companies, company_representatives, challenges, teams.challenge_id) e `regras-de-negocio.md` §12 e §12.1.

## Fase 5 — Gestão operacional

- [x] tasks;
- [x] task_sectors;
- [x] task_interactions;
- [x] occurrences;
- [x] occurrence_sectors;
- [x] occurrence_interactions;
- [x] encaminhamento;
- [x] ocorrência → pendência;
- [x] regras de visibilidade intersetorial;
- [x] responsável individual coerente com o setor (aplicação + banco);
- [x] ampliação da regra de inativação de setor;
- [x] permissões/policies, auditoria, seeds e testes.

Concluída em 09/10/2026 (aguardando aprovação para commit): 249 testes passando em `hacklab_test` dentro do Docker, 23 migrations do zero, seed idempotente, `db-check`, Pint, encaminhamento e poderes por perfil validados via HTTP com cookies reais, nenhuma tabela em `public`, `hacklab_dev` intacto após os testes.

Endpoints entregues (`/api/v1`):

| Método | Rota | Acesso |
|---|---|---|
| GET/POST | `/events/{event}/tasks` | `tasks.view` (escopo na query) / `tasks.create` (+ regras de origem/responsável) |
| GET/PATCH | `/tasks/{task}` | ver: participa; PATCH: estrutura = gestão do responsável, status = operação do responsável |
| POST | `/tasks/{task}/comments` | `tasks.comment` + participa |
| POST | `/tasks/{task}/forward` | `tasks.route` + responsável atual |
| POST | `/tasks/{task}/complete` · `/reopen` | `tasks.operate` + responsável atual |
| GET/POST | `/events/{event}/occurrences` | idem com `occurrences.*` |
| GET/PATCH | `/occurrences/{occurrence}` | idem |
| POST | `/occurrences/{occurrence}/comments` · `/forward` · `/resolve` · `/reopen` | idem |
| POST | `/occurrences/{occurrence}/tasks` | `tasks.create` + `occurrences.route` + setor relacionado |

Filtros de pendências: `search`, `status`, `priority`, `origin_sector_id`, `responsible_sector_id`, `involved_sector_id`, `assigned_user_id`, `due_before`, `due_after`, `overdue`, `source_occurrence_id`. Ocorrências: `search`, `status`, `priority`, `category`, `origin_sector_id`, `responsible_sector_id`, `involved_sector_id`, `assigned_user_id`, `event_day_id`, `team_id`, `occurred_before`, `occurred_after`. O detalhe (`GET`) devolve o histórico e `meta.abilities` (o que o usuário pode fazer).

Especificação fechada em 09/10/2026: ver `banco-de-dados.md` §4–5 e `regras-de-negocio.md` §5–8.

## Fase 6 — Jurados e avaliações

- [x] jurors;
- [x] juror_team_assignments;
- [x] evaluation_criteria;
- [x] evaluations;
- [x] evaluation_scores;
- [x] fluxo de submissão;
- [x] revisão solicitada pelo Administrador e reenvio;
- [x] progresso agregado e resultado técnico (derivados);
- [x] permissões/policies, auditoria, seeds e testes.

Concluída em 09/10/2026 (aguardando aprovação para commit): 297 testes passando em `hacklab_test` dentro do Docker, 26 migrations do zero, seed idempotente, `db-check`, Pint, fluxo jurado → envio → revisão → reenvio validado via HTTP com cookies reais, nenhuma tabela em `public`, `hacklab_dev` intacto após os testes.

Endpoints entregues (`/api/v1`):

| Método | Rota | Acesso |
|---|---|---|
| GET/POST | `/events/{event}/jurors` | `jurors.view` / `jurors.manage`. Filtros: `status`, `company_id`, `search` |
| GET/PATCH | `/jurors/{juror}` | `jurors.view` (com conta derivada e atribuições) / `jurors.manage` |
| PATCH | `/jurors/{juror}/status` | `jurors.manage` |
| GET/PUT | `/jurors/{juror}/assignments` | `jurors.view` / `jurors.manage` (lista final de equipes, um único log) |
| GET/POST | `/events/{event}/evaluation-criteria` | `evaluation_criteria.view` (com `meta.locked`) / `evaluation_criteria.manage` |
| PATCH | `/evaluation-criteria/{criterion}` · `/status` | `evaluation_criteria.manage` (travado após a 1ª avaliação, exceto nome/descrição) |
| GET | `/events/{event}/my-evaluations` | `evaluations.own` (atribuições ativas da própria Person) |
| PUT | `/juror-assignments/{assignment}/evaluation` | `evaluations.own` + mesma Person (rascunho) |
| POST | `/juror-assignments/{assignment}/evaluation/submit` | `evaluations.own` + mesma Person (envio/reenvio) |
| GET | `/events/{event}/evaluations` · `/evaluations/{evaluation}` | `evaluations.all.view` (ou a própria, para o jurado) |
| POST | `/evaluations/{evaluation}/request-revision` | `evaluations.request_revision` |
| GET | `/events/{event}/evaluation-progress` | `evaluations.progress.view` (sem notas) |
| GET | `/events/{event}/technical-results` | `evaluations.all.view` (não é o resultado final) |

Especificação fechada em 09/10/2026: ver `banco-de-dados.md` §6 e `regras-de-negocio.md` §10–11.

Regra obrigatória:
atribuição de equipes é explícita.

## Fase 7 — Votação pública e resultados

- [x] voting_sessions (DRAFT → OPEN → CLOSED; DRAFT/OPEN sem votos → CANCELLED; 1 OPEN por evento);
- [x] voting_session_teams (candidatas explícitas);
- [x] voting_eligibilities (User ACTIVE + VOTER, explícito por sessão);
- [x] public_votes (append-only, 1 por usuário/sessão, retry idempotente, concorrência);
- [x] participação durante OPEN e contagem por equipe só após CLOSED;
- [x] result_publications (snapshot versionado, 1 PUBLISHED por evento, revogação com motivo);
- [x] prévia, publicação, revogação e consulta do resultado oficial;
- [x] trava técnica enquanto há publicação;
- [x] permissões/policies, auditoria (sem a escolha do eleitor), seeds e testes.

Três domínios independentes: Resultado Técnico (jurados), Escolha do Público (votos) e Publicação (snapshot). Sem score combinado e sem desempate artificial.

Concluída e aprovada em 12/10/2026: 385 testes passando em `hacklab_test` dentro do Docker (297 anteriores + 88 novos, incluindo 7 de concorrência real com processos paralelos e o bloqueio de publicação sem avaliação técnica válida), 28 migrations do zero, seed idempotente, `db-check`, Pint, fluxo admin → abertura → votos → troca recusada → participação → fechamento → resultado agregado → publicação → resultado oficial validado via HTTP com cookies reais, nenhuma tabela em `public`, `hacklab_dev` intacto após os testes.

Especificação fechada em 12/10/2026: ver `banco-de-dados.md` §7 e `regras-de-negocio.md` §11.

Endpoints (`/api/v1`):

| Método | Rota | Acesso |
|---|---|---|
| GET/POST | `/events/{event}/voting-sessions` | `voting_sessions.view` / `voting_sessions.manage`. Filtros: `status`, `search` |
| GET/PATCH | `/voting-sessions/{session}` | `voting_sessions.view` (candidatas + totais) / `voting_sessions.manage` (só nome, em DRAFT) |
| PUT | `/voting-sessions/{session}/teams` | `voting_sessions.manage` (lista final; só DRAFT; um log) |
| GET | `/voting-sessions/{session}/eligibilities` | `voting_sessions.view`. Filtros: `search` (pessoa/e-mail), `has_voted`. Nunca traz a escolha |
| PUT | `/voting-sessions/{session}/eligibilities` | `voting_eligibility.manage` (lista final; só DRAFT; um log) |
| POST | `/voting-sessions/{session}/open` · `/close` · `/cancel` | `voting_sessions.manage` |
| GET | `/voting-sessions/{session}/turnout` | `voting_aggregates.view` (só totais) |
| GET | `/voting-sessions/{session}/results` | `voting_aggregates.view` (só CLOSED) |
| GET | `/events/{event}/my-voting-sessions` | `votes.cast` (sessões em que o usuário é elegível) |
| GET | `/voting-sessions/{session}/ballot` | `votes.cast` + VOTER + elegível |
| POST | `/voting-sessions/{session}/vote` | `votes.cast` + VOTER + elegível (201 novo / 200 retry idêntico) |
| GET | `/voting-sessions/{session}/my-vote` | `votes.cast` + VOTER + elegível |
| GET | `/events/{event}/results/preview` | `results.preview` (`?voting_session_id=`) |
| GET | `/events/{event}/results/publications` | `results.preview` (histórico de versões) |
| POST | `/events/{event}/results/publish` | `results.publish` |
| POST | `/events/{event}/results/revoke` | `results.publish` (motivo obrigatório) |
| GET | `/events/{event}/results/published` | `results.view_published` (todos os perfis) |

Fora desta fase: Even3, EventRegistration, Attendance, QR/check-in, frontend, PDF, notificações, voto anônimo por token, login sem conta, ranking combinado, desempate artificial, "quem fez check-in pode votar".

## Fase 8 — Auditoria completa

- [x] audit_logs enriquecido (migration incremental: evento, snapshot do ator, rótulo da entidade, índices);
- [x] AuditLogger compatível (inferência de ator/evento/rótulo) + AuditSanitizer reforçado;
- [x] AuditActionCatalog (módulo + rótulo de cada ação, rótulo de cada módulo);
- [x] filtros;
- [x] paginação;
- [x] detalhes before/after + changes derivado;
- [x] endpoint somente leitura;
- [x] Policies apenas Administrador (`audit_logs.view`);
- [x] inventário das ações das Fases 1–7 (78 ações, todas catalogadas e gravadas por alguma operação; verificado por teste).

Concluída em 12/10/2026 (aguardando aprovação para commit): 427 testes passando em `hacklab_test` dentro do Docker (385 anteriores + 42 novos), 29 migrations do zero, seed idempotente (sem logs artificiais na segunda execução), `db-check`, Pint, fluxo admin altera equipe → filtra auditoria → detalhe com ator/evento/entidade/before/after/changes → Gestor 403 validado via HTTP com cookies reais, leitura sem auditoria, nenhuma tabela em `public`, `hacklab_dev` intacto após os testes.

Especificação fechada em 12/10/2026: ver `banco-de-dados.md` §11 e `regras-de-negocio.md` §9.

Endpoints (`/api/v1`), todos `audit_logs.view`:

| Método | Rota | Observação |
|---|---|---|
| GET | `/audit-logs` | Filtros: `search`, `event_id`, `has_event`, `module`, `action`, `actor_user_id`, `actor_role`, `actor_sector_id`, `entity_type`, `entity_id`, `ip_address`, `date_from`, `date_to`. `per_page` 1–100 (padrão 50). Ordem `created_at desc, id desc` |
| GET | `/audit-logs/meta` | Catálogo de ações e módulos (para filtros) |
| GET | `/audit-logs/{auditLog}` | Detalhe com `before_data`, `after_data`, `changes`, IP e User-Agent |

Sem POST/PATCH/DELETE, purge, retenção ou exportação.

## Fase 9 — Integração Even3

- [x] PoC/comando migrados para a API V2 (Bearer), sem fallback para V1;
- [x] events.timezone (IANA);
- [x] event_integrations (vínculo explícito, conferido na Even3);
- [x] external_ticket_types + mapeamento manual por `external_ticket_id`;
- [x] event_registrations (matching de Person, source_*, status, conflitos);
- [x] attendance_records (presença derivada por dia);
- [x] integration_sync_runs (1 RUNNING por integração, SUCCESS/PARTIAL/FAILED);
- [x] integration_webhook_receipts (idempotência por disparo);
- [x] EventPlatformProvider, Even3Client, Even3Provider, Even3Mapper, serviço de sincronização;
- [x] webhook (token em header configurável), sync manual e comando `even3:sync`;
- [x] materialização explícita de participantes;
- [x] permissões/policies, auditoria administrativa, testes (sem rede real);
- [x] PoC real da API V2: evento, entradas (5, identidade `prices[].ticket_id`), participantes por categoria, paginação, erros e sync/mapeamento/materialização pelo HackLab;
- [ ] PoC real de cancelamento (implementação via webhook concluída e testada);
- [ ] PoC real do webhook: `pending_public_url` (Fase 12).

Implementada em 12/10/2026 (aguardando aprovação; PoCs pendentes acima): 490 testes passando em `hacklab_test` dentro do Docker (427 anteriores + 63 novos, incluindo 4 de concorrência real), 31 migrations do zero, seed idempotente e offline, `db-check`, Pint, fluxo configurar → status → sync de entradas → sync de participantes → inscrições → materialização → histórico validado via HTTP com cookies reais **contra a Even3 V2 real**, Consultor só consulta, nenhuma tabela em `public`, `hacklab_dev` intacto após os testes.

Especificação fechada em 12/10/2026: ver `integracao-even3.md`, `banco-de-dados.md` §8 e `regras-de-negocio.md` §13–18.

Endpoints (`/api/v1`):

| Método | Rota | Acesso |
|---|---|---|
| GET | `/events/{event}/integrations/even3/status` | `integrations.view` |
| PUT | `/events/{event}/integrations/even3` | `integrations.manage` |
| GET | `/events/{event}/integrations/even3/tickets` | `integrations.view` |
| PATCH | `/external-ticket-types/{ticket}/mapping` | `integrations.manage` |
| POST | `/events/{event}/integrations/even3/sync` | `integrations.sync` |
| GET | `/events/{event}/integrations/even3/sync-runs` · `/integration-sync-runs/{run}` | `integrations.view` (detalhe de avisos/erros só com `integrations.manage`) |
| GET | `/events/{event}/event-registrations` · `/event-registrations/{registration}` | `event_registrations.view` |
| POST | `/events/{event}/event-registrations/materialize-participants` | `integrations.manage` + `participants.manage` |
| GET | `/events/{event}/attendance` | `attendance.view` |
| POST | `/webhooks/even3` | público, token de segurança do webhook |

Fora desta fase: frontend, relatórios, escrita na Even3 (participante/check-in), pagamentos, certificados, submissões, criação automática de User/Juror/Company/Voter, elegibilidade de voto por check-in.

## Fase 10 — Migração completa do frontend para o backend real

O Vue deixa de ser protótipo: todos os dados vêm da API (`/api/v1`), login é Sanctum SPA real e o que não tem endpoint sai da interface. Desenho em `arquitetura.md` §13.

### Classificação das rotas

- **REAL_API** — tela lê e grava só pela API.
- **UI_HELPER_WITH_REAL_API** — tela auxiliar (montagem, distribuição, painel) cujas ações chamam endpoints reais.
- **DEFERRED** — sem backend ainda; fica fora da navegação e da rota (não há operação local).
- **REMOVE_PROTOTYPE** — existia só para simular; removida.

| Rota (`#/…`) | Tela | Classe | Endpoints / observação |
|---|---|---|---|
| `login` | Login | REAL_API | `/sanctum/csrf-cookie`, `/auth/login`, `/auth/me` |
| `cadastro` | Register | REMOVE_PROTOTYPE | cadastro público fake (Votante/Jurado com convite) |
| `dashboard` | Dashboard | UI_HELPER_WITH_REAL_API | painel mínimo com contagens reais dos módulos permitidos (`GET /events/{e}/dashboard`, 1 requisição) |
| `config` | Config (evento) | REAL_API | `/events`, `/events/{e}/days` |
| `usuarios` | Usuários | REAL_API | `/users`, `/people`, `/roles`, `/users/{u}/role|status|sector`, setores do evento |
| `participantes` | Participantes e turmas | REAL_API | `/events/{e}/participants`, `/participants/{p}(/team)`, `/events/{e}/classes`, `/classes/{c}(/status)` |
| `equipes` | Equipes | REAL_API | `/events/{e}/teams`, `/teams/{t}`, `/teams/{t}/members` |
| `montar` | Montar equipe | UI_HELPER_WITH_REAL_API | adicionar/remover membro pelos endpoints de membros |
| `roletas` | Roleta | REMOVE_PROTOTYPE | sorteio local sem persistência |
| `empresas`, `empresa` | Empresas | REAL_API | `/events/{e}/companies`, `/companies/{c}(/status)`, representantes |
| `desafios`, `desafio` | Desafios | REAL_API | `/events/{e}/challenges`, `/challenges/{c}(/status)` |
| `distribuicao` | Distribuição | UI_HELPER_WITH_REAL_API | `PATCH /challenges/{c}/team` |
| `setores` | Setores | REAL_API | `/events/{e}/sectors`, `/sectors/{s}(/status)` + resumo de pendências/ocorrências/reuniões do setor. Registros operacionais do protótipo (equipamentos, finanças, campanhas, espaços, materiais) → REMOVE_PROTOTYPE |
| `reunioes`, `reuniao` | Reuniões | REAL_API | `/events/{e}/meetings`, `/meetings/{m}` |
| `manifestacao` | Manifestação em ata | DEFERRED | não há atas/decisões/manifestações no backend |
| `pendencias` | Pendências | REAL_API | `/events/{e}/tasks`, `/tasks/{t}` (+ comments/forward/complete/reopen), `meta.abilities` |
| `documentos` | Documentos | DEFERRED | sem backend; fora da navegação |
| `ocorrencias` | Ocorrências | REAL_API | `/events/{e}/occurrences`, `/occurrences/{o}` (+ comments/forward/resolve/reopen/tasks) |
| `presenca` | Inscrições e Presença | REAL_API | integração Even3 (status, entradas, mapeamento, sync, histórico), inscrições, materialização, presença (somente leitura). Scanner/QR/credenciais locais → REMOVE_PROTOTYPE |
| `jurados` | Jurados e critérios | REAL_API | `/events/{e}/jurors`, `/jurors/{j}(/status|/assignments)`, `/events/{e}/evaluation-criteria` |
| `avaliacoes` | Avaliações | REAL_API | `/evaluation-progress`, `/technical-results`, `/evaluations`, `/request-revision` |
| `area-jurado`, `avaliar` | Área do Jurado | REAL_API | `/events/{e}/my-evaluations`, `PUT /juror-assignments/{a}/evaluation`, `/submit` |
| `votacao-gestao` | Gestão da votação | REAL_API | `/voting-sessions` (candidatas, elegíveis, abrir/fechar/cancelar, participação, resultado) |
| `votacao` | Votação do público | REAL_API | `/my-voting-sessions`, `/ballot`, `/vote`, `/my-vote` |
| `resultados` | Resultados | REAL_API | `/results/preview`, `/publications`, `/publish`, `/revoke`, `/published` |
| `painel` | Painel de resultado | UI_HELPER_WITH_REAL_API | só `/results/published` |
| `apresentacao` | Apresentação | UI_HELPER_WITH_REAL_API | só `/results/published` |
| `premiacao` | Premiação | DEFERRED | sem backend de premiações |
| `relatorios`, `relatorio` | Relatórios | DEFERRED | Fase 11 |
| `auditoria` | Auditoria | REAL_API | `/audit-logs`, `/audit-logs/meta`, `/audit-logs/{id}` |

Rotas antigas (`inicio`, `preparacao`, `gestao`, `encerramento`, `evento`, `ingresso`, `validar`, `sala`) continuam redirecionando para as rotas atuais.

### Removido

Modo demonstração (`buildDemo`, `loadDemo`, `resetAll`, contas `ACCOUNTS`, modal de boas-vindas, selo de demonstração), troca de perfil e setor demonstrativo, perfil Validador, cadastro público e convites de jurado, scanner/QR/credenciais locais, auditoria local (`appendAudit`, `audit-logger.js`, `state.audit`), voto local (`hacklab.vote.cast`, `voting.ballots`, `hasVoted`), exclusões sem endpoint (participante, equipe, empresa, pendência, ocorrência), dados operacionais sem backend (finanças, equipamentos, campanhas etc.).

### Ajuste de backend feito na Fase 10

- Gestor podia encaminhar/criar demanda para outro setor, mas não conseguia descobrir os destinos (`/sectors` é escopado). Criado o contrato explícito `GET /events/{e}/tasks/routing-sectors` e `/occurrences/routing-sectors` (só `*.route`; setores ativos do evento, `{id, name}`). Ver `arquitetura.md` §6.3.

### Pendências do backend encontradas na migração (não corrigidas na Fase 10)

Os pontos abaixo ficam para decisão:

- `GET /events/{e}/jurors` não devolve `active_assignments_count`: o `select('jurors.*')` depois do `withCount` descarta a contagem. A tela mostra "Ver equipes" até a correção.
- Datas com offset diferente de UTC (ex.: `occurred_at`/`due_at` com `-03:00`) são gravadas sem converter o offset. O front envia sempre UTC (`Z`) e não é afetado, mas o backend deveria normalizar.
- Mensagens genéricas de validação do Laravel saem em inglês (`APP_LOCALE=en`, sem `lang/pt_BR`). Mensagens de regra de negócio já estão em português.
- Sem filtros de status/busca/período em `GET /events/{e}/meetings`. O Dashboard ganhou o resumo agregado `GET /events/{e}/dashboard` no diagnóstico de desempenho (13/10/2026); o resumo do setor ainda conta pelas listagens.
- `ParticipantResource.person` não traz `document`; a listagem de empresas não traz o representante principal.
- Ambiente de desenvolvimento: resolvido no diagnóstico de 13/10/2026 (IPv6 em `localhost`, opcache, workers do `php -S`, preflight CORS). Ver `infraestrutura.md` §2.1. O timeout do client continua 30 s.

### Checklist

- [x] documentação antes do código;
- [x] client HTTP central, store de autenticação, contexto de evento;
- [x] login, shell e navegação por permissão;
- [x] telas na ordem: usuários, evento, participantes, equipes, empresas, desafios, setores, reuniões, pendências, ocorrências, jurados, avaliações, votação/resultados, inscrições e presença, auditoria, dashboard;
- [x] limpeza do `localStorage` legado;
- [x] testes do front (Vitest) e build;
- [x] smoke test no navegador real por perfil.

## Fase 11 — Relatórios

Quatro relatórios com dados reais do backend (desenho em `arquitetura.md` §15, regras em `regras-de-negocio.md` §20):

| Relatório | Endpoint | Conteúdo |
|---|---|---|
| Relatório Geral do Hackathon | `GET /events/{e}/reports/general` | evento e dias; contagens de participantes, turmas, equipes, empresas, desafios, setores, reuniões, pendências, ocorrências, inscrições externas, presença por dia, progresso das avaliações, sessões de votação e se há resultado oficial publicado |
| Participantes e Presença | `GET /events/{e}/reports/participants-presence` | resumo (status, equipe, turma, presença por dia) e lista: participante, turma, status, equipe atual e presença por dia (`PRESENT` / `NO_RECORD`) |
| Gestão e Operação | `GET /events/{e}/reports/operations` | setores, reuniões, empresas, desafios, equipes, pendências (status, prioridade, setor, vencidas) e ocorrências (status, categoria, prioridade, solução) |
| Encerramento e Resultados | `GET /events/{e}/reports/closing-results` | progresso das avaliações (sem notas), jurados (só contagens), sessões de votação com participação, e o resultado oficial a partir do snapshot publicado |

- [x] permissão `reports.view` (Administrador e Consultor);
- [x] endpoints agregados (1 relatório = 1 requisição);
- [x] relatório geral;
- [x] participantes/presença;
- [x] gestão/operação;
- [x] encerramento/resultados;
- [x] Central de Relatórios no Vue com visualização e PDF (pdfmake sob demanda);
- [x] financeiro: **não entregue** — não existe domínio financeiro real no backend; nada é inventado ou zerado.

Sem migration nova: relatório não é persistido (sem tabela, sem PDF guardado, sem histórico).

## Complemento 11.1 — Primeiro acesso e recuperação de senha

Antes da Fase 12. Desenho em `arquitetura.md` §16 e regras em `regras-de-negocio.md` §19.

| Método | Endpoint | Observação |
|---|---|---|
| POST | `/auth/activation/request` | público; sempre `202` genérico; limite `auth-recovery` |
| POST | `/auth/activation/complete` | público; token + senha → cria User `JUROR`/`VOTER` |
| POST | `/auth/password/forgot` | público; sempre `202` genérico; limite `auth-recovery` |
| POST | `/auth/password/reset` | público; Password Broker; encerra as sessões da conta |
| GET | `/voting-sessions/{s}/public-voters/readiness` | `voting_sessions.view`; prontidão dos votantes do público (por Person) |
| POST | `/voting-sessions/{s}/eligibilities/public-voters` | `voting_eligibility.manage`; só DRAFT; adiciona os Votantes ativados (não remove ninguém) |

- [x] tabela `account_activation_tokens` (migration 32);
- [x] elegibilidade: Juror interno `ACTIVE` → JUROR; inscrição `CONFIRMED` + entrada `PUBLIC` → VOTER; conta existente nunca muda;
- [x] anti-enumeração, rate limit, token com HMAC, uso único, 60 minutos;
- [x] e-mails por Notifications (log em desenvolvimento, SMTP em produção);
- [x] auditoria `USER_SELF_ACTIVATED` e `PASSWORD_RESET_COMPLETED`;
- [x] `php artisan hacklab:prune-auth-tokens`;
- [x] telas públicas `#/primeiro-acesso`, `#/ativar-conta`, `#/esqueci-senha`, `#/redefinir-senha`;
- [x] preparação da votação: "Adicionar votantes ativados" (só adiciona, idempotente, auditado como `VOTING_ELIGIBILITY_CHANGED`), resumo de prontidão e aviso antes de abrir.

Regra operacional: os Votantes ativam o acesso **antes** da abertura da sessão; depois de aberta, candidatas e elegíveis ficam congelados e quem ativar depois não entra naquela sessão.

Fora deste complemento: login social, magic link no lugar de senha, MFA, SSO, SMS/WhatsApp, elegibilidade automática para votação, Juror automático pela categoria externa, conta automática de participante, múltiplos perfis por evento.

## Fase 12 — Implantação (Render + Supabase)

- [ ] projeto Supabase de produção, schema `hacklab` criado (SQL em `infraestrutura.md` §3.1);
- [ ] alvo de produção no `back/Dockerfile` (PHP-FPM + servidor HTTP, porta `PORT` do Render);
- [ ] Web Service no Render com variáveis de ambiente (Session pooler, `sslmode=require`);
- [ ] `migrate --force` no start/pre-deploy;
- [ ] Static Site do Vue com `VITE_API_URL` e rewrite `/* → /index.html`;
- [ ] **PoC do cookie de sessão** (rewrite `/api` no Static Site ou domínio próprio; ver `infraestrutura.md` §3.4);
- [ ] `GET /api/v1/system/readiness` (Laravel + conexão + consulta ao banco);
- [ ] `pg_dump` periódico para armazenamento fora do Supabase + teste de restauração.

## Fase 13 — Teste de carga do dia 3

- [ ] degraus de 50, 100, 200 e 300 usuários concorrentes;
- [ ] fluxos: login, consulta de equipes, votação e gravação de voto, jurados, resultados;
- [ ] medir p95, taxa de erro, memória da instância e conexões no pooler;
- [ ] decidir: Render Free aprovado, ou contingência (mais compute no Render ou outro host para o Laravel).

## Critérios globais

Antes de considerar backend pronto:

- [ ] banco sobe do zero (`migrate:fresh --seed`);
- [ ] ambiente de dev/testes sobe do zero com `docker compose up`;
- [ ] produção conecta no Supabase só pelo Session pooler, com SSL;
- [ ] seed funciona;
- [ ] autenticação funciona;
- [ ] Policies cobrem endpoints;
- [ ] testes críticos passam;
- [ ] auditoria funciona;
- [ ] sync externo é idempotente;
- [ ] frontend não acessa Even3 diretamente;
- [ ] secrets não estão versionados;
- [ ] erros são JSON consistentes;
- [ ] sem dependência de localStorage como banco;
- [ ] documentação atualizada.

## Estratégia para prazo curto

Prioridade máxima:

```text
Auth
→ Participantes/Equipes
→ Empresas/Desafios
→ Setores/Pendências/Ocorrências
→ Jurados/Avaliações
→ Votação
→ Auditoria
→ Even3
→ Relatórios
```

Se faltar tempo, reduzir automações, não integridade.

Nunca cortar:
- migrations;
- validação;
- autorização;
- constraints;
- auditoria de ações sensíveis;
- testes dos fluxos críticos.
