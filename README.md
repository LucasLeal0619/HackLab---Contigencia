# HackLab

Sistema interno de gestão de Hackathons do Senac: organização, operação durante o evento e encerramento (avaliação dos jurados, votação do público e resultado oficial).

```text
Vue 3 SPA (front/)
    ↓  HTTP + cookie de sessão (Sanctum)
Laravel REST API /api/v1 (back/)
    ↓
PostgreSQL 17 na produção · MySQL 8 no desenvolvimento local
```

| Ambiente | Frontend | API | Banco |
|---|---|---|---|
| Desenvolvimento (branch `dev`) | Vue/Vite em `http://localhost:5174` | Laravel (`php artisan serve`), `http://localhost:8000` | MySQL Server 8 local, sem Docker |
| Produção | Netlify | Laravel no Render (Web Service) | PostgreSQL gerenciado no Supabase |

A produção está no ar desde 20/10/2026, no Render Free, que serve só para validação (**Fase 12**, em andamento). Pendências antes do evento: e-mail real, Even3 em produção e o plano do Render para o evento.

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
| 11.2 | Centro Even3 e Presença (simplificado antes do deploy) | em revisão |
| 12 | Implantação (Render + Supabase + Netlify) | em andamento (produção no ar; HACKLAB-IP-001 concluído; pendentes: e-mail real, Even3, plano do evento) |
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
- **Presença (Even3)**: uma tela com inscritos, presentes, sem registro e o que precisa de atenção; sincronização central e webhook; configuração da integração (entradas, histórico) num painel discreto.
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

- **Produção:** PostgreSQL 17 no Supabase, com todas as tabelas no schema `hacklab` (nunca `public`). É a referência: o CI testa nele.
- **Desenvolvimento local da equipe:** **MySQL Server 8** instalado no Windows, sem Docker. Cada integrante tem o próprio banco, no próprio computador.
- **Bancos locais:**
  - `hacklab_dev`: desenvolvimento;
  - `hacklab_test`: testes automatizados. Os testes apagam e recriam este banco; nunca tocam no `hacklab_dev`.
- **Estrutura:** vem só das migrations do Laravel. Não crie tabelas à mão.
- **Compatibilidade:** o mesmo código roda em PostgreSQL e MySQL. As regras de integridade (triggers, índices, CHECKs, travas) existem nos dois bancos.
- **Proteção:** no ambiente de testes, o backend recusa qualquer banco cujo nome não termine em `_test` e qualquer host do Supabase. Ninguém conecta no banco de produção a partir do próprio computador.

## Desenvolvimento local (Windows, MySQL 8, sem Docker)

Este é o guia oficial para a equipe do Senac. Siga na ordem. Todos os comandos são para o **PowerShell** do Windows.

```text
Navegador ──▶ Vue/Vite (http://localhost:5174) ──▶ Laravel (http://localhost:8000) ──▶ MySQL 8 (localhost:3306)
                                                                                         ├── hacklab_dev
                                                                                         └── hacklab_test
```

### 1. Pré-requisitos

| Programa | Versão | Onde baixar |
|---|---|---|
| Git | qualquer versão recente | https://git-scm.com/download/win |
| PHP | **8.2 ou maior** (recomendado 8.3) | https://windows.php.net/download (ou o PHP do XAMPP, ver abaixo) |
| Composer | **2.x** | https://getcomposer.org/download/ |
| Node.js | **22** (mínimo 20), com npm | https://nodejs.org (versão LTS) |
| MySQL Server | **8.0.19 ou maior** (recomendado 8.4 LTS) | https://dev.mysql.com/downloads/installer/ |
| Editor | VS Code ou equivalente | https://code.visualstudio.com |

**Extensões do PHP obrigatórias:** `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl`, `zip`, `bcmath`, `tokenizer`, `xml`, `ctype`. A extensão `intl` é opcional. Para ativar uma extensão, abra o `php.ini` (descubra qual com `php --ini`) e tire o `;` do começo da linha `extension=...`.

**MySQL Server 8 não é o MariaDB do XAMPP.** O XAMPP traz o **MariaDB**, um banco parecido mas diferente, que **não serve** para o HackLab: não tem os índices e o comportamento dos triggers que as migrations usam. Você pode usar o **PHP do XAMPP**, mas o "MySQL" do painel do XAMPP precisa ficar **parado**: ele usa a mesma porta 3306 do MySQL Server 8.

