# Integração Even3 (Fase 9)

## 1. Objetivo

A Even3 é a plataforma externa de página pública, inscrição, credencial/QR e check-in. O HackLab **lê** dela inscrições e presença e consolida esses dados internamente.

```text
Vue → Laravel → EventPlatformProvider → Even3Provider → Even3Client → Even3 API V2
                                                                       (Even3 → HackLab)
```

- O frontend nunca chama a Even3.
- Nesta fase o HackLab **não escreve** na Even3 (não cria participante, não faz check-in, não altera inscrição), mesmo que a API permita.

## 2. Princípios

- Nenhum módulo de negócio depende da Even3: só `App\Integrations\Even3` conhece formatos externos; o resto lê o banco do HackLab.
- Uma sincronização central (evento → entradas → inscritos), nunca um "sincronizar" por módulo.
- Webhook = atualização rápida; sincronização pela API = reconciliação. Os dois convergem para o mesmo registro.
- Categoria externa classifica a inscrição; **não concede** conta, perfil, jurado, empresa, votante nem elegibilidade.
- Sincronização idempotente: rodar N vezes sem mudança externa não cria nem altera nada.

## 3. Credencial e configuração

```text
EVEN3_ENABLED=false                         # true para habilitar sync/webhook
EVEN3_BASE_URL=https://api.even3.com/api/v2
EVEN3_API_TOKEN=                            # só no .env do backend
EVEN3_WEBHOOK_HEADER=                       # nome do header do token de segurança do webhook (confirmar no PoC real)
EVEN3_WEBHOOK_SECRET=                       # diferente do EVEN3_API_TOKEN
```

- Autenticação V2: `Authorization: Bearer <token>` (documentação oficial "Autenticação" e PoC real).
- A chave é **por evento** (gerada em Ferramentas → Integrações → API Even3). Nesta versão: **1 `EVEN3_API_TOKEN` ativo por deployment**. O banco aceita vários eventos, mas guardar e escolher tokens por evento com segurança fica fora desta fase.
- O token e o segredo do webhook nunca vão para Vue, banco, `audit_logs`, logs, exceções ou respostas. O `Even3Client` substitui o token por `[token oculto]` em qualquer mensagem.
- **V1 (`https://www.even3.com.br/api/v1`, header `Authorization-Token`) é legado/diagnóstico.** A integração usa só V2, sem fallback silencioso.

Configurar o vínculo é explícito (nunca "o primeiro evento da chave = o primeiro evento do HackLab"):

```text
PUT /api/v1/events/{event}/integrations/even3   { "external_event_id": "793572", "status": "ACTIVE" }
```

Antes de salvar/ativar, o HackLab consulta a Even3 e exige que o evento externo seja acessível pela chave. Nome, datas e local diferentes geram só avisos.

## 4. API V2 — observado no PoC real (12/10/2026, evento 793572)

| Endpoint | Status | Formato observado |
|---|---|---|
| `GET /events` | 200 | `{ data: [evento], count, pagination }` |
| `GET /events/{id}` | 200 | objeto do evento **sem** envelope `data`; `tickets: null` |
| `GET /events/{id}/tickets` | 200 | **lista pura** (sem envelope); vazia enquanto não há entradas |
| `GET /attendees` | 200 | `{ data: [participante], count, pagination }` |
| `GET /attendees/{id}` | 200 | objeto do participante, sem envelope |
| `GET /events/1` (outro evento) | 404 | `{ success:false, message, detail, help, code:"NotFound" }` |
| `GET /attendees?event_id=…` | 400 | `code:"InvalidParameter"` (o filtro por evento não existe; a chave já é do evento) |
| token inválido / ausente | 401 | `code:"InvalidAuthorizationToken"` / `"AuthorizationToken"` |

Paginação (observada e documentada): `page` (padrão 1), `page_size` (padrão 30, **máximo 100**; valores maiores viram 100).

```text
pagination: { current_page, total_page, page_size, quantity, has_previous, has_next }
```

