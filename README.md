# HackLab

Sistema interno de gestão de Hackathons do Senac: organização, operação durante o evento e encerramento (avaliação dos jurados, votação do público e resultado oficial).

```text
Vue 3 SPA (front/)
    ↓  HTTP + cookie de sessão (Sanctum)
Laravel REST API /api/v1 (back/)
    ↓
PostgreSQL 17 (schema hacklab)
```

| Ambiente | Frontend | API | Banco |
|---|---|---|---|
| Desenvolvimento | Vue/Vite em `http://localhost:5174` | Laravel em Docker, `http://localhost:8000` | PostgreSQL 17 em Docker, `127.0.0.1:5442` |
| Produção (planejada) | Static Site | Laravel no Render (Web Service) | PostgreSQL gerenciado no Supabase |

A implantação em produção é a **Fase 12** e ainda não foi feita.

As inscrições e o credenciamento acontecem na plataforma externa **Even3**. O HackLab importa inscrições e presença pelo backend e cuida da gestão interna. Não vende ingresso, não gera QR e não faz check-in próprio.

## Estado do projeto

**Fases 0 a 11 concluídas. Fase 12 (implantação) e Fase 13 (teste de carga) pendentes.**

| Fase | Conteúdo | Situação |
|---|---|---|
| 0 | Fundação: Docker, PostgreSQL, Laravel, Sanctum | concluída |
| 1 | Pessoas, usuários, perfis e autenticação | concluída |
| 2 | Eventos, dias, setores e reuniões | concluída |
| 3 | Participantes, turmas e equipes | concluída |
| 4 | Empresas, representantes e desafios | concluída |
| 5 | Pendências, ocorrências e operação intersetorial | concluída |
| 6 | Jurados, critérios e avaliações | concluída |
| 7 | Votação, resultados e publicação oficial | concluída |
| 8 | Auditoria completa | concluída |
| 9 | Integração Even3 | concluída |
| 10 | Frontend integrado ao backend real | concluída |
| 11 | Relatórios reais e PDF | concluída |
| 11.1 | Primeiro acesso, recuperação de senha e autocadastro seguro de Votantes | concluído |
| 12 | Implantação (Render + Supabase + Static Site) | pendente |
| 13 | Teste de carga | pendente |

O detalhe de cada fase está em [`plano-implementacao.md`](hacklab_docs/docs/plano-implementacao.md).

## Funcionalidades

- **Autenticação e autorização**: login por sessão (Sanctum SPA); permissões por perfil, verificadas no Laravel.
- **Pessoas e usuários**: uma pessoa pode existir sem login; toda conta é ligada a uma pessoa. Contas administrativas são criadas pelo Administrador; Votantes elegíveis e Jurados internos ativam o próprio acesso por e-mail (primeiro acesso).
- **Eventos e dias**: evento com datas, local, fuso horário e dias reais (`EventDay`).
- **Setores e reuniões**: reuniões gerais ou setoriais.
- **Participantes e turmas**: status Disponível, Indisponível ou Desistente; não há exclusão.
- **Equipes**: composição com histórico de entrada e saída de membros.
- **Empresas e representantes**.
- **Desafios e distribuição**: uma equipe por desafio.
- **Pendências e ocorrências**:
  - demandas entre setores, com comentários, encaminhamento, conclusão ou resolução, reabertura e histórico;
  - geração de pendência a partir de uma ocorrência.
- **Jurados e atribuições**: as equipes de cada jurado são atribuídas explicitamente, nunca de forma automática.
- **Critérios e avaliações técnicas**:
  - o critério trava depois que começam as avaliações;
  - o jurado salva rascunho e envia; a revisão é solicitada pela gestão.
- **Votação pública autenticada**: sessões com equipes candidatas e eleitores elegíveis.
- **Resultados**:
  - resultado técnico e Escolha do Público, sempre separados;
  - publicação oficial versionada, que pode ser revogada.
- **Inscrições Even3 e presença**:
  - configuração da integração, entradas e mapeamento de categorias;
  - sincronização com histórico de execuções e webhook;
  - inscrições e criação explícita de participantes a partir delas;
  - presença (check-ins) sincronizada.
- **Auditoria**: trilha somente leitura das ações relevantes, gerada no backend.
- **Relatórios**: quatro relatórios consolidados com PDF.

O que o HackLab **não** tem nesta versão:
- financeiro, premiações, documentos e atas separadas;
- cadastro público (ninguém escolhe o próprio perfil) e convite de jurado;
- QR, scanner ou check-in próprio (o credenciamento é feito na Even3);
- votação anônima e nota combinada entre jurados e público;
- Supabase Auth ou qualquer recurso do Supabase além do PostgreSQL.

## Perfis

Seis perfis: **Administrador**, **Gestor**, **Editor**, **Consultor**, **Jurado** e **Votante**.

