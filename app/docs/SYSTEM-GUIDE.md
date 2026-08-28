# How a non-programmer built a professional IT system with AI

> A technical briefing — and an invitation to build your own.

## Starting point

Early 2026. No computer-science degree, no programming experience, no company. A Proxmox
server, curiosity, and Claude Code.

Today: 17 VMs and containers, 16+ AI agents, an app published in the Nextcloud App Store, four
Telegram bots for the family, a self-improvement loop that learns from every session, and an
infrastructure a senior DevOps engineer would call solid.

---

## 1. Infrastructure overview

### What runs

- **Proxmox VE** hypervisor with 17 VMs and containers (NixOS, Debian, Alpine)
- **Workstation** (Arch-based, GPU for local LLMs)
- **Relay VPS** (public services over a WireGuard tunnel)
- Three hosts on **NixOS** with declarative, versioned configuration — every change is a git
  commit and can be rolled back at any time

### Services (a selection)

Nextcloud, Home Assistant, n8n (workflow automation), Paperless-NGX (document OCR), Vaultwarden
(password manager), AdGuard (DNS filtering), Jellyfin (media), Audiobookshelf, plus on-demand
game servers.

### Architectural principle

Everything self-hosted. No cloud vendor lock-in. Every service runs in its own container or VM
with clear network segmentation.

---

## 2. AI agent architecture

### Tier 1: development and architecture

| Agent | Role |
|-------|------|
| **Claude Code** | Architecture, code review, security, coordination |
| **Codex** | Bulk implementation, tests, parallel execution |
| **Gemini** | Analysis, transcription, content processing |

The division of labour: Claude plans and reviews, Codex implements autonomously through
formalised handoff documents, Gemini analyses media.

### Tier 2: the family bot platform

One Python codebase, four containers, four personalised Telegram bots with their own personas
and tool sets. Every user gets an assistant cut to fit.

**Technical stack**

- LLM: Gemini 2.5 Flash (primary) plus Ollama (local fallback)
- Memory: SQLite (conversations, learnings, preferences)
- RAG: ChromaDB with local embeddings
- Voice: Gemini TTS with an edge-tts fallback
- Hosting: rootless Podman on NixOS
- Content pipeline: video URL → download → transcription → summary → vault

### Tier 3: automation

| Agent | Function |
|-------|----------|
| Voice pipeline | Voice-message transcription (n8n plus Gemini STT) |
| ScanInbox | Scanner to OCR pipeline |
| KlimaBot | Smart-home automation (mould warnings, ventilation) |
| Healthcheck | SSH check across all services, Telegram alert on failure |

### Tier 4: local (offline-capable)

- **Ollama** with 10+ models, GPU accelerated
- **Whisper ASR** for local speech recognition
- **Stable Diffusion** for image generation

---

## 3. Software development: learning-nc

### What it is

A **Nextcloud app for flashcard learning** with spaced repetition (the Leitner system),
published in the official Nextcloud App Store.

### Tech stack

- Backend: PHP 8.1+, the Nextcloud app framework, PostgreSQL 16
- Frontend: Vue 3.5, Vite
- Hosting: Docker on a dedicated server

### Feature scope

**Core**

- **The Leitner system** with five boxes plus a smart queue
- **Exam mode** with timers, attempts and deadlines
- **A PBQ simulator** for performance-based questions: CLI state machine, SVG topology, drag and drop

**Arena and multiplayer**

- Live duels, game-show modes (sprint, elimination), board-game modes
- A league system with seasons and a leaderboard
- Co-op mode: two to four players solving campaigns together

**Campaign RPG**

- A graph-based narrative system with decisions and consequences
- Three playable campaigns (Security+ scenarios with NPCs, items, reputation)
- Quest map (D3.js), HUD, countdown timer, DauBot (an AI apprentice as a learning mechanic)

**Eight network simulators**

DNS resolver, firewall builder, port scanner, routing table, NAT table, Wireshark-lite, 802.1X
authentication flow, and an advanced subnet calculator.

**VirtuProf, the AI assistant**

- A context-aware tutor with a hint system
- Telos onboarding to build a learning profile
- TTS and STT voice settings across 15 languages

**By the numbers:** 50+ API endpoints, 13+ database tables, 15 services, 100+ Vue components,
2,000+ exam questions.

### Development process

Built entirely with AI. Not a line of PHP or Vue written beforehand.

**Quality gates (a four-gate pyramid)**

