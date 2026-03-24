# CompTIA Network+ N10-009 -- Wissensbasis

> Kompakte Zusammenfassung aller pruefungsrelevanten Begriffe und Themen.
> Quelle: Kammermann, "CompTIA Network+", 9. Auflage 2024 (mitp).
> 5 Wissensgebiete, 23 Kapitel, 90 Pruefungsfragen + ~200 Kapitel-Fragen.

---

## Pruefungsueberblick

| Feld | Wert |
|------|------|
| Pruefung | N10-009 |
| Fragen | max. 90 (Multiple Choice + Performance-Based) |
| Dauer | 90 Minuten |
| Bestehensgrenze | 720 von 900 Punkten |
| Sprache | Deutsch oder Englisch |
| Voraussetzung | CompTIA A+ empfohlen + 9-12 Monate Erfahrung |

### Die 5 Wissensgebiete (Domains)

| Nr | Domain | Gewichtung | Buch-Kapitel |
|----|--------|-----------|--------------|
| 1 | Netzwerk-Konzepte | ~23% | 2-5, 9-10 |
| 2 | Netzwerk-Implementationen | ~19% | 4, 6-8, 11 |
| 3 | Netzwerkbetrieb | ~20% | 12, 17-18 |
| 4 | Netzwerksicherheit | ~19% | 13-16 |
| 5 | Netzwerk-Troubleshooting | ~19% | 19-22 |

---

## Domain 1: Netzwerk-Konzepte (~23%)

### 1.1 OSI-Modell und DoD-Modell (Kap 2)

```
OSI 7 Schichten (oben -> unten):
7  Application    HTTP, DNS, SMTP, SSH, FTP, SNMP
6  Presentation   TLS/SSL, Verschluesselung, Encoding, Kompression
5  Session        Verbindungs-Management, NetBIOS
4  Transport      TCP, UDP, Ports, Segmente
3  Network        IP, ICMP, Routing, Pakete
2  Data Link      Ethernet, MAC, ARP, Switch, Frames
1  Physical       Kabel, WLAN, Bits, Hubs, Repeater

Eselsbruecke: "All People Seem To Need Data Processing"
```

**DoD/TCP-IP-Modell** (4 Schichten -- die Praxis):

| DoD | OSI-Entsprechung | Protokolle |
|-----|------------------|------------|
| Application | 5-7 | HTTP, DNS, FTP, SSH, SMTP |
| Transport | 4 | TCP, UDP |
| Internet | 3 | IP, ICMP, ARP |
| Network Access | 1-2 | Ethernet, WLAN |

**Kernbegriffe:**
- **Encapsulation**: Jede Schicht fuegt Header hinzu (Daten -> Segment -> Paket -> Frame -> Bits)
- **De-Encapsulation**: Umgekehrt beim Empfaenger
- **PDU** (Protocol Data Unit): Dateneinheit pro Schicht (Frame, Paket, Segment, Daten)
- **SAP** (Service Access Point): Schnittstelle zwischen Schichten

### 1.2 Netzwerk-Grundbegriffe (Kap 3)

**Uebertragungstechnik:**

| Begriff | Erklaerung |
|---------|-----------|
| Bandbreite | Maximale Datenmenge pro Zeiteinheit (bps, Mbps, Gbps) |
| Latenz | Verzoegerung eines Signals (ms) -- kritisch bei VoIP/Gaming |
| Jitter | Schwankung der Latenz -- schlecht fuer Echtzeitanwendungen |
| Throughput | Tatsaechlich gemessener Durchsatz (immer < Bandbreite) |
| Daempfung | Signalverlust ueber Distanz |
| Crosstalk | Stoerung zwischen benachbarten Leitungen |
| EMI | Elektromagnetische Interferenz von externen Quellen |
| Duplex | Half-Duplex (abwechselnd) vs Full-Duplex (gleichzeitig) |
| Baseband | Gesamte Bandbreite fuer ein Signal |
| Broadband | Bandbreite aufgeteilt auf mehrere Signale |
| Multiplexing | TDM (Zeit), FDM (Frequenz), WDM (Wellenlaenge bei Glasfaser) |

**Zahlensysteme:**
- Binaer (Basis 2): 11000000 = 192
- Dezimal (Basis 10): Standard
- Hexadezimal (Basis 16): MAC-Adressen, IPv6

### 1.3 Topologien (Kap 5)

**Physische Topologien:**

| Topologie | Beschreibung | Vorteil | Nachteil |
|-----------|-------------|---------|----------|
| Stern (Star) | Alle zu einem zentralen Switch | Einfach, Ausfallsicher | Single Point of Failure (Switch) |
| Bus | Alle an einem Kabel | Guenstig | Ein Bruch = alles tot |
| Ring | Kette, Anfang = Ende | Deterministisch | Ein Ausfall = Ring unterbrochen |
| Mesh (Full) | Jeder mit jedem | Maximale Redundanz | Teuer, komplex |
| Mesh (Partial) | Einige mit einigen | Guter Kompromiss | Planung noetig |
| Hybrid | Kombination | Flexibel | Komplex |

**Logische Konzepte:**
- **Unicast**: 1:1 (eine Quelle -> ein Ziel)
- **Broadcast**: 1:alle (z.B. ARP Request, DHCP Discover)
- **Multicast**: 1:Gruppe (z.B. Streaming, OSPF Updates)
- **Anycast**: 1:naechster (z.B. DNS-Anycast, IPv6)

