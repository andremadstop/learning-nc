# Wireshark -- Noob-Anleitung

> Wireshark ist ein Netzwerk-Protokollanalysator: er zeichnet Pakete auf und macht sie lesbar.
> Diese Anleitung zeigt typische Anwendungsfaelle in einem Lab-Netzwerk.

---

## Starten

### Option A -- Remote Capture (empfohlen fuer Lab)
```bash
# Alle VMs auf einmal sehen (Hypervisor-Bridge)
ssh root@hypervisor-01 "tcpdump -w - -i vmbr0 2>/dev/null" | wireshark -k -i -

# Nur eine bestimmte VM
ssh root@dns-server "tcpdump -w - -i eth0 2>/dev/null" | wireshark -k -i -
ssh app-server "tcpdump -w - -i eth0 2>/dev/null" | wireshark -k -i -
```

### Option B -- Lokaler Traffic
```bash
wireshark
# Interface auswaehlen (z.B. eth0) -> Doppelklick -> läuft
```

### Stoppen
- Wireshark-Fenster schließen, oder
- Im Terminal: `Ctrl+C`

---

## Die Oberflaeche

```
+------------------------------------------------------------+
|  Menüleiste                                                |
+------------------------------------------------------------+
|  [> Start] [# Stop] [~ Restart]   Toolbar                  |
+------------------------------------------------------------+
|  Filterleiste:  [ ip.addr == 10.0.0.100             ] [->] |
+------------------------------------------------------------+
|                                                             |
|  PAKETLISTE (oben)                                          |
|  Nr. | Zeit | Quelle | Ziel | Protokoll | Laenge | Info    |
|  1   | 0.00 | .10    | .60  | ICMP      | 98     | ...     |
|  2   | 0.01 | .60    | .10  | ICMP      | 98     | ...     |
|                                                             |
+------------------------------------------------------------+
|                                                             |
|  PAKETDETAILS (mitte) -- Schichten aufklappbar              |
|  v Frame 1 (98 bytes)                                       |
|  v Ethernet II, Src: aa:bb:cc...  Dst: dd:ee:ff...         |
|  v Internet Protocol, Src: 10.0.0.10                        |
|  v Internet Control Message Protocol                        |
|                                                             |
+------------------------------------------------------------+
|                                                             |
|  RAW BYTES (unten) -- Hex links, ASCII rechts               |
|  0000  00 0c 29 ab cd ef  00 50 56 ...                      |
|                                                             |
+------------------------------------------------------------+
```

### Die drei Bereiche erklärt

**Paketliste (oben)**
Jede Zeile = ein Paket. Spalten:
- `No.` -- laufende Nummer seit Capture-Start
- `Time` -- Sekunden seit Start (oder Uhrzeit, einstellbar)
- `Source` / `Destination` -- IP oder MAC
- `Protocol` -- höchstes erkanntes Protokoll (z.B. DNS statt UDP)
- `Length` -- Paketgröße in Bytes
- `Info` -- Kurzinfo (z.B. "Standard query A google.com")

**Paketdetails (mitte)**
Aufklappbare Baumstruktur -- jede Schicht einzeln:
- Frame = alles zusammen (Metadaten)
- Ethernet II = Layer 2 (MAC-Adressen)
- Internet Protocol = Layer 3 (IP-Adressen, TTL)
- TCP/UDP = Layer 4 (Ports, Flags)
- HTTP/DNS/... = Layer 7 (Inhalt)

Ein Klick auf ein Feld markiert die entsprechenden Bytes unten im Hex-View.

**Raw Bytes (unten)**
Hexadezimale Darstellung des rohen Pakets. Links Hex, rechts ASCII.
Nicht lesbar? Keine Sorge -- du brauchst das selten direkt.

---

## Farben verstehen

Wireshark faerbt Pakete automatisch:

| Farbe | Bedeutung |
|-------|-----------|
| Hellgruen | TCP-Traffic |
| Hellblau | UDP-Traffic |
| Dunkelblau | DNS |
| Schwarz (rot markiert) | TCP-Fehler (Retransmission, Reset) |
| Gelb | ARP |
| Grau | ICMP |
| Weiss | Unbekannt / generisch |

Farben anpassen: `View -> Coloring Rules`

---

## Filter -- das Wichtigste überhaupt

Ohne Filter siehst du alles auf einmal = Chaos. Filter sind das Herzstueck.

