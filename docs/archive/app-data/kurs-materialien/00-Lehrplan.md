# Netzwerk-Lehrplan -- Absoluter Einstieg

> Ziel: Solide Netzwerk-Grundlagen aufbauen, die auf Network+ Level fuehren.
> Methode: Theorie -> Beobachten (Wireshark im Lab) -> Simulieren (Packet Tracer) -> Festigen (Learning-NC)
> Dauer: ~12 Wochen, ~3-4h/Woche

## Leitprinzip: Jedes Thema folgt demselben Lernzyklus

```
1. KONTEXT   -- Warum brauche ich das? Was sehe ich davon im Alltag?
2. THEORIE   -- Konzept verstehen (Notizen)
3. BEOBACHTEN -- Wireshark im Lab: echten Traffic ansehen
4. SIMULIEREN -- Packet Tracer: Szenario nachbauen/verändern
5. FESTIGEN  -- Learning-NC Karten erstellen
6. CHECKPOINT -- Kann ich es jemandem erklären?
```

## Lab-Werkzeuge

| Tool | Wo | Wofuer |
|------|----|--------|
| Wireshark | Linux Workstation | Echten Traffic beobachten |
| Nmap | Linux Workstation (`sudo pacman -S nmap`) | Hosts, Ports, Dienste scannen -> siehe Nmap-Anleitung.md |
| arp-scan | Linux Workstation (`sudo pacman -S arp-scan`) | Schnelles LAN-Inventar |
| Remote Capture | `ssh root@hypervisor-01 "tcpdump -w - -i vmbr0" \| wireshark -k -i -` | Alle VMs auf einmal sehen |
| Packet Tracer | netacad.com (kostenlos mit Account) | Szenarien simulieren ohne echte Hardware |
| GNS3 | gns3.com (spaeter) | Cisco-Images emulieren, advanced |
| Learning-NC | app-server:8080 | Spaced Repetition fuer Konzepte |

## Fortschritt

- [ ] Block 1: Das grosse Bild (Woche 1)
- [ ] Block 2: Adressen -- wie Pakete ihren Empfänger finden (Woche 2)
- [ ] Block 3: Subnetting -- Netzwerke aufteilen (Woche 3)
- [ ] Block 4: Layer 2 -- Ethernet, MAC, ARP (Woche 4)
- [ ] Block 5: Layer 3 -- IP-Routing, ICMP (Woche 5)
- [ ] Block 6: Transport -- TCP & UDP (Woche 6)
- [ ] Block 7: DNS -- das Telefonbuch des Internets (Woche 7)
- [ ] Block 8: DHCP & HTTP/HTTPS (Woche 8)
- [ ] Block 9: Sicherheit Basics (Woche 9-10)
- [ ] Block 10: WLAN (Woche 11)
- [ ] Block 11: Praxis-Projekt Packet Tracer (Woche 12)

---

## Block 1: Das grosse Bild

**Lernziel:** Verstehen warum Netzwerke existieren, was das OSI-Modell ist, und wie ein Paket von A nach B kommt -- konzeptionell.

### Warum dieser Block zuerst?
Ohne das OSI-Modell reden alle anderen Themen aneinander vorbei. Es ist die gemeinsame Sprache. Wer OSI versteht, kann jedes Netzwerk-Problem einordnen -- egal ob Anfaenger oder Senior.

### Theorie

#### Was ist ein Netzwerk?
Mehrere Geräte, die Daten austauschen können. Dein Lab ist ein Netzwerk: workstation (.10), mgmt-server (.60), hypervisor-01 (.20), automation-server (.70), alle reden miteinander.

#### Das OSI-Modell (7 Schichten)
Das OSI-Modell beschreibt wie Daten von einer Anwendung über Kabel/Funk zum Empfänger kommen -- in 7 abstrakten Schichten.

```
+--------------------------------------------------+
|  7  Application   HTTP, DNS, SMTP, SSH            |  <- Was du siehst
|  6  Presentation  TLS, Verschlüsselung, Encoding |
|  5  Session       Verbindungs-Management          |
|  4  Transport     TCP, UDP, Ports                 |  <- Zuverlaessigkeit
|  3  Network       IP, Routing, ICMP               |  <- Wegrouting
|  2  Data Link     Ethernet, MAC, ARP, Switch      |  <- Lokales Netz
|  1  Physical      Kabel, WLAN, Bits               |  <- Physik
+--------------------------------------------------+
```

