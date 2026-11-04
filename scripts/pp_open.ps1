#Requires -Version 5.1
<#
.SYNOPSIS
    COM-оракул PowerPoint: проверяет, что pptx открывается без ремонта (см. docs/pptx-assembly-lessons.md).

.DESCRIPTION
    Открывает файл ReadOnly без окна через позднее связывание (GetType().InvokeMember —
    обходит битую интероп-библиотеку Office, TYPE_E_CANTLOADLIBRARY) и печатает результат:
    «ОТКРЫЛСЯ, слайдов: N», либо «ОШИБКА ОТКРЫТИЯ: <сообщение/HRESULT>».

    Коды ошибок: 0x80070570 — файл повреждён нечитаемо; 0x80CB4404 — нужен ремонт
    («Восстановить»), файл открывается только после ремонта.

.PARAMETER Path
    Путь к проверяемому pptx.

.EXAMPLE
    Get-Process POWERPNT -ErrorAction SilentlyContinue | Stop-Process -Force
    powershell -ExecutionPolicy Bypass -File scripts\pp_open.ps1 -Path .\storage\output\deck.pptx
#>
param(
    [Parameter(Mandatory = $true)]
    [string]$Path
)

if (-not (Test-Path $Path)) {
    Write-Output "ОШИБКА ОТКРЫТИЯ: файл не найден: $Path"
    exit 1
}

$pp = New-Object -ComObject PowerPoint.Application
try {
    $presentations = $pp.GetType().InvokeMember('Presentations', 'GetProperty', $null, $pp, $null)
    $pres = $presentations.GetType().InvokeMember(
        'Open', 'InvokeMethod', $null, $presentations,
        @([string]$Path, [int]-1, [int]0, [int]0)
    )
    $slides = $pres.GetType().InvokeMember('Slides', 'GetProperty', $null, $pres, $null)
    $count = $slides.GetType().InvokeMember('Count', 'GetProperty', $null, $slides, $null)
    Write-Output "ОТКРЫЛСЯ, слайдов: $count"
    $pres.GetType().InvokeMember('Close', 'InvokeMethod', $null, $pres, $null) | Out-Null
} catch {
    $msg = $_.Exception.Message
    if ($_.Exception.InnerException) { $msg = $_.Exception.InnerException.Message }
    Write-Output "ОШИБКА ОТКРЫТИЯ: $msg"
    exit 1
} finally {
    try {
        $pp.GetType().InvokeMember('Quit', 'InvokeMethod', $null, $pp, $null) | Out-Null
    } catch {
        # PowerPoint уже закрывается/не отдал COM — не глушим результат
    }
}
