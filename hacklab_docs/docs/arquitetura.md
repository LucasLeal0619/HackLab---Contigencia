# Arquitetura do HackLab

## 1. Visão geral

O HackLab é um monólito modular com frontend Vue, backend Laravel e PostgreSQL.

```text
┌────────────────────┐
│      Vue Front      │
│   JavaScript/Vue    │
└─────────┬──────────┘
          │ HTTPS / JSON
          ▼
┌────────────────────┐
│    Laravel API      │
│      /api/v1        │
└──────┬───────┬─────┘
       │       │
       │       └───────────────┐
       ▼                       ▼
┌─────────────┐        ┌────────────────┐
│ PostgreSQL  │        │ Plataforma     │
│             │        │ externa/Even3 │
└─────────────┘        └────────────────┘
```

### 1.1 Onde cada parte roda

| Parte | Dev/testes | Produção |
|---|---|---|
| Vue | `npm run dev` | Static Site |
| Laravel | Docker | Render Web Service (Docker) |
| PostgreSQL | Docker (`hacklab_dev`, `hacklab_test`) | Supabase |

O Supabase é usado só como PostgreSQL gerenciado. Autenticação, autorização e regras ficam no Laravel. Detalhes em `infraestrutura.md`.

## 2. Princípio arquitetural

A plataforma externa resolve:
- divulgação pública;
- inscrição;
- credencial/QR;
- credenciamento;
- check-in.

O HackLab resolve:
- organização interna;
- regras do Hackathon;
- equipes;
- setores;
- jurados;
- avaliações;
- votação;
- resultados;
- auditoria;
- consolidação de presença.

## 3. Monólito modular

Não usar microserviços nesta etapa.

Sugestão de organização Laravel:

```text
backend/
├── app/
│   ├── Domain/
│   │   ├── Auth/
│   │   ├── People/
│   │   ├── Users/
│   │   ├── Events/
│   │   ├── Participants/
│   │   ├── Teams/
│   │   ├── Companies/
│   │   ├── Challenges/
│   │   ├── Sectors/
│   │   ├── Meetings/
│   │   ├── Tasks/
│   │   ├── Occurrences/
│   │   ├── Documents/
│   │   ├── Jurors/
│   │   ├── Evaluations/
│   │   ├── Voting/
│   │   ├── Results/
│   │   ├── Attendance/
│   │   └── Audit/
│   ├── Http/
│   │   ├── Controllers/Api/V1/
│   │   ├── Requests/
│   │   └── Resources/
│   ├── Integrations/
│   │   ├── EventPlatform/
│   │   └── Even3/
│   ├── Policies/
│   ├── Jobs/
│   └── Providers/
├── routes/api.php
├── database/
│   ├── migrations/
│   ├── factories/
│   └── seeders/
└── tests/
    ├── Feature/
    └── Unit/
```

A estrutura pode ser adaptada ao estilo do projeto, mas a separação de responsabilidades deve ser preservada.

## 4. Domínios

### People

`Person` representa uma pessoa real conhecida pelo HackLab.

Pode existir sem usuário.

É o ponto central para evitar duplicidade entre:
- participante;
- jurado;
- representante de empresa;
- inscrito externo;
- usuário.

### Users/Auth

Usuário é uma credencial de acesso ao HackLab.

Um usuário:
- pertence a uma pessoa;
- possui um perfil;
- pode possuir setor;
- possui status ativo/inativo.

### Events

Mantém configuração do Hackathon:
- nome;
- período;
- local;
- status;
- dias do evento;
- integração externa associada.

### Participants

Representa exclusivamente o aluno que participa do Hackathon desenvolvendo solução.

Não usar `Participant` como termo genérico para todos os inscritos externos.

### Teams

Equipes são dinâmicas.
Não assumir quantidade fixa de participantes.

### Companies

Empresa é entidade interna, de um evento.
Representantes são pessoas (Person) vinculadas à empresa, sem conta de acesso obrigatória.

Não criar empresa automaticamente a partir de inscrição externa.

### Challenges

Desafios pertencem ao evento e podem ter empresa. A equipe recebe o desafio (`teams.challenge_id`, fonte única da relação); a API de desafio devolve a equipe pela relação inversa.