**Eselsbrücke (oben -> unten):** "All People Seem To Need Data Processing"

**Wichtig:** In der Praxis nutzt jeder das TCP/IP-Modell mit 4 Schichten (Application / Transport / Internet / Network Access). OSI ist das Denkmodell, TCP/IP ist die Realität.

#### Was passiert wenn du google.com oeffnest?
1. **App (L7):** Browser baut HTTP-Request
2. **Transport (L4):** TCP packt Request in Segmente, Ziel-Port 443
3. **Network (L3):** IP-Paket wird erstellt, Ziel-IP von Google
4. **Data Link (L2):** Ethernet-Frame mit MAC deines Routers
5. **Physical (L1):** Bits raus übers Kabel
-> Beim Empfänger alles umgekehrt (De-Encapsulation)

#### Encapsulation -- der Paketierungsvorgang
```
Daten
  -> + TCP-Header     = Segment
  -> + IP-Header      = Paket
  -> + Ethernet-Header = Frame
  -> Bits über Kabel
```
Jede Schicht fuegt ihren Header hinzu (Sender) bzw. zieht ihn ab (Empfänger).

### Beobachten (Wireshark)

**Aufgabe 1 -- Erster Blick:**
```bash
# Wireshark starten, eigenes Interface (z.B. eth0) waehlen
# Filter eingeben: icmp
# Dann in Terminal:
ping -c 4 10.0.0.60  # mgmt-server anpingen
```
Was siehst du? ICMP Echo Request + Echo Reply. Klick auf ein Paket -> sieh die Schichten links unten (Ethernet / IP / ICMP).

**Aufgabe 2 -- Lab Traffic:**
```bash
ssh root@hypervisor-01 "tcpdump -w - -i vmbr0 -c 200 2>/dev/null" | wireshark -k -i -
# Einfach 30 Sekunden laufen lassen. Was passiert alles?
```

### Simulieren (Packet Tracer)
- Neue Simulation erstellen
- 2 PCs + 1 Switch platzieren
- PCs mit statischen IPs (192.168.1.1 / 192.168.1.2 /24) konfigurieren
- Ping von PC1 zu PC2
- Im "Simulation Mode" jeden Schritt einzeln durchklicken -- PDU-Details ansehen

### Learning-NC Karten (diese erstellen)
- Was sind die 7 OSI-Schichten? (und Beispiel-Protokoll je Schicht)
- Was ist Encapsulation?
- Unterschied OSI-Modell vs. TCP/IP-Modell?
- Was ist ein Frame? Was ist ein Paket? Was ist ein Segment?

---

## Block 2: IP-Adressen & Binär

**Lernziel:** IPv4-Adressen lesen, schreiben, in Binär umrechnen. Öffentlich vs. Privat verstehen.

### Warum?
Ohne IP-Adressen kommt kein Paket an. Und ohne Binär versteht man Subnetting nicht.

### Theorie

#### IPv4-Adresse
32 Bit, dargestellt als 4 Dezimalzahlen: `10.0.0.10`
Jede Zahl = 1 Byte = 8 Bit -> Wertebereich 0-255

#### Binär -- nur was du wirklich brauchst
```
128  64  32  16   8   4   2   1
  1   0   1   0   1   0   0   1  = 128+32+8+1 = 169
```
Trick: Von links nach rechts, passt die Zahl rein? -> 1, Rest weiter. Nein? -> 0.

#### Private vs. Öffentliche IP-Adressen
```
10.0.0.0    - 10.255.255.255   (Class A privat)
172.16.0.0  - 172.31.255.255   (Class B privat)
192.168.0.0 - 192.168.255.255  (Class C privat) <- dein Lab
```
Dein Router macht NAT: alle privaten IPs -> eine öffentliche. Deshalb kennt google.com nicht deine 10.0.0.10, sondern die IP deines Routers.

