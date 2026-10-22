# CLAUDE.md — HackLab

Este arquivo é a entrada principal para qualquer agente/assistente que trabalhar neste repositório.

## 1. Objetivo

Construir uma versão funcional e independente do **HackLab**, sistema interno de gestão de um Hackathon do Senac.

O repositório é uma versão paralela do projeto, usada para acelerar a implementação e reduzir dependência de outras frentes.

O HackLab **não deve reinventar plataforma de inscrição/ingresso/QR**. A arquitetura atual considera a **Even3** como plataforma externa de inscrição e credenciamento, com integração via API e, se a infraestrutura permitir, Webhook.

## 2. Stack obrigatória

### Frontend
- JavaScript
- Vue
- Preservar a estrutura e identidade visual existentes sempre que possível
- Não migrar para TypeScript sem decisão explícita
- Não chamar Even3 diretamente do navegador

### Backend
- PHP
- Laravel
- API REST versionada em `/api/v1`
- Laravel Sanctum para autenticação SPA, salvo decisão futura documentada
- Form Requests para validação
- API Resources para respostas
- Policies/Gates para autorização
- Services para regras de negócio complexas
- Jobs/Queues para processamento assíncrono quando necessário

### Banco
- Produção: **PostgreSQL** gerenciado no Supabase. É a referência: o comportamento oficial e a suíte oficial de testes (CI) são em PostgreSQL
- Desenvolvimento local da equipe (branch `dev`): **MySQL 8.0.19+** (recomendado 8.4 LTS; **não MariaDB/XAMPP**), sem Docker, bancos `hacklab_dev` e `hacklab_test`. Alternativa: PostgreSQL 17 em Docker (`docker-compose.yml`)
- O mesmo código roda nos dois bancos. Recurso exclusivo de um banco só com caminho equivalente no outro, sem enfraquecer o PostgreSQL: triggers, índices parciais, CHECKs e travas de concorrência são reproduzidos no MySQL, nunca removidos (HACKLAB-DEV-001, `docs/infraestrutura.md` §2.2). Busca textual por `whereILike`/`orWhereILike`, nunca `'ilike'` direto
- Tabelas no schema `hacklab` (nunca `public`)
- Migrations obrigatórias
- Seeders e factories para dados de desenvolvimento
- Chaves estrangeiras, índices e constraints devem ser definidos desde o início

### Infraestrutura
- Desenvolvimento local: PHP + Composer + Node + MySQL instalados na máquina, **sem Docker** (guia no `README.md`). Docker Compose (PostgreSQL 17) continua como alternativa opcional
- CI: GitHub Actions com PostgreSQL 17 em todo PR para `dev` e `main` (`.github/workflows/ci.yml`)
- Produção: API Laravel no Render (Web Service), frontend no Netlify, banco no Supabase
- Render e Supabase são hospedagem, não dependências do código
- `back/Dockerfile`: alvo `dev` (compose, `php -S`, código montado) ≠ alvo `prod` (Render: FrankenPHP na porta `PORT`, sem dev deps, sem segredos, caches no start). Detalhes em `docs/infraestrutura.md` §3.2

## 3. Estrutura do repositório

```text
HackLab/
├── front/
│   └── aplicação Vue
├── back/
│   ├── API Laravel
│   ├── Dockerfile         (alvos dev e prod)
│   ├── docker/prod/       (Caddyfile, php.ini, entrypoint e release do alvo prod)
│   ├── .env.example
│   └── .env.testing.example
├── docker/
│   └── postgres/init/     (cria hacklab_test e o schema hacklab no primeiro start)
├── hacklab_docs/
│   ├── README.md
│   └── docs/
│       ├── arquitetura.md
│       ├── infraestrutura.md
│       ├── banco-de-dados.md
│       ├── integracao-even3.md
│       ├── regras-de-negocio.md
│       └── plano-implementacao.md
├── .github/workflows/ci.yml (CI: PostgreSQL 17, Pint, testes e build em PRs para dev e main)
├── docker-compose.yml     (opcional: PostgreSQL 17 + Laravel em Docker)
├── CLAUDE.md
├── .gitignore
└── README.md
```

Nos documentos, `frontend/` e `backend/` se referem às pastas `front/` e `back/`, e `docs/` se refere a `hacklab_docs/docs/`.

## 4. Ordem de leitura

Antes de implementar qualquer feature:

1. `CLAUDE.md`
2. `docs/arquitetura.md`
3. `docs/infraestrutura.md`
4. `docs/regras-de-negocio.md`
5. `docs/banco-de-dados.md`
6. `docs/integracao-even3.md`
7. `docs/plano-implementacao.md`

## 5. Decisões arquiteturais atuais

### 5.1 HackLab é sistema interno

