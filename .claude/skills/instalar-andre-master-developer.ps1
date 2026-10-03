# Instala (ou atualiza) a skill AndreMasterDeveloper no Claude Code deste computador.
# Cole esta linha no PowerShell (nao precisa ser administrador):
#   irm https://raw.githubusercontent.com/andrefelippeadfarias/andrefelippeadfarias/claude/determined-bardeen-tlp9wg/.claude/skills/instalar-andre-master-developer.ps1 | iex
#
# A skill vai para %USERPROFILE%\.claude\skills\andre-master-developer e passa a valer em
# todos os projetos. Rodar de novo substitui a versao instalada pela mais nova do GitHub.

& {
    $ErrorActionPreference = 'Stop'
    $ProgressPreference = 'SilentlyContinue'  # a barra de progresso deixa o download muito mais lento
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12

    $nome = 'andre-master-developer'
    $repo = 'andrefelippeadfarias/andrefelippeadfarias'
    # Primeiro o ramo principal (onde a skill fica depois do merge); se ainda nao estiver la, o ramo do PR.
    $ramos = @('claude/pricelabs-hotel-occupancy-ku6zn5', 'claude/determined-bardeen-tlp9wg')
    $skills = Join-Path (Join-Path $HOME '.claude') 'skills'
    $destino = Join-Path $skills $nome
    $marca = "/.claude/skills/$nome/"

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $tmp = Join-Path ([IO.Path]::GetTempPath()) ('amd-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
    New-Item -ItemType Directory -Path $tmp | Out-Null
    try {
        $origem = $null
        foreach ($ramo in $ramos) {
            $zip = Join-Path $tmp 'repo.zip'
            Write-Host "Baixando do GitHub (ramo $ramo)..."
            try {
                Invoke-WebRequest -Uri "https://codeload.github.com/$repo/zip/refs/heads/$ramo" -OutFile $zip -UseBasicParsing
            } catch {
                Write-Host '  ramo indisponivel, tentando o proximo.'
                continue
            }
            # Extrai so a pasta da skill: mais rapido e sem esbarrar no limite de caminho do Windows
            $arquivo = [IO.Compression.ZipFile]::OpenRead($zip)
            try {
                $entradas = @($arquivo.Entries | Where-Object { $_.FullName -like "*$marca*" -and $_.Name })
                if (-not ($entradas | Where-Object { $_.Name -eq 'SKILL.md' })) {
                    Write-Host '  a skill ainda nao esta neste ramo.'
                    continue
                }
                $novo = Join-Path $tmp $nome
                foreach ($e in $entradas) {
                    $relativo = $e.FullName.Substring($e.FullName.IndexOf($marca) + $marca.Length)
                    $alvo = Join-Path $novo $relativo
                    New-Item -ItemType Directory -Path (Split-Path $alvo) -Force | Out-Null
                    [IO.Compression.ZipFileExtensions]::ExtractToFile($e, $alvo, $true)
                }
                $origem = $novo
            } finally {
                $arquivo.Dispose()
                Remove-Item $zip -Force -ErrorAction SilentlyContinue
            }
            if ($origem) { break }
        }
        if (-not $origem) {
            throw 'Nao encontrei a skill no GitHub. Confira a internet e cole a linha de novo.'
        }

        New-Item -ItemType Directory -Path $skills -Force | Out-Null
        $atualizacao = Test-Path $destino
        if ($atualizacao) { Remove-Item $destino -Recurse -Force }
        Move-Item $origem $destino

        # Os scripts da skill precisam de Python 3.8+; no Windows o comando costuma ser "py -3"
        $python = $null
        $mapa = Join-Path (Join-Path $destino 'scripts') 'mapa_codigo.py'
        foreach ($cmd in 'py', 'python3', 'python') {
            if (-not (Get-Command $cmd -ErrorAction SilentlyContinue)) { continue }
            $extra = if ($cmd -eq 'py') { @('-3') } else { @() }
            try {
                $versaoOk = & $cmd @extra -c 'import sys; print(int(sys.version_info >= (3, 8)))' 2>$null
            } catch {
                $versaoOk = $null
            }
            if ("$versaoOk".Trim() -ne '1') { continue }
            $teste = & $cmd @extra $mapa (Join-Path $destino 'scripts') --simbolo main 2>&1
            if ($LASTEXITCODE -eq 0 -and "$teste" -match 'def main') {
                $python = (@($cmd) + $extra) -join ' '
                break
            }
        }

        Write-Host ''
        if ($atualizacao) { $acao = 'atualizada' } else { $acao = 'instalada' }
        Write-Host "Pronto! Skill AndreMasterDeveloper $acao em $destino" -ForegroundColor Green
        if ($python) {
            Write-Host "Python encontrado ($python) e scripts testados: ok."
        } else {
            Write-Host 'Aviso: nao achei Python 3.8+. A skill funciona, mas os scripts de economia de tokens precisam dele.' -ForegroundColor Yellow
            Write-Host 'Instale pelo site python.org (marque "Add python.exe to PATH") e cole esta linha de novo para testar.' -ForegroundColor Yellow
        }
        Write-Host 'Abra uma nova sessao do Claude Code e digite /andre-master-developer'
    } finally {
        Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
    }
}