#### Loopback & Spezial-Adressen
- `127.0.0.1` = localhost (Gerät spricht mit sich selbst)
- `169.254.x.x` = APIPA (kein DHCP erreichbar)
- `255.255.255.255` = Broadcast (alle im Netz)

### Beobachten (Wireshark)
```bash
# Filter: ip.addr == 10.0.0.10
# Schaue: welche IPs kommunizieren mit dir?
# Filter: ip.addr == 10.0.0.30  (DNS-Server)
# Dazu im Terminal: nslookup google.com 10.0.0.30
```

### Simulieren (Packet Tracer)
- 3 PCs, unterschiedliche Netzwerke (192.168.1.x und 192.168.2.x)
- Versuch: PC1 (192.168.1.1) pingt PC3 (192.168.2.1) -- warum schlaegt es fehl?
- Antwort: kein Router dazwischen -> Block 5 erklärt die Loesung

### Learning-NC Karten
- Wie viele Bit hat eine IPv4-Adresse?
- Was bedeutet 10.0.0.10 in Binär? (erste 2 Oktette)
- Was sind die 3 privaten IP-Bereiche?
- Was ist NAT und warum brauchen wir es?
- Was ist Loopback?

---

## Block 3: Subnetting

**Lernziel:** Subnetzmaske lesen, CIDR-Notation verstehen, Netzwerke aufteilen können.

### Warum?
Subnetting ist die häufigste Prüfungsfrage im Network+. Und du brauchst es täglich wenn du Netzwerke planst.

### Theorie

#### Subnetzmaske
Trennt Netzwerk-Anteil von Host-Anteil einer IP.
```
IP:    10.0.0. 10   = 00001010.00000000.00000000.00001010
Maske: 255.255.255. 0   = 11111111.11111111.11111111.00000000
                                                      ^^^^^^^^
                                                      Host-Teil (256 Adressen)
```

#### CIDR-Notation
`/24` = 24 Einsen in der Maske = 255.255.255.0
```
/8  = 255.0.0.0       (16.777.214 Hosts)
/16 = 255.255.0.0     (65.534 Hosts)
/24 = 255.255.255.0   (254 Hosts)      <- dein Lab-Netz
/30 = 255.255.255.252 (2 Hosts)        <- Point-to-Point Links
```

#### Wichtige Adressen im Subnetz (Beispiel /24)
```
10.0.0.0   = Netzwerkadresse (nicht nutzbar)
10.0.0.1   = meist Default Gateway (Router)
10.0.0.254 = letzter nutzbarer Host
10.0.0.255 = Broadcast
```

#### Subnetting-Formel
- Hosts pro Subnetz: 2^(Host-Bits) - 2
- /24 -> 8 Host-Bits -> 2^8 - 2 = 254 Hosts

#### Dein Lab als Beispiel
```
Netz:    10.0.0.0/24
Router:  10.0.0.1 (lab-router)
Du:      10.0.0.10 (workstation)
Mgmt:    10.0.0.60
Hypervisor: 10.0.0.20
```

### Beobachten (Wireshark)
```bash
# Zeige deine aktuelle Netzwerk-Konfiguration
ip addr show
ip route show
# Verstehe: was ist dein Default Gateway? Welches Interface?
```

### Simulieren (Packet Tracer)
- 1 Router, 2 Switches, je 3 PCs
- Subnetz 1: 192.168.10.0/24
- Subnetz 2: 192.168.20.0/24
- Router-Interfaces konfigurieren
- Ping zwischen Subnetzen

### Learning-NC Karten
- Was bedeutet /24 in CIDR?
- Wie viele Hosts passen in ein /26-Netz?
- Was ist die Broadcast-Adresse von 192.168.1.0/24?
- Was ist der Unterschied zwischen Netzwerkadresse und Default Gateway?

---

## Block 4: Layer 2 -- Ethernet, MAC-Adressen, ARP, Switches

**Lernziel:** Verstehen wie Pakete im lokalen Netz von Gerät zu Gerät kommen.

### Warum?
Layer 3 (IP) kuemmert sich um den Weg durchs Internet. Layer 2 kuemmert sich darum, wie das Paket vom Router zu deinem Laptop kommt -- das letzte Stueck.

