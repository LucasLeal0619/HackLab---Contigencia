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

- `event_integrations`: vínculo Event ↔ evento externo (`EVEN3`, `V2`, `ACTIVE`/`DISABLED`). `unique(event_id, provider)` e `unique(provider, external_event_id)`. Sem token.
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

Explícita e idempotente: inscrição `CONFIRMED` + entrada mapeada como `PARTICIPANT` → cria `Participant(event, person)` (`AVAILABLE`, sem turma) se não existir. Participante existente não muda (status, turma, equipe, desafio). Cancelar na Even3 não tira ninguém de equipe: isso é ação humana.

## 9. Auditoria

Só ações administrativas: `EVEN3_INTEGRATION_CONFIGURED`, `EVEN3_TICKET_MAPPING_CHANGED`, `EVEN3_SYNC_TRIGGERED` (um por sync manual; o resultado fica no `integration_sync_run`) e `EVEN3_PARTICIPANTS_MATERIALIZED` (um por execução). Pessoas, inscrições e participantes criados pela integração não geram um log cada.

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

Tudo o que está marcado como pendente é suposição documentada, não fato observado, até o PoC correspondente.
