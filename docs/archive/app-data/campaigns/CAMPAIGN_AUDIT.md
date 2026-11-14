# Campaign Audit

Stand: 2026-03-31

## Ergebnis

- Geprueft: `25` Kampagnen-JSONs in `app/data/campaigns/`
- Harte Blocker nach Audit: `0`
- Auf `is_legacy: true` gesetzt: `0`

## Reparierte Strukturfehler

Folgende Kampagnen hatten kaputte Szenen-Referenzen durch Umlaut-/ASCII-Mischung in den Scene-IDs. Diese Verweise wurden direkt korrigiert:

- `ki_fluesterer.json`
- `phishing_friday.json`
- `ransomware.json`
- `wannacry.json`

## Warnungen ohne Legacy-Flag

Diese Punkte wurden bewusst nicht als harte Blocker eingestuft:

### Unbenutzte Fail-Epiloge

Die Kampagnen sind spielbar, enthalten aber je einen derzeit nicht erreichten Fail-Epilog:

- `cloud_under_fire.json`
- `compliance_marathon.json`
- `crypto_chaos.json`
- `incident_response_golden_hour.json`
- `phishing_friday.json`
- `zero_trust_migration.json`

### Unbekannte Simulations-Aliase

Diese Kampagnen nutzen Simulations-Typen, die nicht explizit im aktuellen Adventure-Mapper auftauchen und deshalb nur ueber den generischen Fallback laufen:

- `das_erbe.json`: `soho_troubleshooting`
- `der_neue_standort.json`: `cable_test_analysis`, `soho_troubleshooting`
- `ransomware.json`: `static_route_config`

## Fazit

Nach den Daten-Fixes gibt es keine Kampagne in `app/data/campaigns/`, die allein wegen kaputter Verweise oder unerreichbarer Szenen auf `legacy` gesetzt werden musste.