O HackLab percorre páginas enquanto `has_next = true` (com teto de segurança de páginas).

### Evento

`id` (inteiro), `title`, `url`, `start_date`/`end_date` (ISO UTC), `registration_*`, `country`, `state`, `city`, `venue`, `tickets`.

### Entradas (`/events/{id}/tickets`) — documentação oficial

```text
[ { title, ticket_confirmed_quantity,
    prices: [ { id, ticket_id, price, sales_start_date, sales_end_date, lot_confirmed_quantity } ] } ]
```

- A entrada **não tem `id` no nível de cima**. O identificador estável é `prices[].ticket_id` (cada lote/preço tem o seu `id`, e todos apontam para o mesmo `ticket_id`).
- `external_ticket_id = ticket_id`. Entrada sem preço/lote (sem `ticket_id`) ou com `ticket_id` divergente entre lotes é ignorada com aviso (`TICKET_WITHOUT_ID` / `TICKET_ID_AMBIGUOUS`).
- **PoC real (12/10/2026, 5 entradas gratuitas):** cada entrada veio com exatamente 1 item em `prices` (`price = 0`), com `id` do lote e `ticket_id` distintos; nenhuma entrada tem id próprio no nível de cima (chaves: `title`, `ticket_confirmed_quantity`, `prices`). `ticket_id` é a única identidade estável disponível e foi mantido como `external_ticket_id`.
- Entradas gratuitas: o painel da Even3 não ofereceu configuração de múltiplos lotes. O comportamento com vários lotes (pagos) segue a documentação (mesmo `ticket_id` em todos os lotes) e não foi observado.

### Participantes (`/attendees`) — observado

```text
id, event_id, name, badge_name, email, gender, photo, document_number,
check_in_code, is_confirmed, registration_category, registration_date ("dd/mm/aaaa"), registration_hour ("hh:mm")
```

- Só existe `id` (participante/inscrição da V2): **não há** id de pessoa, id de inscrição separado nem id de entrada.
- A entrada vem só como texto em `registration_category`. **PoC real:** nas 3 inscrições de teste o texto é idêntico ao `title` da entrada ("Participante", "Jurado", "Público"); uma inscrição sem entrada veio com `registration_category = ""`.
- **Não há status de cancelamento**: só `is_confirmed`.
- Não há telefone.
- **`registration_date` diverge da documentação:** a doc mostra `"22/07/2026"`; o PoC real devolveu `"2026-12-10T00:00:00Z"` (+ `registration_hour: "12:56"`) para inscrições feitas em 12/10/2026 — dia e mês aparentemente invertidos. O HackLab só interpreta o formato documentado `dd/mm/aaaa`; fora dele, `registered_at` fica nulo.
- A API V2 possui operações de check-in (credenciamento no evento e em atividade). O que **não** foi localizado/validado é um endpoint GET V2 de histórico de check-ins adequado para reconciliar a presença no HackLab. Nesta arquitetura: presença recebida = webhook; reconciliação histórica de presença = ainda não validada.

## 5. Modelo interno

- `event_integrations`: vínculo Event ↔ evento externo (`EVEN3`, `V2`, `ACTIVE`/`DISABLED`). `unique(event_id, provider)` e `unique(provider, external_event_id)`. Sem token. Guarda o último título e as datas lidos da Even3 (`external_event_title`, `external_start_date`, `external_end_date`, `external_metadata_synced_at`) para comparar com o evento interno sem chamar a Even3 (Complemento 11.2).
- `integration_reconciliations` e `account_access_grants`: decisões administrativas sobre os candidatos vindos das inscrições (§12).
- `external_ticket_types`: entradas descobertas, identidade = `external_ticket_id`. Mapeamento manual para `PARTICIPANT`, `ORGANIZATION`, `COMPANY_GUEST`, `JUROR`, `PUBLIC` (nova entrada nasce sem mapeamento).
- `event_registrations`: inscrição externa ligada a uma Person, com os dados da origem em `source_*`.
- `attendance_records`: check-ins (vários por dia permitidos), presença diária derivada.
- `integration_sync_runs`: histórico e contadores de cada sincronização.
- `integration_webhook_receipts`: um recibo por disparo do webhook (idempotência), sem payload completo.

