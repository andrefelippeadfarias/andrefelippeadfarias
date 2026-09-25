# Instalador da automacao de precos (PriceLabs + Jev) para Windows.
# Uso: abra o PowerShell (usuario normal, sem administrador) e cole a linha abaixo:
#   irm https://raw.githubusercontent.com/andrefelippeadfarias/andrefelippeadfarias/claude/pricelabs-hotel-occupancy-ku6zn5/automacao-pricelabs/windows/instalar-da-internet.ps1 | iex
# Rodar de novo atualiza o programa e preserva o config.json. As chaves ficam no Gerenciador de Credenciais.

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$RecantoRamo = 'claude/pricelabs-hotel-occupancy-ku6zn5'
$RecantoZip = "https://codeload.github.com/andrefelippeadfarias/andrefelippeadfarias/zip/refs/heads/$RecantoRamo"
$RecantoDestino = if ($env:RECANTO_DESTINO) { $env:RECANTO_DESTINO } else { 'C:\RecantoPrecos' }

function Test-RecantoPython {
    try {
        $saida = & py -3 -c "import sys; print(int(sys.version_info >= (3, 10)))" 2>$null
        return ($LASTEXITCODE -eq 0 -and "$saida".Trim() -eq '1')
    } catch {
        return $false
    }
}

function Install-RecantoPython {
    if (Test-RecantoPython) { Write-Host 'Python ja instalado.'; return }
    if (-not (Get-Command winget -ErrorAction SilentlyContinue)) {
        throw 'Python 3.10+ nao encontrado e o winget nao existe neste Windows. Instale o Python pelo site python.org (marque Add python.exe to PATH) e rode esta linha de novo.'
    }
    Write-Host 'Instalando o Python 3.12 (pode levar alguns minutos)...'
    winget install -e --id Python.Python.3.12 --scope user --accept-package-agreements --accept-source-agreements
    $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')
    if (-not (Test-RecantoPython)) {
        throw 'O Python foi instalado, mas o comando py ainda nao responde. Feche o PowerShell, abra de novo e cole a linha outra vez.'
    }
}

function Find-RecantoPasta([string]$Extraido) {
    $pasta = Get-ChildItem -Path $Extraido -Directory | Select-Object -First 1
    if (-not $pasta) { throw 'Arquivo baixado vazio ou corrompido.' }
    $projeto = Join-Path $pasta.FullName 'automacao-pricelabs'
    if (-not (Test-Path (Join-Path $projeto 'pacing'))) { throw 'Pasta automacao-pricelabs nao encontrada no download.' }
    return $projeto
}

function Copy-RecantoProjeto([string]$Origem, [string]$Destino) {
    if ((Test-Path $Destino) -and (Get-ChildItem -Path $Destino -Force | Select-Object -First 1) -and
        -not (Test-Path (Join-Path (Join-Path $Destino 'pacing') '__init__.py'))) {
        throw "A pasta $Destino ja existe e nao e uma instalacao anterior deste programa. Escolha outra pasta ou esvazie esta."
    }
    New-Item -ItemType Directory -Force -Path $Destino | Out-Null
    $manterConfig = Test-Path (Join-Path $Destino 'config.json')
    # Pastas do programa: trocadas inteiras, para nao sobrar arquivo antigo. Os dados do dono ficam em LOCALAPPDATA.
    foreach ($pasta in @('pacing', 'tests', 'windows', 'schemas', 'docs')) {
        $alvo = Join-Path $Destino $pasta
        if (Test-Path $alvo) { Remove-Item -Path $alvo -Recurse -Force }
    }
    foreach ($item in Get-ChildItem -Path $Origem -Force) {
        if ($item.Name -eq '.thinker-doer') { continue }
        if ($item.Name -eq 'config.json' -and $manterConfig) { continue }
        Copy-Item -Path $item.FullName -Destination $Destino -Recurse -Force
    }
    return $manterConfig
}

function Invoke-RecantoInstalacao {
    Install-RecantoPython
    # Caminho curto: o Windows PowerShell 5.1 nao extrai caminhos com mais de 260 caracteres.
    $tmp = Join-Path ([IO.Path]::GetTempPath()) ('rp' + (Get-Random -Maximum 99999999))
    New-Item -ItemType Directory -Path $tmp | Out-Null
    try {
        $zip = Join-Path $tmp 'p.zip'
        Write-Host 'Baixando o programa...'
        Invoke-WebRequest -Uri $RecantoZip -OutFile $zip -UseBasicParsing
        Expand-Archive -Path $zip -DestinationPath (Join-Path $tmp 'x') -Force
        $origem = Find-RecantoPasta (Join-Path $tmp 'x')
        $manteve = Copy-RecantoProjeto $origem $RecantoDestino
    } finally {
        Remove-Item -Path $tmp -Recurse -Force -ErrorAction SilentlyContinue
    }
    if (Get-Command Unblock-File -ErrorAction SilentlyContinue) {
        Get-ChildItem -Path $RecantoDestino -Recurse -File | Unblock-File
    }
    if ($manteve) { Write-Host 'Atualizado. Seu config.json foi mantido.' } else { Write-Host "Instalado em $RecantoDestino." }
    Write-Host 'Agora o instalar.bat vai pedir as chaves (ao colar, a chave nao aparece na tela; isso e normal).'
    & cmd.exe /c (Join-Path $RecantoDestino 'windows\instalar.bat')
}

if (-not $env:RECANTO_TESTE) { Invoke-RecantoInstalacao }
