# Instalação alternativa (computador sem o MySQL do Senac)

O caminho principal está no [README](../../README.md) e vale para os computadores do Senac, que já têm MySQL Server e MySQL Workbench. Este guia é para quem **não** tem esse MySQL, por exemplo num computador pessoal.

Escolha **uma** das opções:

| Opção | Quando usar | Banco |
|---|---|---|
| [A. MySQL Server + script PowerShell](#a-mysql-server-instalado-com-o-script-powershell) | você pode instalar programas no Windows | MySQL 8 |
| [B. MySQL em ZIP](#b-mysql-em-zip-sem-instalador-e-sem-administrador-do-windows) | você não é administrador do Windows | MySQL 8 |
| [C. Docker com PostgreSQL](#c-docker-com-postgresql-17) | você já usa Docker e quer o mesmo banco da produção | PostgreSQL 17 |

Nas opções A e B, depois de criar os bancos, siga o README a partir do **passo 5**, pulando os passos 3 e 4 (Workbench). No `.env` (passo 6), use `DB_USERNAME=hacklab` e a senha escolhida no script.

Os pré-requisitos (Git, PHP 8.2+, Composer 2, Node 20+) são os mesmos do passo 1 do README. Para conferir tudo de uma vez num PowerShell, rode o `verificar-ambiente.ps1`. Ele só lê e não altera nada:

```powershell
powershell -ExecutionPolicy Bypass -File back\scripts\windows\verificar-ambiente.ps1
```

## A. MySQL Server instalado, com o script PowerShell

Instale o **MySQL Server 8.4 LTS** pelo instalador (https://dev.mysql.com/downloads/installer/). O instalador define a senha do **root**. Não use o MariaDB do XAMPP: ele não serve para o HackLab e, se estiver ligado, ocupa a mesma porta 3306.

Na raiz do projeto, num PowerShell, rode:

```powershell
powershell -ExecutionPolicy Bypass -File back\scripts\windows\criar-bancos-mysql.ps1
```

O que acontece:
- o script pede uma senha para o usuário `hacklab`, duas vezes e sem mostrá-la. Use pelo menos 8 caracteres, só letras sem acento, números e `. _ - @ ! % + =`;
- depois o MySQL pede a senha do root.

A senha não fica no histórico do PowerShell nem na linha de comando. Pode rodar de novo: nada é duplicado, e a senha do `hacklab` passa a ser a nova, **o que também recupera o acesso**. Opções: `-Porta 3307` para outra porta e `-Mysql "C:\...\bin\mysql.exe"` se o `mysql.exe` não for encontrado.

O script ([`criar-bancos-mysql.sql`](../../back/scripts/windows/criar-bancos-mysql.sql)) cria:
- os bancos `hacklab_dev` e `hacklab_test`, com `utf8mb4_0900_as_cs`;
- o usuário `hacklab`, só para este computador e só com acesso a esses bancos;
- o ajuste `log_bin_trust_function_creators = 1`. Ele é global, e sem ele um usuário comum não cria os triggers num MySQL recém-instalado.

| Problema | O que fazer |
|---|---|
| `Access denied for user 'root'` | Use a senha do root definida na instalação. |
| `you need ... privilege` | A conta usada não é administradora do MySQL: rode com o root. |
| "A execução de scripts foi desabilitada neste sistema" | Uma política bloqueia scripts PowerShell: use a opção B, ou crie os bancos pelo Workbench como no README (passos 3 e 4). |

## B. MySQL em ZIP, sem instalador e sem administrador do Windows

Funciona se a política da máquina permitir rodar programas na pasta do usuário. Baixe o "Windows (x86, 64-bit), ZIP Archive" em https://dev.mysql.com/downloads/mysql/ e extraia, por exemplo, em `$HOME\mysql`. Use outra porta se a 3306 estiver ocupada.

Num PowerShell, rode:

```powershell
& "$HOME\mysql\bin\mysqld.exe" --initialize-insecure --datadir="$HOME\mysql-data"      # uma vez: root sem senha
& "$HOME\mysql\bin\mysqld.exe" --datadir="$HOME\mysql-data" --port=3307 --bind-address=127.0.0.1 --mysqlx=OFF --console   # deixe aberto
```

Em outro PowerShell, na raiz do projeto:

```powershell
powershell -ExecutionPolicy Bypass -File back\scripts\windows\criar-bancos-mysql.ps1 -Porta 3307 -AdminSemSenha -Mysql "$HOME\mysql\bin\mysql.exe"
```

Depois, use `DB_PORT=3307` no `back\.env`. O root fica sem senha e só aceita conexões deste computador. Para um MySQL que só você usa, no desenvolvimento, isso é aceitável. Sempre que for trabalhar, inicie o MySQL com o segundo comando.

| Ação | Permissão necessária |
|---|---|
| Instalar o MySQL Server (opção A: instalador `.msi`, serviço do Windows) | administrador do Windows (ou a TI) |
| Rodar o `criar-bancos-mysql.ps1` (bancos, usuário, `SET PERSIST`) | administrador do **MySQL** (root), não do Windows |
| MySQL em ZIP, migrations, seed, testes, `php artisan serve`, `npm run dev` | nenhuma: usuário comum |

## C. Docker com PostgreSQL 17

Para quem já usa Docker Desktop e quer o mesmo banco da produção. O `docker-compose.yml` sobe o Laravel e um PostgreSQL 17 e define sozinho as variáveis do banco.

No CMD, na raiz do projeto:

```bat
copy back\.env.example back\.env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Para rodar os testes no PostgreSQL (banco `hacklab_test` do Docker):

```bat
copy back\.env.testing.example back\.env.testing
docker compose exec app php artisan key:generate --env=testing
docker compose exec app php artisan test
```

O frontend é igual ao do README (passo 11). `docker compose down -v` apaga os dados do PostgreSQL do Docker.

Para recriar o banco de desenvolvimento do zero: `docker compose exec -e HACKLAB_ALLOW_DESTRUCTIVE_DB=true app php artisan migrate:fresh --seed`.

Detalhes do ambiente Docker em [`infraestrutura.md`](infraestrutura.md) §2.
