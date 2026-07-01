# Gemini (gemini-2.5-pro via fabric) — v5.2.0 Konzept-Review
**Datum:** 2026-07-01 · **Input:** research/SUMMARY.md + Scope · **Gate:** vor Requirements/Roadmap-Lock

Absolut. Hier ist das Review. Als Architekt und Compliance-Experte sehe ich einen sehr durchdachten Plan, aber mein Job ist es, die Risse zu finden, bevor sie zu Brüchen werden. Der Plan ist gut, aber nicht lückenlos.

---

### Zusammenfassende Bewertung

Der Plan ist technisch solide und adressiert viele kritische Punkte proaktiv, insbesondere die serverseitige Logik und die Grundlagen des Audit-Trails. Die größten blinden Flecken liegen nicht im Code, sondern in den Prozessen, die ein Auditor erwartet, und in den rechtlichen Feinheiten jenseits der reinen Datenspeicherung. Das Team hat an das *Bauen* gedacht, aber noch nicht vollständig an das *Beweisen* und das *Betreiben* unter realen, juristischen Bedingungen.

---

### 1. Blinde Flecken / Fehlende Requirements

Der Plan ist stark auf die technische Implementierung fokussiert. Ein Auditor und die Rechtsabteilung des Kunden werden jedoch nach Folgendem fragen, was derzeit komplett fehlt:

*   **Auditor-Export-Funktion:** Ein Auditor bekommt keinen Shell-Zugang. Der `occ learning:audit:verify`-Befehl ist für den Admin, nicht für den Prüfer. Es fehlt eine Funktion, mit der ein designierter Benutzer (z.B. ein Datenschutzbeauftragter) einen **signierten, menschenlesbaren Export** des Audit-Trails für einen bestimmten Zeitraum erstellen kann (z.B. als PDF oder JSONL-Datei mit einem begleitenden Signatur-File). Ohne dies ist der Nachweis in der Praxis wertlos.
*   **Löschkonzept & Aufbewahrungsfristen:** DSGVO Art. 5(1)(e) fordert Speicherbegrenzung. Der Plan beschreibt, was *gelöscht* wird (Video-Segmente), aber nicht, wie lange der Rest aufbewahrt wird. Für Compliance-Nachweise können gesetzliche Aufbewahrungsfristen gelten (z.B. aus Arbeitsschutzgesetzen, je nach Schulungsinhalt). Es muss ein konfigurierbares, automatisches Lösch- oder Anonymisierungsschema für Zertifikate, Audit-Einträge und Assignments nach X Jahren geben.
*   **DSGVO-Betroffenenrechte (Art. 17 & 20):**
    *   **Recht auf Löschung (Art. 17):** Was passiert, wenn ein Nextcloud-Admin einen User löscht? Kaskadiert der Löschvorgang? Der Plan erwähnt, dass PII aus dem Hash exkludiert wird, was gut ist. Aber es fehlt der Prozess zur **Anonymisierung** der Audit-Logs und Zertifikate dieses Nutzers. Ein `user_id` einfach zu löschen, würde die Kette korrumpieren. Der Standardweg ist, die `user_id` in den relevanten Tabellen durch einen statischen Wert wie `ANONYMIZED_USER` zu ersetzen. Dieser Prozess muss definiert und implementiert werden.
    *   **Recht auf Datenübertragbarkeit (Art. 20):** Jeder Mitarbeiter hat das Recht, seine Daten (hier: seine Zertifikate und Lernhistorie) in einem maschinenlesbaren Format zu erhalten. Es fehlt eine Export-Funktion für den einzelnen Nutzer.
*   **Barrierefreiheit (BITV 2.0 / WCAG 2.1):** Eine **Pflichtschulung** muss für alle Mitarbeiter zugänglich sein, auch für solche mit Behinderungen. Der Plan erwähnt Barrierefreiheit mit keinem Wort. Das ist bei einem Kunden wie der AWO, der im öffentlichen Sektor tätig ist, ein potenzieller Dealbreaker und ein rechtliches Risiko. Das betrifft: Screenreader-Kompatibilität des Vue-Frontends, Tastaturbedienbarkeit, Untertitel für Videos, Kontraste.
*   **Systemausfall & Fristverlängerung:** Was passiert, wenn Nextcloud am letzten Tag der Frist für 4 Stunden ausfällt? Es muss einen dokumentierten Prozess und idealerweise eine technische Funktion für Admins geben, um Fristen für einzelne Gruppen oder Kurse aufgrund technischer Störungen pauschal zu verlängern. Die Grace Period ist gut, aber deckt diesen Fall nicht ab.

### 2. Sequenzierung

Die vorgeschlagene 4-Phasen-Reihenfolge ist grundsätzlich logisch, aber es gibt eine kritische, übersehene Abhängigkeit:

**Phase 2 (Video Gating) ist nicht unabhängig von Phase 1 (Foundation).**

*   Das Event "Video zu 95% angesehen" ist ein zentrales Compliance-Ereignis. Es **muss** über `AuditService::logComplianceEvent()` in die Hash-Kette geschrieben werden.
*   Das bedeutet, der `VideoProgressService` (aus Phase 2) hat eine harte Abhängigkeit zum `AuditService` (aus Phase 1).
*   **Konsequenz:** Die *Schnittstelle* und der *Event-Typ* (z.B. `course.video.completed`) müssen bereits in Phase 1 definiert und im `AuditService` bekannt gemacht werden, auch wenn die Implementierung erst in Phase 2 erfolgt. Andernfalls muss in Phase 2 die Kernkomponente aus Phase 1 wieder angefasst werden.

**Empfehlung:** Verschiebe den `ImportUsersCommand` von Phase 2 nach Phase 1. Er hängt direkt vom `learning_assignments`-Schema (Phase 1) ab und ist Teil der grundlegenden Benutzerverwaltung, die von Anfang an sauber funktionieren muss, insbesondere für die "Username-only"-Anforderung.

### 3. Security-Design

Der Plan ist gut, aber hier sind die Löcher, die ein Angreifer suchen würde:

*   **Video-Gating-Bypass durch Zeitmanipulation:** Der Plan sieht serverseitige Verfolgung vor. Gut. Ein Angriffsvektor ist jedoch das Senden von hunderten "Watch-Heartbeats" in wenigen Sekunden. Der `VideoProgressService` muss serverseitig eine Plausibilitätsprüfung durchführen, z.B. "Letzter Fortschritts-Ping für diesen User/dieses Video war vor < 5 Sekunden, ignoriere diesen Ping". Ohne eine solche Drosselung/Validierung kann ein Skript die Sehdauer fälschen.
*   **Audit-Trail-Integrität – Liveness-Problem:** Der Forgejo-Anker schützt vor einem Admin, der DB und Schlüssel kontrolliert. Aber was hindert diesen Admin daran, den `AuditCheckpointJob` einfach zu deaktivieren? Nach drei Wochen Stille reaktiviert er ihn und tut so, als sei nichts gewesen. Einem externen Auditor würde das nur auffallen, wenn er eine **Erwartungshaltung** an die Frequenz der Anker hat. Der Prozess muss definieren, dass das Ausbleiben von wöchentlichen Checkpoints ein Alarmereignis ist, das untersucht werden muss.
*   **Audit-Trail-Integrität – Fork-Resolution:** Der Plan erkennt, dass der Mangel an `FOR UPDATE` in Nextcloud zu Forks führen kann und dass `occ:verify` diese findet. Und dann? Was ist der **dokumentierte Admin-Prozess**, um einen Fork zu beheben? Ohne einen solchen Prozess ist die Entdeckung nur ein Alarm ohne Handlungsanweisung, was ein Auditor als unzureichend bewerten wird.

### 4. DSGVO / Betriebsrat

Der Plan identifiziert das Kernproblem (Verhaltens- und Leistungskontrolle, BetrVG §87) und schlägt mit den transienten Segmenten eine gute technische Lösung vor. Rechtlich gibt es aber noch Lücken:

*   **`completed_at` ist bereits Leistungsdaten:** Die Annahme, dass `(user_id, content_id, completed_at)` keine problematischen Leistungsdaten sind, ist zu optimistisch. Ein Betriebsrat kann argumentieren: "Wir können sehen, dass Mitarbeiter A die Schulung am ersten Tag um 9:05 Uhr abschließt, während Mitarbeiter B bis zum letzten Tag der Frist um 16:55 Uhr braucht. Das ist Leistungskontrolle." Die rechtliche Argumentation darf nicht sein "das sind keine Leistungsdaten", sondern muss lauten: "Diese Daten sind zur Erfüllung der rechtlichen Verpflichtung (Nachweis der Schulung, Art. 6(1)(c) DSGVO) **erforderlich** und auf das absolute Minimum reduziert (Datenminimierung, Art. 5(1)(c) DSGVO)". Diese präzise Formulierung muss in der Kommunikation mit dem Kunden und dessen Betriebsrat verwendet werden.
*   **Fehlende Transparenz (DSGVO Art. 13):** Unabhängig von der Einwilligung muss der Nutzer zu Beginn der Schulung klar und verständlich darüber informiert werden, **welche** Daten zu **welchem Zweck** verarbeitet werden. Es fehlt ein explizites Requirement für einen "Datenschutzhinweis"-Banner oder eine Infoseite, die genau das erklärt: "Zur Erfüllung Ihrer Teilnahmepflicht wird der Abschluss von Lerneinheiten mit Zeitstempel gespeichert. Detaillierte Wiedergabemuster werden nicht permanent gespeichert."
*   **Auftragsverarbeitungsvertrag (AVV):** Das Geschäftsmodell ist "Umsatz über Service-Layer". Sobald ihr Support mit Systemzugriff oder Hosting anbietet, seid ihr Auftragsverarbeiter nach Art. 28 DSGVO. Ein AVV mit AWO Sachsen ist dann zwingend erforderlich. Das ist zwar kein technisches Requirement für v5.2.0, aber eine Bedingung für den legalen Betrieb und muss dem Kunden gegenüber proaktiv angesprochen werden.