**Vermittlungsarten:**
- **Leitungsvermittelt** (Circuit-Switched): ISDN, POTS -- dedizierter Kanal
- **Paketvermittelt** (Packet-Switched): IP-Netze -- Pakete unabhaengig geroutet
- **Nachrichtenvermittelt** (Message-Switched): Store-and-Forward, z.B. E-Mail

### 1.4 IP-Adressierung (Kap 9)

**IPv4-Grundlagen:**
- 32 Bit = 4 Oktette (z.B. 10.0.0.10)
- Netzwerk-Teil + Host-Teil, getrennt durch Subnetzmaske

**Private IP-Bereiche (RFC 1918):**

| Klasse | Bereich | CIDR | Hosts |
|--------|---------|------|-------|
| A | 10.0.0.0 - 10.255.255.255 | 10.0.0.0/8 | ~16 Mio |
| B | 172.16.0.0 - 172.31.255.255 | 172.16.0.0/12 | ~1 Mio |
| C | 192.168.0.0 - 192.168.255.255 | 192.168.0.0/16 | ~65k |

**Besondere Adressen:**

| Adresse | Funktion | Hinweis |
|---------|----------|---------|
| 127.0.0.0/8 | Loopback | 127.0.0.1 = localhost |
| 0.0.0.0 | Unspecified / Default Route | **Nicht** der Default Gateway! 0.0.0.0/0 = Route fuer unbekannte Netze |
| 255.255.255.255 | Limited Broadcast | Geht nicht ueber Router hinaus |
| 169.254.0.0/16 | APIPA / Link-Local | **Nicht privat (RFC1918)!** Selbstvergabe bei fehlendem DHCP |
| 224.0.0.0/4 | Multicast (Klasse D) | 224.0.0.0 - 239.255.255.255 |
| 240.0.0.0/4 | Reserviert (Klasse E) | Experimentell |

> **Pruefungsfalle**: 169.254.x.x ist APIPA, nicht privat. Private Netze sind **ausschliesslich** 10/8, 172.16/12, 192.168/16.

**Subnetting -- das Wichtigste:**

```
CIDR    Maske               Hosts   Anwendung
/24     255.255.255.0       254     Standard-LAN
/25     255.255.255.128     126     Halbiertes Netz
/26     255.255.255.192     62      Kleines Segment
/27     255.255.255.224     30      Server-VLAN
/28     255.255.255.240     14      Management-Netz
/29     255.255.255.248     6       Point-to-Point
/30     255.255.255.252     2       Router-Link
/31     255.255.255.254
/32     255.255.255.255     1       Host-Route
```

**Subnetting-Rechnung:**
1. Netzadresse = erste Adresse im Subnetz (Host-Bits = 0)
2. Broadcast = letzte Adresse (Host-Bits = 1)
3. Nutzbare Hosts = 2^(Host-Bits) - 2

**Lab-Netzwerk:**
```
Netz:        10.0.0.0/24
Gateway:     10.0.0.1   (lab-router)
Workstation: 10.0.0.10  (Linux, eth0)
Mgmt-Server: 10.0.0.60
Hypervisor:  10.0.0.20
App-Server:  10.0.0.100
DNS-Server:  10.0.0.30
```

**IPv6-Grundlagen:**
- 128 Bit = 8 Gruppen a 4 Hex-Zeichen
- Kurzschreibweise: fuehrende Nullen weglassen, `::` fuer laengste Null-Sequenz
- `fe80::/10` = Link-Local (wie APIPA)
- `2000::/3` = Global Unicast (oeffentlich)
- `fc00::/7` = Unique Local (wie RFC 1918)
- `::1` = Loopback
- Uebergang: **6in4** Tunneling, **Dual-Stack**, **NAT64**

### 1.5 TCP/IP-Protokolle (Kap 10)

**TCP vs UDP:**

| Eigenschaft | TCP | UDP |
|------------|-----|-----|
| Verbindung | Verbindungsorientiert | Verbindungslos |
| Zuverlaessigkeit | Ja (ACK, Retransmission) | Nein |
| Reihenfolge | Garantiert | Nicht garantiert |
| Overhead | Hoch | Niedrig |
| Anwendung | HTTP, SSH, FTP, SMTP | DNS, DHCP, VoIP, Streaming |

**TCP 3-Way Handshake:**
```
Client -> SYN      -> Server
Client <- SYN-ACK  <- Server
Client -> ACK      -> Server
-> Verbindung steht, Daten fliessen
```

**TCP-Verbindungsabbau:** FIN -> ACK -> FIN -> ACK (4-Way)

**TCP-Flags:** SYN, ACK, FIN, RST, PSH, URG

**Wichtige Protokolle & Ports:**

| Port | Protokoll | Transport | Funktion |
|------|-----------|-----------|----------|
| 20/21 | FTP | TCP | Dateitransfer (20=Daten, 21=Steuerung) |
| 22 | SSH/SFTP/SCP | TCP | Sichere Shell / Dateitransfer |
| 23 | Telnet | TCP | Unsichere Remote-Shell |
| 25 | SMTP | TCP | Mail senden |
| 53 | DNS | TCP+UDP | Namensaufloesung |
| 67/68 | DHCP | UDP | IP-Vergabe (67=Server, 68=Client) |
| 69 | TFTP | UDP | Einfacher Dateitransfer |
| 80 | HTTP | TCP | Webseiten unverschluesselt |
| 110 | POP3 | TCP | Mail abholen |
| 123 | NTP | UDP | Zeitsynchronisation |
| 143 | IMAP | TCP | Mail synchronisieren |
| 161/162 | SNMP | UDP | Netzwerkmanagement |
| 389 | LDAP | TCP | Verzeichnisdienst |
| 443 | HTTPS | TCP | Webseiten verschluesselt |
| 445 | SMB | TCP | Windows-Dateifreigabe |
| 514 | Syslog | UDP | Log-Uebertragung |
| 587 | SMTP (Submission) | TCP | Mail senden (authentifiziert) |
| 636 | LDAPS | TCP | LDAP verschluesselt |
| 993 | IMAPS | TCP | IMAP verschluesselt |
| 995 | POP3S | TCP | POP3 verschluesselt |
| 3389 | RDP | TCP | Windows Remote Desktop |

