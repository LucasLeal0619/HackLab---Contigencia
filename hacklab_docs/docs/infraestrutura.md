# Infraestrutura

Decisão vigente desde 09/10/2026. Substitui as decisões anteriores (Supabase sem Docker local; MySQL + Oracle VM).

## 1. Princípio

O sistema é sempre **Vue + Laravel + PostgreSQL**. Onde ele roda é escolha de hospedagem, não dependência do código.

| Papel | Desenvolvimento e testes | Produção (atual) |
|---|---|---|
| Frontend Vue | `npm run dev` local | Static Site (Render) |
| API Laravel | container Docker local | Render Web Service (Free, imagem Docker) |
| PostgreSQL | container Docker local (`hacklab_dev`, `hacklab_test`) | Supabase (PostgreSQL gerenciado) |

```text
DESENVOLVIMENTO                          PRODUÇÃO

Vue (npm run dev, localhost:5174)        Vue — Static Site
        ↓                                        ↓
Laravel (Docker, localhost:8000)         Laravel API — Render Web Service
        ↓                                        ↓
PostgreSQL (Docker)                      PostgreSQL — Supabase
├── hacklab_dev
└── hacklab_test                         Even3 → Laravel API → PostgreSQL
```

Função do Docker: **ambiente reproduzível de desenvolvimento e testes**. Ele não é a infraestrutura de produção atual. Se um dia o Senac pedir hospedagem própria, monta-se uma stack Docker de produção (Nginx + Laravel + PostgreSQL) sem mudar o código.

## 2. Desenvolvimento

```text
docker-compose.yml (raiz)
├── app       Laravel (servidor embutido do PHP) → http://localhost:8000
└── postgres  PostgreSQL 17 → hacklab_dev + hacklab_test
              porta publicada só em 127.0.0.1:5442

front/        Vue com Vite, fora do Docker → http://localhost:5174
```

- O código de `back/` é montado no container (`./back:/var/www/html`). Uma edição em PHP vale em até `PHP_OPCACHE_REVALIDATE_FREQ` segundos (padrão 30; ver abaixo).
- A API é publicada em `127.0.0.1:8000` **e** `[::1]:8000`. No Windows, `localhost` tenta IPv6 primeiro; publicada só em IPv4, cada conexão nova esperava ~2 s antes de cair para `127.0.0.1`. O front continua usando `VITE_API_URL=http://localhost:8000` (mesmo host do Vite, para cookie/CORS/Sanctum).
- Os dados ficam no volume `postgres-data`. `docker compose down -v` apaga tudo.
- O Laravel local nunca conecta no Supabase. A conexão Supabase existe só no ambiente do Render.

Passo a passo no [README da raiz](../../README.md).

### 2.0 Banco de desenvolvimento x banco de testes

- `hacklab_dev`: desenvolvimento. Guarda o que o seed não recria: a configuração da integração Even3 do evento (`event_integrations`) e os dados sincronizados (entradas, inscrições, presença, execuções de sync). `migrate:fresh` nele apaga tudo isso.
- `hacklab_test`: o único banco onde se apaga e recria schema. A suíte usa `APP_ENV=testing`, `DB_DATABASE=hacklab_test`, `DB_SEARCH_PATH=hacklab` (`phpunit.xml` + `.env.testing`) e o `DatabaseEnvironmentGuard` recusa qualquer outro banco.
- `DestructiveCommandGuard` (`back/app/Support/Database`): `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback` e `db:wipe` saem com erro em banco que não termine em `_test` (bloqueio nativo do Laravel, antes de abrir a conexão). `migrate`, `migrate --seed` e `db:seed` seguem normais. Liberação intencional, só naquele comando: `docker compose exec -e HACKLAB_ALLOW_DESTRUCTIVE_DB=true app php artisan migrate:fresh --seed`. Em production a liberação é ignorada.

### 2.0.1 Rede com inspeção HTTPS

Redes corporativas com inspeção TLS (ex.: FortiGate) reassinam os certificados com uma CA própria. Nelas, a chamada à Even3 falha com `SSL certificate problem: unable to get local issuer certificate` (no Windows: `SEC_E_UNTRUSTED_ROOT`) — no container e no host. Não é defeito da integração e **não se desliga a verificação TLS**. Alternativas: outra rede sem inspeção (ex.: roteador do celular), exceção de inspeção para `api.even3.com` pela TI, ou instalar a CA corporativa no container de dev, só com autorização.

### 2.1 Desempenho do ambiente de desenvolvimento

Diagnóstico de 13/10/2026 (Docker Desktop no Windows, código montado por 9p):