Para conferir tudo de uma vez (só lê, não altera nada), depois de clonar o projeto (passo 2):

```powershell
powershell -ExecutionPolicy Bypass -File back\scripts\windows\verificar-ambiente.ps1
```

Ou confira à mão:

```powershell
git --version
php -v                 # 8.2 ou maior
php -m                 # precisa listar pdo_mysql, mbstring, openssl, fileinfo, curl, zip, bcmath...
composer --version     # Composer version 2.x
node -v                # v22.x (ou v20+)
npm -v
& "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" --version   # 8.0.19 ou maior, sem "MariaDB"
```

O instalador do MySQL não coloca o `mysql.exe` no PATH. Ou use o caminho completo como acima (troque `8.0` por `8.4` se instalou o 8.4), ou acrescente `C:\Program Files\MySQL\MySQL Server 8.0\bin` ao PATH do Windows. Os exemplos abaixo usam `mysql` como se estivesse no PATH.

### 2. Clonar o projeto e entrar na branch `dev`

```powershell
cd $HOME\Documents
git clone https://github.com/LucasLeal0619/HackLab---Contigencia.git HackLab
cd HackLab
git switch dev
git pull
```

Todo o trabalho da equipe parte da `dev`. A `main` é a produção: não trabalhe nela.

### 3. Criar os bancos e o usuário no MySQL (uma vez por computador)

Na raiz do projeto, rode:

```powershell
powershell -ExecutionPolicy Bypass -File back\scripts\windows\criar-bancos-mysql.ps1
```

1. O script pede uma **senha para o usuário `hacklab`**, duas vezes, sem mostrá-la na tela. Ela fica só no seu computador e vai no `back\.env` (passo 4). Use pelo menos 8 caracteres, só letras sem acento, números e `. _ - @ ! % + =`.
2. Depois o MySQL pede a senha do **root**, o administrador do MySQL definido na instalação.

A senha não aparece no histórico do PowerShell nem na linha de comando. Pode rodar de novo sem problema: nada é duplicado, e a senha do `hacklab` passa a ser a que você digitar agora. **É assim que se recupera o acesso** quando a senha do `.env` foi esquecida.

O MySQL está em outra porta? Use `-Porta 3307`. O `mysql.exe` não foi encontrado? Use `-Mysql "C:\...\bin\mysql.exe"`.

O script ([`criar-bancos-mysql.sql`](back/scripts/windows/criar-bancos-mysql.sql)) cria:
- `hacklab_dev` e `hacklab_test` com `utf8mb4_0900_as_cs`, que diferencia maiúsculas e acentos como o PostgreSQL da produção;
- o usuário `hacklab`, só para este computador, com acesso só a esses dois bancos (o Laravel não usa o root);
- `log_bin_trust_function_creators = 1`, sem o qual o MySQL 8 não deixa um usuário comum criar os triggers das migrations.

#### Quem precisa de quais permissões

| Ação | Permissão necessária | Sem essa permissão |
|---|---|---|
| Instalar o MySQL Server (instalador `.msi`, serviço do Windows) | **administrador do Windows** | A TI instala; ou use o MySQL sem instalador (abaixo) |
| Parar o MySQL/MariaDB do XAMPP ou o serviço `MySQL80` | administrador do Windows, se for serviço | A TI desliga o serviço |
| Rodar este script (criar bancos, usuário e `SET PERSIST`) | **administrador do MySQL** (root), não do Windows | A TI roda o script uma vez e digita a senha do root; você só escolhe a senha do `hacklab` |
| Migrations, seed, testes, `php artisan serve`, `npm run dev` | nenhuma: usuário comum | — |

O `log_bin_trust_function_creators` vale para o **servidor MySQL inteiro**. Num MySQL só seu, não afeta mais nada. Num MySQL compartilhado ou administrado pela TI, combine antes com quem cuida dele.

**MySQL sem instalador e sem administrador do Windows.** Se a TI não puder instalar e a política da máquina permitir rodar programas na sua pasta de usuário, use o MySQL Server em ZIP. Baixe o "Windows (x86, 64-bit), ZIP Archive" em https://dev.mysql.com/downloads/mysql/ e extraia, por exemplo, em `$HOME\mysql`. Use outra porta se a 3306 estiver ocupada:

```powershell
& "$HOME\mysql\bin\mysqld.exe" --initialize-insecure --datadir="$HOME\mysql-data"      # uma vez: root sem senha
& "$HOME\mysql\bin\mysqld.exe" --datadir="$HOME\mysql-data" --port=3307 --bind-address=127.0.0.1 --mysqlx=OFF --console   # deixe aberto
# em outro terminal, na raiz do projeto:
powershell -ExecutionPolicy Bypass -File back\scripts\windows\criar-bancos-mysql.ps1 -Porta 3307 -AdminSemSenha -Mysql "$HOME\mysql\bin\mysql.exe"
```

Depois, use `DB_PORT=3307` no `back\.env`. O root fica sem senha e só aceita conexões deste computador; para um MySQL que só você usa, no desenvolvimento, é aceitável. Foi assim, sem privilégio de administrador, que o guia foi validado.

### 4. Backend (Laravel)

```powershell
cd back
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Abra o `back\.env` e coloque em `DB_PASSWORD` a mesma senha do passo 3. O resto já vem pronto para o MySQL local:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hacklab_dev
DB_USERNAME=hacklab
DB_PASSWORD=a-senha-do-passo-3
```

Confira a conexão e crie as tabelas com os dados de exemplo:

```powershell
php artisan hacklab:db-check      # deve mostrar MySQL 8.x, utf8mb4_0900_as_cs, READ-COMMITTED e "Conexão OK"
php artisan migrate --seed        # cria as tabelas, os triggers e os dados de exemplo no hacklab_dev
php artisan serve                 # API em http://localhost:8000 — deixe este terminal aberto
```

O `.env` nunca vai para o Git. As integrações externas ficam desligadas por padrão: a Even3 não é chamada (`EVEN3_ENABLED=false`) e os e-mails vão para o log (`MAIL_MAILER=log`, em `back\storage\logs`).

### 5. Frontend (Vue)

Em **outro** terminal, na raiz do projeto:

```powershell
cd front
npm install
npm run dev                       # http://localhost:5174 — deixe este terminal aberto
```

### 6. Acessar

Abra **http://localhost:5174** e entre com `admin@hacklab.local` e a senha `hacklab123` (contas de exemplo abaixo). Use sempre `localhost` (não misture com `127.0.0.1`): o cookie de sessão depende do mesmo host no front e na API.

| | URL |
|---|---|
| Frontend | http://localhost:5174 |
| API | http://localhost:8000 |
| Health check | http://localhost:8000/api/v1/health |

### 7. Dia a dia

```powershell
cd HackLab
git switch dev
git pull                           # traz o que foi integrado
cd back
composer install                   # só se o composer.lock mudou
php artisan migrate                # aplica migrations novas (nunca apaga dados)
php artisan serve
```

E no outro terminal: `cd front`, `npm install` (se o `package-lock.json` mudou) e `npm run dev`.

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

`migrate`, `migrate --seed` e `db:seed` são seguros no `hacklab_dev`. Os comandos que apagam o banco (`migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`) ficam **bloqueados** em qualquer banco que não termine em `_test`. Para recriar o seu `hacklab_dev` do zero de propósito, libere só aquele comando:

```powershell
$env:HACKLAB_ALLOW_DESTRUCTIVE_DB = 'true'; php artisan migrate:fresh --seed; Remove-Item Env:HACKLAB_ALLOW_DESTRUCTIVE_DB
```

Não coloque `HACKLAB_ALLOW_DESTRUCTIVE_DB` no `.env`.

Em produção, os perfis vêm do passo de release (`docker/prod/release.sh`, que roda o `RolePermissionSeeder`), e o primeiro Administrador é criado por terminal (forma de execução no Render em [`infraestrutura.md`](hacklab_docs/docs/infraestrutura.md) §3.2.4):

```bash
php artisan hacklab:create-admin   # pede nome, e-mail e senha
```

## Testes

Rode antes de enviar o seu trabalho. Os testes do backend usam o banco **`hacklab_test`**, com o mesmo usuário do `.env`; o `hacklab_dev` não é tocado.

```powershell
# Backend com MySQL (na pasta back)
composer test:mysql                 # = php vendor/bin/phpunit -c phpunit.mysql.xml (cerca de 10 minutos)
vendor\bin\pint --test              # estilo do código PHP; para corrigir: vendor\bin\pint
php artisan hacklab:db-check        # diagnóstico da conexão e das migrations

# Frontend (na pasta front)
npm run test
npm run build
```