Detalhes em `banco-de-dados.md` §8–9.

## 6. Sincronização (API → HackLab)

```text
POST /api/v1/events/{event}/integrations/even3/sync   { "scope": "EVENT" | "TICKETS" | "ATTENDEES" | "ALL" }
php artisan even3:sync                                 (pronto para o Scheduler; frequência ainda não definida)
```

`ALL` = evento → entradas → participantes. Regras:

- No máximo **1 sync `RUNNING` por integração** (lock + índice único parcial). O segundo recebe 409. Um `RUNNING` abandonado há mais de 30 min é encerrado como `FAILED` (`STALE_RUN`) na próxima tentativa.
- Cada participante é processado numa transação pequena; um item ruim não derruba os outros. `SUCCESS` (tudo ok), `PARTIAL` (algum item rejeitado, em conflito ou com erro), `FAILED` (recurso externo essencial não pôde ser lido).
- Contadores contam só mudança real; avisos guardam id externo + código + mensagem segura (nunca o payload completo).
- `event_id` de cada participante precisa ser o `external_event_id` configurado; senão é rejeitado (`EVENT_MISMATCH`).
- Ausência na resposta **nunca** cancela inscrição.

### Entradas

Nova → criada sem mapeamento. Nome alterado → `external_name` atualizado, mapeamento preservado. Entrada conhecida que some numa leitura completa e bem-sucedida → `active = false` (nunca apagada).

### Categoria do participante

`registration_category` (texto) → entrada **só se** o nome corresponde (sem diferença de caixa/espaços) a exatamente uma entrada conhecida da integração. Zero → sem entrada; mais de uma → sem entrada + aviso `CATEGORY_AMBIGUOUS`. O texto nunca cria mapeamento persistente: o mapeamento é sempre do `external_ticket_id`.

### Pessoa (matching)

1. inscrição já conhecida pelos IDs externos (`external_attendee_id`, `external_registration_id`);
2. documento normalizado (maiúsculas, só letras e dígitos) que aponte para **uma** Person;
3. e-mail normalizado (trim + minúsculas) que aponte para **uma** Person;
4. nova Person.

Documento e e-mail apontando para Persons diferentes (ou um deles ambíguo) = conflito (`IDENTITY_CONFLICT`): o item não é vinculado, nada é sobrescrito, nenhuma terceira Person é criada.

Person nova recebe os dados da Even3. Person existente só tem **campos vazios** preenchidos. Os valores da origem ficam em `source_name`, `source_badge_name`, `source_email`, `source_phone`, `source_document`; e-mail/documento diferentes do cadastro interno marcam `identity_mismatch`.

Uma Person pode ter **várias** inscrições externas na mesma integração (não há unique por pessoa). A inscrição é identificada pelos IDs externos, nesta ordem: `external_registration_id` → `external_attendee_id` → `external_person_id` (este só quando aponta para exatamente uma inscrição compatível). Documento/e-mail associam a **Person**, mas não provam que duas inscrições externas são a mesma: uma inscrição existente da pessoa só é reaproveitada quando é a **única compatível** (nenhum id externo ou código de credenciamento divergente — caso típico: criada pelo webhook, completada pela API). Mais de uma compatível → conflito `REGISTRATION_AMBIGUOUS`, sem merge. Nenhuma compatível → nova inscrição da mesma pessoa.

### Status

| Fonte | Valor | Status interno |
|---|---|---|
| API | `is_confirmed = true` | `CONFIRMED` |
| API | `is_confirmed = false` | `PENDING`, **exceto** se já estiver `CANCELLED` (cancelamento explícito é preservado) |
| Webhook | inscrição pendente / confirmada / cancelada no evento | `PENDING` / `CONFIRMED` / `CANCELLED` |

