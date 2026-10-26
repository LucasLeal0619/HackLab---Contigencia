# HackLab

Sistema interno de gestão dos Hackathons do Senac: equipes, jurados, avaliações, votação, resultados e relatórios. Frontend Vue 3, backend Laravel 12 e MySQL 8 no desenvolvimento.

## Pré-requisitos

- Git
- PHP 8.2 ou maior, com a extensão `pdo_mysql`
- Composer 2
- Node.js 20 ou maior (recomendado 22)
- MySQL Server 8.0.19 ou maior e MySQL Workbench (já instalados no Senac, com o seu usuário e senha)

## Instalação

**1. Clonar a branch `dev`**, no **Prompt de Comando (CMD)**:

```bat
cd /d %USERPROFILE%\Documents
git clone -b dev https://github.com/LucasLeal0619/HackLab---Contigencia.git HackLab
```

**2. Criar os bancos.** No MySQL Workbench, abra a sua conexão e uma nova aba de consulta, cole o SQL e execute (⚡):

```sql
CREATE DATABASE IF NOT EXISTS hacklab_dev  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
CREATE DATABASE IF NOT EXISTS hacklab_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
```

**3. Instalar o backend e criar o `.env`**

```bat
cd /d %USERPROFILE%\Documents\HackLab\back
composer install
copy .env.example .env
notepad .env
```

No `.env`, preencha com os dados da sua conexão no Workbench (botão direito na conexão → **Edit Connection**). Mantenha a senha entre aspas simples e salve:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USERNAME=seu_usuario
DB_PASSWORD='sua senha'
```

**4. Criar as tabelas e iniciar o Laravel**, no mesmo CMD:

```bat
php artisan key:generate
php artisan hacklab:db-check
php artisan migrate --seed
php artisan serve
```

O `db-check` deve terminar em **Conexão OK**. Deixe esta janela aberta.

**5. Iniciar o Vue**, num **novo** CMD:

```bat
cd /d %USERPROFILE%\Documents\HackLab\front
npm install
npm run dev
```

Deu erro? O [guia detalhado de instalação](hacklab_docs/docs/instalacao-senac.md) traz as verificações de cada passo, a solução de problemas e quando chamar a TI.

## Acesso

- Endereço: **http://localhost:5174** (use `localhost`, não `127.0.0.1`)
- Login: `admin@hacklab.local`, senha `hacklab123`
- Contas de Gestor, Editor, Jurado e Votantes: no [guia detalhado](hacklab_docs/docs/instalacao-senac.md#contas-de-desenvolvimento)

## Testes

```bat
cd /d %USERPROFILE%\Documents\HackLab\back
composer test:mysql
vendor\bin\pint --test
cd ..\front
npm run test
npm run build
```

## Como enviar alterações

```bat
cd /d %USERPROFILE%\Documents\HackLab
git switch dev
git pull
git switch -c feature/nome-curto
```

Desenvolva e rode os testes. Depois, envie a branch:

```bat
git add .
git status
git commit -m "feat: descrição curta"
git push -u origin feature/nome-curto
```

No `git status`, confira que nenhum `.env` aparece. Depois, informe o nome da branch ao responsável. **Você não abre Pull Request:** a revisão e a integração na `dev` são feitas pelo Claude Code, com aprovação do responsável.

## Documentação

- [Instalação detalhada no Senac](hacklab_docs/docs/instalacao-senac.md): verificações, permissões do MySQL, TI, solução de problemas
- [Instalação alternativa](hacklab_docs/docs/instalacao-alternativa.md): computador sem o MySQL do Senac
- [CLAUDE.md](CLAUDE.md): regras do projeto, branches e integração
- [Documentação técnica](hacklab_docs/README.md): arquitetura, regras de negócio, banco, Even3, infraestrutura e plano