| Causa | Efeito medido | Ajuste (só desenvolvimento) |
|---|---|---|
| Porta publicada só em IPv4; `localhost` resolve `::1` primeiro | ~2–3 s por conexão a `localhost:8000` | `docker-compose.yml` publica também `[::1]:8000` |
| opcache revalidando ~574 arquivos (550 do vendor) a cada 2 s pela montagem lenta | ~2 s na primeira requisição depois de 2 s parado | `docker/php/dev.ini`: `opcache.revalidate_freq=${PHP_OPCACHE_REVALIDATE_FREQ}` (padrão 30) |
| Servidor embutido do PHP atende 1 requisição por vez | requisições paralelas de uma tela faziam fila | `PHP_CLI_SERVER_WORKERS` (padrão 4) |
| Todo GET levava preflight `OPTIONS` (cabeçalho `X-Requested-With`; `max_age` 0) | o dobro de requisições no servidor | GET só com `Accept`; `CORS_MAX_AGE` (padrão 7200) |
| Dashboard com ~11 contagens em sequência | ~3,4 s para montar o painel | `GET /events/{e}/dashboard` (1 requisição) |

Com `opcache.revalidate_freq=30`, uma alteração em PHP pode levar até 30 s para aparecer no navegador. Ainda esperado em dev: a cada `PHP_OPCACHE_REVALIDATE_FREQ` segundos uma requisição paga a revalidação (~2–5 s). Para eliminar isso seria preciso tirar o `vendor/` da montagem do Windows (volume do Docker), o que muda o fluxo do `composer`; não foi feito. Nada disso vale para produção (Render usa a própria imagem, sem montagem).

## 3. Produção

### 3.1 Supabase (banco)

Usado **somente como PostgreSQL gerenciado**. Não usar Supabase Auth, Supabase JS, Storage, Realtime, Edge Functions nem Data API pelo frontend. Laravel continua dono de autenticação, autorização, regras, migrations e integração Even3.