### Theorie

#### MAC-Adresse
48-Bit Hardware-Adresse, eingebrannt in die Netzwerkkarte.
Format: `AA:BB:CC:DD:EE:FF` (erste 3 Bytes = Hersteller-OUI, letzte 3 = Gerät)
```bash
ip link show  # eigene MACs sehen
```

#### Ethernet-Frame
```
+----------+---------+------+--------+--------------+-----+
| Ziel-MAC | Src-MAC | Type | Payload|   (Padding)  | FCS |
|  6 Byte  |  6 Byte |2 Byte| IP-Pkt |              |4 Byte|
+----------+---------+------+--------+--------------+-----+
```

#### ARP -- Address Resolution Protocol
Problem: IP-Paket hat Ziel-IP, aber der Switch braucht MAC.
Loesung: ARP fragt: "Wer hat 10.0.0.60? Sag mir deine MAC!"
```
ARP Request:  Broadcast -> "Wer hat .60?"
ARP Reply:    mgmt-server -> "Ich! Meine MAC ist XX:XX:XX:XX:XX:XX"
```
```bash
arp -n  # ARP-Cache ansehen -- gespeicherte IP->MAC-Zuordnungen
```

#### Switch vs. Hub vs. Router
```
Hub:    Alles an alle (veraltet, Sicherheitsproblem)
Switch: Lernt MAC-Tabelle, schickt Frame nur ans richtige Port
Router: Verbindet verschiedene Netzwerke (Layer 3)
```

### Beobachten (Wireshark)
```bash
# Filter: arp
# Dann in Terminal:
ping 10.0.0.60
# Sieh: erst ARP Request, dann ARP Reply, dann ICMP

# Noch besser -- auf Hypervisor-Bridge:
ssh root@hypervisor-01 "tcpdump -w - -i vmbr0 arp 2>/dev/null" | wireshark -k -i -
# Starte dann eine neue VM oder rufe eine an
```

### Simulieren (Packet Tracer)
- Switch mit 4 PCs
- Simulation Mode: ersten Ping beobachten
- Sieh wie Switch seine MAC-Table aufbaut (im Switch-CLI: `show mac-address-table`)

### Learning-NC Karten
- Was ist eine MAC-Adresse? Wie lang? Wo gespeichert?
- Was macht ARP?
- Unterschied Switch vs. Router?
- Was ist ein Broadcast und wann wird er genutzt?

---

## Block 5: Layer 3 -- IP-Routing & ICMP

**Lernziel:** Verstehen wie Pakete über mehrere Netzwerke geroutet werden.

### Theorie

#### Routing-Tabelle
Jedes Gerät hat eine Routing-Tabelle: "Fuer Ziel X -> nehme Interface Y / gehe über Gateway Z"
```bash
ip route show
# Beispiel-Output:
# default via 10.0.0.1 dev eth0       <- alles unbekannte -> Router
# 10.0.0.0/24 dev eth0                <- lokales Netz direkt
```

#### Default Gateway
Wenn keine spezifische Route passt -> Paket geht an Default Gateway (deinen Router).
Router hat dann wieder eine Routing-Tabelle usw. -- Hop by Hop bis zum Ziel.

#### TTL -- Time to Live
Jedes IP-Paket hat TTL (z.B. 64). Jeder Router zieht 1 ab.
Bei TTL=0 -> Router wirft Paket weg + sendet ICMP "Time Exceeded" zurück.
Verhindert ewige Routing-Schleifen.

#### ICMP -- Internet Control Message Protocol
Kein eigenes Transport-Protokoll, sondern Diagnose-Nachrichten:
- `Echo Request/Reply` = ping
- `Time Exceeded` = traceroute Mechanismus
- `Destination Unreachable` = Ziel nicht erreichbar

#### traceroute -- Routing sichtbar machen
```bash
traceroute 8.8.8.8
# Zeigt jeden Router (Hop) auf dem Weg zu Google
# Jede Zeile = ein Router, RTT in ms
```