**Port-Bereiche:**
- 0-1023: Well-known Ports (reserviert)
- 1024-49151: Registered Ports
- 49152-65535: Dynamic/Private Ports

**ARP (Address Resolution Protocol):**
- Loesung: IP -> MAC im lokalen Netz
- ARP Request = Broadcast ("Wer hat 10.0.1.1?")
- ARP Reply = Unicast ("Ich! Meine MAC ist aa:bb:cc:dd:ee:ff")
- RARP: umgekehrt (MAC -> IP, veraltet)
- `arp -a` zeigt den lokalen ARP-Cache

**ICMP:**
- Fehlermeldungen und Diagnose auf Layer 3
- `ping` = ICMP Echo Request/Reply (Erreichbarkeit)
- `traceroute` = ICMP TTL Exceeded (Pfad verfolgen)
- Typen: Echo (8/0), Destination Unreachable (3), TTL Exceeded (11), Redirect (5)

**NAT/PAT:**
- **NAT**: Eine private IP <-> eine oeffentliche IP
- **PAT** (Port Address Translation): Viele private IPs <-> eine oeffentliche IP (verschiedene Ports)
- Dein lab-router macht PAT fuer dein ganzes 10.0.0.0/24

**QoS (Quality of Service):**
- **DiffServ**: DSCP-Feld im IP-Header (Layer 3), Pakete nach Klassen priorisieren
- **CoS**: 802.1p Tag im VLAN-Header (Layer 2), 0-7 Prioritaetsstufen
- Wichtig fuer VoIP: niedriger Jitter, niedrige Latenz, geringer Paketverlust

---

## Domain 2: Netzwerk-Implementationen (~19%)

### 2.1 Hardware & Medien (Kap 4)

**Kabeltypen:**

| Kabel | Max. Laenge | Geschwindigkeit | Einsatz |
|-------|------------|-----------------|---------|
| Cat 5e | 100m | 1 Gbps | Legacy-LAN |
| Cat 6 | 55m (10G) / 100m (1G) | 10 Gbps | Standard |
| Cat 6a | 100m | 10 Gbps | Empfohlen |
| Cat 7 | 100m | 10 Gbps | S/FTP geschirmt |
| Cat 8 | 30m | 25/40 Gbps | Rechenzentrum |
| Koaxial (RG-6) | 500m | variabel | TV/Kabel-Internet |
| Singlemode LWL | 40+ km | 100+ Gbps | WAN, Backbone |
| Multimode LWL | 550m (OM3) | 10/40 Gbps | LAN, Rechenzentrum |

**Stecker:**

| Stecker | Kabel | Einsatz |
|---------|-------|---------|
| RJ-45 | UTP/STP | Ethernet |
| LC | Glasfaser | Standard-LWL |
| SC | Glasfaser | Aeltere LWL |
| ST | Glasfaser | Legacy |
| F-Type | Koaxial | TV/Kabel |

**Netzwerkgeraete:**

| Geraet | OSI-Layer | Funktion |
|--------|-----------|----------|
| Hub | 1 | Signal-Verstaerker, alle Ports = eine Kollisionsdomaene |
| Repeater | 1 | Signal-Verstaerker ueber Distanz |
| Bridge | 2 | Trennt Kollisionsdomaenen, lernt MACs |
| Switch (L2) | 2 | MAC-basiert, jeder Port = eigene Kollisionsdomaene |
| Switch (L3) | 3 | Kann auch routen (IP-basiert) |
| Router | 3 | Verbindet Netze, IP-basiert, trennt Broadcast-Domaenen |
| Firewall | 3-7 | Filtert Traffic nach Regeln |
| Access Point | 1-2 | WLAN-Zugang |
| Modem | 1 | Digital <-> Analog (DSL, Kabel) |
| Media Converter | 1 | Kupfer <-> Glasfaser |
| Load Balancer | 4-7 | Verteilt Last auf mehrere Server |
| Proxy | 7 | Stellt Anfragen stellvertretend |
| IDS/IPS | 3-7 | Erkennt/verhindert Angriffe |

**PoE (Power over Ethernet):**
- 802.3af: 15.4W (IP-Telefone, einfache APs)
- 802.3at (PoE+): 25.5W (bessere APs, Kameras)
- 802.3bt (PoE++): 71W (PTZ-Kameras, Displays)

### 2.2 Ethernet & VLANs (Kap 6)

**Ethernet-Standards:**

| Standard | Geschwindigkeit | Medium |
|----------|----------------|--------|
| 10Base-T | 10 Mbps | Cat 3+ |
| 100Base-TX (Fast Ethernet) | 100 Mbps | Cat 5+ |
| 1000Base-T (Gigabit) | 1 Gbps | Cat 5e+ |
| 1000Base-SX | 1 Gbps | Multimode LWL |
| 1000Base-LX | 1 Gbps | Singlemode LWL |
| 10GBase-T | 10 Gbps | Cat 6a |
| 10GBase-SR | 10 Gbps | Multimode LWL |
| 10GBase-LR | 10 Gbps | Singlemode (10km) |
| 10GBase-ER | 10 Gbps | Singlemode (40km) |
| 40GBase-SR4 | 40 Gbps | Multimode LWL |