Conexão do Laravel em produção, conforme o [guia Laravel do Supabase](https://supabase.com/docs/guides/getting-started/quickstarts/laravel) consultado em 09/10/2026:

- **Session pooler (porta 5432)**, string copiada do botão **Connect** do projeto. É IPv4, então funciona no Render; a documentação do Render não confirma saída IPv6, e a conexão direta do Supabase é IPv6;
- **não** usar o Transaction pooler (6543): ele não suporta os prepared statements do PDO;
- `sslmode=require` (ou `verify-full` com o certificado CA do Supabase). O backend recusa iniciar em produção com sslmode inseguro;
- tabelas no schema `hacklab` (`search_path`), não em `public`, que o Supabase expõe pela Data API. O mesmo schema é usado no Docker local, para dev, teste e produção se comportarem igual.

SQL a rodar **uma vez** no SQL Editor do projeto Supabase de produção (cria só o schema, sem tabelas):

```sql
create schema if not exists hacklab;

-- O HackLab não usa a Data API: os papéis dela não recebem acesso.
revoke all on schema hacklab from anon, authenticated;
alter default privileges in schema hacklab revoke all on tables from anon, authenticated;
alter default privileges in schema hacklab revoke all on sequences from anon, authenticated;
alter default privileges in schema hacklab revoke all on functions from anon, authenticated;
```

Autorização fica só no Laravel (Policies/Gates). Não implementar regras de negócio em RLS.

### 3.2 Render (API Laravel)

Fatos da documentação do Render consultada em 09/10/2026 ([Free](https://render.com/docs/free), [Docker](https://render.com/docs/docker)):

- PHP não é runtime nativo do Render: a API sobe como **serviço Docker**, a partir de um alvo de produção do `back/Dockerfile` (a criar na etapa de deploy);
- o Free Web Service **dorme após 15 minutos sem tráfego** e leva **cerca de 1 minuto** para acordar;
- 750 horas grátis por mês por workspace. Sem disco persistente, sem shell/SSH, sem one-off jobs, uma instância só. Arquivos locais somem a cada deploy, restart ou sleep;
- variáveis de ambiente são configuradas no painel e também chegam ao build como build args, então segredos não podem ser usados no Dockerfile.

Consequências para o HackLab:
- uploads (documentos) não podem ficar no disco do container. Quando o módulo de documentos existir, decidir o armazenamento externo por interface S3-compatível, sem SDK proprietário;
- sem shell: `php artisan migrate --force` roda no start do container (ou no pre-deploy, se o plano permitir);
- sessão, cache e fila ficam no PostgreSQL (drivers `database`), não em arquivo;
- **não** criar mecanismo artificial para manter o serviço acordado. Antes de momentos críticos, chamar o endpoint de readiness (seção 5).

### 3.2.1 E-mail (primeiro acesso e recuperação de senha)

O backend envia e-mails por Laravel Mail/Notifications, sem SDK de provedor:

- desenvolvimento: `MAIL_MAILER=log` (o e-mail, com o link, vai para `storage/logs`; só em desenvolvimento);
- produção (Fase 12): SMTP configurado só nas variáveis do Render, nunca no Git:

```text
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_SCHEME=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=HackLab
FRONTEND_URL=https://…        (base dos links de ativação e redefinição)
```

- a escolha do provedor SMTP fica para a Fase 12;
- `php artisan hacklab:prune-auth-tokens` limpa tokens vencidos; em produção, rodar periodicamente (ou no start do container) — definir na Fase 12.

### 3.3 Frontend (Static Site)

- Em produção: `npm run build`, e o `dist/` publicado como Static Site (Render Static Site é grátis);
- a URL da API vem de variável de ambiente no build: `VITE_API_URL`. Nunca fixar domínio no código;
- rewrite `/* → /index.html` para as rotas do Vue.

### 3.4 ⚠️ Cookie de sessão entre front e API (pendente de PoC)

`onrender.com` está na [Public Suffix List](https://publicsuffix.org/list/public_suffix_list.dat). Para o navegador, `hacklab.onrender.com` (front) e `hacklab-api.onrender.com` (API) são **sites diferentes**. O cookie de sessão do Sanctum (SPA, HttpOnly) viraria cookie de terceiro, que Safari e Chrome restringem. Sem resolver isso, o login por cookie não funciona de forma confiável.

Opções, em ordem de preferência:

1. **Rewrite no Static Site:** regra `/api/*` e `/sanctum/*` → URL da API no Render. O navegador vê front e API na mesma origem: sem CORS, cookie primário. A documentação do Render diz que o destino de um rewrite pode ser "a full, publicly accessible URL", mas não detalha cookies, métodos e headers. **Precisa de PoC** (login, POST com CSRF, Set-Cookie).
2. **Domínio próprio:** `app.<dominio>` (front) e `api.<dominio>` (API), mesmo site. Funciona com Sanctum SPA (`SESSION_DOMAIN=.<dominio>`). Exige um domínio.
3. Token Bearer em vez de cookie. Último recurso, porque contraria a decisão de cookie HttpOnly (`CLAUDE.md` §8).

Decisão: fazer a PoC da opção 1 no primeiro deploy, antes de ligar o login do front na API.

## 4. Readiness antes de momentos críticos

Endpoint previsto: `GET /api/v1/system/readiness`. Valida no mínimo:
- Laravel respondendo;
- conexão com o PostgreSQL;
- o banco aceita consulta.

Uso: chamar manualmente, ou por um agendamento pontual, alguns minutos antes da abertura de cada dia, da votação do terceiro dia e da apuração. Absorve o cold start de cerca de 1 minuto antes do público chegar. Não é ping contínuo.

## 5. Dia 3 e capacidade do Render Free

O Render Free é aceito para começar, mas **não está aprovado para o pico do terceiro dia** sem teste de carga.

Antes do evento, testar em degraus de **50, 100, 200 e 300 usuários concorrentes**, nos fluxos:
- login;
- consulta de equipes;
- votação (abrir a tela e gravar o voto);
- jurados (avaliações);
- resultados.

Critérios a medir: tempo de resposta (p95), taxa de erro, uso de memória da instância e conexões no pooler do Supabase.

Plano de contingência, se o Free não aguentar: aumentar temporariamente o plano/compute do serviço no Render, ou subir o mesmo Laravel em outro host. Banco (Supabase) e frontend não mudam.

## 6. Portabilidade (sair do Render/Supabase)

O código não usa nada proprietário do Render nem do Supabase. Para trocar de hospedagem:

1. Banco: `pg_dump` do Supabase (schema `hacklab`) e `pg_restore` no PostgreSQL novo.
2. API: a mesma imagem Docker em outro host, com as variáveis de ambiente.
3. Front: o mesmo `dist/` em qualquer servidor estático, com `VITE_API_URL` do novo endereço.
4. Validar `/api/v1/health` e o login, depois trocar o DNS.

## 7. Backup

- O Supabase faz backups conforme o plano do projeto (verificar retenção no plano usado).
- Além disso, `pg_dump` periódico do schema `hacklab` para um armazenamento **fora** do Supabase. O backup não pode depender da mesma conta.
- Teste de restauração no `hacklab_dev` local antes do evento.

## 8. Histórico de decisões

| Data | Decisão | Situação |
|---|---|---|
| 08/10/2026 | PostgreSQL só no Supabase, sem Docker local | substituída |
| 08/10/2026 | MySQL 8.4 + Docker, produção numa VM Oracle Cloud (Always Free) | substituída. Oracle fica como alternativa futura de host para uma stack Docker de produção |
| 09/10/2026 | PostgreSQL em todos os ambientes; Docker para dev/testes; Render + Supabase em produção | **vigente** |