Webhook mais antigo que a última mudança de status conhecida (`created` < `status_changed_at`) não rebaixa o status (o gatilho "pendente" pode chegar até 30 min depois).

## 7. Webhook (Even3 → HackLab)

```text
POST /api/v1/webhooks/even3     (público, sem Sanctum; protegido pelo token de segurança)
```

Documentação oficial (developers.even3.com, "Webhook" e "Gatilhos de participantes"):

- o organizador define um **token de segurança** enviado num header — o **nome do header não está documentado**, por isso é configurável (`EVEN3_WEBHOOK_HEADER`) e precisa ser confirmado no PoC real;
- cada disparo tem `id` único (GUID), `created` (epoch) e `type { id, name }`; dados em `pessoa { id, nome, email, cpf, passaporte, nome_para_cracha, celular, … }`, `inscricao { id, codigo, titulo, data_inscricao }`, `evento { id, url, titulo, data }`;
- resposta fora de 2xx ou acima de 60 s gera até 3 reenvios (15, 30 e 150 min); 3 dias sem nenhuma entrega bem-sucedida desativam o webhook;
- uma ação pode disparar mais de um gatilho.

Regras do HackLab:

- Token ausente/errado → 401, nada é gravado. Webhook sem header/segredo configurado ou com segredo igual ao token da API → 503.
- `evento.id` diferente do `external_event_id` configurado → recibo `IGNORED` (`EVENT_MISMATCH`), 2xx, nada processado. Nunca pelo título.
- Idempotência por `id` do disparo (`unique(event_integration_id, external_delivery_id)`): já `PROCESSED`/`IGNORED` → 2xx sem novo efeito; `FAILED` → reprocessa no reenvio.
- Gatilhos tratados: inscrição pendente, confirmada e cancelada no evento; check-in no evento; check-in em atividade. Os demais (atividades, venda, submissão…) → `IGNORED` + 2xx.
- Despacho pelo `type.name` (nomes oficiais da documentação). O `type.id` recebido é guardado no recibo, mas não decide nada: só o id `2` aparece na documentação, num exemplo de inscrição confirmada; os demais não são inventados. Confirmar ids e nomes no PoC real.
- `inscricao.codigo` não é tratado como `check_in_code` até o PoC real mostrar que é o mesmo código da API.
- Webhook pode chegar antes de qualquer sync: o HackLab localiza a inscrição pelos IDs externos ou pela Person (matching da seção 6) ou cria Person + inscrição. A próxima sincronização completa os dados.
- Não gera um AuditLog por inscrito/check-in: o histórico operacional fica em `event_registrations`, `attendance_records`, `integration_webhook_receipts` e `integration_sync_runs`.

### Presença

- Cada check-in vira um `attendance_record` (`EVENT` ou `ACTIVITY`, fonte `WEBHOOK`), no máximo um por disparo (`unique(webhook_receipt_id)`).
- `observed_at` = `created` do disparo. `checked_in_at` fica **nulo** enquanto o payload real não mostrar um horário específico do check-in (não documentado).
- Dia do evento: horário UTC → `events.timezone` (IANA, ex.: `America/Sao_Paulo`) → data local → `event_days.date`. Sem timezone ou sem dia correspondente → `event_day_id = null` + aviso no recibo. Nunca cria `EventDay`.
- Presença no dia = existe ao menos um registro naquele `EventDay`. Não existe booleano global de presença.

## 8. Materialização de participantes

```text
POST /api/v1/events/{event}/event-registrations/materialize-participants
```

Idempotente: inscrição `CONFIRMED` + entrada mapeada como `PARTICIPANT` → cria `Participant(event, person)` (`AVAILABLE`, sem turma) se não existir. Participante existente não muda (status, turma, equipe, desafio). Cancelar na Even3 não tira ninguém de equipe: isso é ação humana.

Desde o Complemento 11.2 roda **automaticamente ao fim de cada sincronização** de escopo `ATTENDEES`/`ALL` que não falhou (contador `participants_created` no histórico; um log `EVEN3_PARTICIPANTS_MATERIALIZED` só quando cria alguém). O webhook não materializa: a próxima sincronização completa. O endpoint explícito continua existindo.