1. **Static:** PHPStan level 5, ESLint with zero errors, 1,200+ unit tests, a security scan
2. **API:** automated endpoint tests
3. **Browser:** Playwright end-to-end checks
4. **Release:** PHPUnit plus a manual test protocol of 62 checks

A pre-push hook blocks any code that fails gate 1. Impact analysis via a knowledge graph runs
before every change.

---

## 4. Security concepts

### Principles

- **Secrets only in a password manager or `.env`** — never in code, logs or Markdown
- **SSH with dedicated keys** per purpose (work, mounts, emergency); `IdentitiesOnly` prevents
  key leakage
- **DNS filtering** plus a VPN tunnel plus a reverse proxy with TLS for every service
- **Firewall** allowing only LAN and VPN access to management interfaces
- **Backups** deduplicated and encrypted (restic), automated with alerting

### Application security

- Rate limiting, CSRF protection from the framework, prepared statements throughout
- Access control: ownership and share checks in every service
- Optimistic locking against race conditions
- Three external security reviews

### Privacy

- Children's data carries a TTL; logs are redacted
- Personal data encrypted (AES-256-GCM)
- No cloud upload — everything self-hosted

---

## 5. Knowledge management

### Obsidian as the central knowledge base

- Hundreds of Markdown files in one vault
- Real-time sync across all devices (CouchDB plus LiveSync)
- Glossary, recipes, a food encyclopedia — all structured with front matter
- Family members have their own vaults; bots write into them automatically

### Learning path

CompTIA Network+ → Security+ → CySA+ → LPIC, with the homelab as the practical laboratory, and
the app's own question pools (2,000+ questions) as the study material.

---

## 6. A self-improving system

### The ISC workflow (Ideal State Criteria)

Before every non-trivial task:

1. **OBSERVE** — translate the goal into binary, testable criteria
2. **BUILD** — implement
3. **VERIFY** — tick off every criterion explicitly
4. **LEARN** — rate the session and record a signal

### Signals into steering rules

Every session is rated from 1 to 10. Once enough entries accumulate, the patterns are
synthesised into concrete rules that feed into future sessions. The system gets smarter with use.

### Formalised project management

Roadmap → milestones → phases → plans → tasks, with specialised sub-agents (researcher, planner,
executor, verifier), atomic commits, state tracking and deviation handling.

---

## 7. What the AI actually did

### Replaced

- Backend developer, frontend developer, DevOps engineer
- Security auditor, technical writer, sysadmin

### Did not replace

- **Architectural decisions** — the AI proposes, a human decides
- **Product vision** — what gets built comes from the user
- **Judgement of quality** — "is this good enough?" stays human
- **Risk assessment** — destructive actions need approval

### The real lever

Not "AI writes code", but: **AI multiplies the speed of learning.**

Every problem solved becomes knowledge. Every pattern becomes a rule. Every rule makes the next
session better. After 50-odd sessions the system knows more than any single session could.

This is not vibe coding. It is accumulated, structured, verifiable system knowledge.

---

## 8. Building your own

### What you need

- A computer (Linux, Windows with WSL, or a Mac)
- A Claude Code account, or another AI coding tool
- Obsidian (free) for knowledge management
- Curiosity, and a willingness to treat mistakes as material to learn from

### The core in three sentences

1. **The Obsidian vault is the memory.** Everything you learn, build and decide gets written
   down in Markdown. That is your persistent knowledge, and it grows across sessions.
2. **The AI is a multiplier.** You set the direction, the AI implements — but you have to
   understand and check the results. Trust, then verify.
3. **The feedback loop compounds.** Every session makes you better: signals, corrections and
   patterns are stored and feed into the next one. After 50 sessions you are an order of
   magnitude faster than at the start.

### First steps

1. **Install Obsidian.** Create a vault, write the first notes: what do you want to build, and
   what can you already do?
2. **Set up Claude Code.** Write a CLAUDE.md — who you are, what the rules are, where the code
   lives.
3. **Start something small.** Not "I'm building an app", but "I'm learning how one API endpoint
   works".
4. **Thirty minutes a day.** Consistency beats intensity: half an hour daily beats eight hours
   once a week.

### What this system is not

- Not a substitute for understanding fundamentals — you have to know WHAT you are building, even
  when the AI handles the HOW
- Not a magic button — it takes weeks before the feedback loop starts to pay
- Not a solo project — community, mentors and peers are indispensable

---

*Written 2026-03-26, translated and refreshed 2026-08-28. Created with Claude Code, verified by
the author. The stack and test figures reflect the state at the time of the refresh; the
CHANGELOG is authoritative.*