**CSMA/CD**: Carrier Sense Multiple Access / Collision Detection -- Ethernet-Zugriffsverfahren.

**VLAN (Virtual LAN):**
- Logische Trennung auf einem physischen Switch
- Trennt Broadcast-Domaenen ohne Router
- **802.1Q**: Trunk-Protokoll -- VLAN-Tag im Frame (4 Bytes)
- **Trunk**: Verbindung zwischen Switches die mehrere VLANs transportiert
- **Access Port**: Gehoert zu genau einem VLAN
- **VTP** (VLAN Trunking Protocol): Cisco-proprietaer, verbreitet VLAN-Konfiguration

**STP (Spanning Tree Protocol):**
- Verhindert Loops in redundanten Switch-Topologien
- Waehlt Root Bridge, sperrt redundante Pfade
- **RSTP (802.1w)**: Schnellere Konvergenz
- **MSTP (802.1s)**: Mehrere Spanning Trees fuer verschiedene VLANs

### 2.3 WLAN (Kap 7)

**Wi-Fi Standards:**

| Standard | Name | Frequenz | Max. Speed | Reichweite |
|----------|------|----------|-----------|-----------|
| 802.11a | -- | 5 GHz | 54 Mbps | Kurz |
| 802.11b | -- | 2.4 GHz | 11 Mbps | Lang |
| 802.11g | -- | 2.4 GHz | 54 Mbps | Mittel |
| 802.11n | Wi-Fi 4 | 2.4 + 5 GHz | 600 Mbps | Gut |
| 802.11ac | Wi-Fi 5 | 5 GHz | 6.9 Gbps | Mittel |
| 802.11ax | Wi-Fi 6/6E | 2.4 + 5 + 6 GHz | 9.6 Gbps | Gut |
| 802.11be | Wi-Fi 7 | 2.4 + 5 + 6 GHz | 46 Gbps | Gut |

**WLAN-Begriffe:**
- **SSID**: Netzwerkname
- **BSSID**: MAC des Access Points
- **BSS**: Basic Service Set (1 AP + Clients)
- **ESS**: Extended Service Set (mehrere APs, gleiches Netz = Roaming)
- **MIMO**: Multiple Input Multiple Output (mehrere Antennen)
- **MU-MIMO**: Multi-User MIMO (mehrere Clients gleichzeitig)
- **OFDMA**: Orthogonal Frequency Division Multiple Access (Wi-Fi 6)
- **Beamforming**: Signal gezielt auf Client richten
- **Channel Bonding**: Kanaele buendeln fuer mehr Bandbreite
- **WPS**: Wi-Fi Protected Setup (unsicher! Deaktivieren!)

**Frequenzen:**
- **2.4 GHz**: 3 ueberlappungsfreie Kanaele (1, 6, 11), groessere Reichweite, mehr Stoerungen
- **5 GHz**: Viele Kanaele (bis 165), weniger Stoerungen, geringere Reichweite
- **6 GHz**: Wi-Fi 6E/7, noch mehr Kanaele, am wenigsten Stoerungen

**WLAN-Sicherheit:**
- **WEP**: Veraltet, unsicher (RC4, leicht knackbar)
- **WPA**: TKIP-Verschluesselung (besser, aber auch unsicher)
- **WPA2**: AES/CCMP -- aktueller Standard
- **WPA3**: SAE-Handshake (kein PSK-Woerterbuch-Angriff), PMF obligatorisch
- **Enterprise**: 802.1X + RADIUS (Benutzername/Passwort)
- **Personal**: Pre-Shared Key (gemeinsames Passwort)

**Kurzstrecken:**

| Technologie | Reichweite | Geschwindigkeit | Einsatz |
|------------|-----------|-----------------|---------|
| Bluetooth 5.x | 10-200m | 2 Mbps | Headsets, IoT |
| Zigbee | 10-100m | 250 kbps | Smart Home, Sensoren |
| Z-Wave | 30m | 100 kbps | Heimautomation |
| NFC | <10cm | 424 kbps | Bezahlen, Zugang |
| RFID | 1-100m | variabel | Inventar, Zugang |

### 2.4 WAN-Technologien (Kap 8)

| Technologie | Typ | Geschwindigkeit | Einsatz |
|------------|-----|-----------------|---------|
| DSL (ADSL/VDSL) | Kupfer | 10-250 Mbps | Heimanschluss |
| Kabel (DOCSIS) | Koaxial | bis 10 Gbps (D3.1) | Heimanschluss |
| FTTH (Fiber) | Glasfaser | 1-10 Gbps | Wohn-/Geschaeft |
| SONET/SDH | Glasfaser | OC-1 (51 Mbps) bis OC-192 | Backbone |
| MPLS | Label-Switching | variabel | Enterprise WAN |
| Metro Ethernet | Ethernet | 1-100 Gbps | Stadtnetze |
| Satellit | Funk | 25-100 Mbps | Laendlich, hohe Latenz |
| 4G/LTE | Mobilfunk | 50-300 Mbps | Mobil, Backup |
| 5G | Mobilfunk | 1-10 Gbps | Zukunft, IoT |
| LPWAN (LoRa) | Funk | <50 kbps | IoT, Sensoren |
| ATM | Zellen (53 Bytes) | 155-622 Mbps | Legacy |

### 2.5 Dienste (Kap 11)

**Routing-Protokolle:**