### 8.1 Automático ao sincronizar (decisão de 20/10/2026)

Ao fim de cada sincronização que não falhou, no mesmo `IntegrationSyncService`:

| Escopo | O que acontece | Contador |
|---|---|---|
| `ATTENDEES`/`ALL` | inscritos confirmados `PARTICIPANT` → Participant | `participants_created` |
| `ATTENDEES`/`ALL` | inscritos confirmados `JUROR` (sem conflito, não ignorados, ainda não jurados) → `Juror` ativo pelo `JurorService` (decisão `RESOLVED` na reconciliação) | `jurors_created` |
| `EVENT`/`ALL` | nome e datas diferentes do retrato da Even3 → `EventService::update` | `event_fields_applied` |

Datas que deixariam dias cadastrados fora do período não são aplicadas (aviso `EVENT_DATES_NOT_APPLIED`; o nome ainda é aplicado). Falha em qualquer passo automático vira aviso; o sync não perde as inscrições.

**Sincronização automática:** enquanto um Administrador está com o sistema aberto, o front dispara a sincronização central sozinho quando a última tem mais de 10 minutos (`POST .../sync` com `automatic: true` → `trigger = SCHEDULED`, mesmo lock e histórico; 409 é ignorado em silêncio). O comando `even3:sync` também está no Scheduler do Laravel (a cada 10 min, sem sobreposição) para quando houver um processo de agendamento (decisão da Fase 12). O botão do topo mostra as novidades da última sincronização (inscrições, participantes, jurados, evento) até o painel ser aberto.

## 9. Auditoria

Só ações administrativas: `EVEN3_INTEGRATION_CONFIGURED`, `EVEN3_TICKET_MAPPING_CHANGED`, `EVEN3_SYNC_TRIGGERED` (um por sync manual; o resultado fica no `integration_sync_run`) e `EVEN3_PARTICIPANTS_MATERIALIZED` (um por execução). Pessoas, inscrições e participantes criados pela integração não geram um log cada.

Reconciliação (Complemento 11.2): `EVEN3_RECONCILIATION_RESOLVED` (referência à entidade criada/vinculada; a criação do Jurado/representante mantém o próprio log), `EVEN3_RECONCILIATION_IGNORED`, `EVEN3_RECONCILIATION_RESTORED`, `ACCESS_GRANT_CREATED`, `ACCESS_GRANT_REVOKED` e `EVEN3_EVENT_METADATA_APPLIED` (além do `EVENT_UPDATED` da edição). Consultas (workbench, filas, histórico) não geram log.

## 9.1 Tela "Inscrições e Presença" (Fase 10; reorganizada no Complemento 11.2)

Desde o 11.2 é um item do menu lateral com submenu **Integração · Entradas · Inscrições · Presença** (rotas `#/integracao`, `#/entradas`, `#/inscricoes`, `#/presenca`). Sincronizar e o histórico de sincronizações ficam no **Centro Even3** do cabeçalho; não há botão de materializar (é automático). A tabela abaixo é o desenho original da Fase 10.

Substitui a antiga tela "Credenciais e Presença" do protótipo. Não existe scanner, câmera, QR, credencial local nem check-in pelo HackLab: credenciamento é operado na Even3 e não há perfil Validador.

| Aba | Endpoints | Quem opera |
|---|---|---|
| Integração | `GET .../integrations/even3/status`, `PUT .../integrations/even3` (id externo e status) | configurar: `integrations.manage` |
| Entradas | `GET .../integrations/even3/tickets`, `PATCH /external-ticket-types/{t}/mapping` | mapear: `integrations.manage` |
| Sincronização | `POST .../integrations/even3/sync` (escopo), `GET .../sync-runs`, `GET /integration-sync-runs/{run}` | disparar: `integrations.sync` |
| Inscrições | `GET .../event-registrations` (filtros), `GET /event-registrations/{r}`, `POST .../materialize-participants` | materializar: `integrations.manage` + `participants.manage` |
| Presença | `GET .../attendance` (somente leitura) | `attendance.view` |