- Cada perfil tem um conjunto de permissões. As Policies/Gates do Laravel combinam permissão e escopo.
- **Gestor e Editor** só atuam no próprio setor. O Gestor gerencia e encaminha; o Editor opera, mas não encaminha.
- O **Consultor** consulta de forma transversal (inclusive relatórios), sem administrar usuários.
- O **Jurado** vê só as equipes atribuídas a ele.
- O **Votante** vota quando é elegível e vê o resultado oficial publicado.
- Esconder um botão no frontend não é segurança: toda requisição é autorizada no Laravel. A matriz completa de permissões está em [`banco-de-dados.md`](hacklab_docs/docs/banco-de-dados.md).

## Autenticação

Laravel Sanctum no modo SPA:

```text
GET  /sanctum/csrf-cookie     cookie XSRF-TOKEN
POST /api/v1/auth/login       cria a sessão (cookie HttpOnly)
GET  /api/v1/auth/me          usuário, pessoa, perfil, setor e permissões
POST /api/v1/auth/logout
```

- A sessão fica num cookie HttpOnly, protegido por CSRF.
- Não há bearer token guardado no navegador.
- Não há login simulado nem troca de perfil para demonstração.
- O login tem limite de tentativas.

### Primeiro acesso e recuperação de senha

```text
POST /api/v1/auth/activation/request    primeiro acesso: pede o link por e-mail
POST /api/v1/auth/activation/complete   token do link + senha → cria a conta
POST /api/v1/auth/password/forgot       pede o link de redefinição
POST /api/v1/auth/password/reset        token do link + nova senha
```

- **Primeiro acesso** (`#/primeiro-acesso`): a pessoa informa o mesmo e-mail da inscrição. Se o acesso estiver habilitado, recebe um link; ao abrir e criar a senha, a conta nasce `ACTIVE` e com e-mail verificado.
  - Vira **Jurado** quem tem cadastro interno de jurado ativo; vira **Votante** quem tem inscrição confirmada numa entrada mapeada como Público.
  - A sincronização da Even3 nunca cria contas, e conta existente nunca muda de perfil.
  - Ser Votante não dá direito a votar: a elegibilidade em cada sessão continua explícita. Antes de abrir a sessão, o Administrador usa **"Adicionar votantes ativados"** na gestão da votação para incluir de uma vez as contas já ativadas pelos inscritos do público.
  - Os Votantes precisam ativar o acesso **antes da abertura**: depois de aberta, a lista de elegíveis fica congelada.
- **Esqueci minha senha** (`#/esqueci-senha`): link por e-mail; ao redefinir, as sessões abertas da conta são encerradas.
- Mesmo conhecer um e-mail cadastrado não concede acesso: o link enviado ao próprio endereço prova a posse da caixa postal. A Even3 fornece identidade e inscrição; o HackLab decide a autoridade.
- As respostas dos pedidos são sempre iguais (não revelam se o e-mail existe), com limite de tentativas. Links valem 60 minutos e são de uso único; só o hash do token fica no banco.
- Os links usam `FRONTEND_URL`. Em desenvolvimento o e-mail vai para o log (`MAIL_MAILER=log`, em `back/storage/logs`); em produção, SMTP configurado nas variáveis (Fase 12).
- `php artisan hacklab:prune-auth-tokens` remove tokens vencidos.

## Frontend (`front/`)

- Vue 3, Vite e JavaScript, com roteamento por hash (`#/rota`).
- Um único cliente HTTP (`src/js/api/client.js`): `fetch` com `credentials: 'include'`, XSRF, tempo limite e tratamento de 401, 403, 419, 422 e 429.
- As páginas chamam os services de `src/js/services/`, nunca o `fetch` diretamente.
- A URL da API vem de `VITE_API_URL` (`front/.env.development` aponta para `http://localhost:8000`).
- Todo dado vem da API real. Com a API fora do ar, a tela mostra erro de conexão.
- O navegador guarda só preferências:
  - `hacklab.ui`: acessibilidade e evento ativo;
  - `hacklab.remember` (opcional): só o e-mail do login.

  Nenhum dado de negócio, sessão, senha ou token fica no navegador.
- Testes com Vitest, Vue Test Utils e jsdom.
- PDF com pdfmake, baixado só quando um relatório é gerado.

## Backend (`back/`)

- Laravel 12 (PHP 8.3 no Docker de desenvolvimento) e API REST em `/api/v1`.
- Organização:
  - Form Requests para validação;
  - API Resources para as respostas;
  - Services do domínio (`app/Domain/*`) para as regras de negócio;
  - Policies/Gates para autorização;
  - Controllers finos.
- Banco: migrations, factories e seeders.
- Testes Feature no PostgreSQL.
- Toda operação que altera várias tabelas roda em transação, e as ações sensíveis geram auditoria.