### Sectors

Setores organizam a operação interna.

Gestor e Editor são escopados ao próprio setor.

### Tasks/Pendências

Algo que precisa ser feito.

Pode ter:
- setor de origem;
- um setor responsável principal;
- múltiplos setores envolvidos;
- responsável individual do setor responsável;
- histórico/interações (somente inserção).

### Occurrences

Algo que aconteceu.

Pode:
- ter setor de origem;
- ter responsável principal;
- ter múltiplos envolvidos;
- ser encaminhada;
- ser resolvida com uma solução registrada;
- gerar uma ou várias Pendências.

### Demandas (código compartilhado)

Pendência e ocorrência são domínios separados, mas compartilham a mecânica de setores (origem, responsável, envolvidos), escopo, encaminhamento, atribuição e histórico em `App\Domain\Demands`. Cada domínio tem seu próprio model, service, policy, endpoints e auditoria.

### Jurors

Jurado é papel de domínio de uma Person no evento (não é conta de acesso nem representante de empresa).

Atribuição a equipes é explícita.

```text
Jurado ↔ Equipes
```

Empresa do jurado é opcional e nunca define automaticamente as equipes avaliadas.

O usuário age como jurado quando `users.person_id = jurors.person_id`; a Policy de avaliação exige esse vínculo + atribuição ativa, então nem o Administrador escreve pela conta de outro jurado.

### Evaluations

Avaliação técnica por jurado é separada do voto público.

Rascunho → envio → (revisão solicitada pelo Administrador → reenvio pelo jurado). O Administrador nunca edita nota. Percentuais e resultado técnico são calculados na consulta, não persistidos.

### Voting

Votação pública possui regras próprias e não conhece notas técnicas.

```text
VotingSession (DRAFT → OPEN → CLOSED | CANCELLED)
├── candidatas explícitas (voting_session_teams)
├── elegíveis explícitos (voting_eligibilities: User ACTIVE + VOTER)
└── public_votes (append-only, 1 por usuário/sessão)
```

- `VotingSessionService`: configuração em `DRAFT`, abertura, fechamento e cancelamento (transações, locks e auditoria).
- `PublicVoteService`: grava o voto. Não audita a escolha.
- `VotingAggregates`: participação (sempre) e contagem por equipe (só `CLOSED`).

Concorrência (pico do dia 3):

```text
voto     : sessão FOR SHARE → elegibilidade FOR UPDATE → (voto existente?) → insert
fechar   : sessão FOR UPDATE  (espera os votos em andamento; votos seguintes veem CLOSED)
abrir    : evento FOR UPDATE → sessão FOR UPDATE → candidatas/elegíveis FOR SHARE
config   : sessão FOR UPDATE
```

Votos de usuários diferentes não se bloqueiam entre si (todos pegam a sessão em modo compartilhado). Dois votos do mesmo usuário serializam na linha de elegibilidade; o `unique(voting_session_id, user_id)` é a última barreira (violação vira o voto existente ou 422). Triggers repetem as garantias essenciais no banco.

Sigilo na aplicação: nenhuma API devolve eleitor → equipe (só o próprio voto ao próprio eleitor) e a auditoria não registra a escolha.

### Results

Publicação oficial, separada dos dois cálculos que consome:

```text
EvaluationScoring (técnico, Fase 6)  ─┐
                                       ├─ ResultsCalculator → prévia (Administrador)
VotingAggregates (popular, CLOSED)   ─┘                   → ResultPublicationService → snapshot jsonb versionado
```

- Sem score combinado. Ranking por competição (`1, 1, 3`), sem desempate artificial; técnico comparado em aritmética decimal (bcmath).
- Publicar: evento `FOR UPDATE` → verificações → snapshot → `RESULTS_PUBLISHED`. No máximo uma publicação `PUBLISHED` por evento (índice parcial).
- Revogar: motivo obrigatório; versão antiga preservada; nova publicação = próxima versão.
- Enquanto `PUBLISHED`: `JurorService::syncAssignments` e `EvaluationService::requestRevision` são recusados (também por trigger). Essas operações pegam o lock do evento, então não há corrida com a publicação.
- Qualquer usuário autenticado lê o snapshot `PUBLISHED`; nada além dele.