- O Consultor vê tudo em modo leitura (`integrations.view`, `event_registrations.view`, `attendance.view`), sem botões de configurar, mapear, sincronizar ou materializar.
- A tela nunca mostra nem recebe `EVEN3_API_TOKEN`, `EVEN3_WEBHOOK_SECRET` ou o header do webhook; o status só informa se o token está configurado.
- Mapear uma entrada não concede perfil, não cria Jurado, Empresa nem Usuário.

## 9.2 Inscrições e contas (Complemento 11.1)

- A sincronização cria/atualiza só Person e EventRegistration. **Nunca cria User**, Juror, VotingEligibility ou Participant (este só pela materialização explícita).
- Uma inscrição `CONFIRMED` em entrada mapeada como `PUBLIC` permite que a própria pessoa faça o **primeiro acesso** e vire `VOTER` depois de provar a posse do e-mail. A entrada externa "Jurado" não concede nada: Jurado só com `Juror` interno `ACTIVE`.
- O primeiro acesso lê apenas o que já está no PostgreSQL; não chama a Even3.
- Inscritos em entrada `ORGANIZATION` só fazem o primeiro acesso depois que o Administrador **pré-libera** o acesso (perfil e setor escolhidos por ele) — ver §12.4.

## 9.3 Centro Even3 e reconciliação (Complemento 11.2)

Princípio: **sincroniza uma vez, reconcilia em vários módulos.**

```text
SINCRONIZAR   Even3 → IntegrationSyncService (um por evento) → PostgreSQL
RECONCILIAR   PostgreSQL → filas de candidatos → decisão do Administrador → entidade interna
```

### Centro Even3 (frontend)

Componente global no cabeçalho do ambiente administrativo, ao lado do seletor de evento. Mostra o estado da integração do evento ativo (atualizado, sincronizando, pendências, última sincronização falhou, não configurado) e abre um painel só com resumo: status, última sincronização, botão **Sincronizar tudo**, contagem de pendências por fila e atalhos para o histórico e para a aba Reconciliação de "Inscrições e Presença".

- Administrador: vê, sincroniza e reconcilia. Consultor: vê status, contagens e histórico, sem sincronizar nem reconciliar. Gestor, Editor, Jurado e Votante não veem o Centro (não têm `integrations.view`). O backend autoriza tudo.
- "Sincronizar tudo" usa o endpoint central de sempre (`POST .../integrations/even3/sync`, escopo `ALL`; as opções avançadas expõem só os escopos existentes). Com uma execução `RUNNING`, o backend devolve 409 e o Centro mostra "Sincronização já em andamento".
- Estado no front num store global (`even3-context`): carrega ao entrar (perfil com `integrations.view`), ao trocar de evento (cancela a requisição anterior e zera os contadores), depois de uma sincronização ou reconciliação, e ao abrir o painel se estiver desatualizado. Sem polling permanente; enquanto houver execução `RUNNING` de outra origem, consulta em intervalo curto até terminar.
- O painel mostra também as últimas sincronizações (histórico).
- Revisão manual só sobra onde a Even3 não dá informação suficiente, dentro da página do assunto: Empresa/Convidado em Empresas. Jurados ignorados ou que chegaram pelo webhook depois do último sync aparecem em Jurados; divergências de datas não aplicadas, em Evento. Organização não tem fila (é interna).

### Resumo (workbench)

```text
GET /api/v1/events/{event}/integrations/even3/workbench      integrations.view
```

Só contagens e estado, a partir do PostgreSQL (nunca chama a Even3, não gera auditoria, não devolve dados pessoais nem segredo):

