# HackLab — confere se este computador está pronto para o desenvolvimento local (Windows, sem Docker).
#
#   powershell -ExecutionPolicy Bypass -File scripts\windows\verificar-ambiente.ps1
#
# Só lê informações: não instala, não altera nada e não conecta em nenhum banco.

$ok = $true
function Item($nome, $passou, $detalhe) {
    $marca = if ($passou) { '[OK]  ' } else { '[FALTA]' }
    Write-Host ("{0} {1,-28} {2}" -f $marca, $nome, $detalhe)
    if (-not $passou) { $script:ok = $false }
}
function Ou($valor, $padrao) {
    if ($null -ne $valor -and "$valor" -ne '') { $valor } else { $padrao }
}
function Versao($comando, $argumento) {
    try { (& $comando $argumento 2>$null | Select-Object -First 1) -as [string] } catch { $null }
}

Write-Host "`n== Programas" -ForegroundColor Cyan
$git = Versao 'git' '--version'
Item 'Git' ($null -ne $git) (Ou $git 'não encontrado no PATH')

$php = Versao 'php' '-v'
$phpOk = $false
if ($php -match 'PHP (\d+)\.(\d+)\.(\d+)') { $phpOk = ([int]$Matches[1] -gt 8) -or ([int]$Matches[1] -eq 8 -and [int]$Matches[2] -ge 2) }
Item 'PHP 8.2 ou maior' $phpOk (Ou $php 'não encontrado no PATH')

$composer = Versao 'composer' '--version'
Item 'Composer 2' ($composer -match 'Composer version 2') (Ou $composer 'não encontrado no PATH')

$node = Versao 'node' '-v'
$nodeOk = $node -match 'v(\d+)\.' -and [int]$Matches[1] -ge 20
Item 'Node.js 20 ou maior (22 rec.)' $nodeOk (Ou $node 'não encontrado no PATH')

$npm = Versao 'npm' '-v'
Item 'npm' ($null -ne $npm) (Ou $npm 'não encontrado no PATH')

Write-Host "`n== Extensões do PHP" -ForegroundColor Cyan
$modulos = @()
if ($php) { $modulos = (php -m) | ForEach-Object { $_.Trim().ToLower() } }
foreach ($ext in 'pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'curl', 'zip', 'bcmath', 'tokenizer', 'xml', 'ctype') {
    Item "extensão $ext" ($modulos -contains $ext) $(if ($modulos -contains $ext) { 'ativa' } else { "ative no php.ini (extension=$ext)" })
}
Write-Host ("[info]  {0,-28} {1}" -f 'extensão intl (opcional)', $(if ($modulos -contains 'intl') { 'ativa' } else { 'desativada (não é obrigatória)' }))

Write-Host "`n== MySQL Server 8" -ForegroundColor Cyan
$servicos = Get-Service -ErrorAction SilentlyContinue | Where-Object { $_.Name -match 'mysql|maria' }
foreach ($s in $servicos) { Write-Host ("[info]  serviço {0,-20} {1}" -f $s.Name, $s.Status) }
$mysql = Get-Command mysql -ErrorAction SilentlyContinue
if (-not $mysql) {
    $padrao = Get-ChildItem 'C:\Program Files\MySQL\MySQL Server 8*\bin\mysql.exe' -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($padrao) { Write-Host "[info]  mysql.exe fora do PATH; use: & `"$($padrao.FullName)`"" }
}
$mysqld = Get-ChildItem 'C:\Program Files\MySQL\MySQL Server 8*\bin\mysqld.exe' -ErrorAction SilentlyContinue | Select-Object -First 1
if ($mysqld) {
    $v = (& $mysqld.FullName --version) -as [string]
    $vOk = $v -match '(\d+)\.(\d+)\.(\d+)' -and ([version]"$($Matches[1]).$($Matches[2]).$($Matches[3])" -ge [version]'8.0.19') -and ($v -notmatch 'MariaDB')
    Item 'MySQL Server 8.0.19+' $vOk $v
} else {
    Item 'MySQL Server 8.0.19+' $false 'não encontrado em C:\Program Files\MySQL (instale o MySQL Community Server 8)'
}
$xamppMaria = Get-Process mysqld -ErrorAction SilentlyContinue | Where-Object { $_.Path -match 'xampp' }
Item 'MariaDB do XAMPP desligado' (-not $xamppMaria) $(if ($xamppMaria) { 'PARE o MySQL no painel do XAMPP: ele ocupa a porta 3306' } else { 'ok' })

Write-Host "`n== Portas" -ForegroundColor Cyan
foreach ($porta in @(@{n = 3306; uso = 'MySQL Server 8' }, @{n = 8000; uso = 'Laravel (php artisan serve)' }, @{n = 5174; uso = 'Vue (npm run dev)' })) {
    $con = Get-NetTCPConnection -LocalPort $porta.n -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
    $dono = if ($con) { (Get-Process -Id $con.OwningProcess -ErrorAction SilentlyContinue).ProcessName } else { $null }
    Write-Host ("[info]  porta {0,-5} {1,-28} {2}" -f $porta.n, $porta.uso, $(if ($dono) { "em uso por $dono" } else { 'livre' }))
}

Write-Host ''
if ($ok) { Write-Host 'Tudo pronto. Siga o README a partir de "Instalação".' -ForegroundColor Green }
else { Write-Host 'Corrija os itens [FALTA] (veja "Solução de problemas" no README) e rode de novo.' -ForegroundColor Yellow; exit 1 }