Para rodar só um arquivo ou um teste: `php vendor\bin\phpunit -c phpunit.mysql.xml tests\Feature\Teams\TeamTest.php` ou `... --filter=nome_do_teste`.

**Não crie `back\.env.testing`** para o MySQL: sem ele, os testes usam o usuário e a senha do `.env` e trocam o banco para `hacklab_test` sozinhos. O `.env.testing` serve só para a alternativa com PostgreSQL no Docker.

No GitHub, o CI roda sozinho três verificações, que precisam estar verdes antes de qualquer integração:
1. **Backend (Laravel + PostgreSQL 17)**: migrations do zero, Pint e a suíte de testes no banco da produção;
2. **Backend (Laravel + MySQL 8)**: a mesma suíte no banco da equipe;
3. **Frontend (Vue + Vite)**: testes e build de produção.

## Como a equipe trabalha (branches)

```text
feature/sua-tarefa  ──git push──▶  GitHub  ──(Claude Code integra)──▶  dev  ──(publicação autorizada)──▶  main
```

- **`main`:** é a produção (Render + Netlify + Supabase). Ninguém faz push nela.
- **`dev`:** é onde o trabalho da equipe é integrado.
- **Sua branch:** uma por tarefa, sempre criada a partir da `dev` atualizada.

### O que cada integrante faz

1. Atualize a `dev`:
   ```powershell
   git switch dev
   git pull
   ```
2. Crie a sua branch com um nome curto (`feature/...` para funcionalidade, `fix/...` para correção):
   ```powershell
   git switch -c feature/participantes
   ```
3. Desenvolva e teste no seu MySQL local (seção "Testes").
4. Faça commits pequenos e com mensagens claras:
   ```powershell
   git add .
   git status                      # confira: nada de .env, senhas ou arquivos pessoais
   git commit -m "feat: filtro de participantes por turma"
   ```
5. Envie a branch para o GitHub:
   ```powershell
   git push -u origin feature/participantes
   ```
6. Avise o nome da branch para o responsável pela integração.

Você **não** precisa abrir Pull Request, resolver conflitos com a `dev`, adaptar o código ao PostgreSQL nem publicar em produção: a integração técnica é feita pelo Claude Code, e a publicação só acontece com autorização do responsável.

**Nunca envie** `.env`, senhas, tokens, dumps de banco ou dados de produção. Se algo assim entrar num commit por engano, avise antes de fazer `push`.

### O que acontece depois (integração pelo Claude Code)

Quando o responsável informa a sua branch, o Claude Code:
1. busca a branch, revisa as mudanças e as regras de negócio afetadas;
2. faz os ajustes técnicos: resolve conflitos com a `dev` e garante a compatibilidade com MySQL e PostgreSQL (migrations, SQL e testes), numa branch de integração própria, sem reescrever a sua;
3. roda as verificações locais (suítes MySQL e PostgreSQL, Pint, testes e build do front);
4. **abre um Pull Request de integração para a `dev`**, para que o GitHub Actions rode os três checks;
5. só faz o merge na `dev` com os três checks verdes e as aprovações aplicáveis, e apresenta um relatório do que entrou.

Mudança funcional relevante, conflito que exija decisão de negócio ou risco de regressão passam antes pela aprovação do responsável. A passagem da `dev` para a `main` (produção) é sempre uma decisão explícita do responsável, e pode ser parcial (só as funcionalidades aprovadas). O procedimento completo está no `CLAUDE.md` (§5.6.1).

