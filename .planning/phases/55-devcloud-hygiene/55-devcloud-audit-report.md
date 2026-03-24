# DevCloud Audit Report

> Erstellt: 2026-03-24 | Server: learning-dev (.65), CT 201
> Disk: 32G total, 14G used, 16G available (47% used)

## Zusammenfassung

| Kategorie | Groesse | Bemerkung |
|-----------|---------|-----------|
| User Homes (/home/) | 1.8 GB | Nur User `andre` hat ein Home |
| NC User Data | 173 MB | 12 User-Verzeichnisse |
| NC AppData | 170 MB | Preview-Cache dominiert |
| NC Logs | 1030 MB | nextcloud.log.1 = 1011 MB |
| Dozenten-Material (broecker) | 827 MB | CompTIA PDFs + Materialien |
| **Gesamt identifizierte Redundanz** | **~134 MB** | Ohne Logs: ~134 MB, mit Log-Rotation: ~1145 MB |

## User Home Directories

Nur ein User (`andre`) hat ein Home-Verzeichnis auf learning-dev:

| Verzeichnis | Groesse | Status |
|-------------|---------|--------|
| /home/andre/learning-nc/ | 416 MB | BEHALTEN — aktiver App-Code, Deploy-Quelle |
| /home/andre/memories/ | 503 MB | PRUEFEN — Nextcloud Memories App (Fork-Repo) |
| /home/andre/stas-bundle/ | 5.4 MB | PRUEFEN — alter STAS-Bundle, moeglicherweise obsolet |

### /home/andre/memories/ (503 MB)

Breakdown:
- node_modules/: 438 MB (Build-Abhaengigkeiten)
- js/: 29 MB (Build-Output)
- bin-ext/: 26 MB (exiftool + go-vod Binaries)
- Rest: ~10 MB (src, lib, l10n, etc.)

**Empfehlung:** `node_modules/` kann jederzeit per `npm install` wiederhergestellt werden. Falls das Memories-Projekt nicht aktiv weiterentwickelt wird, koennte das gesamte Verzeichnis geloescht oder auf die Workstation verschoben werden (438 MB node_modules allein).

### /home/andre/stas-bundle/ (5.4 MB)

Alter STAS-Vault-Bundle. Wurde durch die Phase-54-Loesung (Kurs-Materialien shared folder) ersetzt.

**Empfehlung:** LOESCHEN — superseded by NC shared folder approach.

## Nextcloud Data Directories

### Per-User Breakdown

| User | NC Data Total | Mein-Wissensvault | Vorlagen | Sonstiges | Redundant |
|------|---------------|-------------------|----------|-----------|-----------|
| admin | 92 MB | 5.4 MB | - | Learning/ 82 MB, Kursmaterial/ 5.4 MB, Kurs-Materialien/ 132 KB | 10.8 MB |
| broecker | 843 MB | 5.4 MB | 11 MB | Dozenten-Material/ 827 MB | 5.4 MB |
| alexander | 16 MB | 5.4 MB | 11 MB | - | 5.4 MB |
| azad | 16 MB | 5.4 MB | 11 MB | - | 5.4 MB |
| benjamin | 16 MB | 5.4 MB | 11 MB | - | 5.4 MB |
| raja | 16 MB | 5.4 MB | 11 MB | Photos/ 4 KB | 5.4 MB |
| stas | 16 MB | 5.4 MB | 11 MB | - | 5.4 MB |
| andre | 5.4 MB | 5.4 MB | - | Learning/ 16 KB, Photos/ 4 KB | 5.4 MB |
| adaeze | 5.4 MB | 5.4 MB | - | - | 5.4 MB |
| bilal | 5.4 MB | 5.4 MB | - | - | 5.4 MB |
| bilos | 5.4 MB | 5.4 MB | - | - | 5.4 MB |
| sayed | 5.4 MB | 5.4 MB | - | - | 5.4 MB |
| **Gesamt** | **~1053 MB** | **64.8 MB** | **66 MB** | | **~64.8 MB** |

### Redundanzanalyse

#### 1. Mein-Wissensvault (12x 5.4 MB = 64.8 MB) — REDUNDANT

Jeder der 12 User hat eine identische Kopie von `Mein-Wissensvault/` mit:
- DAS-REZEPT.md, README.md, SETUP-PROMPT.md
- 5 CompTIA-Vault-Kopien (comptia-a-plus, cysa-plus, linux-plus, network-plus, security-plus)

Diese Vaults wurden durch die **Phase-54-Loesung** (Kurs-Materialien shared folder unter admin) ersetzt. Die individuellen Kopien sind nun obsolet.

**Empfehlung:** Alle `Mein-Wissensvault/` Ordner LOESCHEN (64.8 MB Einsparung). Die Materialien sind ueber den shared folder `Kurs-Materialien/` verfuegbar.