### Beobachten (Wireshark)
```bash
# Filter: icmp
# Dann: traceroute 8.8.8.8
# Sieh: TTL steigt von 1 aufwaerts, verschiedene IPs antworten mit "Time Exceeded"

# Routing zwischen deinen VMs beobachten:
ssh root@hypervisor-01 "tcpdump -w - -i vmbr0 icmp 2>/dev/null" | wireshark -k -i -
# Dann: ping von workstation auf verschiedene Lab-Hosts
```

### Simulieren (Packet Tracer)
- 2 Router, 3 Netzwerke (192.168.1/2/3.0/24)
- Statische Routen konfigurieren
- traceroute von PC1 zu PC3 -- sieh die Hops

### Learning-NC Karten
- Was ist eine Routing-Tabelle?
- Was ist TTL und warum brauchen wir es?
- Was macht traceroute und wie funktioniert es?
- Was ist ICMP?

---

## Block 6: Transport -- TCP & UDP

**Lernziel:** TCP und UDP unterscheiden, Ports verstehen, den 3-Way Handshake kennen.

### Theorie

#### Ports
Ports identifizieren Dienste auf einem Host (nicht nur Gerät).
```
IP:Port = Socket  ->  10.0.0.100:8080 = App-Server HTTP
```

Bekannte Ports:
```
22   SSH
53   DNS
80   HTTP
443  HTTPS
3306 MySQL / 5432 PostgreSQL
8080 HTTP alternativ (z.B. App-Server)
```

#### TCP -- Transmission Control Protocol
Zuverlaessig, verbindungsorientiert.
- Bestaetigt jedes Segment (ACK)
- Wiederholt verlorene Pakete
- Fluss-Kontrolle (nicht überfluten)
- Reihenfolge garantiert

**3-Way Handshake:**
```
Client -> Server:  SYN       "Ich will verbinden, seq=100"
Server -> Client:  SYN-ACK   "OK, seq=200, ack=101"
Client -> Server:  ACK       "Verstanden, ack=201"
-> Verbindung steht, Daten können fließen
```

**Verbindungsabbau (4-Way):**
```
Client -> Server:  FIN
Server -> Client:  ACK
Server -> Client:  FIN
Client -> Server:  ACK
```

#### UDP -- User Datagram Protocol
Schnell, verbindungslos, keine Garantien.
- Kein Handshake
- Kein ACK
- Perfekt fuer: DNS, DHCP, VoIP, Video-Streaming, Games

### Beobachten (Wireshark)
```bash
# HTTP-Verbindung zum App-Server komplett beobachten:
# Filter: ip.addr == 10.0.0.100

# Im Browser: http://10.0.0.100:8080 aufrufen (oder curl)
# Sieh in Wireshark: SYN -> SYN-ACK -> ACK -> HTTP GET -> HTTP 200

# DNS beobachten (UDP):
# Filter: dns
# Dann: nslookup google.com
# UDP-Paket hin, UDP-Paket zurück -- kein Handshake
```

### Simulieren (Packet Tracer)
- Simulation Mode: HTTP-Anfrage zwischen PC und Server
- Jeden PDU-Schritt aufklappen: TCP-Header mit SYN/ACK/SEQ-Nummern sehen

### Learning-NC Karten
- Was sind die wichtigsten Unterschiede TCP vs. UDP?
- Beschreibe den 3-Way Handshake
- Was ist ein Port? Was ist ein Socket?
- Wann benutzt man UDP statt TCP?
- Welche Ports haben HTTP, HTTPS, SSH, DNS?

---

## Block 7: DNS

**Lernziel:** Verstehen wie Domainnamen in IP-Adressen aufgeloest werden.

### Warum?
Du hast einen DNS-Server auf .30 laufen -- du siehst DNS-Filtering live. Das macht diesen Block besonders konkret.

### Theorie

#### Was ist DNS?
Domain Name System = Telefonbuch des Internets.
Übersetzt `google.com` -> `142.250.x.x`

#### DNS-Hierarchie
```
         . (Root, unsichtbar)
         |
        com    org    de    ...
         |
      google
         |
       www   mail   ...
```
Aufloesung von rechts nach links: `. -> com -> google -> www`