### Attendance

Consolida inscrição e presença vindas da plataforma externa.

O HackLab acompanha presença, não executa QR/credenciamento.

### Audit

Auditoria backend, imutável pela interface.

## 5. Perfis

Perfis internos atuais:

```text
Administrador
Gestor
Editor
Consultor
Jurado
Votante
```

### Administrador
Acesso global.

### Gestor
Lidera um setor e gerencia apenas seu escopo, salvo demandas intersetoriais onde seu setor participe.

### Editor
Atua em um setor com poderes reduzidos.

### Consultor
Visão transversal/orientação, sem administração de usuários.

### Jurado
Acessa apenas avaliações atribuídas.

### Votante
Acessa votação pública quando elegível na sessão (perfil `VOTER` sozinho não basta).

## 6. Autorização

Usar Policies/Gates.

A autorização precisa combinar:
- perfil;
- setor;
- vínculo com recurso;
- estado do recurso.

Exemplo:

```text
Editor de Marketing
→ pode ver pendência do Marketing
→ pode ver pendência intersetorial envolvendo Marketing
→ não pode ver pendência privada de Produção
```

Administrador vê tudo.

### 6.2 Escopo setorial (implementado na Fase 2)

```text
Policy = permissão do perfil (PermissionCode) + escopo do usuário
```

- O escopo vem do vínculo, não do nome do perfil: `User::canReachSector()` / `User::reachesAllSectors()`.
- Gestor e Editor têm setor obrigatório (trigger no banco) e alcançam só o próprio setor.
- Administrador e Consultor não têm setor: alcance global, cada um até onde suas permissões vão (Consultor só consulta).
- Ações estruturais (criar/ativar/inativar setor, gerenciar evento, gerenciar reunião geral) exigem alcance global.
- Listagens aplicam o mesmo escopo na query (`Sector::visibleTo`, `Meeting::visibleTo`).
- Controllers não testam nome de perfil. Endpoints de escrita autorizam no `authorize()` do Form Request, antes da validação (sem permissão = 403, sem detalhes de validação).

Pendências e ocorrências (Fase 5) reutilizam esse escopo, somando setor de origem, responsável e envolvidos:

```text
vê/comenta  = permissão + (alcance global OU setor do usuário ∈ {origem, responsável, envolvidos})
opera       = permissão + (alcance global OU setor do usuário = responsável atual)
encaminha   = permissão de roteamento + (alcance global OU setor do usuário = responsável atual)
```


### 6.3 Setores de destino de demandas (Fase 10)

`GET /events/{event}/sectors` continua escopado (Gestor/Editor veem só o próprio setor). Para encaminhar ou criar demanda para outro setor, quem tem a operação usa um contrato próprio:

```text
GET /events/{event}/tasks/routing-sectors         exige tasks.route
GET /events/{event}/occurrences/routing-sectors   exige occurrences.route
```

- devolve só `{ id, name }` dos setores **ativos** do evento (os destinos que o backend aceita no encaminhamento);
- quem tem setor só consulta o evento do próprio setor (403 nos demais); alcance global consulta qualquer evento;
- Editor, Consultor, Jurado e Votante (sem `*.route`) recebem 403. A visibilidade normal de setores não muda.

## 7. Integração externa (Fase 9)

```text
App\Integrations\EventPlatform\EventPlatformProvider     contrato neutro (sem formato da Even3)
        ↑
App\Integrations\Even3\Even3Provider                      usa Even3Client + Even3Mapper
App\Integrations\Even3\Even3Client                        único lugar com HTTP: Bearer, base URL, timeouts, retry, paginação, erros, redação do token
App\Integrations\Even3\Even3Mapper                        formatos V2/webhook → arrays neutros
App\Domain\Integrations\*                                 regras do HackLab (sync, matching, webhook, materialização)
```

```php
interface EventPlatformProvider
{
    public function fetchEvent(string $externalEventId): array;          // id, title, start_date, end_date…
    public function fetchTickets(string $externalEventId): array;        // [{ external_ticket_id, name }]
    public function fetchAttendees(): iterable;                         // páginas de participantes normalizados
}
```