| Protokoll | Typ | Metrik | Einsatz |
|-----------|-----|--------|---------|
| RIP (v2) | Distance Vector | Hop Count (max 15) | Kleine Netze |
| OSPF | Link State | Kosten (Bandbreite) | Enterprise |
| IS-IS | Link State | Kosten | ISP, grosse Netze |
| BGP | Path Vector | AS-Pfad | Internet (zwischen ISPs) |
| EIGRP | Hybrid (Cisco) | Bandbreite + Delay | Cisco-Netze |

**Redundanz-Protokolle:**
- **VRRP**: Virtual Router Redundancy Protocol (offener Standard)
- **HSRP**: Hot Standby Router Protocol (Cisco)
- **CARP**: Common Address Redundancy Protocol (BSD)
- **FHRP**: First Hop Redundancy Protocol (Oberbegriff)

**DHCP (Dynamic Host Configuration Protocol):**
```
DORA-Prozess:
1. Discover (Client -> Broadcast: "Ich brauche eine IP!")
2. Offer    (Server -> "Hier, nimm 10.0.0.42")
3. Request  (Client -> "Ja, die nehme ich!")
4. ACK      (Server -> "Bestaetigt, Lease 24h")
```
- **Scope**: IP-Bereich den der Server vergibt
- **Lease**: Gueltigkeitsdauer der IP
- **Reservation**: Feste IP fuer bestimmte MAC
- **Relay Agent** (ip helper): Leitet DHCP-Broadcasts ueber Router weiter

**DNS (Domain Name System):**
- Loesung: Name -> IP (z.B. google.com -> 142.250.185.14)
- **A-Record**: Name -> IPv4
- **AAAA-Record**: Name -> IPv6
- **CNAME**: Alias (www.example.com -> example.com)
- **MX**: Mail-Server
- **NS**: Nameserver
- **PTR**: Reverse DNS (IP -> Name)
- **SOA**: Start of Authority (Zonen-Info)
- **SRV**: Service-Lokation
- **TXT**: Beliebiger Text (SPF, DKIM, DMARC)
- Hierarchie: Root -> TLD (.com, .de) -> Domain -> Subdomain
- **DNSSec**: Signierte DNS-Antworten (gegen Spoofing)
- **DoH/DoT**: DNS ueber HTTPS/TLS (verschluesselt)
- Dein Setup: DNS-Server (.30) als lokaler DNS

**NTP (Network Time Protocol):**
- Synchronisiert Uhren im Netz (Port 123, UDP)
- Stratum 0 = Atomuhr, Stratum 1 = direkt verbunden, etc.

---

## Domain 3: Netzwerkbetrieb (~20%)

### 3.1 Betriebssysteme & Verwaltung (Kap 12)

**Netzwerk-Betriebssysteme:**
- Windows Server (AD, GPO, NTFS)
- Linux (iptables/nftables, systemd, ext4/XFS)
- macOS (Bonjour, AFP/SMB)

**Client/Server vs Peer-to-Peer:**
- Client/Server: Zentrale Verwaltung, Skalierbar (AD, LDAP)
- Peer-to-Peer: Einfach, keine zentrale Kontrolle (Workgroup)

**Virtualisierung:**
- **Hypervisor Typ 1** (Bare Metal): ESXi, Proxmox VE, Hyper-V -- direkt auf Hardware
- **Hypervisor Typ 2** (Hosted): VirtualBox, VMware Workstation -- auf OS
- **Container**: Docker -- teilt Kernel, leichtgewichtig
- Dein Setup: Hypervisor (.20) = Typ 1 Hypervisor mit LXC-Containern

**Cloud-Modelle:**

| Modell | Du verwaltest | Provider verwaltet |
|--------|--------------|-------------------|
| IaaS | OS, Apps, Daten | Hardware, Netzwerk, Virtualisierung |
| PaaS | Apps, Daten | Alles darunter |
| SaaS | Daten | Alles |

| Deployment | Beschreibung |
|-----------|-------------|
| Public | AWS, Azure, GCP -- geteilte Infrastruktur |
| Private | Eigene Cloud (z.B. Hypervisor + Nextcloud) |
| Hybrid | Mix aus Public + Private |
| Community | Geteilte Cloud fuer bestimmte Branche |

### 3.2 Netzwerkmanagement (Kap 17)

**FCAPS-Modell:**
- **F**ault Management: Fehler erkennen und beheben
- **C**onfiguration Management: Dokumentation, Backups
- **A**ccounting Management: Nutzung erfassen
- **P**erformance Management: Leistung messen
- **S**ecurity Management: Zugriffe kontrollieren

**SNMP (Simple Network Management Protocol):**
- Manager (NMS) -> Agent (auf Geraet) -> MIB (Datenbank)
- **v1/v2c**: Community Strings (Klartext = unsicher)
- **v3**: Authentifizierung + Verschluesselung
- **Trap**: Agent meldet proaktiv ein Ereignis
- **GET/SET**: Manager fragt/setzt Werte

**Dokumentation (pruefungsrelevant!):**
- Netzwerkdiagramm (physisch + logisch)
- Verkabelungsschema
- IP-Adressplan
- Aenderungsmanagement (Change Management)
- Baseline-Dokumentation
- Inventar (Asset Management)
- SLA (Service Level Agreement)