### 5. Over-/Under-Engineering

*   **Over-Engineering (rausnehmen):** Der **externe Forgejo-Anker (Layer 3)**. Kryptografisch brillant, aber für v5.2.0 ("das Gerüst") ist es zu viel. Eine Hash-Kette (Layer 1) mit Ed25519-signierten Checkpoints (Layer 2) ist bereits ein extrem starker, auditierbarer Nachweis, der 99% der Konkurrenz übertrifft. Den externen Anker als Fast-Follow in v5.2.1 zu verschieben, reduziert die Komplexität und die Abhängigkeit von einem externen System für den initialen Launch.
*   **Under-Engineering (reinnehmen):**
    1.  **Auditor-Export:** Wie in Punkt 1 beschrieben. Ein Compliance-Tool ohne Compliance-Export ist unvollständig. Das ist ein Table-Stake.
    2.  **Admin-UI für Audit-Status:** Sich ausschließlich auf einen `occ`-Befehl zu verlassen, um den Zustand des teuersten und wichtigsten Features zu prüfen, ist unzureichend. Ein kleines Widget im Admin-Bereich ("Audit-Trail-Status: OK | Letzter Checkpoint: [Datum] | X Events seit letztem Checkpoint") ist für den Betrieb unerlässlich und schafft Vertrauen.

### 6. Audit-Trail-Substanz

Der 3-Schichten-Ansatz ist kryptografisch sehr stark. Ein feindseliger Auditor würde trotzdem an folgenden Punkten bohren:

*   **Zeitstempel-Integrität:** Die Hash-Kette inkludiert `created_at`. Dieser Zeitstempel kommt von der PHP-Anwendung, also von der Systemuhr des Servers. Ein Admin mit Root-Zugriff könnte die Systemzeit zurückdatieren, eine Serie von "alten" Events mit gültiger Kette erzeugen und die Uhrzeit wieder vorstellen. Die signierten Checkpoints erschweren das, aber der eigentliche Schutz dagegen ist der **externe Anker**, da der Zeitstempel des Forgejo-Commits nicht vom Admin kontrolliert wird. Das ist ein starkes Argument, den Anker nicht zu lange aufzuschieben, aber auch eine anerkannte Schwäche von rein internen Zeitstempeln.
*   **Trennung von Hash und Kontext:** Der Plan besagt, dass PII im `context_json` steht und nicht Teil des Hashes ist. Der Auditor wird fragen: "Sie können also den Kontext eines Ereignisses ändern, ohne die Kette zu brechen. Wie stellen Sie die Integrität der nicht-gehashten, aber für das Verständnis des Events wichtigen Kontextdaten sicher?" Die Antwort ist vermutlich "Standard-DB-Integrität", aber man muss klar argumentieren können, dass der kryptografisch gesicherte Teil der unveränderliche *Fakt* des Events ist (WER, WAS, WANN), während der Kontext nur der besseren Darstellung dient.

### 7. Das Riskanteste

Der riskanteste Punkt, der am ehesten zu Nacharbeit oder Scheitern führen wird, ist die **Neugestaltung der Re-Zertifizierungs-Guards in Phase 4 (`PassCriteriaService`, `IssuanceService`)**.

**Warum?**
Es ist der einzige Teil des Plans, der eine **Operation am offenen Herzen** des bestehenden, kritischen Zertifizierungs-Flows darstellt. Alles andere sind größtenteils neue, additive Komponenten. Ein Fehler hier hat katastrophale Folgen: Entweder können Mitarbeiter ihre abgelaufenen Zertifikate nicht erneuern (was ihre Arbeit blockiert und Compliance-Ziele verfehlt) oder es werden fälschlicherweise Zertifikate ausgestellt (was den gesamten Audit-Nachweis wertlos macht). Die Komplexität der Zustandsverwaltung (`active_period_key`, idempotency guards, period-close-Logik) ist extrem hoch und fehleranfällig. Der Plan gibt selbst zu, dass dies "explizit spezifiziert werden muss", was ein klares Signal für das hohe Risiko ist.
