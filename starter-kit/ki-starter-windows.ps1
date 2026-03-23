# ============================================================
#  KI-Starter — Windows Setup (PowerShell)
#  Installiert ein KI-gestuetztes Lernsystem auf Windows 10/11
# ============================================================

$ErrorActionPreference = "Stop"

function Write-Info  { Write-Host "[INFO] $args" -ForegroundColor Cyan }
function Write-Ok    { Write-Host "[OK]   $args" -ForegroundColor Green }
function Write-Warn  { Write-Host "[WARN] $args" -ForegroundColor Yellow }

Write-Host ""
Write-Host "============================================" -ForegroundColor White
Write-Host "  KI-Starter — Dein Lernsystem einrichten"
Write-Host "  Windows Edition"
Write-Host "============================================" -ForegroundColor White
Write-Host ""

# ─── Stufe auswählen ──────────────────────────────────────
Write-Host "Welche Stufe moechtest du einrichten?"
Write-Host ""
Write-Host "  1) Vault       — Obsidian Wissensvault (5 Min, empfohlen)"
Write-Host "  2) Power User  — Vault + Ollama + Fabric (15 Min)"
Write-Host "  3) Full Stack  — Alles + Claude Code (30 Min, braucht API-Key)"
Write-Host ""
$Stufe = Read-Host "Deine Wahl [1/2/3]"
if (-not $Stufe) { $Stufe = "1" }

$InstallDir = "$env:USERPROFILE\Wissensvault"

# ─── winget prüfen ──────────────────────────────────────
$hasWinget = Get-Command winget -ErrorAction SilentlyContinue
if (-not $hasWinget) {
    Write-Warn "winget nicht gefunden — bitte Windows App Installer aus dem Microsoft Store installieren"
    Write-Warn "https://apps.microsoft.com/detail/9nblggh4nns1"
}

# ─── Stufe 1: Vault ───────────────────────────────────────
Write-Info "Stufe 1: Obsidian Wissensvault einrichten..."

# Obsidian
if (Get-Command obsidian -ErrorAction SilentlyContinue) {
    Write-Ok "Obsidian ist bereits installiert"
} elseif ($hasWinget) {
    Write-Info "Obsidian installieren via winget..."
    winget install --id Obsidian.Obsidian --accept-package-agreements --accept-source-agreements
    Write-Ok "Obsidian installiert"
} else {
    Write-Warn "Bitte Obsidian manuell installieren: https://obsidian.md"
}

# Vault erstellen
if (Test-Path $InstallDir) {
    Write-Warn "Vault existiert bereits in $InstallDir — ueberspringe"
} else {
    Write-Info "Vault-Ordner erstellen..."
    New-Item -ItemType Directory -Path $InstallDir -Force | Out-Null

    # Prüfe ob Nextcloud-Sync vorhanden
    $ncVault = "$env:USERPROFILE\Nextcloud\Mein-Wissensvault"
    if (Test-Path $ncVault) {
        Copy-Item -Path "$ncVault\*" -Destination $InstallDir -Recurse
        Write-Ok "Vault aus Nextcloud kopiert"
    } else {
        @"
# Mein Wissensvault

Dein Vault-Rohling wird vom Dozenten bereitgestellt.

## Naechste Schritte
1. Frage deinen Dozenten nach dem Vault-Download-Link
2. Entpacke das ZIP in diesen Ordner
3. Oeffne den Ordner in Obsidian
"@ | Out-File -FilePath "$InstallDir\README.md" -Encoding UTF8
        Write-Warn "Kein Vault-Template gefunden — Platzhalter erstellt"
        Write-Warn "Frage deinen Dozenten nach dem Download-Link"
    }
}

Write-Ok "Stufe 1 abgeschlossen: Vault bereit in $InstallDir"

if ($Stufe -eq "1") {
    Write-Host ""
    Write-Host "============================================"
    Write-Host "  Fertig! Oeffne Obsidian und waehle:"
    Write-Host "  '$InstallDir' als Vault-Ordner"
    Write-Host "============================================"
    exit 0
}

# ─── Stufe 2: Power User ──────────────────────────────────
Write-Info "Stufe 2: KI-Tools installieren..."