O contrato completo da API está em [`back/routes/api.php`](back/routes/api.php), e o resumo por fase em [`plano-implementacao.md`](hacklab_docs/docs/plano-implementacao.md).

## Banco de dados

- PostgreSQL 17, com todas as tabelas no schema `hacklab` (nunca `public`).
- Bancos: `hacklab_dev` (desenvolvimento) e `hacklab_test` (testes automatizados, recriado a cada execução).
- Porta na máquina local: `127.0.0.1:5442`, usuário e senha `hacklab`/`hacklab` (só desenvolvimento).
- A estrutura vem só das migrations do Laravel (32 hoje). Não crie tabelas à mão nem pelo painel do Supabase.
- No ambiente de testes, o backend recusa qualquer banco cujo nome não termine em `_test` e qualquer host do Supabase.

## Desenvolvimento local

Pré-requisitos:
- Docker Desktop (Windows/macOS) ou Docker Engine com Compose (Linux);
- Node.js 20 ou mais recente, com npm.

PHP, Composer e PostgreSQL rodam nos containers.

`node_modules`, `vendor` e os arquivos `.env` não vão para o Git.

### Primeira instalação

Na raiz do projeto:

```bash
cp back/.env.example back/.env
cp back/.env.testing.example back/.env.testing
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan key:generate --env=testing
docker compose exec app php artisan hacklab:db-check
docker compose exec app php artisan migrate --seed
```

No PowerShell, troque `cp` por `Copy-Item`.

### Dia a dia

```bash
docker compose up -d        # sobe a API e o PostgreSQL
docker compose ps
```

```bash
cd front
npm ci
npm run dev
```

| | URL |
|---|---|
| Frontend | http://localhost:5174 |
| API | http://localhost:8000 |
| Health check | http://localhost:8000/api/v1/health |

Comandos úteis:

```bash
docker compose logs -f app            # logs do Laravel
docker compose exec app php artisan … # qualquer comando artisan
docker compose down                   # para os containers (os dados ficam no volume)
docker compose down -v                # para e APAGA o volume do PostgreSQL
```

O servidor PHP de desenvolvimento atende uma requisição por vez e é lento no Docker do Windows (alguns segundos por requisição). Isso afeta só o desenvolvimento; desempenho é assunto das Fases 12 e 13.

**Rede que bloqueia o build** (proxy ou firewall barrando o `apt` dentro do Docker): faça o primeiro `docker compose up -d --build` em outra rede. Depois as camadas ficam em cache. Outra opção é subir só o banco (`docker compose up -d postgres`) e rodar o Laravel no PHP da máquina (8.2+ com `pdo_pgsql`), dentro de `back/`.

### Contas de desenvolvimento

O seed cria essas contas **apenas nos ambientes `local` e `testing`, nunca em produção**. Todas usam a senha `hacklab123`:

| Conta | Perfil |
|---|---|
| `admin@hacklab.local` | Administrador (e-mail e senha trocáveis por `DEV_ADMIN_EMAIL` e `DEV_ADMIN_PASSWORD`) |
| `gestor@hacklab.local` | Gestor do "Setor Exemplo A" |
| `editor@hacklab.local` | Editor do "Setor Exemplo B" |
| `jurado@hacklab.local` | Jurado com duas equipes atribuídas (uma avaliação enviada e um rascunho) |
| `votante01@hacklab.local` … `votante05@hacklab.local` | Votantes elegíveis nas sessões de exemplo |

O seed também cria um evento fictício com:
- 3 dias, setores, uma reunião, turmas e 12 participantes;
- 2 equipes, 3 empresas com representantes e 5 desafios;
- pendências, ocorrências, critérios e jurados;
- uma votação encerrada e outra em rascunho;
- nenhum resultado publicado.

O seed pode ser executado mais de uma vez sem duplicar dados.

`migrate`, `migrate --seed` e `db:seed` são seguros no `hacklab_dev`. Os comandos que apagam o banco (`migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`) ficam **bloqueados** em qualquer banco que não termine em `_test`: eles apagariam a configuração da integração Even3 e os dados sincronizados (inscrições, entradas, presença), que o seed não recria. Para recriar o banco de dev de propósito, libere só aquele comando:

```bash
docker compose exec -e HACKLAB_ALLOW_DESTRUCTIVE_DB=true app php artisan migrate:fresh --seed
```

Depois disso, a integração Even3 precisa ser configurada e sincronizada de novo pela tela Inscrições e Presença. Não coloque `HACKLAB_ALLOW_DESTRUCTIVE_DB` no `.env`.

Em produção, o primeiro Administrador é criado pelo terminal:

```bash
php artisan db:seed --class=RolePermissionSeeder --force
php artisan hacklab:create-admin   # pede nome, e-mail e senha
```

## Testes