#### 2. admin/Kursmaterial/ (5.4 MB) — REDUNDANT

Aeltere Kopie der CompTIA-Vault-Sammlung (5 Vault-Ordner). Wurde durch `Kurs-Materialien/` (Phase 54) ersetzt.

**Empfehlung:** LOESCHEN — doppelt mit Mein-Wissensvault UND Kurs-Materialien.

#### 3. admin/Mein-Wissensvault/ (5.4 MB) — REDUNDANT

Gleicher Inhalt wie alle anderen User. Admin braucht das nicht, da er den shared folder besitzt.

**Empfehlung:** LOESCHEN.

#### 4. admin/Learning/images/ (82 MB) — PRUEFEN

Enthalt Bilder fuer die Learning-App (Fragen mit Bildanhang). Moeglicherweise werden diese von der App referenziert.

**Empfehlung:** VOR dem Loeschen pruefen ob Bilder noch von Fragen/Pools referenziert werden. Falls ja: BEHALTEN. Falls nicht: LOESCHEN (82 MB Einsparung).

#### 5. Vorlagen/ (6x 11 MB = 66 MB) — STANDARD NC

Nextcloud-Standard-Vorlagen (Templates). Werden automatisch bei User-Erstellung angelegt.

**Empfehlung:** BEHALTEN — NC-Standard, Loeschen wuerde bei naechstem Login ggf. neu erstellt.

#### 6. broecker/Dozenten-Material/ (827 MB) — BEHALTEN

Authentisches Dozenten-Material (CompTIA-Network N10-009: 732 MB, CompTIA-A-Plus: 28 MB, Sonstiges: 69 MB). Dies ist einzigartiger Content, keine Kopie.

**Empfehlung:** BEHALTEN — originaeres Material, kein Duplikat.

### NC Logs (1030 MB) — OPTIMIERBAR

| Datei | Groesse |
|-------|---------|
| nextcloud.log | 19 MB |
| nextcloud.log.1 | 1011 MB |

**Empfehlung:** Log-Rotation konfigurieren. `nextcloud.log.1` kann geloescht werden (1011 MB Einsparung). NC config `log_rotate_size` auf z.B. 50 MB setzen.

### NC AppData (170 MB)

| Verzeichnis | Groesse | Status |
|-------------|---------|--------|
| preview/ | 163 MB | NC Preview-Cache, kann regeneriert werden |
| appstore/ | 5.1 MB | App-Katalog-Cache |
| Rest | ~2 MB | theming, avatar, js, text |

**Empfehlung:** Preview-Cache koennte mit `occ preview:cleanup` verkleinert werden, aber regeneriert sich. Kein dringender Handlungsbedarf.

## Einsparungs-Uebersicht

| Massnahme | Einsparung | Prioritaet | Risiko |
|-----------|------------|------------|--------|
| Log-Rotation (nextcloud.log.1 loeschen) | 1011 MB | HOCH | Kein |
| Mein-Wissensvault (12 User) loeschen | 64.8 MB | MITTEL | Gering (Backup in Kurs-Materialien) |
| admin/Kursmaterial/ loeschen | 5.4 MB | MITTEL | Kein (ersetzt durch Kurs-Materialien) |
| stas-bundle/ loeschen | 5.4 MB | NIEDRIG | Kein |
| memories/node_modules/ loeschen | 438 MB | NIEDRIG | Kein (npm install regeneriert) |
| admin/Learning/images/ pruefen | bis 82 MB | NIEDRIG | Muss geprueft werden |
| **Gesamt (sicher)** | **~1525 MB** | | |
| **Gesamt (nach Pruefung)** | **~1607 MB** | | |

## Projizierter Zustand nach Cleanup

| Metrik | Vorher | Nachher | Delta |
|--------|--------|---------|-------|
| Disk Used | 14 GB (47%) | ~12.4 GB (39%) | -1.6 GB |
| NC Data (ohne Logs) | ~1053 MB | ~988 MB | -65 MB |
| User Home | 1.8 GB | ~0.9 GB | -0.9 GB |
| NC Logs | 1030 MB | ~19 MB | -1011 MB |

## Empfehlungen fuer Plan 02 (Cleanup)

1. **Sofort:** `nextcloud.log.1` loeschen + Log-Rotation konfigurieren
2. **Sofort:** `admin/Kursmaterial/` loeschen (klar redundant)
3. **Batch:** Alle `Mein-Wissensvault/` per NC occ files:scan nach Loeschung aktualisieren
4. **Pruefung:** `admin/Learning/images/` auf Referenzen checken vor Loeschung
5. **Optional:** `memories/` Verzeichnis bewerten — aktives Projekt oder archivierbar?