#### DNS-Ablauf (rekursiv)
```
Du -> Resolver (DNS-Server .30): "Was ist google.com?"
Resolver -> Root-Server:         "Wer kennt .com?"
Resolver -> .com-Server:         "Wer kennt google.com?"
Resolver -> google.com-NS:       "Was ist www.google.com?"
-> IP zurück, gecacht
```

#### DNS Record-Typen
```
A       -> IPv4-Adresse
AAAA    -> IPv6-Adresse
CNAME   -> Alias auf anderen Namen
MX      -> Mail-Server
TXT     -> Text (SPF, DKIM, Verifikation)
PTR     -> Reverse DNS (IP -> Name)
NS      -> Nameserver fuer Zone
```

#### DNS über TCP vs. UDP
- Normal: UDP Port 53 (schnell, kleine Pakete)
- Bei grossen Antworten: TCP Port 53 (Zone Transfers)

### Beobachten (Wireshark + DNS-Server)
```bash
# Filter: dns
# Dann verschiedene Domains abfragen:
nslookup google.com 10.0.0.30
nslookup app-server 10.0.0.30
nslookup thisdomaindoesnotexist123.com 10.0.0.30

# Sieh: UDP-Request, UDP-Response, Record-Typ, TTL
# DNS-Server Weboberflaeche oeffnen: Query-Log anschauen
# Was wird gefiltert? Was wird gecacht?

# Reverse DNS:
nslookup 8.8.8.8
```

### Simulieren (Packet Tracer)
- DNS-Server konfigurieren
- PCs über Namen statt IP anpingen
- Simulation Mode: DNS-Query beobachten bevor HTTP funktioniert

### Learning-NC Karten
- Was ist DNS und warum brauchen wir es?
- Welche DNS Record-Typen gibt es? (A, AAAA, CNAME, MX, TXT, PTR)
- Was ist der Unterschied zwischen autoritativem und rekursivem DNS?
- Warum nutzt DNS UDP statt TCP?
- Was ist ein DNS-Cache und warum ist TTL wichtig?

---

## Block 8: DHCP & HTTP/HTTPS

**Lernziel:** Verstehen wie Geräte automatisch IPs bekommen (DHCP) und wie Web-Kommunikation funktioniert.

### DHCP -- Dynamic Host Configuration Protocol

#### DORA-Prozess
```
Discover  -> Client Broadcast: "Ich brauche eine IP!"
Offer     -> DHCP-Server: "Nimm 10.0.0.50!"
Request   -> Client: "Ja, ich nehme .50!"
Acknowledge -> Server: "Bestaetigt, gueltig fuer 24h"
```

#### Was DHCP vergibt
- IP-Adresse + Subnetzmaske
- Default Gateway
- DNS-Server
- Lease-Time (wie lange die IP gilt)

```bash
# DHCP-Lease-Info:
cat /var/lib/dhcp/dhclient.leases  # oder
nmcli device show | grep DHCP
```

### Beobachten (Wireshark)
```bash
# Filter: bootp  (DHCP nutzt BOOTP-Protokoll)
# Dann: sudo dhclient -r eth0 && sudo dhclient eth0
# Sieh den kompletten DORA-Handshake
```

### HTTP/HTTPS

#### HTTP -- HyperText Transfer Protocol
Textbasiertes Protokoll, Port 80.
```
GET /api/pools HTTP/1.1
Host: app-server:8080
Authorization: Basic xxx

HTTP/1.1 200 OK
Content-Type: application/json
{"pools": [...]}
```

#### HTTPS = HTTP + TLS
TLS macht vor dem HTTP-Request zuerst einen Handshake:
1. Client Hello (unterstuetzte TLS-Versionen, Cipher Suites)
2. Server Hello + Zertifikat
3. Key Exchange (asymmetrisch -> symmetrischer Session-Key)
4. Fertig: alles verschlüsselt

**Wichtig:** In Wireshark siehst du bei HTTPS nur "TLSv1.3 Application Data" -- der Inhalt ist verschlüsselt. Nur Metadaten (IPs, Ports, Zertifikat-Info) sichtbar.

