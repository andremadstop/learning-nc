#!/usr/bin/env bash
set -euo pipefail

# ============================================================
#  KI-Starter — Linux Setup
#  Installiert ein KI-gestuetztes Lernsystem auf Linux
#  Getestet auf: Ubuntu 22.04+, Debian 12+, Arch/CachyOS
# ============================================================

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
NC='\033[0m'

info()  { echo -e "${CYAN}[INFO]${NC} $1"; }
ok()    { echo -e "${GREEN}[OK]${NC} $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
fail()  { echo -e "${RED}[FAIL]${NC} $1"; }

echo ""
echo "============================================"
echo "  KI-Starter — Dein Lernsystem einrichten"
echo "  Linux Edition"
echo "============================================"
echo ""

# ─── Stufe auswählen ──────────────────────────────────────
echo "Welche Stufe möchtest du einrichten?"
echo ""
echo "  1) Vault       — Obsidian Wissensvault (5 Min, empfohlen)"
echo "  2) Power User  — Vault + Ollama + Fabric (15 Min)"
echo "  3) Full Stack  — Alles + Claude Code (30 Min, braucht API-Key)"
echo ""
read -rp "Deine Wahl [1/2/3]: " STUFE
STUFE=${STUFE:-1}

INSTALL_DIR="${HOME}/Wissensvault"

# ─── Paketmanager erkennen ─────────────────────────────────
detect_pm() {
    if command -v apt-get &>/dev/null; then
        echo "apt"
    elif command -v pacman &>/dev/null; then
        echo "pacman"
    elif command -v dnf &>/dev/null; then
        echo "dnf"
    else
        echo "unknown"
    fi
}

install_pkg() {
    local pkg="$1"
    local pm=$(detect_pm)
    case "$pm" in
        apt)    sudo apt-get install -y "$pkg" 2>/dev/null ;;
        pacman) sudo pacman -S --noconfirm "$pkg" 2>/dev/null ;;
        dnf)    sudo dnf install -y "$pkg" 2>/dev/null ;;
        *)      warn "Paketmanager nicht erkannt — bitte $pkg manuell installieren" ;;
    esac
}

# ─── Stufe 1: Vault ───────────────────────────────────────
info "Stufe 1: Obsidian Wissensvault einrichten..."

# Obsidian prüfen/installieren
if command -v obsidian &>/dev/null || [ -f /usr/bin/obsidian ] || ls ~/.local/bin/obsidian* &>/dev/null 2>&1; then
    ok "Obsidian ist bereits installiert"
else
    info "Obsidian installieren..."
    if command -v flatpak &>/dev/null; then
        flatpak install -y flathub md.obsidian.Obsidian 2>/dev/null && ok "Obsidian via Flatpak installiert"
    elif command -v snap &>/dev/null; then
        sudo snap install obsidian --classic 2>/dev/null && ok "Obsidian via Snap installiert"
    else
        OBSIDIAN_URL="https://github.com/obsidianmd/obsidian-releases/releases/latest/download/Obsidian-1.8.9.AppImage"
        info "Lade Obsidian AppImage herunter..."
        mkdir -p ~/.local/bin
        curl -fsSL "$OBSIDIAN_URL" -o ~/.local/bin/Obsidian.AppImage 2>/dev/null
        chmod +x ~/.local/bin/Obsidian.AppImage
        ok "Obsidian AppImage in ~/.local/bin/"
    fi
fi

# Vault herunterladen
if [ -d "$INSTALL_DIR" ]; then
    warn "Vault existiert bereits in $INSTALL_DIR — überspringe Download"
else
    info "Vault-Template herunterladen..."
    # Option 1: Von Nextcloud Share-Link (wenn verfügbar)
    # curl -fsSL "https://deine-nextcloud.de/s/SHARE_TOKEN/download" -o /tmp/vault.zip

    # Option 2: Lokale Kopie erstellen (für DevCloud-User)
    if [ -d "${HOME}/Nextcloud/Mein-Wissensvault" ]; then
        cp -r "${HOME}/Nextcloud/Mein-Wissensvault" "$INSTALL_DIR"
        ok "Vault aus Nextcloud kopiert"
    else
        mkdir -p "$INSTALL_DIR"
        cat > "$INSTALL_DIR/README.md" << 'VAULTEOF'
# Mein Wissensvault

Dein Vault-Rohling wird vom Dozenten bereitgestellt.

## Nächste Schritte
1. Frage deinen Dozenten nach dem Vault-Download-Link
2. Entpacke das ZIP in diesen Ordner
3. Öffne den Ordner in Obsidian
VAULTEOF
        warn "Kein Vault-Template gefunden — Platzhalter erstellt in $INSTALL_DIR"
        warn "Frage deinen Dozenten nach dem Download-Link"
    fi
fi

ok "Stufe 1 abgeschlossen: Vault bereit in $INSTALL_DIR"

# Stufe 1 fertig?
if [ "$STUFE" = "1" ]; then
    echo ""
    echo "============================================"
    echo "  Fertig! Öffne Obsidian und wähle:"
    echo "  '$INSTALL_DIR' als Vault-Ordner"
    echo "============================================"
    exit 0
