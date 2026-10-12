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

- [ ] event_integrations;
- [ ] external_ticket_types;
- [ ] event_registrations;
- [ ] attendance_records;
- [ ] integration_sync_runs;
- [ ] EventPlatformProvider;
- [ ] Even3Client;
- [ ] Even3Mapper;
- [ ] Even3SyncService;
- [ ] status;
- [ ] sync manual;
- [ ] PoC real;
- [ ] webhook opcional.

## Fase 10 — Integração progressiva do frontend

Migrar uma tela por vez.

Ordem sugerida:

1. login;
2. usuários;
3. evento;
4. participantes;
5. equipes;
6. empresas;
7. desafios;
8. setores;
9. pendências;
10. ocorrências;
11. jurados;
12. avaliações;
13. votação;
14. presença;
15. auditoria.

Para cada tela:

- [ ] criar service JS;
- [ ] ligar GET;
- [ ] ligar POST/PATCH/DELETE;
- [ ] tratar loading;
- [ ] tratar erro;
- [ ] preservar responsividade;
- [ ] remover mock correspondente.

## Fase 11 — Relatórios

- [ ] endpoints agregados;
- [ ] relatório geral;
- [ ] participantes/presença;
- [ ] gestão/operação;
- [ ] encerramento/resultados;
- [ ] financeiro se houver dados.

Relatório deve ser gerado com dados reais do backend.

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
