# Infraestrutura

Decisão vigente desde 09/10/2026. Substitui as decisões anteriores (Supabase sem Docker local; MySQL + Oracle VM).

**O banco de produção é PostgreSQL**, gerenciado no Supabase (schema `hacklab`). O HackLab não usa MySQL em nenhum ambiente: o MySQL aparece neste documento só como decisão antiga, substituída em 09/10/2026 (§8).

## 1. Princípio

O sistema é sempre **Vue + Laravel + PostgreSQL**. Onde ele roda é escolha de hospedagem, não dependência do código.

| Papel | Desenvolvimento e testes | Produção (atual) |
|---|---|---|
| Frontend Vue | `npm run dev` local | Netlify (site estático) |
| API Laravel | container Docker local | Render Web Service (Free, imagem Docker) |
| PostgreSQL | container Docker local (`hacklab_dev`, `hacklab_test`) | Supabase (PostgreSQL gerenciado) |

```text
DESENVOLVIMENTO                          PRODUÇÃO

Vue (npm run dev, localhost:5174)        Vue — Netlify
        ↓                                        ↓
Laravel (Docker, localhost:8000)         Laravel API — Render Web Service
        ↓                                        ↓
PostgreSQL (Docker)                      PostgreSQL — Supabase
├── hacklab_dev
└── hacklab_test                         Even3 → Laravel API → PostgreSQL
```

Função do Docker: **ambiente reproduzível de desenvolvimento e testes**. Ele não é a infraestrutura de produção atual. A produção usa o alvo `prod` do mesmo `back/Dockerfile` (§3.2.1). Se um dia o Senac pedir hospedagem própria, essa mesma imagem roda ao lado de um PostgreSQL, sem mudar o código.

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

- PHP não é runtime nativo do Render: a API sobe como **serviço Docker**, a partir do alvo `prod` do `back/Dockerfile`;
- o Free Web Service **dorme após 15 minutos sem tráfego** e leva **cerca de 1 minuto** para acordar;
- 750 horas grátis por mês por workspace. Sem disco persistente, sem shell/SSH, sem one-off jobs, uma instância só. Arquivos locais somem a cada deploy, restart ou sleep;
- variáveis de ambiente são configuradas no painel e também chegam ao build como build args, então segredos não podem ser usados no Dockerfile.