O HackLab gerencia:
- pessoas;
- participantes/alunos;
- equipes;
- turmas;
- empresas;
- desafios;
- setores;
- reuniões;
- pendências;
- ocorrências;
- documentos;
- jurados;
- avaliações;
- votação;
- resultados;
- presença sincronizada;
- relatórios;
- auditoria;
- usuários e permissões.

A plataforma externa gerencia:
- página pública do evento;
- inscrições;
- entradas/categorias externas;
- QR/código de credenciamento;
- credenciamento;
- check-ins.

### 5.2 Não implementar Validador no HackLab

Na arquitetura atual, o credenciamento é operado na plataforma externa.

Perfis internos atuais:

```text
Administrador
Gestor
Editor
Consultor
Jurado
Votante
```

Não criar o perfil `Validador`, a menos que esta decisão seja alterada primeiro na documentação.

### 5.3 Pessoa != Usuário != Participante != Jurado

Essa separação é obrigatória.

Uma pessoa pode existir no HackLab sem login.

Exemplos:

```text
Pessoa
└── Participante
    ├── turma
    ├── equipe
    └── desafio
```

```text
Pessoa
├── Jurado
│   └── equipes atribuídas
└── Usuário
    └── perfil Jurado
```

```text
Pessoa
└── Inscrição externa
    └── categoria Público

sem usuário HackLab
```

### 5.4 Integração externa é isolada

Nenhum módulo de negócio deve depender diretamente da Even3.

Fluxo:

```text
Even3
  ↓
Even3Provider / Even3SyncService
  ↓
Banco HackLab
  ↓
Módulos internos
```

No futuro deve ser possível criar outro provider, por exemplo Sympla, sem reescrever Participantes, Jurados, Presença etc.

### 5.5 Uma sincronização central; o seguro é automático

Premissa do evento real: **participantes, equipes, organização interna (Gestores, Editores, Consultores), setores, empresas e desafios são cadastrados no HackLab antes do evento.** A Even3 não monta o HackLab: ela valida inscrições e traz jurados externos, convidados de empresas, o público e a presença.

A sincronização da Even3 é **única e central por evento**: o mesmo endpoint (`POST /events/{event}/integrations/even3/sync`), o mesmo `IntegrationSyncService`, o mesmo lock e o mesmo histórico (`integration_sync_runs`). Sincronizar é idempotente, auditado e protegido por lock: **não pede confirmação**.

**Tudo que é seguro é automático. Só ambiguidades viram pendência ("Precisa de atenção").**

```text
Sincronizar → atualiza inscrições/entradas → é seguro aplicar?
                                              ├── sim → aplica (Jurado confiável → Juror)
                                              └── não → "Precisa de atenção" para o Administrador
```

Precisa de atenção (lista única, na tela Presença): participante não localizado no HackLab, inscrição com identidade divergente, representante de empresa sem empresa identificada, entrada externa sem mapeamento. Inscrição nova não é pendência.

Interface: **Presença** é a tela da integração (indicadores, lista de inscritos com presença, "Precisa de atenção", botão Sincronizar). Mapeamento de entradas, histórico e divergências do evento ficam em **Configuração Even3** (discreta). O cabeçalho mostra o estado compacto ("Even3 · Atualizado há 3 min ↻"). A sincronização também roda sozinha, em silêncio, como reserva (a cada 10 min com Administrador ativo e `even3:sync` no Scheduler); o caminho principal em produção é webhook + sincronização manual.

Não criar: botão de sincronizar em Participantes, Jurados, Empresas, Usuários ou Votação; sync separado por domínio; filas de revisão por domínio; etapas "candidato → revisar → confirmar" quando não há decisão humana real.

### 5.6 Ambientes e hospedagem

```text
DESENVOLVIMENTO (branch dev)             CI (PRs)                 PRODUÇÃO (branch main)

Vue (npm run dev)                                                 Vue — Netlify (VITE_API_URL)
        ↓                                                                 ↓
Laravel (php artisan serve)              PostgreSQL 17            Laravel API — Render Web Service (Docker)
        ↓                                (GitHub Actions)                 ↓
MySQL 8 local (sem Docker)                                        PostgreSQL — Supabase (Session pooler, SSL)
├── hacklab_dev                          ou PostgreSQL do Docker
└── hacklab_test                                                  Even3 → Laravel → PostgreSQL
```

