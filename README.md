# HackLab

Sistema interno de gestão dos Hackathons do Senac: participantes, equipes, empresas e desafios, pendências e ocorrências, jurados e avaliações, votação do público, resultado oficial, auditoria e relatórios. As inscrições e o credenciamento ficam na plataforma externa **Even3**, integrada pelo backend.

- **Frontend:** Vue 3 + Vite (`front/`)
- **Backend:** Laravel 12, API REST em `/api/v1` (`back/`)
- **Banco:** MySQL 8 no desenvolvimento local; PostgreSQL 17 (Supabase) na produção

## Como instalar e executar o HackLab no Senac

Este é o caminho para os computadores do Senac, que já têm o **MySQL Server** e o **MySQL Workbench** instalados, com a sua conta. Não instale outro MySQL nem crie outro usuário.

Computador sem MySQL (pessoal, por exemplo)? Use o [guia de instalação alternativa](hacklab_docs/docs/instalacao-alternativa.md).

Os comandos são para o **Prompt de Comando (CMD)**. Para abri-lo: tecla Windows, digite `cmd`, Enter.

### 1. Verificar os programas necessários

**Onde:** CMD.

```bat
git --version
php -v
composer --version
node -v
php -m | findstr /i "pdo_mysql mbstring openssl fileinfo curl zip bcmath tokenizer xml ctype"
```

**Resultado esperado:**
- o `git` mostra uma versão qualquer;
- o PHP mostra **8.2 ou maior**;
- o Composer mostra **2.x**;
- o Node mostra **v20 ou maior** (recomendado v22);
- a última linha lista as 10 extensões: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl`, `zip`, `bcmath`, `tokenizer`, `xml` e `ctype`.

Falta algum programa ou extensão? Veja [Solução de problemas](#solução-de-problemas). A versão do MySQL Server é conferida no passo 3.

### 2. Clonar o repositório na branch `dev`

**Onde:** CMD.

```bat
cd /d %USERPROFILE%\Documents
git clone -b dev https://github.com/LucasLeal0619/HackLab---Contigencia.git HackLab
```

**Resultado esperado:** a pasta `Documents\HackLab`, com as pastas `back` e `front`. Todo o trabalho parte da `dev`; a `main` é a produção.

### 3. Abrir o MySQL Workbench e conferir o servidor

**Onde:** MySQL Workbench.

1. Abra o Workbench e clique no quadro da sua conexão, em **MySQL Connections**.
2. Anote os dados da conexão: clique com o botão direito no quadro e escolha **Edit Connection...**. Anote **Hostname**, **Port** e **Username**. Eles vão no `.env` (passo 6).
3. Abra uma aba de consulta (**File → New Query Tab**), cole o SQL abaixo e execute com o raio (⚡) ou **Ctrl+Shift+Enter**:

```sql
SELECT VERSION() AS versao, CURRENT_USER() AS conta, @@port AS porta;
SHOW GRANTS;
```

**Resultado esperado:**
- `versao` é **8.0.19 ou maior** e não contém "MariaDB";
- `conta` mostra o seu usuário no formato `usuario@host`;
- `SHOW GRANTS` lista as suas permissões, que são testadas na prática no passo 4.

A versão é menor ou aparece "MariaDB"? Veja [Quando chamar a TI](#quando-chamar-a-ti).

### 4. Criar os bancos `hacklab_dev` e `hacklab_test`

**Onde:** MySQL Workbench, na mesma aba de consulta.

**a) Criar os bancos.** Cole e execute:

```sql
CREATE DATABASE IF NOT EXISTS hacklab_dev  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
CREATE DATABASE IF NOT EXISTS hacklab_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
SELECT schema_name, default_collation_name
  FROM information_schema.schemata
 WHERE schema_name IN ('hacklab_dev', 'hacklab_test');