```bash
# Backend (PostgreSQL, banco hacklab_test)
docker compose exec app php artisan test
docker compose exec app vendor/bin/pint --test

# Frontend
cd front
npm run test
npm run build
```

Na validação do Complemento 11.1 eram 549 testes no backend e 93 no frontend, todos passando.

## Integração Even3

```text
Even3 (API V2 e webhook) → Laravel (Even3Provider / sincronização) → PostgreSQL → Vue
```

- O Vue nunca chama a Even3. O token (`EVEN3_API_TOKEN`, um por deployment) e o segredo do webhook ficam só nas variáveis do backend.
- A tela **Inscrições e Presença** reúne:
  - status da integração e entradas;
  - mapeamento de categorias;
  - sincronização com histórico de execuções;
  - inscrições e criação explícita de participantes a partir delas;
  - presença.
- Sincronizar é repetível e não duplica dados. O webhook (`POST /api/v1/webhooks/even3`) é idempotente.
- Mapear uma entrada não concede perfil e não cria jurado, empresa ou usuário.
- Comandos: `php artisan even3:test-connection` e `php artisan even3:sync`.
- O teste do webhook público real fica para a Fase 12.

Detalhes em [`integracao-even3.md`](hacklab_docs/docs/integracao-even3.md).

## Votação e resultados

- **Avaliação técnica** (jurados) e **Escolha do Público** (votação) são classificações independentes. Não existe nota combinada.
- Voto:
  - um voto por usuário em cada sessão, e ele é definitivo;
  - repetir o mesmo voto devolve sucesso; tentar trocar é recusado.
- Nenhuma tela, endpoint ou relatório mostra em qual equipe cada pessoa votou. A contagem por equipe só existe com a sessão encerrada.
- O **resultado oficial** é um snapshot imutável e versionado. Corrigir significa revogar (com motivo) e publicar a próxima versão.

## Auditoria

- Gerada no backend e somente acrescentada (append-only). Só o Administrador consulta.
- Cada registro guarda:
  - ação, módulo, evento e entidade;
  - estado antes e depois, com as mudanças;
  - retrato de quem agiu (nome, perfil e setor naquele momento);
  - IP e User-Agent.
- Nunca registra senhas, tokens, segredos, o voto individual ou notas e comentários de jurados.

## Relatórios

| Relatório | Endpoint |
|---|---|
| Relatório Geral do Hackathon | `GET /api/v1/events/{event}/reports/general` |
| Participantes e Presença | `GET /api/v1/events/{event}/reports/participants-presence` |
| Gestão e Operação | `GET /api/v1/events/{event}/reports/operations` |
| Encerramento e Resultados | `GET /api/v1/events/{event}/reports/closing-results` |

- Acesso para Administrador e Consultor (permissão `reports.view`).
- Fluxo: PostgreSQL → endpoint agregado no Laravel (um por relatório) → Vue → PDF.
- O PDF é montado no navegador a partir do payload da API, com pdfmake carregado só na hora de gerar. Nada é guardado.
- Presença aparece como "Presente" ou "Sem registro"; a falta de check-in não é tratada como ausência.
- O resultado oficial vem do snapshot publicado.
- Não há relatório financeiro porque o HackLab não tem domínio financeiro.

## Produção (planejada — Fase 12)

- Frontend: Static Site (`npm run build`, com `VITE_API_URL` apontando para a API).
- API: Laravel no Render (Web Service, Docker).
- Banco: Supabase, só como PostgreSQL gerenciado (Session pooler, SSL obrigatório, `search_path=hacklab`).

A Fase 12 fará o deploy (variáveis, cookies e Sanctum entre domínios, migrations, HTTPS e webhook real da Even3). A Fase 13 fará o teste de carga. Riscos e pendências estão em [`infraestrutura.md`](hacklab_docs/docs/infraestrutura.md).

## Documentação

| Documento | Conteúdo |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | Regras do projeto e decisões obrigatórias para quem trabalha no código |
| [`arquitetura.md`](hacklab_docs/docs/arquitetura.md) | Camadas, domínios, autorização, frontend, auditoria e relatórios |
| [`plano-implementacao.md`](hacklab_docs/docs/plano-implementacao.md) | Fases, endpoints de cada fase e pendências conhecidas |
| [`regras-de-negocio.md`](hacklab_docs/docs/regras-de-negocio.md) | Regras do domínio (equipes, demandas, avaliação, votação, presença, relatórios) |
| [`banco-de-dados.md`](hacklab_docs/docs/banco-de-dados.md) | Tabelas, restrições e matriz de permissões por perfil |
| [`infraestrutura.md`](hacklab_docs/docs/infraestrutura.md) | Ambientes, Docker, Render, Supabase e riscos de produção |
| [`integracao-even3.md`](hacklab_docs/docs/integracao-even3.md) | Integração Even3: API V2, sincronização, webhook e estado dos testes reais |