### Beobachten (Wireshark)
```bash
# HTTP (unverschlüsselt) auf App-Server:
# Filter: http
curl http://10.0.0.100:8080/apps/learning/
# Sieh: vollstaendiger HTTP-Request + Response im Klartext!

# HTTPS zum Vergleich:
# Filter: tls
curl https://google.com
# Nur TLS-Handshake sichtbar, Inhalt verschlüsselt
```

### Learning-NC Karten
- Was sind die 4 DORA-Schritte bei DHCP?
- Was gibt DHCP einem Client mit?
- Was ist der Unterschied HTTP vs. HTTPS?
- Was macht TLS beim Verbindungsaufbau?
- Warum kann man HTTPS-Traffic in Wireshark nicht lesen?

---

## Block 9: Sicherheit Basics

**Lernziel:** Firewalls, NAT, VLANs, häufige Angriffe verstehen.

### NAT -- Network Address Translation
Dein Router übersetzt private IPs in deine eine öffentliche IP.
```
Workstation 10.0.0.10:54321 -> Router -> 93.x.x.x:54321 -> Internet
Antwort: 93.x.x.x:54321 -> Router -> 10.0.0.10:54321
```
NAT-Tabelle im Router merkt sich welche interne IP welchen Port nutzt.

### Firewall-Konzepte
```
Stateless:  Regeln prüfen jedes Paket einzeln (src/dst IP + Port)
Stateful:   Verbindungsstatus wird getrackt -- Antworten automatisch erlaubt
WAF:        Web Application Firewall -- prüft HTTP-Inhalt (SQL Injection etc.)
```

Der Hypervisor nutzt `nftables`/`iptables`. Du kannst deine Firewall-Regeln ansehen:
```bash
ssh root@hypervisor-01 "nft list ruleset"  # oder
ssh root@hypervisor-01 "iptables -L -n -v"
```

### Häufige Angriffe (zum Kennen, nicht zum Durchführen)
```
ARP Spoofing:   Falscher ARP-Reply -> Man-in-the-Middle im LAN
DNS Poisoning:  Falscher DNS-Cache -> Redirect auf Fake-Site
SYN Flood:      Viele SYN ohne ACK -> Server-Ressourcen erschoepfen
Port Scanning:  nmap scannt offene Ports
```

```bash
# Dein eigenes Netz scannen (lehrreich):
nmap -sV 10.0.0.0/24
# Sieh welche Ports auf welchen Hosts offen sind
```

### Beobachten (Wireshark)
```bash
# ARP-Cache-Poisoning Schutz in deinem Netz prüfen:
# Filter: arp
# Beobachte: Gibt es unerwartete ARP-Replies?

# Hypervisor Firewall-Traffic:
ssh root@hypervisor-01 "tcpdump -w - -i vmbr0 -c 500 2>/dev/null" | wireshark -k -i -
# Welche Verbindungen zwischen VMs siehst du?
```

### Learning-NC Karten
- Was ist NAT und warum brauchen wir es?
- Unterschied Stateless vs. Stateful Firewall?
- Was ist ARP Spoofing?
- Was macht nmap?

---

## Block 10: WLAN Basics

**Lernziel:** WLAN-Konzepte verstehen, Unterschiede zu kabelgebundenen Netzen.

### Theorie

#### WLAN Standards (IEEE 802.11)
```
802.11n   (WiFi 4):  2.4/5 GHz, bis 600 Mbit/s
802.11ac  (WiFi 5):  5 GHz, bis 3.5 Gbit/s
802.11ax  (WiFi 6):  2.4/5/6 GHz, bis 9.6 Gbit/s
```

#### Frequenzbänder
```
2.4 GHz: Weiter Reichweite, wenige Kanäle (1/6/11 überlappungsfrei), voller
5 GHz:   Kurze Reichweite, viele Kanäle, schneller
```

#### SSID, BSSID, AP
- SSID = Netzwerkname (was du siehst)
- BSSID = MAC des Access Points
- BSS = Basic Service Set (ein AP + seine Clients)
- ESS = Extended SS (mehrere APs, gleiche SSID = Roaming)

#### WPA2 vs. WPA3
```
WPA2: AES-CCMP, Pre-Shared Key (PSK) oder Enterprise (802.1X)
WPA3: SAE statt PSK (kein Dictionary-Angriff), Forward Secrecy
```

