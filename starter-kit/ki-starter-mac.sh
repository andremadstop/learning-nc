#!/usr/bin/env bash
set -euo pipefail

# ============================================================
#  KI-Starter — macOS Setup
#  Installiert ein KI-gestuetztes Lernsystem auf macOS
#  Getestet auf: macOS 13+ (Ventura, Sonoma, Sequoia)
# ============================================================

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

info()  { echo -e "${CYAN}[INFO]${NC} $1"; }
ok()    { echo -e "${GREEN}[OK]${NC} $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }

echo ""
echo "============================================"
echo "  KI-Starter — Dein Lernsystem einrichten"
echo "  macOS Edition"
echo "============================================"
echo ""

echo "Welche Stufe möchtest du einrichten?"
echo ""
echo "  1) Vault       — Obsidian Wissensvault (5 Min, empfohlen)"
echo "  2) Power User  — Vault + Ollama + Fabric (15 Min)"
echo "  3) Full Stack  — Alles + Claude Code (30 Min, braucht API-Key)"
echo ""
read -rp "Deine Wahl [1/2/3]: " STUFE
STUFE=${STUFE:-1}

INSTALL_DIR="${HOME}/Wissensvault"

# ─── Homebrew ──────────────────────────────────────────────
ensure_brew() {
    if command -v brew &>/dev/null; then
        ok "Homebrew ist installiert"
    else
        info "Homebrew installieren..."
        /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
        # Apple Silicon Path
        if [ -f /opt/homebrew/bin/brew ]; then
            eval "$(/opt/homebrew/bin/brew shellenv)"
        fi
        ok "Homebrew installiert"
    fi
}

# ─── Stufe 1: Vault ───────────────────────────────────────
info "Stufe 1: Obsidian Wissensvault einrichten..."

ensure_brew

# Obsidian
if [ -d "/Applications/Obsidian.app" ] || brew list --cask obsidian &>/dev/null 2>&1; then
    ok "Obsidian ist bereits installiert"
else
    info "Obsidian installieren..."
    brew install --cask obsidian
    ok "Obsidian installiert"
fi

# Vault
if [ -d "$INSTALL_DIR" ]; then
    warn "Vault existiert bereits in $INSTALL_DIR"
else
    info "Vault-Ordner erstellen..."
    mkdir -p "$INSTALL_DIR"

    if [ -d "${HOME}/Nextcloud/Mein-Wissensvault" ]; then
        cp -r "${HOME}/Nextcloud/Mein-Wissensvault/"* "$INSTALL_DIR/"
        ok "Vault aus Nextcloud kopiert"
    else
        cat > "$INSTALL_DIR/README.md" << 'VAULTEOF'
# Mein Wissensvault

Dein Vault-Rohling wird vom Dozenten bereitgestellt.

## Nächste Schritte
1. Frage deinen Dozenten nach dem Vault-Download-Link
2. Entpacke das ZIP in diesen Ordner
3. Öffne den Ordner in Obsidian
VAULTEOF
        warn "Kein Vault-Template gefunden — Platzhalter erstellt"
    fi
fi

ok "Stufe 1 abgeschlossen"

[ "$STUFE" = "1" ] && echo -e "\nFertig! Öffne Obsidian → $INSTALL_DIR" && exit 0

# ─── Stufe 2: Power User ──────────────────────────────────
info "Stufe 2: KI-Tools installieren..."

# Python
if command -v python3 &>/dev/null; then
    ok "Python $(python3 --version | cut -d' ' -f2) gefunden"
else
    brew install python@3.12
fi

# Ollama
if command -v ollama &>/dev/null; then
    ok "Ollama ist bereits installiert"
else
    info "Ollama installieren..."
    brew install ollama
    ok "Ollama installiert"
fi

info "Ollama Modell herunterladen (llama3.2)..."
ollama pull llama3.2 &
OLLAMA_PID=$!

# Fabric
if command -v fabric &>/dev/null; then
    ok "Fabric ist bereits installiert"
else
    info "Fabric installieren..."
    pip3 install fabric-ai 2>/dev/null || python3 -m pip install fabric-ai
    ok "Fabric installiert"
    warn "Bitte 'fabric --setup' ausführen und Ollama als Backend wählen"
fi

wait $OLLAMA_PID 2>/dev/null
ok "Stufe 2 abgeschlossen"

[ "$STUFE" = "2" ] && echo -e "\nFertig! Teste: ollama run llama3.2 'Erkläre Subnetting'" && exit 0

# ─── Stufe 3: Full Stack ──────────────────────────────────
info "Stufe 3: Claude Code + GitHub CLI..."

# Node.js
if command -v node &>/dev/null; then
    ok "Node.js $(node --version) gefunden"
else
    brew install node@20
fi

# Claude Code
if command -v claude &>/dev/null; then
    ok "Claude Code ist bereits installiert"
else
    info "Claude Code installieren..."
    npm install -g @anthropic-ai/claude-code
    ok "Claude Code installiert"
    echo ""
    read -rp "Anthropic API-Key (oder Enter zum Überspringen): " API_KEY
    if [ -n "$API_KEY" ]; then
        echo "export ANTHROPIC_API_KEY=\"$API_KEY\"" >> ~/.zshrc
        ok "API-Key in ~/.zshrc gespeichert"
    fi
fi

# GitHub CLI
if command -v gh &>/dev/null; then
    ok "GitHub CLI ist bereits installiert"
else
    brew install gh
    ok "GitHub CLI installiert"
fi

echo ""
echo "============================================"
echo "  KI-Starter Setup abgeschlossen!"
echo ""
echo "  ✓ Obsidian + Vault"
echo "  ✓ Ollama (llama3.2)"
echo "  ✓ Fabric"
echo "  ✓ Claude Code"
echo "  ✓ GitHub CLI"
echo ""
echo "  Nächste Schritte:"
echo "    1. Neues Terminal öffnen"
echo "    2. Obsidian → $INSTALL_DIR"
echo "    3. fabric --setup"
echo "    4. claude"
echo "============================================"