**Monitoring (Kap 18):**
- **Syslog**: Zentrales Logging (Severity 0-7: Emergency -> Debug)
- **SNMP**: Polling + Traps
- **NetFlow/sFlow/IPFIX**: Traffic-Analyse (wer redet mit wem, wie viel)
- **Wireshark**: Paketanalyse -> siehe Wireshark-Anleitung.md
- **nmap**: Port-/Netzwerk-Scan -> siehe Nmap-Anleitung.md
- **Bandwidth Monitor**: MRTG, PRTG, Grafana
- **SLA**: Verfuegbarkeit (z.B. 99.99% = max 52 Min/Jahr Downtime)

### 3.3 Hochverfuegbarkeit & Disaster Recovery

| Begriff | Erklaerung |
|---------|-----------|
| MTBF | Mean Time Between Failures -- durchschnittliche Betriebszeit |
| MTTR | Mean Time To Repair -- durchschnittliche Reparaturzeit |
| RTO | Recovery Time Objective -- max. akzeptable Ausfallzeit |
| RPO | Recovery Point Objective -- max. akzeptabler Datenverlust |
| Hot Site | Sofort einsatzbereit (teuer) |
| Warm Site | Hardware da, Daten muessen eingespielt werden |
| Cold Site | Nur Raum + Strom, alles aufbauen |
| RAID 0 | Striping, schnell, kein Schutz |
| RAID 1 | Mirroring, Redundanz |
| RAID 5 | Striping + Paritaet (1 Platte darf ausfallen) |
| RAID 6 | Striping + doppelte Paritaet (2 Platten) |
| RAID 10 | Mirror + Stripe (Performance + Redundanz) |
| LACP/LAG | Link Aggregation -- mehrere Ports buendeln |
| NIC Teaming | Mehrere NICs buendeln |

---

## Domain 4: Netzwerksicherheit (~19%)

### 4.1 Authentifizierung & Verschluesselung (Kap 13)

**AAA-Modell:**
- **Authentication**: Wer bist du? (Passwort, Zertifikat, Biometrie)
- **Authorization**: Was darfst du? (Berechtigungen)
- **Accounting**: Was hast du getan? (Logging)

**Authentifizierungsverfahren:**

| Verfahren | Beschreibung | Sicherheit |
|-----------|-------------|-----------|
| PAP | Passwort im Klartext | Schlecht |
| CHAP | Challenge-Response (Hash) | Besser |
| MS-CHAPv2 | Microsoft-Version | Mittel |
| EAP | Extensible Auth Protocol (Framework) | Variabel |
| 802.1X | Port-based NAC (Switch/AP + RADIUS) | Hoch |
| Kerberos | Ticket-basiert (AD) | Hoch |
| RADIUS | Zentraler AAA-Server (UDP 1812/1813) | Standard |
| TACACS+ | Cisco AAA-Server (TCP 49) | Hoch |
| LDAP/LDAPS | Verzeichnisdienst (389/636) | Standard |
| SAML | SSO fuer Web-Anwendungen | Hoch |
| MFA | Multi-Faktor (Wissen + Besitz + Sein) | Sehr hoch |

**Zero Trust:**
- "Never trust, always verify"
- Jeder Zugriff wird geprueft, auch im internen Netz
- Mikrosegmentierung, Least Privilege

**SASE (Secure Access Service Edge):**
- Cloud-basiert: SD-WAN + Security (FWaaS, CASB, ZTNA, SWG)

**Verschluesselung:**

| Verfahren | Typ | Schluessellaenge | Einsatz |
|-----------|-----|-----------------|---------|
| AES | Symmetrisch | 128/192/256 Bit | Standard, ueberall |
| DES/3DES | Symmetrisch | 56/168 Bit | Veraltet |
| RSA | Asymmetrisch | 2048-4096 Bit | Schluesselaustausch, Signatur |
| Diffie-Hellman | Schluesselaustausch | variabel | TLS Handshake |
| SHA-256/512 | Hash | 256/512 Bit | Integritaet, Passwoerter |
| MD5 | Hash | 128 Bit | Veraltet, unsicher |

**PKI (Public Key Infrastructure):**
- CA (Certificate Authority) stellt Zertifikate aus
- CRL/OCSP: Widerruf-Listen fuer ungueltige Zertifikate

**Data States:**
- **Data-in-transit**: TLS/SSL, VPN, IPSec
- **Data-at-rest**: Festplattenverschluesselung (BitLocker, LUKS)
- **Data-in-use**: Im RAM, schwer zu schuetzen

### 4.2 Angriffe (Kap 14)

**Malware-Typen:**

| Typ | Verhalten |
|-----|-----------|
| Virus | Braucht Wirt-Datei, verbreitet sich bei Ausfuehrung |
| Wurm | Verbreitet sich selbststaendig ueber Netz |
| Trojaner | Tarnt sich als nuetzliches Programm |
| Ransomware | Verschluesselt Daten, fordert Loesegeld |
| Spyware | Spioniert Benutzer aus |
| Adware | Zeigt unerwuenschte Werbung |
| Rootkit | Versteckt sich tief im System |
| Keylogger | Zeichnet Tastatureingaben auf |
| Botnet | Ferngesteuerte Zombie-Rechner |
| Logic Bomb | Zeitgesteuerte Schadfunktion |

**Netzwerkangriffe:**