## Solução de problemas

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| `php` / `composer` / `node` "não é reconhecido como comando" | programa fora do PATH | Instale ou acrescente a pasta ao PATH do Windows (ex.: `C:\xampp\php`) e **abra um terminal novo**. |
| `could not find driver` ao conectar | extensão `pdo_mysql` desligada | No `php.ini` (`php --ini`), tire o `;` de `extension=pdo_mysql` e abra um terminal novo. |
| `composer install` reclama de extensão (`ext-zip`, `ext-fileinfo`...) | extensão desligada | Ative no `php.ini` como acima. |
| `mysql` não é reconhecido | o instalador do MySQL não põe no PATH | Use `& "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe"` ou acrescente essa pasta ao PATH. |
| `MariaDB ... não é suportado` ou a versão mostra "MariaDB" | o MariaDB do XAMPP está respondendo na 3306 | Pare o MySQL no painel do XAMPP, inicie o serviço **MySQL80** (`services.msc`) e rode `php artisan hacklab:db-check`. |
| `MySQL 8.0.x não é suportado` | MySQL anterior a 8.0.19 | Atualize para o MySQL 8.0 mais recente ou para o 8.4 LTS. |
| `Access denied for user 'hacklab'` | senha do `.env` diferente da do MySQL, ou usuário não criado | Rode o script do passo 3 de novo e digite uma senha: ele **redefine** a senha do `hacklab`. Coloque a mesma em `DB_PASSWORD` e rode `php artisan config:clear`. |
| `You do not have the SUPER privilege and binary logging is enabled` nas migrations | faltou o ajuste dos triggers (o script do passo 3 não rodou até o fim) | Rode o script do passo 3 de novo como administrador do MySQL; se você não tem a senha do root, peça à TI (seção "Quem precisa de quais permissões"). |
| `Access denied; you need (at least one of) the ... privilege` no script do passo 3 | a conta usada não é administradora do MySQL | Rode com o root, ou peça à TI para rodar o script uma vez. |
| "A execução de scripts foi desabilitada neste sistema", mesmo com `-ExecutionPolicy Bypass` | política da TI bloqueia scripts PowerShell | Peça à TI para liberar; ou abra o `criar-bancos-mysql.sql` no MySQL Workbench como root, troque `TROQUE_ESTA_SENHA` pela senha só na janela do editor, execute e feche **sem salvar**. |
| `Access denied for user 'root'` no script do passo 3 | senha do root errada | A senha do root é a definida na instalação do MySQL; se foi a TI que instalou, peça a ela. |
| `Unknown database 'hacklab_dev'` / `'hacklab_test'` | bancos não criados | Rode o script do passo 3. |
| Migration falha no meio | banco num estado intermediário | Leia a mensagem; corrigida a causa, recrie o banco de dev com o comando de `migrate:fresh` da seção "Contas de desenvolvimento". |
| Login volta para a tela de entrada, ou `HTTP 419` | cookie de sessão/CSRF não chega | Use `localhost` nos dois (não misture com `127.0.0.1`); confira no `back\.env`: `FRONTEND_URL=http://localhost:5174`, `SANCTUM_STATEFUL_DOMAINS=localhost:5174,127.0.0.1:5174`; limpe os cookies de `localhost` e rode `php artisan config:clear`. |
| Front mostra "não foi possível conectar" | API parada ou em outra porta | Confira o terminal do `php artisan serve` e abra http://localhost:8000/api/v1/health. |
| `Failed to listen on 127.0.0.1:8000` | porta 8000 ocupada | Use outra porta: `php artisan serve --port=8010`; crie `front\.env.development.local` com `VITE_API_URL=http://localhost:8010`, ajuste `APP_URL` no `back\.env` e reinicie o `npm run dev`. |
| `Port 5174 is in use` | porta do Vite ocupada | O Vite oferece outra porta; se aceitar (ex.: 5175), troque `5174` por ela em `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS` e `SANCTUM_STATEFUL_DOMAINS` no `back\.env` e rode `php artisan config:clear`. |
| Descobrir quem ocupa uma porta | — | `Get-NetTCPConnection -LocalPort 8000 -State Listen \| Select-Object OwningProcess` e depois `Get-Process -Id <número>`. |
| `git pull` recusa por "local changes" | arquivos alterados e não commitados | Faça commit na sua branch (`git add .`, `git commit`) ou guarde temporariamente com `git stash` e depois `git stash pop`. |
| `git push` pede login ou dá 403 | sem permissão no repositório | Peça acesso ao responsável; faça login com `gh auth login` ou pelo Git Credential Manager. |
| Commitou na `dev` por engano | trabalho fora da sua branch | Antes do `push`: `git switch -c feature/nome` (leva os commits para a branch nova) e avise o responsável. |

### Alternativa: PostgreSQL 17 no Docker (opcional)

Quem preferir rodar com o mesmo banco da produção pode usar o Docker. O `docker-compose.yml` sobe o Laravel e um PostgreSQL 17 e já define as variáveis do banco, então o `back\.env` serve como está.

