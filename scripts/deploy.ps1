#Requires -Version 5.1
<#
.SYNOPSIS
    Деплой CasesBot на сервер vm-docker-ba: синхронизация кода, сборка образа, пересоздание контейнера.

.DESCRIPTION
    Упаковывает исходники локального репозитория (без .git, .env, presentations/, storage/,
    vendor/), заливает на сервер, синхронизирует поверх /home/user/cases-bot, пересобирает образ
    cases-bot:latest и пересоздаёт контейнер с прежними параметрами (порты 8080/8081,
    bind-mounts, --env-file .env). Данные и секреты на сервере не трогаются. После деплоя на
    сервере автоматически пересобираются публичные копии презентаций (presentations-public/,
    bind-mount — именно их отдаёт :8080, сырые исходники с заметками наружу не выходят).
    Предыдущий образ сохраняется как cases-bot:previous — откат:
        ssh cases-deploy "docker rm -f cases-bot && docker run -d --name cases-bot --restart unless-stopped -p 8080:8080 -p 8081:8081 -v /home/user/cases-bot/presentations:/app/presentations -v /home/user/cases-bot/presentations-public:/app/presentations-public -v /home/user/cases-bot/storage/catalog:/app/storage/catalog --env-file /home/user/cases-bot/.env cases-bot:previous"

.PARAMETER SkipBuild
    Не пересобирать образ: только синхронизация файлов и пересоздание контейнера.
    Пригодится, когда менялся только .env или нужен простой рестарт.

.PARAMETER SshHost
    Алиас/host для ssh (по умолчанию cases-deploy, см. ~/.ssh/config).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\scripts\deploy.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\scripts\deploy.ps1 -SkipBuild
#>
[CmdletBinding()]
param(
    [string]$SshHost = 'cases-deploy',
    [string]$RemoteDir = '/home/user/cases-bot',
    [string]$Image = 'cases-bot:latest',
    [string]$Container = 'cases-bot',
    [switch]$SkipBuild,
    # Доп. аргументы docker build, напр. (когда Docker Hub недоступен, а базовые
    # образы уже вытянуты на сервер, напр. с зеркала mirror.gcr.io и тегированы):
    #   -BuildExtra '--build-context composer=docker-image://composer:2'
    [string]$BuildExtra = ''
)

$ErrorActionPreference = 'Stop'
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch {}

$RepoRoot = Split-Path -Parent $PSScriptRoot
$LocalRemoteScript = Join-Path $PSScriptRoot 'remote-deploy.sh'
$RemoteTarball = '/tmp/casesbot-sync.tar.gz'
$RemoteDeployScript = '/tmp/casesbot-remote-deploy.sh'
$LocalTarball = Join-Path ([System.IO.Path]::GetTempPath()) 'casesbot-sync.tar.gz'
$LocalRemoteScriptLf = Join-Path ([System.IO.Path]::GetTempPath()) 'casesbot-remote-deploy.sh'

function Invoke-Checked {
    param([scriptblock]$Action, [string]$What)
    & $Action
    if ($LASTEXITCODE -ne 0) { throw "$What (код $LASTEXITCODE)" }
}

foreach ($tool in @('ssh', 'scp', 'tar')) {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) { throw "Не найден '$tool' в PATH" }
}
if (-not (Test-Path $LocalRemoteScript)) { throw "Не найден $LocalRemoteScript" }

Write-Host "==> Проверка доступа к $SshHost ..." -ForegroundColor Cyan
Invoke-Checked { ssh -o BatchMode=yes $SshHost 'true' } "ssh $SshHost недоступен"

Write-Host "==> Упаковка исходников (без .git, .env, presentations, storage, vendor) ..." -ForegroundColor Cyan
Remove-Item $LocalTarball -ErrorAction SilentlyContinue
Invoke-Checked {
    tar -czf $LocalTarball -C $RepoRoot `
        --exclude=./.git `
        --exclude=./.claude `
        --exclude=./.env `
        --exclude=./presentations `
        --exclude=./presentations-public `
        --exclude=./storage `
        --exclude=./vendor `
        --exclude=./node_modules `
        .
} "упаковка tar"

# remote-deploy.sh должен ехать с Unix-переводами строк (LF) и без BOM, иначе sh ругается на \r.
Write-Host "==> Подготовка серверного скрипта ..." -ForegroundColor Cyan
$content = [System.IO.File]::ReadAllText($LocalRemoteScript) -replace "`r`n", "`n" -replace "`r", "`n"
[System.IO.File]::WriteAllText($LocalRemoteScriptLf, $content, (New-Object System.Text.UTF8Encoding($false)))

Write-Host "==> Загрузка на сервер ..." -ForegroundColor Cyan
Invoke-Checked { scp $LocalTarball "${SshHost}:$RemoteTarball" } "scp tarball"
Invoke-Checked { scp $LocalRemoteScriptLf "${SshHost}:$RemoteDeployScript" } "scp deploy script"

$skip = if ($SkipBuild) { '1' } else { '0' }
$modeLabel = if ($SkipBuild) { 'без сборки образа' } else { 'со сборкой образа' }
Write-Host "==> Сервер: синхронизация и деплой ($modeLabel) ..." -ForegroundColor Cyan
$remoteCmd = "REMOTE_DIR=$RemoteDir IMAGE=$Image CONTAINER=$Container TARBALL=$RemoteTarball SKIP_BUILD=$skip BUILD_EXTRA='$BuildExtra' sh $RemoteDeployScript"
Invoke-Checked { ssh $SshHost $remoteCmd } "удалённый деплой"

Remove-Item $LocalTarball, $LocalRemoteScriptLf -ErrorAction SilentlyContinue
Write-Host "==> Готово." -ForegroundColor Green