- Outra plataforma (ex.: Sympla) = outro Provider/Client/Mapper; os Services do domínio não mudam.
- Nenhum `Http::` fora do `Even3Client`. Token só em `config('services.even3.token')`; nunca em banco, log, exceção ou resposta.
- Nesta fase o fluxo é só Even3 → HackLab (nenhuma escrita na Even3).

## 8. Fluxo de sincronização

```text
POST /events/{event}/integrations/even3/sync  (ou even3:sync)
  ↓
IntegrationSyncService: abre IntegrationSyncRun (lock; 1 RUNNING por integração)
  ↓
Provider: evento → entradas → participantes (paginado)
  ↓
por participante: transação pequena + advisory lock da integração
  ↓ RegistrationMatcher (IDs externos → documento → e-mail → nova Person)
  ↓ RegistrationUpserter (status, categoria por ticket conhecido, source_*)
people / event_registrations / external_ticket_types
  ↓
IntegrationSyncRun: SUCCESS | PARTIAL | FAILED + contadores + avisos seguros
```

```text
POST /webhooks/even3
  ↓ token de segurança (header configurável) → recibo idempotente (id do disparo)
  ↓ mesmo RegistrationMatcher/Upserter (mesmo advisory lock) → event_registrations / attendance_records
```

Webhook e sincronização usam o mesmo lock por integração e a mesma regra de matching: a inscrição é casada pelos IDs externos (inscrição → participante → pessoa externa); documento/e-mail só associam a Person e reaproveitam uma inscrição dela apenas quando há uma única candidata compatível (ambíguo = conflito, sem merge). Uma pessoa pode ter várias inscrições.

## 9. API

Base:

```text
/api/v1
```

Principais grupos (fonte da verdade: `back/routes/api.php`):

```text
/health
/auth (login, logout, me)
/roles
/people
/users (+ /role, /status, /sector)
/events (+ /days)
/events/{event}/sectors, /sectors
/events/{event}/meetings, /meetings
/events/{event}/classes, /classes
/events/{event}/participants, /participants (+ /team)
/events/{event}/teams, /teams (+ /members)
/events/{event}/companies, /companies (+ /status, /representatives), /company-representatives
/events/{event}/challenges, /challenges (+ /status, /team)
/events/{event}/tasks (+ /routing-sectors), /tasks (+ /comments, /forward, /complete, /reopen)
/events/{event}/occurrences (+ /routing-sectors), /occurrences (+ /comments, /forward, /resolve, /reopen, /tasks)
/events/{event}/jurors, /jurors (+ /status, /assignments)
/events/{event}/evaluation-criteria, /evaluation-criteria
/events/{event}/my-evaluations, /juror-assignments/{a}/evaluation (+ /submit)
/events/{event}/evaluations, /evaluations (+ /request-revision), /evaluation-progress, /technical-results
/events/{event}/voting-sessions, /voting-sessions (+ /teams, /eligibilities, /open, /close, /cancel, /turnout, /results)
/events/{event}/my-voting-sessions, /voting-sessions/{s}/ballot, /vote, /my-vote
/events/{event}/results (/preview, /publications, /publish, /revoke, /published)
/audit-logs (+ /meta, /{id})
/events/{event}/integrations/even3 (/status, /tickets, /sync, /sync-runs), /external-ticket-types/{t}/mapping, /integration-sync-runs/{run}
/events/{event}/event-registrations (+ /materialize-participants), /event-registrations/{r}
/events/{event}/attendance
/webhooks/even3
```

Não existem endpoints de documentos, atas/decisões de reunião, premiações, relatórios ou finanças.

## 10. Respostas e erros

Padronizar resposta JSON.

Exemplo de sucesso:

```json
{
  "data": {},
  "meta": {}
}
```

Erro:

```json
{
  "message": "Mensagem legível",
  "errors": {}
}
```

Usar status HTTP corretamente.

## 11. Auditoria

A auditoria deve ser gerada no backend.

