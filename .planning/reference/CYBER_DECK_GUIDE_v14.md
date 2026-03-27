# Design-Guide: Das Cyber-Deck Interface

Dieses Dokument beschreibt die Umgestaltung der interaktiven Tools (Subnetzrechner, Firewall-Builder, DNS-Resolver) in ein immersives „In-World“-Hardware-Design.

---

## 1. Das Hardware-Konzept
Die Tools sollen nicht wie Web-Formulare aussehen, sondern wie ein **„Field-Service Tablet“** (Cyber-Deck) aus dem Jahr 2080.

### Visuelle Merkmale:
- **Rahmen:** Ein massiver, skizzierter Rahmen mit „Grip“-Texturen an den Seiten.
- **Displays:** Dunkler Hintergrund (#0a0e17) mit hellen, glühenden Segment-Anzeigen (Cyan/Amber).
- **Buttons:** Keine Standard-Buttons. Wir nutzen Schalter (Toggles) und „Push-Buttons“, die beim Klicken kurz aufleuchten.
- **Daten-Leitungen:** Dünne, animierte Blueprint-Linien verbinden die Eingabefelder mit dem Ergebnis-Display.

---

## 2. Tool-Spezifische Anpassungen

### A. Subnetzrechner (🔢)
- **Eingabe:** IP-Felder sehen aus wie mechanische Drehregler oder Nummern-Pads.
- **Visualisierung:** Eine „Netzwerk-Karte“, die sich dynamisch aufbaut, während der User die Subnetzmaske ändert.
- **Animation:** Wenn das Ergebnis berechnet wird, „fließen“ Datenpakete von der IP-Eingabe zum berechneten Netz-Bereich.

### B. Firewall-Builder (🛡️)
- **Design:** Sieht aus wie ein Schaltschrank. Jede Regel ist ein „Modul“, das man einstecken oder entfernen kann.
- **Feedback:** Ein „DENY“ wird durch ein rotes Warnlicht am Modul symbolisiert, ein „ALLOW“ durch ein grünes Leuchten.

### C. DNS-Resolver (🌐)
- **Visual:** Ein rotierender Globus aus Blueprint-Linien. 
- **Aktion:** Beim Resolve-Vorgang schießen Lichtstrahlen (Pakete) vom User zum Globus und bringen die IP-Adresse als „Antwort-Paket“ zurück.

---

## 3. Technische Umsetzung (CSS)

Wir nutzen **CSS Glassmorphism** und **Neon-Glow**:

```css
.cyber-deck-panel {
  background: rgba(10, 14, 23, 0.85);
  backdrop-filter: blur(10px);
  border: 1px solid var(--cyan);
  box-shadow: 0 0 15px rgba(6, 182, 212, 0.2);
  clip-path: polygon(0 0, 100% 0, 95% 100%, 5% 100%); /* Trapez-Form für Tablet-Look */
}

.glow-text {
  color: var(--cyan);
  text-shadow: 0 0 5px var(--cyan);
  font-family: 'Share Tech Mono', monospace;
}
```
