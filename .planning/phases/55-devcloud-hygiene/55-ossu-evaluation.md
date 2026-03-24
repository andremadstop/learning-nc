# OSSU Curriculum Evaluation fuer Learning-NC

> Erstellt: 2026-03-24
> Quelle: [github.com/ossu/computer-science](https://github.com/ossu/computer-science) (203k+ Stars)
> Lokale Kopie: ~/ObsidianVaults/Personal/Projekte/Learning-NC/OSSU-Curriculum/

## Zusammenfassung

**Empfehlung: PARTIALLY** — OSSU taugt als Inspirationsquelle fuer Kursstruktur und Lernpfade, aber NICHT als automatischer Import-Kandidat. Der Detailgrad der OSSU-Kursbeschreibungen ist zu gering fuer einen direkten Fragen-Import. Stattdessen eignet sich die Struktur als Template fuer manuelle Kurs-Kuratierung.

## OSSU Curriculum im Ueberblick

| Eigenschaft | Wert |
|-------------|------|
| Kurse gesamt | 62 |
| Bereiche | 13 (4 Core + 5 Advanced + Intro + Tools + Ethics + Final Project) |
| Geschaetzte Dauer | ~2 Jahre bei 20h/Woche |
| Struktur | Intro -> Core CS -> Advanced CS -> Final Project |
| Metadaten pro Kurs | Dauer (Wochen), Aufwand (h/Woche), Voraussetzungen, Reihenfolge |
| Inhaltliche Tiefe | Nur Kursbeschreibung + externer Link — keine Fragen, keine Lernziele |

## CompTIA-Mapping

### Direkte Ueberschneidungen

| OSSU Modul | CompTIA Cert | Ueberschneidung | Bemerkung |
|------------|--------------|------------------|-----------|
| Computer Networking: a Top-Down Approach | **Network+ (N10-009)** | HOCH | OSI/TCP-IP Modell, Routing, Switching, DNS, DHCP, Subnetting |
| Cybersecurity Fundamentals | **Security+ (SY0-701)** | HOCH | CIA-Triad, Threat Landscape, Risk Management Basics |
| Principles of Secure Coding | **Security+** | MITTEL | Sichere Entwicklung, Input Validation, Injection Prevention |
| Identifying Security Vulnerabilities | **Security+ / CySA+** | MITTEL | Vulnerability Assessment, Common Weaknesses |
| Identifying Security Vulnerabilities in C/C++ | **CySA+ (CS0-003)** | MITTEL | Memory Safety, Buffer Overflows, Code Analysis |
| Exploiting and Securing Vulnerabilities in Java | **CySA+** | MITTEL | Application Security, Penetration Testing Concepts |
| Web Security Fundamentals | **Security+ / CySA+** | HOCH | XSS, CSRF, Authentication, Session Management |
| Security Governance & Compliance | **Security+** | HOCH | Policies, Frameworks, Compliance Standards |
| Digital Forensics Concepts | **CySA+** | MITTEL | Incident Response, Evidence Handling, Chain of Custody |
| Secure Software Development (3 Kurse) | **CySA+** | MITTEL | SDLC Security, Code Review, Verification |
| Operating Systems: Three Easy Pieces | **Linux+ (XK0-005)** | MITTEL | Process Management, File Systems, Memory, Scheduling |
| Build a Modern Computer (Nand to Tetris) | **A+ / Network+** | GERING | Hardware-Grundlagen, aber zu theoretisch fuer CompTIA |

### Keine direkte Ueberschneidung

| OSSU Bereich | Kurse | CompTIA-Relevanz |
|-------------|-------|------------------|
| Core programming (5) | Systematic Program Design, PLs, OOP, Architecture | Keine — CompTIA ist nicht programmierorientiert |
| Core math (4) | Calculus, Discrete Math | Keine direkte (Subnetting-Math ist trivial vs. Calculus) |
| Core theory (2) | Algorithms | Keine |
| Core applications (6) | Databases, ML, Graphics, Software Eng. | Gering (DB-Grundlagen tangential zu Server+) |
| Core ethics (3) | Ethics, IP, Privacy | Gering (Privacy tangential zu Security+) |
| Advanced programming (6) | Compilers, Parallel, Testing | Keine |
| Advanced systems (3) | Digital Circuits, Computer Architecture | Gering (zu theoretisch) |
| Advanced theory (3) | Theory of Computation, Game Theory | Keine |
| Advanced math (14) | Linear Algebra, Probability, IoT, Cloud | IoT/Cloud tangential zu Network+/Cloud+ |

### CompTIA-Abdeckung durch OSSU

| CompTIA Cert | OSSU-Module mit Ueberschneidung | Abdeckung |
|-------------|----------------------------------|-----------|
| **Network+ (N10-009)** | 1 Kurs (Networking Top-Down) | ~15% — fehlt: Wireless, WAN, Troubleshooting, Kabel, Hardware |
| **Security+ (SY0-701)** | 4 Kurse (Cybersec Fund., Secure Coding, Governance, Web Security) | ~25% — fehlt: Cryptography-Details, IAM, Physical Security, Incident Response |
| **CySA+ (CS0-003)** | 5 Kurse (Vuln-Kurse, Forensics, Secure Dev) | ~20% — fehlt: SIEM, Threat Intelligence, Automation, Log Analysis |
| **Linux+ (XK0-005)** | 1 Kurs (OS: Three Easy Pieces) | ~10% — fehlt: Shell Scripting, Package Mgmt, Systemd, SELinux |

**Fazit:** OSSU deckt maximal 10-25% der CompTIA-Pruefungsinhalte ab. Die Ueberschneidung ist konzeptionell (Theorie), nicht praktisch (hands-on Labs, Troubleshooting-Szenarien).

## Evaluation als Learning-NC Kursstruktur-Template

### Staerken

1. **Klare Hierarchie:** Intro -> Core -> Advanced -> Project passt gut zu Learning-NC's Course -> Pool -> Question Modell
2. **Voraussetzungs-Ketten:** OSSU definiert Prerequisites pro Kurs — koennten als Kurs-Dependencies in Learning-NC abgebildet werden
3. **Metadaten vorhanden:** Dauer, Aufwand, Reihenfolge (order) sind pro Kurs erfasst
4. **62 Kurse = ueberschaubar:** Nicht zu viele fuer manuelle Kuratierung

### Schwaechen

1. **Keine Fragen/Lernziele:** OSSU liefert nur Kursnamen + Links, keine inhaltlichen Fragen. Ein automatischer Fragen-Import ist unmoeglich.
2. **Externe Abhaengigkeit:** Alle Kurse verlinken auf Coursera/edX/YouTube — Links koennen veralten
3. **Kein Fragenkatalog:** Im Gegensatz zu CompTIA gibt es keine definierten Pruefungsziele ("Exam Objectives")
4. **Zu breit fuer CompTIA:** 62 Kurse, aber nur ~11 sind CompTIA-relevant
5. **Unterschiedlicher Fokus:** OSSU = akademisches CS-Studium, CompTIA = praxisorientierte IT-Zertifizierung

### Mapping auf Learning-NC Datenmodell

| OSSU Konzept | Learning-NC Mapping | Machbarkeit |
|-------------|---------------------|-------------|
| Curriculum (gesamt) | 1 Kurs ("OSSU CS") | Trivial |
| Bereich (z.B. Core security) | Kurs-Abschnitt (chapter_ref auf Pool) | Machbar mit bestehenden Feldern |
| Einzelkurs (z.B. Cybersecurity Fund.) | 1 Pool | Machbar, aber Pool waere leer (keine Fragen) |
| Voraussetzung | Kurs-Reihenfolge (enforced order in CourseService) | Teilweise — Learning-NC hat keine Pool-Dependencies |

### Import-Machbarkeit

| Aspekt | Status | Aufwand |
|--------|--------|---------|
| Kursstruktur (62 Pools in 1 Kurs) | Machbar per JSON-Import | 1-2h (Script) |
| Metadaten (Dauer, Aufwand) | Machbar — `chapter_ref` + `exam_ref` Felder | 1h |
| Fragen generieren | NICHT automatisierbar — muessten manuell erstellt oder per AI generiert werden | 50-100h |
| Voraussetzungs-Ketten | Nicht unterstuetzt — Learning-NC hat keine Pool-to-Pool Dependencies | Feature-Aufwand: 10-20h |

## Praktische Empfehlung

### Empfohlen: OSSU als Inspirations-Template (nicht als Import)

1. **NICHT importieren:** Leere Pools ohne Fragen haben keinen Lernwert. Der Aufwand, 62 Kurse mit sinnvollen Fragen zu befuellen, uebersteigt den Nutzen.

2. **Stattdessen als Referenz nutzen:**
   - Die OSSU-Struktur zeigt, welche Themen in welcher Reihenfolge sinnvoll sind
   - Die CompTIA-relevanten Module (11 von 62) koennen als Inspiration fuer neue Fragen-Pools dienen
   - Die Voraussetzungs-Ketten sind nuetzlich fuer die manuelle Kurs-Kuratierung

3. **Konkreter Mehrwert fuer bestehende CompTIA-Kurse:**
   - "Computer Networking: Top-Down Approach" als Ergaenzungsquelle fuer Network+ Pool-Fragen
   - "Cybersecurity Fundamentals" + "Web Security" als Vorlage fuer Security+ Pool-Gliederung
   - "OS: Three Easy Pieces" fuer Linux+ Konzeptfragen

4. **Langfristige Option:** Falls Learning-NC ein Feature "Lernpfade" (geordnete Kursfolgen mit Voraussetzungen) bekommt, waere die OSSU-Struktur ein guter erster Testfall.

### Nicht empfohlen

- Automatischer Bulk-Import der 62 Kurse als leere Pools
- Entwicklung eines OSSU-spezifischen Import-Formats
- AI-generierte Fragen fuer alle 62 Kurse (Qualitaetskontrolle waere enorm)

## Fazit

OSSU ist ein hervorragendes **Referenz-Curriculum** fuer die Struktur eines CS-Studiums, aber kein geeigneter **Import-Kandidat** fuer Learning-NC. Die Ueberschneidung mit CompTIA-Zertifizierungen ist auf ~11 von 62 Kursen begrenzt und deckt selbst dort nur 10-25% der Pruefungsinhalte ab.

**Empfohlene Nutzung:** Als Obsidian-Referenz behalten, bei der Erstellung neuer Fragen-Pools als Strukturvorlage heranziehen, aber keinen technischen Import-Aufwand investieren.