### Beobachten
```bash
# Verfügbare Netzwerke scannen:
nmcli device wifi list
# Signal, Frequenz, Sicherheit, BSSID sehen

# Verbindungsdetails:
iwconfig  # oder
iw dev wlan0 link
```

### Learning-NC Karten
- Was ist der Unterschied 2.4 GHz vs. 5 GHz?
- Was ist SSID vs. BSSID?
- Warum ist WPA3 sicherer als WPA2?

---

## Block 11: Praxis-Projekt (Packet Tracer)

**Lernziel:** Alles kombinieren -- ein kleines, vollstaendiges Netzwerk von Grund auf bauen.

### Aufgabe: Firmen-Netzwerk simulieren

Baue ein Netzwerk mit:
- **2 Abteilungen** (VLANs): IT (10.0.1.0/24) + HR (10.0.2.0/24)
- **1 Router** mit Inter-VLAN Routing
- **1 Switch** mit VLAN-Konfiguration
- **1 DNS-Server** (10.0.1.10)
- **1 DHCP-Server** (je Subnetz)
- **1 Web-Server** (10.0.1.20, HTTP)
- **6 Clients** (3 pro VLAN, per DHCP konfiguriert)

Anforderungen:
- [ ] IT-Clients bekommen IP per DHCP
- [ ] HR-Clients bekommen IP per DHCP
- [ ] IT-Client kann Web-Server per Name aufrufen
- [ ] HR-Client kann Web-Server per IP aufrufen
- [ ] HR kann NICHT direkt auf IT-Geräte zugreifen (ACL)
- [ ] Simulation Mode: HTTP-Request Schritt fuer Schritt durchklicken

---

## Block 12: Wiederholung & Network+ Vorbereitung

**Lernziel:** Lücken schließen, Prüfungsformat kennen.

### Network+ Prüfungsformat (CompTIA N10-009)
- 90 Minuten, max. 90 Fragen
- Multiple Choice + Performance-Based Questions (simulierte Szenarien)
- Bestehensgrenze: 720/900
- Themen: Networking Fundamentals (23%), Network Implementations (19%), Network Operations (17%), Network Security (20%), Network Troubleshooting (21%)

### Ressourcen
- **Professor Messer** (professormesser.com) -- kostenlose N+ Kursvideos
- **Jason Dion** (Udemy) -- günstige Prüfungsvorbereitung
- **CompTIA CertMaster Practice** -- offizielle Uebungsfragen (kostenpflichtig)
- **Subreddit** r/CompTIA -- Community-Erfahrungen

### Schwache Stellen identifizieren
Gehe alle Learning-NC Karten durch. Welche Themen kommen immer noch falsch?
-> Gezielte Wiederholung dieser Bloecke

---

## Wireshark Cheat Sheet (Lab)

```bash
# Remote Capture -- alle VMs
ssh root@hypervisor-01 "tcpdump -w - -i vmbr0 2>/dev/null" | wireshark -k -i -

# Gezielter nach Protokoll
ssh root@hypervisor-01 "tcpdump -w - -i vmbr0 port 53 2>/dev/null" | wireshark -k -i -    # DNS
ssh root@hypervisor-01 "tcpdump -w - -i vmbr0 port 80 2>/dev/null" | wireshark -k -i -    # HTTP
ssh root@hypervisor-01 "tcpdump -w - -i vmbr0 arp 2>/dev/null" | wireshark -k -i -         # ARP
ssh root@hypervisor-01 "tcpdump -w - -i vmbr0 icmp 2>/dev/null" | wireshark -k -i -        # Ping

# Lokal -- eigener Traffic
wireshark  # Interface waehlen

# Nuetzliche Wireshark-Filter
ip.addr == 10.0.0.100            # nur App-Server
tcp.flags.syn == 1                # alle SYN-Pakete
http                              # HTTP-Traffic
dns                               # DNS-Queries
arp                               # ARP
icmp                              # Ping/traceroute
not arp and not broadcast         # Rauschen reduzieren
```

---

## Verlinkung

- siehe Netzwerkaufbau-bei-Grossevents.md -- Praxis-Beispiel fuer Skalierung
