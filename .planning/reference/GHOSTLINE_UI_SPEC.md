# Design-Guide: Ghostline „Infection“ Event

Dieses Dokument beschreibt die visuelle Transformation der App, wenn der Antagonist **Ghostline** das System übernimmt oder eine Quest-Phase einleitet.

---

## 1. Farbwelt & Atmosphäre
Wenn Ghostline angreift, wechselt die App vom ruhigen Cyan in einen **„Danger-State“**.

- **Primärfarbe:** Vibrant Magenta (#d946ef) / Rose (#f43f5e).
- **Akzent:** Neon-Rot für Warnmeldungen.
- **Hintergrund:** Bleibt dunkel, aber mit einem zusätzlichen roten Scan-Grid-Overlay.

---

## 2. Visuelle Effekte (The Glitch)

Wir nutzen CSS-Effekte, um eine „Instabilität“ des Systems zu simulieren:

### A. Chromatic Aberration
Texte und Icons erhalten einen leichten Farbversatz (Rot/Blau), der zittert.
```css
.ghostline-glitch {
  text-shadow: 2px 0 red, -2px 0 blue;
  animation: glitch-jitter 0.2s infinite;
}
```

### B. Scanlines & Static
Ein halbtransparentes Overlay über der gesamten App, das wie ein alter Röhrenmonitor wirkt (Scanlines).

### C. Terminal-Overlay
Statt moderner Buttons erscheinen CLI-artige Eingabefelder:
`root@ghostline:~$ [BLOCK TRAFFIC] [BYPASS FIREWALL]`

---

## 3. UI-Transformation

1.  **VirtuProf-Panel:** NOVA wird durch Ghostline ersetzt (oder NOVA glitched und zeigt Ghostlines Gesicht).
2.  **Navigation:** Alle Menüpunkte werden in einen kryptischen Hex-Code umgewandelt, der erst beim Hover lesbar wird.
3.  **Toasts/Benachrichtigungen:** Statt normaler System-Meldungen erscheinen „System Error“ und „Encryption in Progress...“ Warnungen.

---

## 4. Trigger & Dauer
- **Trigger:** Start einer Ghostline-Quest oder ein „kritischer Fehler“ bei einer Security-Frage.
- **Dauer:** Bis die Quest-Stufe abgeschlossen ist.
- **Rückkehr:** Nach dem Sieg über Ghostline „rebootet“ die App visuell (kurzer weißer Flash -> Standard Cyan).