```powershell
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
Copy-Item back\.env.testing.example back\.env.testing
docker compose exec app php artisan key:generate --env=testing
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan test        # suíte no PostgreSQL (banco hacklab_test do Docker)
```

O front continua igual (`cd front`, `npm run dev`). `docker compose down -v` apaga os dados do PostgreSQL do Docker.

## Integração Even3

```text
Even3 (API V2 e webhook) → Laravel (Even3Provider / sincronização) → PostgreSQL → Vue
```

- O Vue nunca chama a Even3. O token (`EVEN3_API_TOKEN`, um por deployment) e o segredo do webhook ficam só nas variáveis do backend.
- **Premissa:** participantes, equipes, organização interna, setores, empresas e desafios são cadastrados no HackLab **antes** do evento. A Even3 valida inscrições e traz jurados externos, convidados de empresas, público e presença.
- A tela **Presença** é a tela da integração: indicadores (inscritos, presentes, sem registro, pendências), lista de pessoas com presença e **Precisa de atenção**. A **Configuração Even3** (entradas, histórico, divergência do evento) fica num painel discreto.
- Sincronizar é repetível e não duplica dados. O webhook (`POST /api/v1/webhooks/even3`) é idempotente.
- Mapear uma entrada não concede perfil e não cria jurado, empresa ou usuário.
- Comandos: `php artisan even3:test-connection` e `php artisan even3:sync`.
- **Uma sincronização central por evento; o que é seguro é automático.** Sincronizar não pede confirmação. Jurados confirmados viram Jurados do HackLab (sem conta: a conta vem do primeiro acesso). Participantes já cadastrados são validados. Público pode fazer o primeiro acesso como Votante.
- **Só exceções vão ao Administrador**, numa lista única: participante não localizado, identidade divergente, representante sem empresa (escolher a empresa) e entrada sem mapeamento. Nada cria participante, empresa, conta ou perfil; nome e datas do evento continuam do HackLab.
- O cabeçalho mostra o estado ("Even3 · Atualizado há 3 min"). Uma sincronização automática silenciosa roda como reserva; em produção o caminho principal é webhook + sincronizar.
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

## Produção (Fase 12)

- Frontend: Netlify (`npm run build`, com `VITE_API_URL` apontando para a API).
- API: Laravel no Render (Web Service, Docker), a partir do alvo **`prod`** do `back/Dockerfile`.
- Banco: Supabase, só como PostgreSQL gerenciado (Session pooler, SSL obrigatório, `search_path=hacklab`).

**Desenvolvimento ≠ produção.** O `docker-compose.yml` usa o alvo `dev`: código montado e servidor embutido do PHP. O Render usa o alvo `prod`:
- código e `vendor` (sem pacotes de dev) dentro da imagem;
- FrankenPHP na porta `PORT`;
- logs em stderr;
- nenhum segredo na imagem.

```bash
# Testar a imagem de produção localmente
docker build --target prod -t hacklab-back-prod ./back
```

| No Render | Valor |
|---|---|
| Web Service | Docker, branch `main`, Root Directory `back`, Dockerfile `./Dockerfile` |
| Health Check Path | `/api/v1/health` |
| Migrations | Pre-Deploy `/app/docker/prod/release.sh` (plano pago); no Free, `HACKLAB_MIGRATE_ON_START=true` só no primeiro deploy, depois `false` |
| Plano | Free só para validar o deploy; no evento, no mínimo o pago básico (decidido após a Fase 13). Free bloqueia SMTP nas portas 25/465/587 |
| Sincronização agendada | Cron Job opcional, `php artisan even3:sync` a cada 10 min (o Web Service não roda o Scheduler) |
| Fila | nenhum fluxo usa; sem worker (`QUEUE_CONNECTION=sync`) |

Variáveis, segredos e decisões em [`infraestrutura.md`](hacklab_docs/docs/infraestrutura.md) §3.2. Já validado em produção:
- deploy;
- login com Sanctum pelo proxy assinado do Netlify (mesma origem);
- IP real do cliente na Auditoria (HACKLAB-IP-001).

Ainda faltam:
- o SMTP real (hoje `MAIL_MAILER=array`: primeiro acesso e recuperação de senha não entregam e-mail);
- a liberação de Jurados e Votantes;
- a Even3 real com webhook.

A Fase 13 fará o teste de carga.

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
