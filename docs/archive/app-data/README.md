# Files formerly shipped in app/data

These files lay in `app/data/` and went out with every installation, but no code reads them
(text search over lib, src, templates, appinfo and scripts, 2026-11 audit #213). They are kept
here, outside the app package, until it is decided whether to delete them or build on them.

| File | What it is |
|------|------------|
| `kurs-materialien/*.md` | course notes of one homelab course (Network+, Nmap, Wireshark, events) |
| `campaigns/gemini_dialoge.md` | draft dialogues for campaigns |
| `campaigns/CAMPAIGN_AUDIT.md` | campaign audit, state 2026-03-31 |
| `subnetting_exercises.json` | subnetting exercises, no reader and no import format |
| `placement_scenarios.json` | network placement scenarios, no reader |

Moved elsewhere at the same time:

- `subnetting_questions.json` → `app/examples/pools/subnetting-ip-adressierung-n10-009-de.json`
  (importable with `occ learning:import-pool-json` since 5.10.0)
- `ai_security_prompt_injection.json` → `app/examples/pools/ai-security-prompt-injection-de.json`
- `pwa-anleitung.md` → `docs/pwa-anleitung.md`

`appinfo/signature.json` still lists the old paths until the next signing.