| Campo de `pending` | Significado |
|---|---|
| `participants` | Persons com inscrição `CONFIRMED` em entrada `PARTICIPANT` ainda sem `Participant` no evento (exatamente o que a materialização criaria) |
| `jurors` | candidatos a Jurado pendentes |
| `organization` | inscritos como Organização sem conta e sem liberação pendente |
| `company_guests` | inscritos como Empresa/Convidado sem vínculo ativo de representante no evento |
| `public_without_access` | inscritos do público confirmados ainda sem conta |
| `event_differences` | campos (nome, data inicial, data final) diferentes entre o evento e o último retrato da Even3 |

Também devolve `ignored` por fila, `unmapped_tickets` (entradas ativas sem mapeamento ficam fora de todas as filas) e o estado da sincronização (`running`, início da execução em andamento, última sincronização, status e código de erro).

### Regras comuns das filas

- Candidato = Person com ao menos uma inscrição `CONFIRMED`, `identity_mismatch = false`, em entrada **desta integração** cuja `internal_category` é a da fila. Nunca pelo texto do nome da entrada. Várias inscrições da mesma Person = um candidato.
- Pendência é **derivada** (não existe linha "PENDING"): some quando a entidade interna existe. A decisão explícita fica em `integration_reconciliations` (`RESOLVED`, com referência à entidade criada/vinculada, ou `IGNORED`), única por integração + Person + fila.
- **Ignorar** tira o candidato da fila padrão (nota opcional). **Restaurar** apaga a decisão de ignorar e devolve o candidato à revisão; não mexe em nenhuma entidade interna. Ignorados aparecem em "Ver ignorados".
- Listas paginadas: `GET .../integrations/even3/reconciliation/{fila}?view=pending|ignored|all&search=` com nome, e-mail, entradas e o estado interno atual (jurado, conta, liberação, representações). Exigem `integrations.manage` + a permissão do domínio da fila (o Consultor vê só as contagens).

| Fila | Ação | Permissões |
|---|---|---|
| `jurors` | `POST .../reconciliation/jurors/{person}/confirm` (empresa e notas opcionais) | `integrations.manage` + `jurors.manage` |
| `company-guests` | `POST .../reconciliation/company-guests/{person}/link` (empresa do evento, cargo, principal) | `integrations.manage` + `companies.manage` |
| `organization` | `POST .../reconciliation/organization/{person}/grant` (perfil e setor) | `integrations.manage` + `users.manage` |
| todas | `POST .../reconciliation/{fila}/{person}/ignore` · `.../restore` | as da fila |

Participantes não têm fila: são criados automaticamente ao sincronizar (§8). `pending.participants` só fica acima de zero entre um webhook e a sincronização seguinte.

### Jurados

`JUROR` externo gera só candidato. "Cadastrar como Jurado" usa o `JurorService` (as mesmas regras do cadastro manual) e marca a decisão `RESOLVED`. Person que já é Jurado do evento (ativo ou inativo) não é pendência; inativo **não** é reativado: o Administrador decide pela tela de Jurados. O cadastro não cria User: depois, a própria pessoa faz o primeiro acesso e vira `JUROR` (Complemento 11.1).

### Organização e liberação de acesso

`ORGANIZATION` não cria User. O Administrador escolhe perfil e setor e clica em **Liberar acesso**: isso cria um `AccountAccessGrant` `PENDING` — "esta Person pode ativar uma conta com este perfil/setor". Sem senha, sem senha temporária, sem envio de senha: a própria pessoa faz o primeiro acesso com o mesmo e-mail e cria a senha.

- Perfis permitidos: `MANAGER` e `EDITOR` (setor obrigatório, ativo, do mesmo evento) e `CONSULTANT` (sem setor). Nunca `ADMINISTRATOR` (criação administrativa explícita), `JUROR` (domínio Jurado) nem `VOTER` (público + primeiro acesso).
- No máximo uma liberação `PENDING` por Person (o perfil do User é global, não por evento): outra liberação pendente bloqueia até ser revogada.
- Person que já tem conta não recebe liberação ("Já possui acesso: Gestor"); troca de perfil é pela gestão de usuários.
- **Revogar liberação** (`POST /api/v1/account-access-grants/{grant}/revoke`, `integrations.manage` + `users.manage`): `PENDING → REVOKED`. Depois de consumida não há o que revogar: a conta segue o fluxo normal de status/perfil.