```

**Resultado esperado:** duas linhas, `hacklab_dev` e `hacklab_test`, ambas com `utf8mb4_0900_as_cs`. Os nomes são obrigatórios: os testes só rodam num banco terminado em `_test`.

**b) Testar se a sua conta cria triggers.** As migrations do HackLab criam triggers. Este teste não deixa nada para trás:

```sql
DROP TABLE IF EXISTS hacklab_test.teste_trigger;
CREATE TABLE hacklab_test.teste_trigger (id INT);
CREATE TRIGGER hacklab_test.teste_trigger_bi BEFORE INSERT ON hacklab_test.teste_trigger FOR EACH ROW SET NEW.id = NEW.id;
DROP TABLE hacklab_test.teste_trigger;
```

**Resultado esperado:** os quatro comandos com o ícone verde, sem erro. Deu erro? Veja [Quando chamar a TI](#quando-chamar-a-ti).

### 5. Instalar as dependências do backend

**Onde:** CMD.

```bat
cd /d %USERPROFILE%\Documents\HackLab\back
composer install
```

**Resultado esperado:** o Composer termina sem erro vermelho e cria a pasta `back\vendor`. Leva alguns minutos na primeira vez.

### 6. Criar e configurar o `.env`

**Onde:** CMD, na pasta `back`, e depois o Bloco de Notas (ou o VS Code).

```bat
copy .env.example .env
notepad .env
```

No arquivo, procure a parte do banco (`DB_`). Preencha com os dados anotados no passo 3 e salve (Ctrl+S):

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hacklab_dev
DB_USERNAME=seu_usuario
DB_PASSWORD='sua senha'
```

| Campo | Valor |
|---|---|
| `DB_HOST` | o **Hostname** do Workbench (`127.0.0.1` ou `localhost`) |
| `DB_PORT` | o **Port** do Workbench (normalmente `3306`) |
| `DB_DATABASE` | `hacklab_dev`, sempre |
| `DB_USERNAME` | o **Username** do Workbench |
| `DB_PASSWORD` | a senha da sua conta, **entre aspas simples** (sem aspas, um `#` na senha corta o resto) |

Não mexa nas outras linhas. Os testes usam esta mesma conta no banco `hacklab_test`; não crie `.env.testing`. Com os valores padrão, a Even3 fica desligada e os e-mails vão para o log (`back\storage\logs`).

> **Segurança:** o `.env` fica só no seu computador e nunca vai para o GitHub (está no `.gitignore`). Não copie a senha em mensagens, prints ou commits.

### 7. Gerar a chave do Laravel

**Onde:** CMD, na pasta `back`.

```bat
php artisan key:generate
```

**Resultado esperado:** `Application key set successfully.` Só é preciso uma vez.

### 8. Verificar a conexão com o banco

**Onde:** CMD, na pasta `back`.

```bat
php artisan hacklab:db-check
```