### Anzeige-Filter (Display Filter)
Wird in die Leiste oben eingegeben. Filtert was du siehst (Aufzeichnung läuft weiter).
Leiste wird **gruen** = Filter gueltig | **rot** = Syntaxfehler

### Die wichtigsten Filter

#### Nach Protokoll
```
arp
icmp
dns
tcp
udp
http
tls
dhcp
ssh
```

#### Nach IP-Adresse
```
ip.addr == 10.0.0.100             # von ODER zu dieser IP
ip.src == 10.0.0.10               # nur von dieser IP
ip.dst == 10.0.0.30               # nur zu dieser IP
ip.addr == 10.0.0.0/24            # ganzes Subnetz
```

#### Nach Port
```
tcp.port == 80                     # HTTP
tcp.port == 443                    # HTTPS
tcp.port == 22                     # SSH
udp.port == 53                     # DNS
tcp.port == 8080                   # Webserver alternativ
```

#### Nach TCP-Flags
```
tcp.flags.syn == 1                              # SYN-Pakete
tcp.flags.syn == 1 and tcp.flags.ack == 0      # nur initiale SYN (neue Verbindungen)
tcp.flags.fin == 1                              # Verbindungsabbau
tcp.flags.reset == 1                           # Resets (Fehler)
```

#### Kombinieren mit AND / OR / NOT
```
ip.addr == 10.0.0.100 and tcp.port == 8080
dns and ip.addr == 10.0.0.30
not arp and not broadcast
icmp or dns
```

#### Rauschen reduzieren
```
not arp                            # kein ARP
not broadcast                      # kein Broadcast
not multicast                      # kein Multicast
not arp and not broadcast and not multicast    # ruhigerer View
not (ip.addr == 10.0.0.1)         # Router ausblenden
```

### Nuetzliche Lab-Filter

```
# Was läuft auf dem App-Server?
ip.addr == 10.0.0.100

# DNS-Anfragen an den DNS-Server
ip.addr == 10.0.0.30 and dns

# Neue TCP-Verbindungen (wer verbindet sich wohin?)
tcp.flags.syn == 1 and tcp.flags.ack == 0

# HTTP-Anfragen im Klartext lesen
http.request

# DHCP-Handshake sehen
bootp

# Nur Fehler und Probleme
tcp.analysis.flags and not tcp.analysis.window_update
```

### Filter aus Paket erstellen (Rechtsklick-Trick)
Auf ein Feld im Paketdetail-Bereich rechtsklicken -> `Apply as Filter` -> sofort übernommen.
Sehr praktisch statt manuell tippen.

---

## Wichtige Menüpunkte

### View
- `Time Display Format` -- Uhrzeit statt Sekunden anzeigen (empfohlen)
- `Name Resolution` -- IPs in Hostnamen aufloesen (kann verlangsamen)
- `Coloring Rules` -- Farben anpassen

### Statistics
- `Protocol Hierarchy` -- Welche Protokolle wie viel Traffic machen (Überblick)
- `Conversations` -- Alle laufenden Verbindungen (IP-Paare + Datenmenge)
- `Endpoints` -- Alle IPs + wie viel Traffic
- `IO Graphs` -- Traffic über Zeit als Graph

### Analyze
- `Follow TCP Stream` -- kompletten Datenaustausch einer Verbindung als Text lesen
- `Follow UDP Stream` -- dasselbe fuer UDP (z.B. DNS)

---

## Follow Stream -- der maechtigste Trick

Rechtsklick auf ein TCP-Paket -> `Follow -> TCP Stream`

Zeigt den kompletten Klartext-Dialog zwischen Client und Server:
```
GET /apps/learning/api/pools HTTP/1.1
Host: 10.0.0.100:8080
Authorization: Basic YWRtaW46YWRtaW4=

HTTP/1.1 200 OK
Content-Type: application/json

{"pools": [...]}
```

Funktioniert nur bei unverschlüsseltem Traffic (HTTP, nicht HTTPS).

---

## Lernuebungen im Lab

### Uebung 1 -- ARP vor dem ersten Ping
```bash
# Wireshark starten, Filter: arp or icmp
# ARP-Cache leeren:
sudo ip neigh flush dev eth0
# Dann pingen:
ping -c 1 10.0.0.60
```
**Was du siehst:** ARP Request -> ARP Reply -> ICMP Echo -> ICMP Reply
**Lerneffekt:** Vor jedem ersten Kontakt wird MAC via ARP aufgeloest