### Empresa / Convidado

`COMPANY_GUEST` não cria Company. O Administrador **vincula a uma empresa existente** do mesmo evento (o `CompanyRepresentativeService` cria o representante) ou abre o formulário real de empresa e vincula depois. Nenhum texto externo é tratado como empresa válida.

### Divergências do evento

A configuração e a sincronização (escopo `EVENT`/`ALL`) guardam o retrato mínimo do evento externo (título, data inicial, data final e quando foi lido). A comparação com o evento interno não chama a Even3:

```text
GET  .../integrations/even3/event-metadata          integrations.view
POST .../integrations/even3/event-metadata/apply    { "fields": ["name", "start_date", "end_date"] }    integrations.manage + events.manage
```

Nada é sobrescrito sozinho. O front envia só **quais campos** aplicar; o backend lê os valores do retrato persistido e chama o `EventService` (as mesmas validações e a mesma auditoria da edição manual). Datas que deixariam dias cadastrados fora do período → 422 com orientação; os dias nunca são movidos sozinhos.

### Atividades/programação

Não implementadas nesta entrega. Resultado da investigação em §11. Uma atividade externa nunca vira `EventDay`; se a Even3 oferecer programação, ela pede um domínio próprio (decisão futura).

## 10. Erros e timeouts

`Even3Client` centraliza Bearer, base URL, timeouts (conexão 5 s, requisição 15 s), retry só de GET (timeout, rede, 429, 5xx; poucas tentativas com espera crescente) e erros tipados: `BAD_REQUEST` (400), `UNAUTHORIZED` (401), `FORBIDDEN` (403), `NOT_FOUND` (404), `RATE_LIMITED` (429), `SERVER_ERROR` (5xx), `TIMEOUT`, `NETWORK_ERROR`, `INVALID_JSON`.

## 11. Estado dos PoCs

| PoC | Situação |
|---|---|
| API V2 real (evento, entradas, participantes, paginação, erros) | **concluída** (12/10/2026) |
| Entradas reais | **concluída**: 5 entradas gratuitas, 1 lote cada; identidade = `prices[].ticket_id` (não há id no nível da entrada). Múltiplos lotes não observados (o painel não oferece lotes para entradas gratuitas) |
| Participantes reais por categoria | **concluída**: 3 inscrições fictícias confirmadas; `registration_category` = `title` da entrada, correlação inequívoca |
| Sync real pelo HackLab | **concluída**: 1º sync criou 5 entradas, 4 inscrições e 4 Persons; 2º e 3º syncs sem nenhuma alteração; mapeamento preservado; materialização criou só o participante fictício e é idempotente |
| Cancelamento | implementação via webhook **concluída e testada**; PoC real **pendente** (ausência na API nunca é cancelamento) |
| Webhook real (header, ids, timestamps, reenvio) | implementação e testes automatizados **concluídos**; PoC real `pending_public_url` — obrigatório na Fase 12, antes de produção |
| Atividades/programação (19/10/2026, somente leitura) | **parcial**: a documentação oficial V2 lista `GET /api/v2/schedule/session` ("Listar atividades": `id`, `event_id`, `title`, `venue`, `description`, `type`, `capacity`, `times`…, paginado) e `GET /api/v2/schedule` ("Visualizar agenda": por dia, sessões com `date`, `utc_date`, `start_time`, `end_time`, sem `event_id`/status). No evento 793572 os dois respondem 200 com `data: []` e a paginação (`page`, `page_size`) funciona, mas **não há atividade cadastrada**: campos, formato de `times`, timezone e status reais seguem não confirmados. Não há GET de histórico de check-in (evento ou atividade) documentado. Nada implementado |

Tudo o que está marcado como pendente é suposição documentada, não fato observado, até o PoC correspondente.