Regras:
- configuração só por variáveis de ambiente. Nada de host, URL ou domínio fixo no código (front usa `VITE_API_URL`);
- **Supabase é só PostgreSQL gerenciado.** Laravel é dono de autenticação (Sanctum), autorização (Policies/Gates), regras, migrations, API e integração Even3. O frontend nunca acessa o Supabase. Não implementar regras em RLS;
- produção conecta pelo **Session pooler (5432)** do Supabase, com `sslmode=require` e `search_path=hacklab`;
- o Render Free dorme após 15 min sem tráfego (cold start de ~1 min). Antes de momentos críticos, chamar `GET /api/v1/health` (também é o Health Check Path). O Free serve só para validar o deploy: no evento, no mínimo o plano pago básico, decidido após a Fase 13. No Free, `HACKLAB_MIGRATE_ON_START=true` só no primeiro deploy (depois `false`) e SMTP nas portas 25/465/587 não está disponível;
- migrations em produção só por `docker/prod/release.sh` (`migrate --force` + `RolePermissionSeeder`), no Pre-Deploy ou com `HACKLAB_MIGRATE_ON_START=true`; nunca no `docker build`;
- IP do cliente só por `$request->ip()`, resolvido pelo `ResolveClientIp` (proxy assinado do Netlify → `CF-Connecting-IP`). Não ler `X-Forwarded-For`/`X-Real-IP` direto, não usar `TRUSTED_PROXIES=*` e nunca confiar em `X-Forwarded-Host` (`docs/infraestrutura.md` §3.2.8);
- o Web Service não roda o Scheduler: sincronização agendada só por Render Cron Job (`even3:sync`). Não há worker de fila (nenhum fluxo usa fila);
- trocar de hospedagem (ex.: servidor do Senac) não exige mudar código: mesma imagem Docker, `pg_dump`/`pg_restore`, novo `VITE_API_URL`.

Detalhes, riscos e pendências (incluindo o cookie de sessão entre domínios `onrender.com`) em `docs/infraestrutura.md`.

### 5.6.1 Branches e integração

Duas branches permanentes, sem homologação/staging:

```text
feature/* ou fix/*  ──PR──▶  dev (equipe, MySQL local)  ──PR──▶  main (produção: Render + Netlify + Supabase)
```

- `main`: produção. Só recebe PR com o CI verde (PostgreSQL 17 + front) e autorização. Nada de push direto.
- `dev`: integração da equipe. Não dispara deploy (Render e Netlify publicam só a `main`).
- `feature/*`/`fix/*`: temporárias, saem da `dev` e voltam por PR.
- Arquivos `.env` nunca no Git.

### 5.7 O que cada categoria da Even3 faz

Pela categoria mapeada (`internal_category` da entrada, nunca o texto do nome), só de inscrições `CONFIRMED` e sem `identity_mismatch`, contadas por Person:

```text
PARTICIPANT     → valida o Participant que já existe (mesma Person no evento); não existe → "participante não localizado"
JUROR           → Juror ativo automático ao sincronizar (nunca User: conta só pelo primeiro acesso)
PUBLIC          → pode fazer o primeiro acesso como Votante (elegibilidade continua explícita na votação, só em DRAFT)
COMPANY_GUEST   → já representa uma empresa do evento → nada; senão "representante sem empresa" (o Administrador escolhe a empresa)
ORGANIZATION    → nada: a organização é interna e cadastrada antes
identity_mismatch (qualquer categoria) → "identidade divergente"; nada é aplicado
```

A presença (check-ins) vem da Even3 e só é exibida: sem check-in é "Sem registro", nunca "Ausente".

O HackLab é autoridade sobre `Participant`, `User`, perfil, setor, `Company`, `CompanyRepresentative`, `VotingEligibility` e **nome/datas do `Event`** (divergência com a Even3 só aparece como aviso na Configuração Even3). Nenhuma categoria externa cria Participant, conta, perfil ou empresa.

## 6. Regras de implementação

- Não colocar regra de negócio importante em Controller.
- Controllers devem orquestrar Request → Service/Action → Resource.
- Não retornar Models crus quando houver contrato de API.
- Não acessar o banco diretamente no frontend (nem Supabase JS/Data API).
- Não colocar token da Even3 no frontend.
- Não usar nome textual de categoria externa como única chave de integração.
- Guardar IDs externos.
- Toda ação sensível deve gerar auditoria no backend.
- Toda operação que altere múltiplas tabelas relacionadas deve usar transaction.
- Endpoints mutáveis devem validar autorização via Policy/Gate.
- Não criar duplicatas silenciosas.
- E-mail pode ajudar a vincular pessoa, mas não deve ser a única chave externa.
- Webhooks devem ser idempotentes.
- Sincronizações devem ser repetíveis sem duplicar dados.
- Paginar listagens grandes.
- Usar soft delete apenas onde houver necessidade real de recuperação/histórico.
- Não apagar logs de auditoria via interface.

## 7. Frontend

O frontend atual é um protótipo rico e não deve ser refeito do zero.

Objetivo da integração:
- substituir mocks/localStorage gradualmente pela API;
- manter comportamento visual;
- manter responsividade;
- manter rotas e fluxos já aprovados sempre que possível.

Centralizar chamadas HTTP, por exemplo:

```text
frontend/src/
├── services/
│   ├── api.js
│   ├── auth.js
│   ├── participants.js
│   └── ...
```

