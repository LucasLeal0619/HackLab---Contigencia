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
- PostgreSQL em todos os ambientes
- Desenvolvimento e testes: PostgreSQL 17 em Docker, bancos `hacklab_dev` e `hacklab_test`
- Produção: PostgreSQL gerenciado no Supabase
- Tabelas no schema `hacklab` (nunca `public`)
- Migrations obrigatórias
- Seeders e factories para dados de desenvolvimento
- Chaves estrangeiras, índices e constraints devem ser definidos desde o início

### Infraestrutura
- Docker / Docker Compose: ambiente de **desenvolvimento e testes**
- Produção: API Laravel no Render (Web Service), frontend como Static Site, banco no Supabase
- Render e Supabase são hospedagem, não dependências do código

## 3. Estrutura do repositório

```text
HackLab/
├── front/
│   └── aplicação Vue
├── back/
│   ├── API Laravel
│   ├── Dockerfile
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
├── docker-compose.yml     (desenvolvimento e testes)
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

### 5.5 Uma sincronização central

A sincronização da Even3 é **única e central por evento**. Sincroniza uma vez; reconcilia em vários módulos.

A interface pode expor um componente global **Centro Even3** em qualquer tela administrativa. Todo disparo usa o mesmo endpoint (`POST /events/{event}/integrations/even3/sync`), o mesmo `IntegrationSyncService`, o mesmo lock e o mesmo histórico (`integration_sync_runs`).

Páginas de domínio (Participantes, Jurados, Empresas, Usuários, Evento, Votação) **nunca** fazem sincronizações próprias e nunca chamam a Even3. Elas podem apenas:
- exibir pendências derivadas dos dados já sincronizados;
- mostrar a própria fila de revisão (candidatos a Jurado em Jurados, Organização em Usuários, Empresa/Convidado em Empresas, divergências em Evento);
- executar ações internas do HackLab sobre esses dados.

Ao sincronizar, o HackLab aplica sozinho (decisão do usuário, 20/10/2026 — um evento só, operação interna):
- inscritos confirmados em entrada `PARTICIPANT` → `Participant` (idempotente, disponível, sem turma, nunca altera quem já existe);
- inscritos confirmados em entrada `JUROR` → `Juror` ativo do evento (sem conta e sem equipes: a conta vem do primeiro acesso, as equipes são atribuídas pelo Administrador; quem foi ignorado ou já é jurado não muda);
- nome e datas do evento na Even3 → evento do HackLab (datas que deixariam dias cadastrados fora do período não são aplicadas e viram aviso).

A sincronização roda sozinha a cada 10 minutos enquanto um Administrador usa o sistema (e pelo Scheduler do Laravel, `even3:sync`, onde houver), sempre pelo mesmo `IntegrationSyncService`. O botão do topo avisa o que chegou de novo. Só Empresa/Convidado continua pedindo decisão (a Even3 não informa a empresa).

Não criar: botão "Sincronizar Even3" dentro de Participantes, Jurados, Empresas, Usuários ou Votação; um segundo mecanismo de sync; vários syncs concorrentes.

Termos: **Sincronizar** = buscar atualizações na Even3 (Even3 → banco do HackLab). **Revisar/Reconciliar** = transformar dados já sincronizados em decisões internas (banco do HackLab → decisão do Administrador). Não usar como sinônimos.

### 5.6 Ambientes e hospedagem

```text
DESENVOLVIMENTO / TESTES                 PRODUÇÃO

Vue (npm run dev)                        Vue — Static Site (VITE_API_URL)
        ↓                                        ↓
Laravel (Docker)                         Laravel API — Render Web Service (Docker)
        ↓                                        ↓
PostgreSQL (Docker)                      PostgreSQL — Supabase (Session pooler, SSL)
├── hacklab_dev
└── hacklab_test                         Even3 → Laravel → PostgreSQL
```

Regras:
- configuração só por variáveis de ambiente. Nada de host, URL ou domínio fixo no código (front usa `VITE_API_URL`);
- **Supabase é só PostgreSQL gerenciado.** Laravel é dono de autenticação (Sanctum), autorização (Policies/Gates), regras, migrations, API e integração Even3. O frontend nunca acessa o Supabase. Não implementar regras em RLS;
- produção conecta pelo **Session pooler (5432)** do Supabase, com `sslmode=require` e `search_path=hacklab`;
- o Render Free dorme após 15 min sem tráfego (cold start de ~1 min). Antes de momentos críticos, chamar o endpoint de readiness. O Free não está aprovado para o pico do dia 3 sem teste de carga;
- trocar de hospedagem (ex.: servidor do Senac) não exige mudar código: mesma imagem Docker, `pg_dump`/`pg_restore`, novo `VITE_API_URL`.

Detalhes, riscos e pendências (incluindo o cookie de sessão entre domínios `onrender.com`) em `docs/infraestrutura.md`.

### 5.7 O que a Even3 decide sozinha e o que não

A Even3 é fonte de evento externo, entradas/categorias, inscrições e presença. Pela categoria mapeada (`internal_category` da entrada, nunca o texto do nome), só de inscrições `CONFIRMED` e sem `identity_mismatch`, contadas por Person:

```text
PARTICIPANT     → Participant automático ao sincronizar
JUROR           → Juror automático ao sincronizar (nunca User nem perfil: conta só pelo primeiro acesso)
PUBLIC          → pode fazer o primeiro acesso como Votante (elegibilidade continua explícita na votação)
COMPANY_GUEST   → candidato: o Administrador vincula a uma empresa (a Even3 não informa a empresa)
ORGANIZATION    → nada: a organização é interna (contas criadas pelo Administrador)
```

O HackLab continua autoridade sobre `User`, perfil, setor, `Company`, `CompanyRepresentative` e `VotingEligibility`. Nenhuma categoria externa cria conta, concede perfil ou cria empresa.

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
- rodam em PostgreSQL, no banco exclusivo `hacklab_test` do Docker (configurado em `back/.env.testing` e `phpunit.xml`). Não usar SQLite;
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
- Não transformar categoria de ingresso em permissão interna automaticamente sem regra explícita. Dados externos podem gerar candidatos de reconciliação (§5.7), mas não concedem autoridade.
- Não conceder perfil/conta Jurado (`User`) porque alguém possui uma entrada externa `JUROR`: o `Juror` é criado ao sincronizar, a conta só pelo primeiro acesso.
- Não criar empresa nem representante automaticamente apenas porque alguém tem entrada `COMPANY_GUEST` ou informou nome de empresa na plataforma externa.
- Não criar User nem conceder Gestor/Editor/Consultor por entrada `ORGANIZATION`: organização é interna. A liberação de acesso (`AccountAccessGrant`) existe no backend, mas não é usada pela interface; nunca `ADMINISTRATOR` por essa via.
- Não mover nem apagar `EventDay` para encaixar datas vindas da Even3.
- Não colocar botão de sincronizar a Even3 em páginas de domínio (§5.5).

## 12. Regra para mudanças de arquitetura

Se uma decisão deste arquivo mudar:
1. atualizar documentação primeiro;
2. registrar impacto;
3. só então alterar código.

A documentação é parte do contrato do projeto.