# Python
if (Get-Command python -ErrorAction SilentlyContinue) {
    $pyVer = python --version 2>&1
    Write-Ok "Python gefunden: $pyVer"
} elseif ($hasWinget) {
    Write-Info "Python installieren..."
    winget install --id Python.Python.3.12 --accept-package-agreements --accept-source-agreements
    Write-Ok "Python 3.12 installiert — bitte Terminal neu starten!"
} else {
    Write-Warn "Bitte Python 3.10+ installieren: https://python.org"
}

# Ollama
if (Get-Command ollama -ErrorAction SilentlyContinue) {
    Write-Ok "Ollama ist bereits installiert"
} elseif ($hasWinget) {
    Write-Info "Ollama installieren..."
    winget install --id Ollama.Ollama --accept-package-agreements --accept-source-agreements
    Write-Ok "Ollama installiert"
} else {
    Write-Warn "Bitte Ollama installieren: https://ollama.com/download"
}

Write-Info "Ollama Modell herunterladen (llama3.2, ~2GB)..."
Write-Info "Starte 'ollama pull llama3.2' in neuem Fenster..."
Start-Process -FilePath "ollama" -ArgumentList "pull llama3.2" -NoNewWindow -ErrorAction SilentlyContinue

# Fabric
Write-Info "Fabric installieren..."
try {
    pip install fabric-ai 2>$null
    Write-Ok "Fabric installiert"
    Write-Warn "Bitte 'fabric --setup' ausfuehren und Ollama als Backend waehlen"
} catch {
    Write-Warn "Fabric-Installation fehlgeschlagen — fuehre 'pip install fabric-ai' manuell aus"
}

Write-Ok "Stufe 2 abgeschlossen"

if ($Stufe -eq "2") {
    Write-Host ""
    Write-Host "============================================"
    Write-Host "  Fertig! Teste mit:"
    Write-Host "  ollama run llama3.2 'Erklaere Subnetting'"
    Write-Host "============================================"
    exit 0
}

# ─── Stufe 3: Full Stack ──────────────────────────────────
Write-Info "Stufe 3: Claude Code + GitHub CLI installieren..."

# Node.js
if (Get-Command node -ErrorAction SilentlyContinue) {
    $nodeVer = node --version
    Write-Ok "Node.js $nodeVer gefunden"
} elseif ($hasWinget) {
    Write-Info "Node.js installieren..."
    winget install --id OpenJS.NodeJS.LTS --accept-package-agreements --accept-source-agreements
    Write-Ok "Node.js installiert — bitte Terminal neu starten!"
} else {
    Write-Warn "Bitte Node.js 20+ installieren: https://nodejs.org"
}

# Claude Code
Write-Info "Claude Code installieren..."
try {
    npm install -g @anthropic-ai/claude-code 2>$null
    Write-Ok "Claude Code installiert"

    $apiKey = Read-Host "Anthropic API-Key eingeben (oder Enter zum Ueberspringen)"
    if ($apiKey) {
        [Environment]::SetEnvironmentVariable("ANTHROPIC_API_KEY", $apiKey, "User")
        Write-Ok "API-Key als Umgebungsvariable gespeichert"
    } else {
        Write-Warn "Uebersprungen — setze ANTHROPIC_API_KEY spaeter als Umgebungsvariable"
    }
} catch {
    Write-Warn "Claude Code Installation fehlgeschlagen — braucht Node.js 20+"
}

# GitHub CLI
if (Get-Command gh -ErrorAction SilentlyContinue) {
    Write-Ok "GitHub CLI ist bereits installiert"
} elseif ($hasWinget) {
    winget install --id GitHub.cli --accept-package-agreements --accept-source-agreements
    Write-Ok "GitHub CLI installiert"
}

Write-Host ""
Write-Host "============================================" -ForegroundColor White
Write-Host "  KI-Starter Setup abgeschlossen!"
Write-Host ""
Write-Host "  Installiert:"
Write-Host "    + Obsidian Wissensvault"
Write-Host "    + Ollama (llama3.2)"
Write-Host "    + Fabric (200+ KI-Patterns)"
Write-Host "    + Claude Code"
Write-Host "    + GitHub CLI"
Write-Host ""
Write-Host "  Naechste Schritte:"
Write-Host "    1. Terminal NEU STARTEN (wichtig!)"
Write-Host "    2. Obsidian oeffnen -> $InstallDir"
Write-Host "    3. fabric --setup (Ollama als Backend)"
Write-Host "    4. claude (im Terminal starten)"
Write-Host "============================================" -ForegroundColor White