Campos mínimos:
- id;
- actor_user_id;
- actor_person_id;
- action;
- module;
- entity_type;
- entity_id;
- description;
- before_data;
- after_data;
- ip_address;
- user_agent;
- created_at.

Evitar registrar segredos, senha ou tokens.

### 11.1 Fluxo (Fase 8)

```text
ação de negócio (Service, dentro da transação)
  ↓
AuditLogger::record(action, module, description, entity?, before?, after?, actor?, event?, entityLabel?)
  ├── ator: snapshot de nome/e-mail/perfil/setor (Auth ou ator explícito)
  ├── evento: inferido da entidade (ou explícito)
  ├── entity_label: inferido da entidade (ou explícito)
  └── AuditSanitizer: remove segredos de before/after
  ↓
audit_logs (PostgreSQL, append-only)
  ↓
GET /api/v1/audit-logs              filtros + paginação, sem before/after/IP/User-Agent
GET /api/v1/audit-logs/{id}         before/after + changes (AuditDiff, derivado) + IP + User-Agent
GET /api/v1/audit-logs/meta         catálogo de ações e módulos
```

- `AuditAction`: códigos de domínio (constantes). `AuditActionCatalog`: módulo + rótulo em português de cada código e rótulo de cada módulo (metadado de apresentação). Um teste garante que toda constante tem entrada no catálogo e que cada chamada usa o módulo do catálogo.
- Chamadas antigas do `AuditLogger` continuam válidas: os parâmetros novos são opcionais e inferidos.
- A listagem lê o ator dos snapshots e o nome do evento por eager loading (sem N+1). Ler auditoria não gera auditoria.
- AuditLog é trilha administrativa imutável, não event sourcing.

## 12. Segurança

- HTTPS em produção.
- Sanctum/CSRF.
- CORS restrito.
- Rate limiting.
- Secrets em `.env`.
- Banco de produção (Supabase) só pelo Session pooler com SSL obrigatório; credenciais só no Render.
- O frontend nunca recebe URL nem chave do Supabase.
- Credenciais do banco só no backend.
- Token Even3 apenas no backend.
- Logs sem dados sensíveis.
- Policies em endpoints.
- Validação via Form Request.
- SQL apenas via Eloquent/Query Builder parametrizado.
- Nunca confiar em IDs enviados pelo frontend sem autorização.

## 13. Frontend (Fase 10)

O frontend é uma SPA Vue 3 (JavaScript, sem TypeScript, sem Pinia, sem Vue Router; roteamento por hash `#/rota`). O backend é o contrato: o Vue só exibe o que a API devolve e só executa operações que existem na API. Não há modo demonstração, dados locais de negócio, login simulado nem fallback quando a API está fora do ar.

### 13.1 Camadas

```text
front/src/
├── js/api/
│   ├── client.js        único cliente HTTP (axios): base VITE_API_URL, cookies, XSRF, timeout, erros
│   ├── errors.js        ApiError normalizado (status, kind, message, fieldErrors)
│   └── pagination.js    leitura de data/meta/links do Laravel
├── js/stores/
│   ├── auth.js          sessão (UNKNOWN/AUTHENTICATED/GUEST), usuário, permissões de /auth/me
│   ├── event-context.js eventos visíveis, evento ativo, dias e fuso do evento
│   └── ui.js            toast e preferências de acessibilidade (hacklab.ui)
├── js/services/         um arquivo por domínio; só eles chamam o client
├── js/composables/      useRequest, usePaged, useAction (loading, erro, 422, duplo envio)
├── js/config/access.js  navegação e rotas por permissão
├── js/labels.js         rótulos pt-BR dos códigos de enum do backend
└── js/utils/format.js   datas/horas pt-BR no fuso do evento
```

Componentes e páginas não chamam `fetch`/axios: chamam services. Services não guardam estado: devolvem dados da API.

### 13.2 Autenticação (Sanctum SPA)

```text
GET  /sanctum/csrf-cookie      cookie XSRF-TOKEN
POST /api/v1/auth/login        cookie de sessão HttpOnly
GET  /api/v1/auth/me           usuário, Person, role.code/name, setor, permissions[]
POST /api/v1/auth/logout
```