**Resultado esperado:** uma tabela com MySQL 8.x, `utf8mb4_0900_as_cs` e `READ-COMMITTED`, terminando em **Conexão OK.** Se der erro, veja [Solução de problemas](#solução-de-problemas).

### 9. Criar as tabelas e os dados de exemplo

**Onde:** CMD, na pasta `back`.

```bat
php artisan migrate --seed
```

**Resultado esperado:** a lista de migrations com `DONE` e, no fim, os seeders com `DONE`. Isso cria as tabelas, os triggers e um evento de exemplo no `hacklab_dev`.

### 10. Iniciar o backend (Laravel)

**Onde:** CMD, na pasta `back`.

```bat
php artisan serve
```

**Resultado esperado:** `Server running on [http://127.0.0.1:8000]`. **Deixe esta janela aberta**: fechá-la desliga a API.

### 11. Instalar e iniciar o frontend (Vue)

**Onde:** um **novo** CMD. Abra outra janela: a do passo 10 precisa continuar aberta.

```bat
cd /d %USERPROFILE%\Documents\HackLab\front
npm install
npm run dev
```

**Resultado esperado:** o `npm install` termina sem erro e o `npm run dev` mostra `Local: http://localhost:5174/`. **Deixe esta janela aberta também.**

### 12. Acessar o HackLab

**Onde:** navegador.

Abra **http://localhost:5174** e entre com:
- **e-mail:** `admin@hacklab.local`
- **senha:** `hacklab123`

**Resultado esperado:** o Dashboard do evento de exemplo. Use sempre `localhost` no endereço, não `127.0.0.1`: o login depende disso.

## Contas de desenvolvimento

Criadas pelo passo 9, **só no seu computador** (nunca em produção). Todas usam a senha `hacklab123`.

| Conta | Perfil |
|---|---|
| `admin@hacklab.local` | Administrador |
| `gestor@hacklab.local` | Gestor do "Setor Exemplo A" |
| `editor@hacklab.local` | Editor do "Setor Exemplo B" |
| `jurado@hacklab.local` | Jurado com duas equipes atribuídas |
| `votante01@hacklab.local` … `votante05@hacklab.local` | Votantes |

O evento de exemplo traz:
- 12 participantes e 2 equipes;
- 3 empresas e 5 desafios;
- pendências, ocorrências e jurados;
- duas votações.

O `php artisan migrate --seed` pode ser repetido sem duplicar dados.

## Dia a dia

Para trazer o que foi integrado na `dev` e voltar a trabalhar.

**Onde:** CMD.

```bat
cd /d %USERPROFILE%\Documents\HackLab
git switch dev
git pull
cd back
composer install
php artisan migrate
php artisan serve
```

Num segundo CMD:

```bat
cd /d %USERPROFILE%\Documents\HackLab\front
npm install
npm run dev
```

O `php artisan migrate` só aplica migrations novas e nunca apaga dados.

## Como executar os testes

Rode antes de enviar as suas alterações. Os testes usam o banco `hacklab_test`; o `hacklab_dev` não é tocado.

**Backend.** Onde: CMD, na pasta `back`.

```bat
composer test:mysql
vendor\bin\pint --test
```

**Resultado esperado:**
- o `composer test:mysql` leva cerca de 10 minutos e termina com `OK` (alguns testes aparecem como pulados: são exclusivos do PostgreSQL);
- o Pint mostra `passed`. Se não mostrar, `vendor\bin\pint` corrige o estilo.

**Frontend.** Onde: CMD, na pasta `front`.

```bat
npm run test
npm run build
```

**Resultado esperado:** todos os testes `passed` e `built in ...`.

Para rodar só um arquivo de teste do backend: `php vendor\bin\phpunit -c phpunit.mysql.xml tests\Feature\Teams\TeamTest.php`.

## Como enviar suas alterações

**Onde:** CMD, na pasta `HackLab`.

1. Atualize a `dev`:
   ```bat
   git switch dev
   git pull
   ```
2. Crie a sua branch: `feature/...` para funcionalidade, `fix/...` para correção.
   ```bat
   git switch -c feature/nome-curto
   ```
3. Desenvolva e rode os testes (seção anterior).
4. Faça o commit:
   ```bat
   git add .
   git status
   git commit -m "feat: descrição curta do que mudou"
   ```
   No `git status`, confira que não aparece nenhum `.env`, senha ou arquivo pessoal.
5. Envie a branch ao GitHub:
   ```bat
   git push -u origin feature/nome-curto
   ```
6. Informe o nome da branch ao responsável pelo projeto.

**Você não precisa abrir Pull Request.** O Claude Code faz o resto, com as aprovações necessárias do responsável:
- a revisão;
- os ajustes técnicos;
- a compatibilidade com MySQL e PostgreSQL;
- a integração na `dev`.

A `main` (produção) só recebe alterações com autorização explícita do responsável. O procedimento completo está no [`CLAUDE.md`](CLAUDE.md) (§5.6.1).

## Solução de problemas

| Problema | O que fazer |
|---|---|
| `php`, `composer`, `node` ou `git` "não é reconhecido como um comando" | Instale o programa ou acrescente a pasta dele ao PATH do Windows (ex.: `C:\xampp\php`). Depois **abra um CMD novo**. |
| Falta extensão no passo 1, ou `could not find driver` | Rode `php --ini` para achar o `php.ini`, abra-o, tire o `;` do começo da linha `extension=...` que falta e abra um CMD novo. |
| `Access denied for user` no passo 8 | Usuário, senha, host ou porta do `.env` diferentes da conexão do Workbench. Confira em **Edit Connection...**, deixe a senha entre aspas simples e rode `php artisan config:clear`. |
| `Unknown database 'hacklab_dev'` | Os bancos não foram criados: refaça o passo 4a. |
| `MariaDB` ou "versão não suportada" no passo 8 | O MySQL que responde não é o MySQL Server 8.0.19+. Se o XAMPP estiver aberto, pare o "MySQL" do painel. Veja também [Quando chamar a TI](#quando-chamar-a-ti). |
| `You do not have the SUPER privilege and binary logging is enabled` no passo 9 | A conta não pode criar triggers: faça o teste do passo 4b e veja [Quando chamar a TI](#quando-chamar-a-ti). |
| Migration falhou no meio | Leia a mensagem, corrija a causa e recrie o `hacklab_dev` (comando abaixo). |
| Login volta para a tela de entrada, ou erro 419 | Acesse por `http://localhost:5174` (não `127.0.0.1`), limpe os cookies do site e rode `php artisan config:clear` no `back`. |
| "Não foi possível conectar ao HackLab" (ou "ao servidor do HackLab") | A janela do passo 10 foi fechada ou deu erro. Confira-a e abra http://localhost:8000/api/v1/health. |
| `Failed to listen on 127.0.0.1:8000` (porta ocupada) | Rode `php artisan serve --port=8010`. Na pasta `front`, rode `echo VITE_API_URL=http://localhost:8010> .env.development.local` e reinicie o `npm run dev`. |
| Descobrir quem ocupa uma porta | `netstat -ano \| findstr :8000` (o último número é o PID) e depois `tasklist /FI "PID eq <número>"`. |
| `git pull` recusa por "local changes" | Faça commit na sua branch, ou guarde com `git stash` e depois devolva com `git stash pop`. |
| `git push` pede login ou dá 403 | Peça acesso ao repositório ao responsável e faça login pela janela do Git Credential Manager. |

**Recriar o `hacklab_dev` do zero.** O comando apaga todos os dados do banco local, por isso fica bloqueado por padrão. Na pasta `back`, rode as três linhas:

```bat
set HACKLAB_ALLOW_DESTRUCTIVE_DB=true
php artisan migrate:fresh --seed
set HACKLAB_ALLOW_DESTRUCTIVE_DB=
```

A liberação vale só para aquela janela. Nunca coloque `HACKLAB_ALLOW_DESTRUCTIVE_DB` no `.env`.

### Quando chamar a TI

Ter o Workbench instalado não garante que o servidor seja compatível nem que a sua conta tenha todas as permissões. Chame a TI nestes casos:

| O que aconteceu | Motivo | O que pedir |
|---|---|---|
| Versão menor que 8.0.19, ou "MariaDB" (passo 3) | as migrations usam recursos do MySQL 8.0.19+ | atualizar o MySQL Server para 8.0.19 ou maior (recomendado 8.4 LTS) |
| `Error 1044: Access denied ... to database` (passo 4a) | a conta não pode criar bancos | criar os dois bancos e dar acesso total a eles (SQL abaixo) |
| `Error 1142: TRIGGER command denied` (passo 4b) | falta a permissão de triggers | dar acesso total aos dois bancos (SQL abaixo) |
| `Error 1419: You do not have the SUPER privilege and binary logging is enabled` (passo 4b) | o MySQL tem o log binário ligado e só libera triggers para administradores | `SET PERSIST log_bin_trust_function_creators = 1;` (vale para o servidor inteiro; **só neste caso**) |

SQL para a TI rodar como administrador do MySQL. Troque `'sua_conta'@'localhost'` pelo resultado de `CURRENT_USER()` do passo 3:

```sql
CREATE DATABASE IF NOT EXISTS hacklab_dev  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
CREATE DATABASE IF NOT EXISTS hacklab_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
GRANT ALL PRIVILEGES ON hacklab_dev.*  TO 'sua_conta'@'localhost';
GRANT ALL PRIVILEGES ON hacklab_test.* TO 'sua_conta'@'localhost';
-- Só se o teste de triggers falhou com o erro 1419:
SET PERSIST log_bin_trust_function_creators = 1;
```

Depois que a TI resolver, refaça o passo 4.

## Documentação técnica

| Documento | Conteúdo |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | Regras obrigatórias do projeto, branches e integração |
| [`instalacao-alternativa.md`](hacklab_docs/docs/instalacao-alternativa.md) | Instalação sem o MySQL do Senac: script PowerShell, MySQL em ZIP, Docker com PostgreSQL |
| [`arquitetura.md`](hacklab_docs/docs/arquitetura.md) | Camadas, perfis, autenticação, frontend, auditoria e relatórios |
| [`regras-de-negocio.md`](hacklab_docs/docs/regras-de-negocio.md) | Regras do domínio: equipes, demandas, avaliação, votação, presença, relatórios |
| [`banco-de-dados.md`](hacklab_docs/docs/banco-de-dados.md) | Tabelas, restrições e matriz de permissões por perfil |
| [`integracao-even3.md`](hacklab_docs/docs/integracao-even3.md) | Integração Even3: sincronização, webhook e comandos |
| [`infraestrutura.md`](hacklab_docs/docs/infraestrutura.md) | Ambientes, MySQL/PostgreSQL, CI, produção (Render, Netlify, Supabase) |
| [`plano-implementacao.md`](hacklab_docs/docs/plano-implementacao.md) | Situação das fases, endpoints e pendências |