### Uebung 2 -- DNS-Aufloesung live
```bash
# Filter: dns
nslookup google.com 10.0.0.30
```
**Was du siehst:** UDP-Query (A-Record) -> UDP-Response mit IP
**Lerneffekt:** DNS ist UDP, geht hin und zurück ohne Handshake

### Uebung 3 -- TCP 3-Way Handshake
```bash
# Filter: ip.addr == 10.0.0.100 and tcp
curl http://10.0.0.100:8080/ -s -o /dev/null
```
**Was du siehst:** SYN -> SYN-ACK -> ACK -> HTTP GET -> HTTP 200 -> FIN
**Lerneffekt:** TCP baut Verbindung auf, bevor ein Byte Nutzlast fliesst

### Uebung 4 -- HTTP im Klartext lesen
```bash
# Filter: http
curl -u "admin:admin" http://10.0.0.100:8080/apps/learning/ -s -o /dev/null
# Auf Paket rechtsklicken -> Follow -> TCP Stream
```
**Was du siehst:** Vollstaendiger HTTP-Dialog inkl. Authorization-Header
**Lerneffekt:** HTTP sendet Credentials im Klartext -- darum HTTPS!

### Uebung 5 -- DHCP-Handshake
```bash
# Filter: bootp
# Netzwerk-Interface kurz neu verbinden oder:
sudo dhclient -r eth0 && sudo dhclient eth0
```
**Was du siehst:** Discover -> Offer -> Request -> ACK (DORA)
**Lerneffekt:** Wie ein Gerät automatisch seine IP-Konfiguration bekommt

### Uebung 6 -- Was macht der IoT-Hub nachts?
```bash
# Remote Capture starten, 5 Minuten laufen lassen
ssh root@hypervisor-01 "tcpdump -w - -i vmbr0 2>/dev/null" | wireshark -k -i -
# Filter: ip.addr == 10.0.0.40
# Statistics -> Conversations ansehen
```
**Was du siehst:** Welche externen IPs der IoT-Hub kontaktiert, wie oft, wie viel
**Lerneffekt:** Jeder Service redet staendig -- auch wenn du nichts tust

---

## Captures speichern und laden

```
# Speichern: File -> Save As -> .pcapng
# Laden:     File -> Open -> .pcapng

# Oder per Kommandozeile aufzeichnen und spaeter analysieren:
ssh root@hypervisor-01 "tcpdump -w /tmp/capture.pcap -i vmbr0 -c 1000 2>/dev/null"
scp root@hypervisor-01:/tmp/capture.pcap ~/
wireshark ~/capture.pcap
```

---

## Häufige Fehler & Lösungen

| Problem | Ursache | Loesung |
|---------|---------|--------|
| `permission denied` beim Start | Nicht in wireshark-Gruppe | `sudo usermod -aG wireshark $USER` + neu einloggen |
| Kein Traffic sichtbar | Falsches Interface | Interface wechseln (Capture -> Options) |
| Alles grau/unbekannt | Kein Protokoll erkannt | Paket aufklappen -> manuell ansehen |
| "Failed to register with host portal" | Wayland/DBus-Warning | Harmlos, ignorieren |
| SSH-Pipe bricht ab | Netzwerkproblem | Neu starten |
| Zu viel Traffic | Kein Filter | Filter setzen! |

---

## Shortcuts

| Shortcut | Aktion |
|----------|--------|
| `Ctrl+F` | Suchen im Capture |
| `Ctrl+R` | Capture neu starten |
| `Ctrl+E` | Capture stoppen |
| `Ctrl+Shift+P` | Paket-Details |
| `Space` | Scrollen stoppen/fortsetzen |
| `Ctrl+G` | Zu Paketnummer springen |
| `Ctrl+Alt+Shift+T` | Follow TCP Stream |

---

## Naechste Schritte

Wenn du die Grundlagen sitzt:
- **tshark** -- Wireshark als Kommandozeilen-Tool (fuer Scripts/Automatisierung)
- **Capture-Filter** (BPF-Syntax) -- Filter schon beim Aufzeichnen, nicht erst beim Anzeigen
- **Wireshark Profiles** -- verschiedene Farbschemata/Spalten fuer verschiedene Aufgaben

---

Verlinkung: siehe 00-Lehrplan.md