| Angriff | Beschreibung | Gegenmassnahme |
|---------|-------------|----------------|
| DoS/DDoS | Ueberlastung (SYN-Flood, DNS Amplification, Smurf) | Firewall, Rate Limiting, CDN |
| Man-in-the-Middle | Abfangen von Kommunikation | TLS, Certificate Pinning |
| ARP Spoofing | Falsche MAC-IP-Zuordnung | Dynamic ARP Inspection (DAI) |
| DNS Spoofing | Falsche DNS-Antworten | DNSSEC |
| IP Spoofing | Gefaelschte Quell-IP | Ingress Filtering |
| VLAN Hopping | Zugriff auf fremdes VLAN | Trunk-Ports sichern, Native VLAN aendern |
| Evil Twin | Fake-AP mit echtem SSID-Namen | WPA3, 802.1X |
| Rogue AP | Unautorisierter Access Point | WIDS, NAC |
| Deauthentication | Client vom WLAN trennen | PMF (Protected Management Frames) |
| Buffer Overflow | Speicherueberlauf ausnutzen | Patching, ASLR |
| Brute Force | Alle Passwoerter durchprobieren | Account Lockout, MFA |
| Phishing | Gefaelschte Mails/Webseiten | Awareness-Training, SPF/DKIM/DMARC |
| Social Engineering | Menschen manipulieren | Schulung, Policies |
| Tailgating | Hinter Autorisiertem durchschluepfen | Man-Traps, Badges |
| Shoulder Surfing | Ueber die Schulter schauen | Blickschutzfolie |
| Zero-Day | Unbekannte Schwachstelle | IPS, Threat Intelligence |
| APT | Langfristige, gezielte Attacke | Defense-in-Depth, SOC |

**CVE/CVSS:**
- **CVE**: Common Vulnerabilities and Exposures (Identifikator)
- **CVSS**: Common Vulnerability Scoring System (Schweregrad 0-10)

### 4.3 Verteidigung (Kap 15)

**Firewall-Typen:**

| Typ | Layer | Beschreibung |
|-----|-------|-------------|
| Packet Filter | 3-4 | Prueft Quell/Ziel-IP, Port, Protokoll |
| Stateful Inspection | 3-4 | Merkt sich Verbindungsstatus |
| Application Gateway (Proxy) | 7 | Versteht Anwendungsprotokolle |
| WAF | 7 | Web Application Firewall (SQL Injection, XSS) |
| NGFW | 3-7 | Next-Gen: Deep Packet Inspection + IPS + App Awareness |
| UTM | 3-7 | Unified Threat Management (All-in-One) |

**Netzwerk-Zonen:**

```
Internet
    |
    v
[Firewall]---- DMZ (Webserver, Mailserver)
    |
    v
[Firewall]---- Internes LAN
    |
    +-- Server-VLAN
    +-- Client-VLAN
    +-- Management-VLAN
```

**Weitere Verteidigungsmittel:**

| Mittel | Funktion |
|--------|----------|
| IDS | Intrusion Detection -- erkennt Angriffe, meldet |
| IPS | Intrusion Prevention -- erkennt UND blockt |
| HIDS/HIPS | Host-basiert (auf dem Server selbst) |
| NIDS/NIPS | Netzwerk-basiert (am Switch/Router) |
| NAC (802.1X) | Network Access Control -- Authentifizierung vor Netzzugang |
| Port Security | Begrenzt MACs pro Switch-Port |
| DHCP Snooping | Schutz vor Rogue DHCP-Server |
| DAI | Dynamic ARP Inspection -- gegen ARP Spoofing |
| ACL | Access Control Lists -- Regeln fuer Traffic |
| Honeypot | Koeder-System um Angreifer abzulenken |
| SIEM | Security Event Management -- korreliert Logs |

**Physische Sicherheit (pruefungsrelevant!):**
- Badge/Keycard, Biometrie (Fingerprint, Retina), PIN
- Man-Trap / Sicherheitsschleuse
- CCTV / Videoueberwachung
- Brandschutz: FM-200 (Gas), Sprinkler
- USV / UPS, Generator
- HVAC (Klimatisierung im Serverraum)

### 4.4 VPN & Remote Access (Kap 16)

**VPN-Typen:**
- **Site-to-Site**: Zwei Standorte permanent verbunden (Router <-> Router)
- **Client-to-Site**: Einzelner Benutzer verbindet sich (Homeoffice -> Firmennetz)
- **Split Tunnel**: Nur Firmendaten ueber VPN, Rest direkt ins Internet
- **Full Tunnel**: Alles ueber VPN

**VPN-Protokolle:**

| Protokoll | Port | Sicherheit | Einsatz |
|-----------|------|-----------|---------|
| IPSec | UDP 500/4500 | Hoch (AH + ESP) | Site-to-Site Standard |
| SSL/TLS VPN | TCP 443 | Hoch | Client-to-Site, einfach |
| OpenVPN | UDP 1194 | Hoch (OpenSSL) | Cross-Platform |
| WireGuard | UDP 51820 | Sehr hoch, schnell | Modern, leichtgewichtig |
| L2TP/IPSec | UDP 1701 | Mittel | Legacy |
| PPTP | TCP 1723 | Unsicher! | Veraltet |
| GRE | Proto 47 | Keine (Tunnel only) | Encapsulation |

**IPSec-Modi:**
- **Transport Mode**: Nur Payload verschluesselt (Host-to-Host)
- **Tunnel Mode**: Gesamtes IP-Paket verschluesselt (Gateway-to-Gateway)
- **AH**: Authentication Header (Integritaet, kein Encryption)
- **ESP**: Encapsulating Security Payload (Integritaet + Verschluesselung)

---

## Domain 5: Netzwerk-Troubleshooting (~19%)

### 5.1 Systematische Fehlersuche (Kap 19)

**7-Schritte-Methode (CompTIA!):**
1. **Problem identifizieren**
2. **Theorie aufstellen** (wahrscheinlichste Ursache)
3. **Theorie testen**
4. **Aktionsplan erstellen**
5. **Loesung implementieren** (oder eskalieren)
6. **Volle Funktionalitaet pruefen** + Praeventivmassnahmen
7. **Dokumentieren** (Ursache, Loesung, Learnings)