Não espalhar `fetch()`/Axios por componentes.

## 8. Autenticação

Preferência atual:
- Laravel Sanctum;
- sessão/cookie HttpOnly para SPA;
- CSRF;
- CORS configurado corretamente;
- senhas com hash padrão seguro do Laravel;
- rate limit no login;
- conta vinculada a uma `person`.

Não armazenar senha ou segredo em texto puro.

## 9. Auditoria

Auditoria real é responsabilidade do backend.

Registrar ações relevantes, por exemplo:
- criação/edição/inativação de usuário;
- alteração de perfil/setor;
- alterações em participante/equipe;
- criação/encaminhamento/resolução de pendência;
- criação/encaminhamento/resolução de ocorrência;
- atribuição de jurado;
- finalização de avaliação;
- abertura/fechamento de votação;
- publicação de resultado;
- sincronização externa;
- ações administrativas.

Logs são somente leitura para o frontend.

## 10. Qualidade mínima

Toda feature backend relevante deve possuir:
- migration;
- model/relations;
- validation;
- authorization;
- service/action quando necessário;
- endpoint;
- resource/serializer;
- teste Feature;
- auditoria quando aplicável.

Testes:
- a suíte oficial roda em PostgreSQL 17 no CI (GitHub Actions, `.github/workflows/ci.yml`) em todo PR para `dev` e `main`, junto com Pint, testes e build do front. Localmente, no `hacklab_test` (MySQL ou PostgreSQL do Docker). Não usar SQLite;
- regra de integridade nova (trigger, índice, CHECK, trava) precisa de teste que passe nos dois bancos;
- nenhum teste usa `hacklab_dev` nem o Supabase. No ambiente `testing`, o backend recusa qualquer banco que não termine em `_test` e qualquer host do Supabase;
- comandos destrutivos (`migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`) são bloqueados em qualquer banco que não termine em `_test` (`DestructiveCommandGuard`). Testes destrutivos e diagnósticos rodam só no `hacklab_test`. O `hacklab_dev` guarda configuração de integração e dados sincronizados da Even3 que o seed não recria. Não usar `HACKLAB_ALLOW_DESTRUCTIVE_DB=true` sem pedido explícito do usuário.

Antes de concluir uma feature:
- executar testes;
- executar formatter/linter configurado no projeto;
- verificar migrations;
- verificar autorização;
- verificar resposta de erro;
- atualizar documentação se uma decisão mudar.

## 11. Não fazer

- Não criar microserviços.
- Não criar tabelas manualmente nem pelo Dashboard do Supabase; a estrutura vem só das migrations.
- Não usar Supabase Auth, Supabase JS, Storage, Realtime, Edge Functions ou Data API.
- Não usar o Transaction Pooler do Supabase (6543) como conexão principal do Laravel.
- Não aceitar conexão sem SSL com o banco de produção.
- Não conectar desenvolvimento ou testes no Supabase.
- Não apagar nem recriar o `hacklab_dev` (`migrate:fresh`, `db:wipe` etc.) sem pedido explícito do usuário.
- Não desligar a verificação TLS (`verify=false`, `CURLOPT_SSL_VERIFYPEER=false`, `curl -k`) para contornar inspeção HTTPS de rede.
- Não criar mecanismo artificial para manter o Render acordado.
- Não acoplar o código a recursos proprietários do host (SDKs de Render, Supabase, Oracle etc.).
- Não guardar arquivos enviados no disco do container (o Render apaga a cada deploy/sleep).
- Não adicionar Redis/Kafka/etc. sem necessidade comprovada.
- Não acoplar domínio à Even3.
- Não criar QR próprio.
- Não implementar câmera no HackLab.
- Não criar regra automática “jurado da empresa X avalia equipes do desafio X”.
- Não transformar categoria de ingresso em permissão interna automaticamente sem regra explícita (§5.7).
- Não conceder perfil/conta Jurado (`User`) porque alguém possui uma entrada externa `JUROR`: o `Juror` é criado ao sincronizar, a conta só pelo primeiro acesso.
- Não criar `Participant` pela Even3: participantes são cadastrados antes; a Even3 só valida.
- Não criar empresa pela Even3 (nem por categoria, nem por texto de empresa); representante só com empresa inequívoca ou escolhida pelo Administrador.
- Não criar User nem conceder Gestor/Editor/Consultor pela Even3 (`ORGANIZATION` não gera nada).
- Não alterar nome/datas do evento com dados da Even3.
- Não implementar atividades/programação da Even3 nesta versão.
- Não colocar botão de sincronizar a Even3 em páginas de domínio (§5.5).

## 12. Regra para mudanças de arquitetura

Se uma decisão deste arquivo mudar:
1. atualizar documentação primeiro;
2. registrar impacto;
3. só então alterar código.

A documentação é parte do contrato do projeto.
