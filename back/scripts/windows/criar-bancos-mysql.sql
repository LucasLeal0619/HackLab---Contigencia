-- HackLab — bancos MySQL do desenvolvimento local (Windows, MySQL Server 8.0.19+). Rode como root, no PowerShell,
-- na raiz do projeto, trocando minha-senha-local pela senha que vai no back\.env (DB_PASSWORD):
--
--   (Get-Content back\scripts\windows\criar-bancos-mysql.sql) -replace 'TROQUE_ESTA_SENHA', 'minha-senha-local' | mysql -u root -p
--
-- A troca é só na hora (o arquivo não muda). Pode rodar de novo: nada é duplicado.
-- Cria só bancos vazios e o usuário da aplicação: as tabelas vêm das migrations (php artisan migrate --seed).
-- Nada aqui toca em produção: produção é PostgreSQL no Supabase e nunca é acessada do seu computador.

-- Collation as_cs: diferencia maiúsculas e acentos, como o PostgreSQL da produção.
CREATE DATABASE IF NOT EXISTS hacklab_dev  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
CREATE DATABASE IF NOT EXISTS hacklab_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;

-- Usuário próprio da aplicação (o Laravel não usa root). Só pode entrar a partir deste computador.
CREATE USER IF NOT EXISTS 'hacklab'@'localhost' IDENTIFIED BY 'TROQUE_ESTA_SENHA';
CREATE USER IF NOT EXISTS 'hacklab'@'127.0.0.1' IDENTIFIED BY 'TROQUE_ESTA_SENHA';

-- Tudo nos dois bancos do HackLab (tabelas, índices, chaves, triggers), nada fora deles.
GRANT ALL PRIVILEGES ON hacklab_dev.*  TO 'hacklab'@'localhost', 'hacklab'@'127.0.0.1';
GRANT ALL PRIVILEGES ON hacklab_test.* TO 'hacklab'@'localhost', 'hacklab'@'127.0.0.1';

-- O MySQL 8 vem com o log binário ligado: sem isto, só o root consegue criar os triggers das migrations
-- ("You do not have the SUPER privilege and binary logging is enabled"). PERSIST mantém após reiniciar.
SET PERSIST log_bin_trust_function_creators = 1;

FLUSH PRIVILEGES;

SELECT VERSION() AS versao_mysql,
       (SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name IN ('hacklab_dev', 'hacklab_test')) AS bancos_criados;