### 5.2 Diagnose-Werkzeuge

**Kommandozeile:**

| Befehl | Funktion | Lab-Beispiel |
|--------|----------|--------------|
| `ping` | ICMP Echo -- Erreichbarkeit | `ping 10.0.0.100` |
| `traceroute` | Pfad zum Ziel anzeigen | `traceroute 8.8.8.8` |
| `nslookup`/`dig` | DNS-Abfrage | `dig @10.0.0.30 google.com` |
| `ipconfig`/`ip` | IP-Konfiguration anzeigen | `ip addr show eth0` |
| `arp -a` | ARP-Cache anzeigen | `arp -a` |
| `netstat`/`ss` | Aktive Verbindungen | `ss -tunap` |
| `nmap` | Port-/Netzwerk-Scan | `sudo nmap -sS 10.0.0.0/24` |
| `tcpdump` | Paketmitschnitt (CLI) | `ssh root@hypervisor-01 "tcpdump -i vmbr0"` |
| `route`/`ip route` | Routing-Tabelle | `ip route` |
| `mtr` | Traceroute + Ping kombiniert | `mtr 8.8.8.8` |
| `iperf3` | Bandbreiten-Messung | `iperf3 -c 10.0.0.100` |
| `curl` | HTTP-Anfrage testen | `curl -I http://10.0.0.100:8080` |

**Hardware-Werkzeuge:**
- Kabeltester (Durchgang, Crosstalk)
- Tonerprobe (Kabel identifizieren)
- OTDR (Glasfaser-Laengenmessung)
- Multimeter (Spannung, Widerstand)
- Spectrum Analyzer (WLAN-Kanalauslastung)
- Cable Certifier (Cat-Zertifizierung nach Standard)
- Loopback-Adapter (Schnittstelle testen)

### 5.3 Haeufige Fehlerszenarien

| Symptom | Wahrscheinliche Ursache | Pruefung |
|---------|------------------------|----------|
| Kein Netz, APIPA-Adresse (169.254.x.x) | DHCP-Server nicht erreichbar | `ipconfig /release && /renew` |
| Langsames Netz | Duplex-Mismatch, Loop, Ueberlastung | `ethtool`, STP-Status |
| Intermittierender Ausfall | Kabel, EMI, defekter Port | Kabeltester, Port wechseln |
| DNS-Fehler | Falscher DNS-Server | `nslookup`, DNS-Config pruefen |
| Kein Internet, LAN geht | Default Gateway falsch | `ip route`, Gateway pruefen |
| Langsames WLAN | Kanalueberlappung, zu viele Clients | Site Survey, Kanal wechseln |
| Zertifikatsfehler | Abgelaufen, falscher Name | Zertifikat pruefen, Uhrzeit! |
| Port gefiltert | Firewall, ACL | `nmap`, Firewall-Regeln pruefen |

---

## Pruefungstipps

1. **Ports auswendig lernen** -- die Top 20 kommen garantiert dran
2. **Subnetting ueben** -- mindestens 5-10 Fragen
3. **OSI-Modell** -- welches Geraet/Protokoll auf welchem Layer
4. **Troubleshooting-Methode** -- die 7 Schritte in der richtigen Reihenfolge
5. **WLAN-Standards** -- Frequenz, Speed, Sicherheit
6. **Kabeltypen** -- Max. Laenge, Geschwindigkeit, Stecker
7. **VPN-Protokolle** -- wann welches, IPSec Modi
8. **Cloud-Modelle** -- IaaS vs PaaS vs SaaS
9. **Angriffsarten** -- Name + Gegenmassnahme
10. **Acronyme** -- das Buch hat >400 Abkuerzungen im Anhang

---

## Lab als Lernumgebung

```
+-----------------------------------------------------+
|  10.0.0.0/24  (Lab-Netzwerk)                         |
|                                                       |
|  .1  lab-router (Router/NAT/DHCP/DNS-Forwarder)      |
|  .10 Linux Workstation (Wireshark, nmap, ssh)         |
|  .20 Hypervisor (Typ 1, LXC + VMs)                   |
|  .30 DNS-Server (DNS-over-HTTPS)                      |
|  .40 IoT-Hub                                          |
|  .42 IoT Sensor/Aktor                                 |
|  .47 Nextcloud (Dateisync, WebDAV)                    |
|  .60 mgmt-server (Management)                        |
|  .100 App-Server (Docker: NC + PostgreSQL)            |
|  .70 automation-server (Automation)                    |
|                                                       |
|  Tools die du hast und nutzen solltest:               |
|  - Wireshark: Pakete live analysieren                 |
|  - nmap: Netz scannen, Ports entdecken                |
|  - tcpdump: Remote Capture via Hypervisor             |
|  - ss/netstat: Aktive Verbindungen                    |
|  - dig/nslookup: DNS testen (DNS-Server)              |
|  - curl: HTTP-Anfragen (App-Server)                   |
|  - iperf3: Bandbreite messen                          |
|  - Hypervisor: VMs/CTs fuer Labore                    |
+-----------------------------------------------------+
```

---

## Querverweise

### Kurs-Material
- siehe 00-Lehrplan.md -- 12-Wochen Lernplan mit Uebungen
- siehe Wireshark-Anleitung.md -- Paketanalyse im Lab
- siehe Nmap-Anleitung.md -- Netzwerk-Scanning im Lab

---

*Stand: 2026-03-21 | Basiert auf Kammermann N10-009, 9. Auflage*