fi

# ─── Stufe 2: Power User ──────────────────────────────────
info "Stufe 2: KI-Tools installieren..."

# Python prüfen
if command -v python3 &>/dev/null; then
    PYTHON_VER=$(python3 --version 2>&1 | cut -d' ' -f2)
    ok "Python $PYTHON_VER gefunden"
else
    info "Python3 installieren..."
    install_pkg python3
    install_pkg python3-pip
fi

# pip prüfen
if ! command -v pip3 &>/dev/null && ! python3 -m pip --version &>/dev/null 2>&1; then
    install_pkg python3-pip
fi

# Ollama
if command -v ollama &>/dev/null; then
    ok "Ollama ist bereits installiert"
else
    info "Ollama installieren..."
    curl -fsSL https://ollama.ai/install.sh | sh
    ok "Ollama installiert"
fi

info "Ollama Modell herunterladen (llama3.2, ~2GB)..."
ollama pull llama3.2 2>/dev/null &
OLLAMA_PID=$!
info "Download läuft im Hintergrund (PID $OLLAMA_PID)"

# Fabric
if command -v fabric &>/dev/null; then
    ok "Fabric ist bereits installiert"
else
    info "Fabric installieren..."
    pip3 install --user fabric-ai 2>/dev/null || python3 -m pip install --user fabric-ai 2>/dev/null
    ok "Fabric installiert"
    info "Fabric einrichten (wähle 'Ollama' als Backend)..."
    echo ""
    warn "Bitte führe 'fabric --setup' aus und wähle Ollama als Backend"
fi

# Warten auf Ollama-Download
if kill -0 $OLLAMA_PID 2>/dev/null; then
    info "Warte auf Ollama-Download..."
    wait $OLLAMA_PID 2>/dev/null
fi
ok "Ollama llama3.2 bereit"

ok "Stufe 2 abgeschlossen: Ollama + Fabric installiert"

if [ "$STUFE" = "2" ]; then
    echo ""
    echo "============================================"
    echo "  Fertig! Teste mit:"
    echo "  ollama run llama3.2 'Erkläre Subnetting'"
    echo "  echo 'Was ist OSPF?' | fabric -p explain_concept"
    echo "============================================"
    exit 0
fi

# ─── Stufe 3: Full Stack ──────────────────────────────────
info "Stufe 3: Claude Code + GitHub CLI installieren..."

# Node.js prüfen
if command -v node &>/dev/null; then
    NODE_VER=$(node --version)
    ok "Node.js $NODE_VER gefunden"
else
    info "Node.js installieren..."
    if command -v apt-get &>/dev/null; then
        curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
        sudo apt-get install -y nodejs
    elif command -v pacman &>/dev/null; then
        sudo pacman -S --noconfirm nodejs npm
    else
        warn "Bitte Node.js 20+ manuell installieren: https://nodejs.org"
    fi
fi

# Claude Code
if command -v claude &>/dev/null; then
    ok "Claude Code ist bereits installiert"
else
    info "Claude Code installieren..."
    npm install -g @anthropic-ai/claude-code 2>/dev/null
    ok "Claude Code installiert"
    echo ""
    warn "Du brauchst einen Anthropic API-Key."
    warn "Erstelle einen auf: https://console.anthropic.com"
    echo ""
    read -rp "API-Key eingeben (oder Enter zum Überspringen): " API_KEY
    if [ -n "$API_KEY" ]; then
        echo "export ANTHROPIC_API_KEY=\"$API_KEY\"" >> ~/.bashrc
        echo "export ANTHROPIC_API_KEY=\"$API_KEY\"" >> ~/.zshrc 2>/dev/null
        ok "API-Key gespeichert in ~/.bashrc"
    else
        warn "Übersprungen — setze ANTHROPIC_API_KEY später in deiner Shell-Config"
    fi
fi

# GitHub CLI
if command -v gh &>/dev/null; then
    ok "GitHub CLI ist bereits installiert"
else
    info "GitHub CLI installieren..."
    if command -v apt-get &>/dev/null; then
        curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | sudo dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg 2>/dev/null
        echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | sudo tee /etc/apt/sources.list.d/github-cli.list > /dev/null
        sudo apt-get update -qq && sudo apt-get install -y gh
    elif command -v pacman &>/dev/null; then
        sudo pacman -S --noconfirm github-cli
    else
        warn "Bitte gh manuell installieren: https://cli.github.com"
    fi
    ok "GitHub CLI installiert"
fi

echo ""
echo "============================================"
echo "  KI-Starter Setup abgeschlossen!"
echo ""
echo "  Installiert:"
echo "    ✓ Obsidian Wissensvault"
echo "    ✓ Ollama (llama3.2)"
echo "    ✓ Fabric (200+ KI-Patterns)"
echo "    ✓ Claude Code"
echo "    ✓ GitHub CLI"
echo ""
echo "  Nächste Schritte:"
echo "    1. Obsidian öffnen → $INSTALL_DIR"
echo "    2. fabric --setup (Ollama als Backend)"
echo "    3. claude (im Terminal starten)"
echo "============================================"
