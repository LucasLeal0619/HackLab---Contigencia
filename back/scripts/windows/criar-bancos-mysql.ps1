# HackLab — cria (ou atualiza) os bancos e o usuário do MySQL local. Na raiz do projeto:
#
#   powershell -ExecutionPolicy Bypass -File back\scripts\windows\criar-bancos-mysql.ps1
#
# Pede a senha do usuário hacklab sem mostrá-la (não fica no histórico do PowerShell nem na linha de comando) e
# depois o próprio MySQL pede a senha do administrador do MySQL (root). Pode rodar de novo: nada é duplicado e a
# senha do hacklab passa a ser a informada agora (serve para recuperar o acesso).
#
# Opções: -Porta 3307 (MySQL em outra porta), -Admin nome (administrador do MySQL que não é o root),
#         -AdminSemSenha (root sem senha, ex.: MySQL ZIP recém-inicializado), -Mysql caminho\mysql.exe.

param(
    [int]$Porta = 3306,
    [string]$Admin = 'root',
    [string]$Mysql,
    [switch]$AdminSemSenha,
    [System.Security.SecureString]$Senha
)

$ErrorActionPreference = 'Stop'

function Texto([System.Security.SecureString]$s) {
    $b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s)
    try { [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b) }
}

if (-not $Mysql) {
    $cmd = Get-Command mysql.exe -ErrorAction SilentlyContinue
    if ($cmd) {
        $Mysql = $cmd.Source
    } else {
        $Mysql = Get-ChildItem 'C:\Program Files\MySQL\MySQL Server *\bin\mysql.exe' -ErrorAction SilentlyContinue |
            Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName
    }
}
if (-not $Mysql -or -not (Test-Path $Mysql)) {
    Write-Host 'mysql.exe não encontrado. Informe o caminho: -Mysql "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe"' -ForegroundColor Red
    exit 1
}

if (-not $Senha) {
    $Senha = Read-Host 'Senha para o usuário hacklab (a mesma que vai em DB_PASSWORD no back\.env)' -AsSecureString
    $repetida = Read-Host 'Repita a senha' -AsSecureString
    if ((Texto $Senha) -cne (Texto $repetida)) {
        Write-Host 'As senhas não conferem. Nada foi alterado.' -ForegroundColor Red
        exit 1
    }
}
$senhaApp = Texto $Senha
# Sem acentos, espaços, aspas, # ou $: passa igual pelo PowerShell, pelo mysql.exe e pelo back\.env (sem aspas).
if ($senhaApp -cnotmatch '^[A-Za-z0-9._@!%+=-]{8,}$') {
    Write-Host 'Use pelo menos 8 caracteres, só letras sem acento, números e . _ - @ ! % + =. Nada foi alterado.' -ForegroundColor Red
    exit 1
}

$arquivo = Join-Path $PSScriptRoot 'criar-bancos-mysql.sql'
$sql = [IO.File]::ReadAllText($arquivo, [Text.Encoding]::UTF8).Replace('TROQUE_ESTA_SENHA', $senhaApp)
$senhaApp = $null

$argumentos = @('--protocol=TCP', '-h', '127.0.0.1', '-P', "$Porta", '-u', $Admin, '--default-character-set=utf8mb4', '--table')
if (-not $AdminSemSenha) { $argumentos += '-p' }

Write-Host "MySQL em 127.0.0.1:$Porta, como '$Admin'. Se pedir 'Enter password', é a senha do administrador do MySQL."
# O SQL (com a senha) vai pela entrada padrão, nunca pela linha de comando.
$OutputEncoding = New-Object System.Text.UTF8Encoding $false
$sql | & $Mysql @argumentos
$codigo = $LASTEXITCODE
$sql = $null

if ($codigo -ne 0) {
    Write-Host ''
    Write-Host 'O MySQL recusou o script (mensagem acima). Causas comuns:' -ForegroundColor Red
    Write-Host '  - "Access denied for user ''root''": senha do administrador do MySQL errada;'
    Write-Host '  - "Access denied; you need ... privilege": a conta não é administradora do MySQL. Peça à TI para rodar'
    Write-Host '    este script (ela digita a senha do root) ou use -Admin com uma conta que tenha CREATE USER e SYSTEM_VARIABLES_ADMIN;'
    Write-Host '  - "Can''t connect": o serviço do MySQL está parado ou em outra porta (-Porta).'
    exit $codigo
}

Write-Host ''
Write-Host 'Pronto. Coloque a mesma senha em DB_PASSWORD no back\.env e rode: php artisan hacklab:db-check' -ForegroundColor Green