Fatos conferidos em 20/10/2026 ([Deploys](https://render.com/docs/deploys), [Cron Jobs](https://render.com/docs/cronjobs), [Docker](https://render.com/docs/docker)):

- **Pre-Deploy Command** só existe em serviços pagos. Roda depois do build e antes do deploy, numa instância separada;
- no deploy e no desligamento, o Render manda **SIGTERM** e espera 30 s (padrão) antes do SIGKILL;
- **Cron Job** custa no mínimo US$ 1/mês, usa cron em UTC e nunca roda duas execuções ao mesmo tempo (a seguinte espera);
- a documentação não mostra como escolher o target de um Dockerfile multi-stage. Por isso `prod` é o **último estágio**: é o que o build gera sem `--target`.

**Uso do Render Free (decisão de 20/10/2026):**
- o Free serve para colocar o HackLab no ar e validar o deploy (smoke), **não** para o dia do Hackathon. No evento, o mínimo é o plano pago básico; o plano final é decidido depois da Fase 13 (carga);
- `HACKLAB_MIGRATE_ON_START=true` **só** no primeiro deploy, ou quando houver migration nova. Depois de confirmar as migrations e os perfis, voltar para `false` e redeployar, para os cold starts não rodarem release à toa. No plano pago, o release vai para o Pre-Deploy Command;
- o Free bloqueia a saída nas portas **25, 465 e 587**. Não presumir SMTP nessas portas: usar um provedor com porta alternativa (ex.: 2525) ou, depois, um provedor de e-mail por HTTPS (§3.2.7);
- o Cron Job não é necessário para o primeiro deploy (§3.2.5).

Consequências para o HackLab:
- uploads (documentos) não podem ficar no disco do container. Quando o módulo de documentos existir, decidir o armazenamento externo por interface S3-compatível, sem SDK proprietário;
- sem shell no Free: migrations rodam no Pre-Deploy (pago) ou no start do container (Free), ver §3.2.4;
- sessão e cache ficam no PostgreSQL (drivers `database`), não em arquivo;
- **não** criar mecanismo artificial para manter o serviço acordado. Antes de momentos críticos, chamar o health check (seção 4).

### 3.2.1 Desenvolvimento ≠ produção: alvos do `back/Dockerfile`

| | `dev` | `prod` |
|---|---|---|
| Uso | `docker-compose.yml` (`target: dev`) | Render (último estágio; `docker build --target prod`) |
| Código | montado por volume (`./back`) | copiado para a imagem (`/app`) |
| Dependências | `composer install` local, com dev | `composer install --no-dev --prefer-dist --optimize-autoloader` no build |
| Servidor HTTP | `php -S` (embutido, só dev) | **FrankenPHP 1.13.1** (Caddy com PHP 8.3 embutido) |
| Porta | 8000 | `$PORT` do Render (padrão da imagem: 8080) |
| opcache | revalida a cada 30 s | não revalida (`validate_timestamps=0`) |
| Logs | arquivo em `storage/logs` | `stderr` (`LOG_CHANNEL=stderr`) |

**Por que FrankenPHP:** um processo só, com servidor HTTP e PHP juntos. Não precisa de php-fpm, nginx nem supervisor. Escuta em todas as interfaces na porta `PORT` (`docker/prod/Caddyfile`, `:{$PORT:8080}`). No SIGTERM, termina os requests em andamento e sai com código 0 (medido: 1,5 s). Roda no **modo clássico**, sem worker/Octane: cada request começa limpo, como no PHP tradicional. `FRANKENPHP_NUM_THREADS` (padrão 4) limita os requests PHP simultâneos e, com isso, a memória.

**Imagem:**
- roda como `www-data`; só `storage/` e `bootstrap/cache/` são graváveis;
- `.env`, `vendor` local, `tests`, logs e caches locais ficam fora (`back/.dockerignore`). O `bootstrap/cache/*.php` de dev nunca entra;
- nenhum segredo e nenhum `ARG`. Os padrões não secretos (`APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=stderr`, `LOG_LEVEL=info`) estão no `ENV` e o Render pode sobrescrevê-los;
- o tamanho da imagem é de cerca de 915 MB, quase todo vindo da imagem base.

**Caches do Laravel no start, não no build.** As variáveis do Render só existem de verdade em runtime. Por isso o `docker/prod/entrypoint.sh` roda `php artisan optimize` (config, rotas, eventos e views) a cada start, antes do servidor, e não precisa de banco. Um `config:cache` no build congelaria a configuração sem as variáveis.

**Proxy do Render:** o TLS termina na borda do Render e o Laravel recebe HTTP de um proxy local (`::1`). O IP real do cliente e a proteção contra host header injection estão em §3.2.8.

### 3.2.2 Web Service no Render (configuração manual no painel)

| Campo | Valor |
|---|---|
| Tipo | Web Service |
| Runtime | Docker |
| Branch | `main` |
| Root Directory | `back` |
| Dockerfile Path | `./Dockerfile` (relativo ao Root Directory) |
| Docker build context | `.` (o próprio `back/`) |
| Target | `prod` (último estágio, gerado por padrão) |
| Docker Command | vazio (usa o `ENTRYPOINT` + `CMD` da imagem) |
| Health Check Path | `/api/v1/health` |
| Pre-Deploy Command | `/app/docker/prod/release.sh` (só em plano pago; no Free, ver §3.2.4) |

**Sem `render.yaml` por enquanto.** A primeira configuração é feita e conferida no painel. Um Blueprint pode ser criado depois, com os segredos marcados `sync: false` (nunca com valor no Git).

### 3.2.3 Variáveis de ambiente do Web Service

Segredos (🔒) só no painel do Render, nunca no Git. URLs reais são definidas no deploy.

| Variável | Valor esperado |
|---|---|
| `APP_KEY` 🔒 | gerar uma vez: `docker compose exec app php artisan key:generate --show` |
| `APP_URL` | URL pública da API |
| `FRONTEND_URL` | URL do front (CORS e links dos e-mails) |
| `CORS_ALLOWED_ORIGINS` | URL do front |
| `SANCTUM_STATEFUL_DOMAINS` | host do front (sem `https://`) |
| `SESSION_SAME_SITE` | `lax` (§3.4) |
| `SESSION_DOMAIN` | **ausente** (cookie host-only, §3.4) |
| `SESSION_SECURE_COOKIE` | `true` |
| `TRUSTED_PROXIES` | `127.0.0.1,::1` (proxy local do Render; nunca `*`, §3.2.8) |
| `HACKLAB_EDGE_CLIENT_IP_HEADER` | `CF-Connecting-IP` (§3.2.8) |
| `HACKLAB_NETLIFY_PROXY_SECRET` 🔒 | mesmo valor de `HACKLAB_PROXY_SIGNATURE_SECRET` no Netlify (§3.2.8) |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` | Session pooler do Supabase (porta 5432) |
| `DB_PASSWORD` 🔒 | senha do banco |
| `DB_SSLMODE` | `require` (ou `verify-full` + `DB_SSLROOTCERT`) |
| `DB_SEARCH_PATH` | `hacklab` |
| `QUEUE_CONNECTION` | `sync` (não há worker; ver §3.2.6) |
| `MAIL_*` (`MAIL_PASSWORD` 🔒) | §3.2.7 |
| `EVEN3_ENABLED`, `EVEN3_BASE_URL`, `EVEN3_WEBHOOK_HEADER` | configuração da Even3 |
| `EVEN3_API_TOKEN` 🔒, `EVEN3_WEBHOOK_SECRET` 🔒 | segredos da Even3 |
| `HACKLAB_MIGRATE_ON_START` | Free: `true` só no primeiro deploy ou com migration nova, depois `false`. Pago: `false` (usa o Pre-Deploy) |
| `FRANKENPHP_NUM_THREADS` | opcional (padrão 4) |

Proteções que valem em produção:
- `DatabaseEnvironmentGuard`: com `sslmode` inseguro, a aplicação não inicia. Testado: o container sai com o erro no log;
- `DestructiveCommandGuard`: `migrate:fresh` e `db:wipe` saem com erro. Testado na imagem `prod`.

### 3.2.4 Migrations

O passo de release é o `docker/prod/release.sh`. Ele pode ser repetido sem efeito colateral:

```sh
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force   # perfis e permissões do código (idempotente)
```

- **Plano pago:** Pre-Deploy Command `/app/docker/prod/release.sh`. Se falhar, o deploy não acontece e a versão anterior continua no ar.
- **Free:** `HACKLAB_MIGRATE_ON_START=true` roda o mesmo script no start do container, antes de aceitar requests. Usar só no primeiro deploy (36 migrations + perfis) ou quando houver migration nova; depois de confirmar, voltar para `false` e redeployar.
- Nunca no `docker build`. Nunca `migrate:fresh`/`db:wipe`.
- O schema `hacklab` precisa existir antes (SQL em §3.1).
- **Primeiro Administrador:** `php artisan hacklab:create-admin` é interativo e precisa de um terminal. O Free não tem shell, então a forma de rodar fica para a Fase 12. Uma opção: a imagem `prod` local com as variáveis de produção num arquivo fora do Git (`docker run -it --rm --env-file … hacklab-back-prod php artisan hacklab:create-admin`).

### 3.2.5 Agendamentos (Scheduler)

O Web Service **não** roda o Scheduler do Laravel. O `Schedule::command('even3:sync')` em `routes/console.php` só vale onde houver `schedule:run`/`schedule:work`.

Solução mínima, quando houver orçamento: um **Render Cron Job**.

| Campo | Valor |
|---|---|
| Repositório / Root Directory / Dockerfile | os mesmos do Web Service (`back`, `./Dockerfile`) |
| Docker Command | `/bin/sh -c "php artisan even3:sync; php artisan hacklab:prune-auth-tokens"` |
| Schedule | `*/10 * * * *` (UTC) |
| Variáveis | as mesmas do Web Service (`APP_KEY`, `DB_*`, `EVEN3_*`), **sem** `HACKLAB_MIGRATE_ON_START` |

- Uma execução por vez é garantida pelo Render; o `IntegrationSyncService` também tem lock próprio.
- `even3:sync` sai com código 1 quando `EVEN3_ENABLED=false`.
- Os comandos funcionam com e sem o entrypoint (com ou sem `optimize`), porque a documentação do Render não diz se o Docker Command substitui o `ENTRYPOINT`.
- Não usar `schedule:run` a cada minuto: custa mais e não traz nada.

**Sem Cron Job (Free)**, a sincronização tem três caminhos:
1. o webhook da Even3;
2. o botão Sincronizar;
3. a sincronização silenciosa que o front dispara a cada 10 minutos enquanto um Administrador usa o sistema.

Nesse caso, a limpeza de tokens vencidos (`hacklab:prune-auth-tokens`) fica sem execução periódica. Isso não afeta a segurança: o token vencido é recusado de qualquer forma.

### 3.2.6 Fila (queue)

Nenhum fluxo atual usa fila: não existe `ShouldQueue` nem `dispatch`. Os e-mails de primeiro acesso e de redefinição saem na própria requisição. **Não há worker em produção.** `QUEUE_CONNECTION=sync` garante que algo enfileirado por engano seja executado em vez de ficar parado. Se um fluxo passar a depender de fila, isso entra antes como decisão de infraestrutura.

### 3.2.7 E-mail (primeiro acesso e recuperação de senha)

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

- a escolha do provedor SMTP fica para a Fase 12. No Render Free, as portas 25/465/587 estão bloqueadas: o provedor precisa oferecer porta alternativa (ex.: 2525) enquanto o serviço estiver no Free;
- `php artisan hacklab:prune-auth-tokens` limpa tokens vencidos; em produção, junto do Cron Job (§3.2.5).

### 3.2.8 IP real do cliente e host (HACKLAB-IP-001)

**Status: concluído e validado em produção em 21/10/2026.**
- Commits `478df95` (backend) e `fa4b03e` (proxy assinado do Netlify).
- Variáveis em produção:
  - Netlify: `HACKLAB_PROXY_SIGNATURE_SECRET` (Runtime);
  - Render: `HACKLAB_NETLIFY_PROXY_SECRET`, `HACKLAB_EDGE_CLIENT_IP_HEADER=CF-Connecting-IP` e `TRUSTED_PROXIES=127.0.0.1,::1`.

**Causa raiz.** O diagnóstico em produção de 20/10/2026 mediu a cadeia abaixo:

```text
cliente → borda Netlify → 100.64.x (rede interna Netlify) → saída AWS do Netlify (variável)
        → Cloudflare (borda do Render) → balanceador Render (10.25–27.x) → proxy local (::1) → Laravel
X-Forwarded-For recebido: "<forjado pelo cliente?>, <cliente>, 100.64.x,<saída Netlify>, <Cloudflare>, <balanceador>"
```

- Com `TRUSTED_PROXIES=*`, o Laravel confia só no `REMOTE_ADDR` (`::1`). O `ip()` virava o último salto, o balanceador `10.x`, e a Auditoria gravava `10.27.0.14`.
- Os rate limits por IP eram compartilhados por todos os usuários.
- O `*` também aceitava `X-Forwarded-Host`: no acesso direto ao Render, um cliente trocava o host que o Laravel enxerga (testado: `https://evil.example`).

**Cabeçalhos medidos:**

| Cabeçalho | Pelo Netlify | Direto no Render | Uso |
|---|---|---|---|
| `X-Forwarded-For` | cada salto acrescenta; valor forjado preservado | idem | **descartado** |
| `x-nf-client-connection-ip` | Netlify sobrescreve com o IP real | **passa forjado** | só com `x-nf-sign` válido |
| `CF-Connecting-IP` | IP de saída do Netlify | IP real; forjado → Cloudflare `error 1000` | quem conectou no Render |
| `True-Client-IP` / `X-Real-IP` | sobrescrito / removido | sobrescrito / removido | não usados |
| `X-Forwarded-Proto` | `https`; forjado é ignorado | idem | confiável |
| `X-Forwarded-Host` | removido pelo Netlify | **passa forjado** | **nunca confiável** |

**Arquitetura definitiva:**

1. **Proxy assinado no Netlify** (`front/netlify.toml`).
   - `/api/*` e `/sanctum/*` usam `signed = "HACKLAB_PROXY_SIGNATURE_SECRET"`, e o Netlify envia o `x-nf-sign` (JWS HS256) em cada requisição.
   - Não há `_redirects`: as regras dele vêm **antes** das do `netlify.toml`, e o `/*` da SPA capturaria `/api/*`.
2. **`ResolveClientIp`**, primeiro middleware global. Só age se o `REMOTE_ADDR` for o proxy local exato (`TRUSTED_PROXIES`, nunca `*`).
   - Descarta o `X-Forwarded-For` recebido e põe no lugar um único IP:
     1. `x-nf-sign` válido → `x-nf-client-connection-ip`;
     2. senão `CF-Connecting-IP` (`HACKLAB_EDGE_CLIENT_IP_HEADER`) → quem conectou no Render;
     3. senão nenhum: o `ip()` fica sendo o proxy local. Nunca um cabeçalho não autenticado e nunca o primeiro IP do `X-Forwarded-For`.
   - O IP precisa ser um IPv4 ou IPv6 único e válido (`FILTER_VALIDATE_IP`). Listas, portas e lixo são recusados.
3. **`ProxyConfiguration`** (no `AppServiceProvider`):
   - só `X-Forwarded-For` e `-Proto` são confiáveis;
   - em production, as URLs absolutas, inclusive as de paginação, vêm do `APP_URL`, nunca do `Host`.
4. O `$request->ip()` continua sendo a única forma de leitura. `AuditLogger` e rate limits não mudaram.

**Validação do `x-nf-sign`** (`App\Support\Http\NetlifyProxySignature`):
- 3 partes base64url, `alg` = `HS256`, `typ` ausente ou `JWT`, sem `crit`;
- HMAC-SHA256 com `HACKLAB_NETLIFY_PROXY_SECRET`, comparado com `hash_equals`;
- claims: `iss` = `netlify`, `exp` inteiro e não vencido (tolerância de 30 s), `deploy_context` = `production`, `site_url` = `FRONTEND_URL`.

**O que a assinatura garante, e o que não garante:**
- **Garante:** a requisição passou pelo proxy do **nosso** site no Netlify (o segredo só existe no Netlify e no Render).
- **Não garante:** os cabeçalhos HTTP. O JWS cobre só as próprias claims e não amarra a requisição nem o IP.
- **De onde vem a confiança no IP:** o Netlify sobrescreve `x-nf-client-connection-ip` nesse caminho (medido em produção). O JWS só trafega entre o Netlify e o Render, sob TLS; o cliente nunca o vê. Para reaproveitá-lo, um atacante precisaria capturar esse tráfego.
- **Prazo de validade:** o `exp` limita a janela de uso. O valor real do `exp` é conferido na validação de produção.

**Segredo:**
- valor aleatório (ex.: `openssl rand -hex 32`);
- no Netlify, em `HACKLAB_PROXY_SIGNATURE_SECRET`, com escopo **Runtime**;
- no Render, em `HACKLAB_NETLIFY_PROXY_SECRET`;
- nunca no Git, nem em variável `VITE_`, nem em log.

**Deploy coordenado** (executado em 20–21/10/2026, nesta ordem). Nenhuma combinação intermediária quebra o login, porque o pior caso é o comportamento anterior ou o IP de saída do Netlify:

1. **Netlify:**
   - conferir se o *Base directory* é `front` (onde está o `netlify.toml`);
   - criar `HACKLAB_PROXY_SIGNATURE_SECRET` (escopo Runtime).
   Ainda não muda nada.
2. **Render:** criar `HACKLAB_NETLIFY_PROXY_SECRET` (mesmo valor) e `HACKLAB_EDGE_CLIENT_IP_HEADER=CF-Connecting-IP`. **Manter `TRUSTED_PROXIES=*` por enquanto.** O código atual ignora as duas variáveis.
3. **Push do backend** (commit 1). Com `*`, a resolução nova fica **desligada** (comportamento atual de IP), mas a correção de host já vale. Validar o login.
4. **Render:** `TRUSTED_PROXIES=127.0.0.1,::1` e redeploy. Agora o acesso direto grava o IP real, e o caminho pelo Netlify grava o IP de saída do Netlify. Validar o login.
5. **Push do frontend** (commit 2: `netlify.toml` e remoção do `_redirects`). A partir daqui, o caminho pelo Netlify grava o IP real.
6. Validar (abaixo). A variável do diagnóstico temporário, que já saiu do código no commit `478df95`, também sai do Render.

**Rollback:**
- Frontend: `git revert` do commit 2, que traz de volta o `_redirects` sem assinatura. O login continua funcionando, e o IP volta a ser o de saída do Netlify.
- Backend sem deploy: voltar `TRUSTED_PROXIES=*` no Render, o que desliga a resolução nova. Se preciso, `git revert` do commit 1.
- As variáveis novas podem ficar: o código anterior as ignora.

**Validação em produção:**

| Teste | Esperado |
|---|---|
| A. Login pelo Netlify | sem 419 |
| B. `Ctrl + Shift + R` | a sessão continua |
| C. Auditoria do login | IP = o IP público do cliente (comparar com https://api.ipify.org) |
| D. Login com `X-Forwarded-For`/`x-nf-client-connection-ip` forjados (curl pelo Netlify e direto no Render) | a Auditoria não grava o valor forjado |
| E. DevTools | `/sanctum/csrf-cookie` e `/api/v1/...` em `hacklab-senac.netlify.app` |

**Resultados (20–21/10/2026):**
- **Automatizados:**
  - backend 616/616, frontend 130/130 e Pint PASS;
  - 17 testes de feature novos (`ClientIpResolutionTest`), pelo stack HTTP real:
    - assinatura válida, ausente, inválida (12 variações), vencida e com claims alteradas;
    - cabeçalhos forjados e IP inválido;
    - IPv4 e IPv6;
    - `X-Forwarded-Host` malicioso e URLs absolutas;
    - Auditoria de login e logout;
    - sessão por cookie;
    - rate limit por cliente real.
- **Passo 4** (`TRUSTED_PROXIES` exato, ainda sem assinatura): a Auditoria gravou o IP de saída do Netlify (`56.126.136.177`), como esperado.
- **Passo 5, verificação técnica:** três logins recusados com e-mails `ip-check-1-*@hacklab.invalid`. O IP público do cliente foi gravado nos três casos:
  - pelo Netlify sem nada forjado;
  - pelo Netlify com `X-Forwarded-For`, `x-nf-client-connection-ip`, `X-Real-IP` e `True-Client-IP` forjados;
  - direto no Render com o `x-nf` forjado, sem assinatura.
  - Respostas 422 (nunca 419), `csrf-cookie` 204, fallback da SPA 200.
  - Isso confirma que o JWS emitido pelo Netlify é aceito pela validação.
- **Passo 5, testes manuais:**
  - login sem 419;
  - sessão mantida após `Ctrl + Shift + R`;
  - navegação entre módulos;
  - API pelo domínio do Netlify;
  - rotas internas da SPA após atualizar a página;
  - IP da Auditoria igual ao do IPify, em **IPv4 e IPv6**.

**Limitações:**
- O valor exato do `exp` emitido pelo Netlify não foi observado: o cabeçalho é descartado pelo middleware e não é registrado.
- A confiança no `CF-Connecting-IP` depende de o Render manter o Cloudflare na frente e o proxy local em `::1`/`127.0.0.1`. Se o Render mudar a infraestrutura, repetir a medição.
- Sem assinatura válida, quem chega pelo Netlify aparece com o IP de saída do Netlify, compartilhado.
- Deploy previews do Netlify (`deploy_context` diferente de `production`) também aparecem assim.

### 3.3 Frontend (Netlify)

- Em produção o front fica no **Netlify**: `npm run build` e o `dist/` publicado como site estático. Decisão da Fase 12; o Render Static Site, previsto antes, ficou só como alternativa;
- a URL da API vem de variável de ambiente no build: `VITE_API_URL`. Nunca fixar domínio no código;
- rewrite `/* → /index.html` para as rotas do Vue.

### 3.4 Cookie de sessão entre front e API

`onrender.com` está na [Public Suffix List](https://publicsuffix.org/list/public_suffix_list.dat). O front no Netlify e a API no Render (`*.onrender.com`) são **sites diferentes** para o navegador. O cookie de sessão do Sanctum (SPA, HttpOnly) viraria cookie de terceiro, que Safari e Chrome restringem. Sem resolver isso, o login por cookie não funciona de forma confiável.

Opções, em ordem de preferência:

1. **Proxy no Netlify:** regra de rewrite com status 200 (proxy) de `/api/*` e `/sanctum/*` para a URL da API no Render. O navegador vê front e API na mesma origem: sem CORS e com cookie primário. **Precisa de PoC** (login, POST com CSRF, Set-Cookie, IP do cliente que chega ao Laravel).
2. **Domínio próprio:** `app.<dominio>` (front) e `api.<dominio>` (API), mesmo site. Funciona com Sanctum SPA (`SESSION_DOMAIN=.<dominio>`). Exige um domínio.
3. Token Bearer em vez de cookie. Último recurso, porque contraria a decisão de cookie HttpOnly (`CLAUDE.md` §8).

Decisão: opção 1. A PoC do primeiro deploy confirmou o problema: chamando o Render direto, o login respondia 419, porque o `XSRF-TOKEN` era do domínio do Render e o front no Netlify não conseguia lê-lo.

Implementação: `front/netlify.toml`, no *Base directory* do site. Antes era o `front/public/_redirects`, substituído no HACKLAB-IP-001 para assinar o proxy (§3.2.8):

```text
/api/*      → https://<api>.onrender.com/api/:splat      200, force, signed
/sanctum/*  → https://<api>.onrender.com/sanctum/:splat  200, force, signed
/*          → /index.html                                200
```

- Primeiro os proxies, depois o fallback da SPA. Nada de `_redirects`, cujas regras viriam antes.
- O domínio da API fica nesse arquivo de configuração do Netlify, nunca no JS.
- Em produção, `VITE_API_URL` fica **vazio/ausente**: o client chama `/sanctum/csrf-cookie` e `/api/v1/...` no próprio domínio do Netlify. Nunca usar `VITE_API_URL=/api`, que duplicaria o prefixo.
- No Render:
  - `FRONTEND_URL` e `CORS_ALLOWED_ORIGINS` = URL do Netlify;
  - `SANCTUM_STATEFUL_DOMAINS` = host do Netlify;
  - `SESSION_SECURE_COOKIE=true` e `SESSION_SAME_SITE=lax`;
  - **sem** `SESSION_DOMAIN` (cookie host-only).
- Não usar `SameSite=None` nem Bearer.

## 4. Readiness antes de momentos críticos

`GET /api/v1/health` (público, sem autenticação) já valida o mínimo:
- Laravel respondendo;
- conexão com o PostgreSQL;
- o banco aceita consulta (`select 1`).

Responde 200 (`status: ok`) ou 503 (`status: degraded`), sem expor host nem erro. É o Health Check Path do Render: com o banco fora, o Render vê a instância como não saudável. Um endpoint separado `/api/v1/system/readiness` não foi criado, porque faria a mesma coisa. `/up`, do Laravel, só diz que o PHP responde e não verifica o banco.

Uso: chamar manualmente, ou por um agendamento pontual, alguns minutos antes da abertura de cada dia, da votação do terceiro dia e da apuração. Absorve o cold start de cerca de 1 minuto antes do público chegar. Não é ping contínuo.

## 5. Dia 3 e capacidade do Render Free

O Render Free é aceito só para validar o deploy. No evento, o mínimo é o plano pago básico, e o plano final sai do teste de carga (Fase 13).

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
| 20/10/2026 | Frontend de produção no Netlify (o Render Static Site fica como alternativa); Render Free só para validação, plano do evento decidido após a Fase 13 | **vigente** |
| 20/10/2026 | HACKLAB-IP-001: proxy assinado do Netlify (`netlify.toml`), IP do cliente resolvido pelo `ResolveClientIp` (x-nf assinado → `CF-Connecting-IP` → nenhum), `TRUSTED_PROXIES` exato, `X-Forwarded-Host` nunca confiável. Validado em produção em 21/10/2026 | **vigente** |
| 20/10/2026 | Alvo `prod` com FrankenPHP (modo clássico), caches no start, release por Pre-Deploy ou `HACKLAB_MIGRATE_ON_START`, Cron Job opcional para o sync, sem worker de fila | **vigente** |