- Cliente com `withCredentials` e `withXSRFToken`: o navegador envia o cookie de sessão e o axios copia `XSRF-TOKEN` para o header `X-XSRF-TOKEN`. O front nunca lê o cookie de sessão nem guarda token, senha ou sessão no navegador.
- Ao abrir o app: tela de carregamento → `/auth/me` → `AUTHENTICATED` ou `GUEST`. Não existe flash de tela autenticada antes da resposta.
- Tratamento central: 401 limpa a sessão e leva ao login; 403 não desloga (mostra "sem permissão"); 419 renova o CSRF e repete a requisição uma única vez; 422 vira erro por campo; 429 vira mensagem amigável; rede/5xx mostram erro com "tentar novamente". Nenhuma repetição infinita.
- Em desenvolvimento o front (`localhost:5174`) e a API (`localhost:8000`) estão no mesmo host, então o cookie `XSRF-TOKEN` é legível pelo front. Em produção, com domínios diferentes, ver o risco de cookie entre domínios em `infraestrutura.md`.

### 13.3 Autorização na interface

- A interface decide o que mostrar por `auth.permissions` (`hasPermission`, `hasAnyPermission`). `role.code` só escolhe a experiência visual: `JUROR` → Área do Jurado (FocusShell), `VOTER` → Votação (FocusShell), demais → primeira área operacional permitida. Jurado e Votante também acessam, dentro da tela focada, o resultado oficial publicado (`#/painel`, `results.view_published`); prévia, histórico, publicação, revogação e contagens continuam só na gestão.
- Esconder um item não é segurança: o backend continua validando tudo (Policies). Um 403 vindo da API é mostrado como "sem permissão" sem encerrar a sessão.
- Ações por registro usam o que a API devolve quando ela devolve (`meta.abilities` de pendências e ocorrências, `meta.locked` dos critérios).

### 13.4 Evento ativo

- `GET /api/v1/events` define o contexto: 0 eventos → estado vazio; 1 → selecionado; mais de 1 → seletor no topo.
- O `activeEventId` é preferência local (`hacklab.ui`), sempre validada contra a lista da API. Nunca há id de evento fixo no código.
- Dias, horários e fuso vêm de `Event` e `EventDay[]` (não há "3 dias" ou "08:00" fixos). Datas chegam em ISO/UTC e são exibidas em pt-BR no fuso do evento.
- Trocar de evento invalida as telas dependentes (recarregam pelo novo id).

### 13.5 Armazenamento no navegador

Única chave: `hacklab.ui` → `{ "a11y": {...}, "activeEventId": 1 }`. Opcional: `hacklab.remember` (só o e-mail digitado, quando o usuário marca "lembrar"). Nenhum dado de negócio, sessão, senha ou token.

Limpeza única das chaves do protótipo: `hacklab` (estado inteiro do protótipo; só as preferências de acessibilidade são aproveitadas) e `hacklab.vote.cast` são apagadas na primeira abertura.

### 13.6 O frontend não conhece a Even3

```text
Vue → GET /api/v1/events/{e}/event-registrations | /attendance → Laravel → dados locais sincronizados
Vue → POST /api/v1/events/{e}/integrations/even3/sync → Laravel → Even3 → PostgreSQL → resumo
```

O front nunca chama `api.even3.com`, nunca recebe nem envia o token da Even3, o segredo ou o header do webhook.

## 14. Migração do protótipo (Fase 10, concluída)

O protótipo guardava tudo em `localStorage` e simulava login, perfis, auditoria e voto. Na Fase 10:

1. layout, componentes visuais, responsividade e acessibilidade foram mantidos;
2. cada tela passou a usar o service do seu domínio;
3. operações sem endpoint foram removidas da interface (não há operação local fingindo persistência);
4. foram removidos: modo demonstração, contas demonstrativas, troca de perfil, setor demonstrativo, perfil Validador, cadastro público, convites de jurado, scanner/QR/credenciais locais, auditoria local e voto local.

A matriz tela a tela (REAL_API / UI_HELPER_WITH_REAL_API / DEFERRED / REMOVE_PROTOTYPE) está em `plano-implementacao.md`, Fase 10.
