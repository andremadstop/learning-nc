## 1. Das Grundmodell: Ein großes Event ist nie „ein Netzwerk“

Technisch sauber gedacht besteht ein großes Event fast immer aus **mehreren getrennten Netzen bzw. Zonen**, die über eine gemeinsame physische Infrastruktur laufen. Cisco beschreibt für große Venues eine **hierarchische, modulare Architektur** mit Core/Distribution, Access und separaten Service-Blocks; Riot beschreibt bei Worlds ein vor Ort aufgebautes LAN mit **VLANs für unterschiedliche Hardware, Services und Anwendungen**; CAGGTUS zeigt das als reales Hallenbeispiel vom redundanten Edge bis hinunter zum 48-Port-Desk-Switch.

Die sauberste Denkweise ist deshalb:  
Es gibt nicht „das Event-Netz“, sondern typischerweise mindestens diese drei Fabrics oder Zonen:

1. **Competition-/Realtime-Fabric**  
    Alles, was für Fairness und Latenz kritisch ist: Spieler-PCs, Turnier-Server, Observer, Referee-Stationen.
    
2. **Venue-/Enterprise-Fabric**  
    Alles, was den Betrieb des Events sicherstellt: Ticketing, POS, Staff, Admin, CCTV, IP-Telefonie, Drucker, Presse, Backoffice.
    
3. **Media-/Broadcast-Fabric**  
    Alles, was mit Produktion, Kameras, Replay, Audio, Grafik, Encoding und Signaltransport zu tun hat. In modernen IP-Produktionsumgebungen läuft das oft auf SMPTE ST 2110 mit Multicast und PTP-Zeitsynchronisation.
    

Der wichtigste Anfängerfehler ist, diese drei Welten logisch zu vermischen. Sobald Competition, Broadcast und Gast-WLAN im selben großen Layer-2-Bereich oder in zu wenigen Sicherheitszonen hängen, bekommst du unnötige Broadcast-Domänen, schlechtere Fehlereingrenzung, schwierigeres QoS und mehr Risiko bei Störungen. Cisco empfiehlt für Venue-Architekturen ausdrücklich VLAN-Segmentierung, um Probleme lokal einzudämmen; Riot hält Spielserver zusätzlich in einer besonders geschützten Tier mit minimalem Inbound-Zugriff.

## 2. Die physische Topologie: Von außen nach innen

### 2.1 WAN-Edge und Internetanbindung

Am Rand steht fast immer ein **dual-homed Edge**, also mindestens zwei unabhängige Internet-/Carrier-Wege. Cisco beschreibt Multihoming mit BGP explizit als Standardweg, um Internet-Redundanz zu erreichen; CAGGTUS zeigt in der Praxis zwei Provider mit insgesamt 60 Gbit/s und zwei redundante Juniper MX204 als Internet-Kopplung.

Das ist der Grundaufbau:

[ISP / Carrier A]         [ISP / Carrier B]  
        |                         |  
   [Edge Router A]           [Edge Router B]  
        |                         |  
        +----------[Firewall / DDoS Edge]----------+  
                              |  
                    [Redundanter Core / Distribution]

Technisch wichtig:  
Wenn du wirklich zwei Provider hast, reicht „zwei Kabel“ nicht. Du willst möglichst auch **getrennte Leitungswege**, damit nicht ein Bagger oder ein Brandabschnitt beide Pfade gleichzeitig kappt. BGP ist dabei das typische Protokoll am Internet-Rand; Cisco beschreibt Multihoming genau in diesem Kontext und weist zugleich darauf hin, dass man aufpassen muss, nicht versehentlich Transit-AS für fremden Internetverkehr zu werden.

### 2.2 Core/Distribution

Im Venue folgt danach der **Core**, oft zusammengelegt mit der Distribution als „collapsed core/distribution“. Cisco beschreibt für Connected Stadium genau dieses Muster: ein redundantes Core/Distribution-Paar, Access-Layer und Service-Blocks. CAGGTUS zeigt dasselbe Prinzip praktisch: geo-redundante Datacenter, Hallen-Hauptverteiler und darunter weitere Verteilerschichten bis zu den Tischblöcken.

Ein sauberes Denkmodell ist:

                    [Core/Distribution A]====[Core/Distribution B]  
                         /      |       \         /      |       \  
                        /       |        \       /       |        \  
                  [IDF 1]   [IDF 2]   [Media] [Stage] [WLAN]  [Service Blocks]

**IDF** heißt Intermediate Distribution Frame, also Unterverteiler näher an den Endgeräten.  
In einer großen Halle oder Arena willst du die Access-Switches nicht alle direkt in den Core ziehen; du aggregierst in Hallen- oder Zonenverteilern. CAGGTUS macht das sichtbar: Datacenter → Hallen-Hauptverteiler → Unterflur-/Zonenverteiler → 48-Port-Blockswitches.

### 2.3 Access-Layer

Im Access-Layer hängen die eigentlichen Endgeräte:

- Spieler-PCs
    
- Practice-Room-PCs
    
- Referee- und Observer-Systeme
    
- Kameras
    
- Encoder
    
- POS-/Ticketing-Terminals
    
- WLAN-Access-Points
    
- IP-Phones
    
- Signage-Player
    
- CCTV
    

Cisco nennt für Venues genau solche Endgeräteklassen im Access-Layer; Riot nennt für Worlds Practice Rooms mit gemeinsamen Switches, an denen die Team-Maschinen hängen.

Die wichtigste Topologie-Frage ist hier:  
**L2 bis zum Core oder L3 schon im Access?**

Cisco beschreibt zwei Grundoptionen:

- **L3 bis zum Access**: VLANs enden auf dem Access-Switch per SVI. Das ist skalierbar und stabil.
    
- **L2 bis zum Core**: Access-VLANs werden per 802.1Q-Trunk in den Core verlängert. Das ist flexibler, wenn bestimmte Layer-2-only-Anwendungen das brauchen.
    

Für moderne Event-Netze ist die Grundregel:  
**Wo immer möglich, L3 früher setzen.**  
Routed Access reduziert Layer-2-Abhängigkeiten, Spanning-Tree-Risiken und verbessert Konvergenz. Cisco empfiehlt in Campus-/Venue-Designs einen gerouteten Core generell und zeigt für Routed Access mit OSPF oder EIGRP schnellere Wiederherstellung und weniger Abhängigkeit von einem gemeinsamen Layer-2-Kontrollraum.

## 3. Die logische Topologie: VLANs, VRFs, Sicherheitszonen

Ein physisch perfektes Netzwerk ist wertlos, wenn die logische Trennung schlecht ist. Die zentrale Frage lautet: **Welche Systeme dürfen überhaupt miteinander sprechen?** Riot sagt offen, dass das Event-LAN aus VLANs für verschiedene Hardware, Services und Anwendungen besteht und dass Spielserver mit minimalem Inbound-Zugriff besonders geschützt werden. Cisco sagt für Venues, dass Services per VLANs getrennt werden, um Broadcast- und Layer-2-Probleme lokal zu halten.

Eine gute logische Struktur für ein großes E-Sport-Event sieht so aus:

VRF / Zone: MGMT  
  VLANs: Switch-Mgmt, AP-Mgmt, Controller, OOB  
  
VRF / Zone: COMPETITION  
  VLANs: Stage-Players, Referees, Game-Servers, Spectator/Observer  
  
VRF / Zone: PRACTICE  
  VLANs: Practice-Rooms, Team-Analyst  
  
VRF / Zone: BROADCAST  
  VLANs: Cameras, Replay, Graphics, Audio, Control  
  
VRF / Zone: OPS  
  VLANs: Admin, Ticketing, POS, Staff, Printing, Voice  
  
VRF / Zone: GUEST  
  VLANs: Guest-WLAN, Press-WLAN, Vendor-Internet

**VLAN** = logische Layer-2-Segmentierung.  
**VRF** = getrennte Routing-Tabelle auf Layer 3.  
Für kleine bis mittlere Events reicht oft saubere VLAN-Segmentierung plus Firewalls/ACLs. Für große Events oder sehr sensible Broadcast-/Competition-Trennung ist eine **VRF-basierte Segmentierung** oft die sauberere Lösung, weil Routing, Default-Gateways und Policies dann wirklich getrennt laufen. Das ist ein abgeleitetes Best Practice; gestützt wird es durch die von Cisco und Riot beschriebene konsequente Segmentierung und durch die Service-Block-Idee im Venue-Design.

## 4. Realtime-/Competition-Netz: das Herzstück einer E-Sport-WM

Hier liegt der größte Unterschied zu Messe, Konferenz oder LAN-Party. Riot schreibt explizit, dass Internetpfade trotz guter Anbindung Risiken bringen, die man nicht kontrollieren kann, und dass League-Profis bei großen Events auf einer hybriden Shard-Struktur mit **offline game servers** spielen, die komplett unabhängig vom Internet funktionieren und near-zero latency liefern. Das lokale Event-LAN wird in der Load-in-Phase von Systems-, Network- und Security-Teams aufgebaut.

Weshalb das so wichtig ist:

- Wettbewerbsintegrität braucht **stabile, vorhersehbare Latenz**
    
- UDP-basierte Spiele reagieren empfindlich auf Paketverlust
    
- variable Internetpfade erzeugen Jitter
    
- selbst wenige Millisekunden Unterschied können im Pro-Bereich relevant sein
    

Riot geht technisch sogar weiter: Die Offline-Game-Server laufen auf einer **stark modifizierten Plattform**, CPU-Boosting wird deaktiviert, ebenso bestimmte Stromsparmechanismen und Hyper-Threading, um möglichst deterministische Performance zu bekommen. Seit 2018 laufen diese portablen Game-Server bei Riot laut Tech-Blog **auf Bare Metal statt als VM**. Das ist eine sehr klare Ansage: Im Competition-Teil zählt Vorhersagbarkeit oft mehr als maximale Virtualisierungsflexibilität.

Ein Competition-Fabric sieht deshalb eher so aus:

               [Competition Core A]====[Competition Core B]  
                     |   \                  /   |  
                     |    \                /    |  
               [Stage Access A]      [Stage Access B]  
                   |      \            /      |  
                   |       \          /       |  
             Spieler PCs   Referees  Observers  
                      \       |       /  
                       \      |      /  
                        [Local Match Servers]  
                              |  
                     [Spectator / Tournament Tools]

Wichtig ist dabei:  
Die **Spieler sitzen kabelgebunden**, nicht im WLAN. Riot nutzt lokale Switches in Practice Rooms; CAGGTUS sagt sehr deutlich, dass WLAN zwar für alle verfügbar ist, aber wegen Latenz und Unvorhersagbarkeit nicht für schnelle, latenzkritische Spiele geeignet ist.

## 5. Broadcast-/Media-Fabric: fast ein eigenes Rechenzentrum

Viele unterschätzen, dass bei einer Weltmeisterschaft die Broadcast-Seite topologisch fast eine eigene Welt ist. Riot und Cisco beschreiben mit Project Stryker eine Remote-Broadcast-Architektur mit dedizierten Produktionszentren; Cisco nennt dafür explizit Nexus 9000, UCS, Intersight und Meraki als Bausteine, und Riot sagt, dass die Remote Broadcast Centers in Dublin und Seattle Live-Feeds empfangen, produzieren, ausstrahlen und in verschiedene Sprachen übersetzen.

In moderner IP-Produktion läuft dieser Bereich häufig auf **SMPTE ST 2110**. SMPTE beschreibt ST 2110 als die Übertragung separater Audio-, Video- und Datastreams über IP mit präziser Synchronisation; Cisco ergänzt, dass ST 2110 im Unterschied zu klassischem SDI auf **gemeinsam genutzten IP-Netzen**, mit **Multicast** für Verteilung und **PTP** für Zeitsynchronisation arbeitet.

Das ist topologisch wichtig, weil Media-Netze andere Anforderungen haben als klassische Office-Netze:

- sehr hohe Bandbreiten
    
- präzise Taktung
    
- viele Multicast-Flows
    
- definierte Nicht-Überbuchung
    
- saubere A/B-Redundanz
    

Cisco beschreibt für IP Media Fabrics genau diese Richtung: Nexus 9000 für hohe Dichten und Bandbreiten, PIM/Multicast, zentrale Steuerung über NDFC und parallelen Betrieb auf **A- und B-Netzen** in 2022-7-Hitless-Konfigurationen für 1+1-Redundanz.

Eine vereinfachte Broadcast-Topologie sieht so aus:

            [Camera GW] [Replay] [Audio] [Graphics] [Comms]  
                  \         |        |        |        /  
                   \        |        |        |       /  
                    +------[Media Fabric A]---------+  
                    +------[Media Fabric B]---------+  
                              |           |  
                      [PTP Grandmaster]  [Control / Orchestration]  
                              |  
                       [Encoder / WAN Contribution]  
                              |  
                 [Remote Broadcast Center / Cloud / CDN]

**PTP** = Precision Time Protocol, also eine gemeinsame hochgenaue Zeitbasis.  
Für Echtzeitproduktion ist das entscheidend, weil Audio, Video und Metadaten trotz separater Flows synchron bleiben müssen. Genau das benennt SMPTE explizit.

## 6. Venue-/Operations-Netz: die stillen, aber kritischen Dienste

Ein Event kann selbst dann scheitern, wenn das Match-Netz funktioniert, aber Einlass, Ticketing, Kassen oder interne Kommunikation ausfallen. Cisco beschreibt Connected Stadium explizit als Plattform für Staff-Zugang, Wireless IP Phones, Ticket- und POS-Scanner sowie Fan-Zugang; dazu kommen Videodistribution und Unified Communications.

Hier hilft das Konzept der **Service Blocks** besonders: Cisco empfiehlt dedizierte Service-Blöcke für verschiedene Anwendungen, damit Ausbau, Betrieb, Upgrades und Troubleshooting weniger Auswirkungen auf den Rest des Netzes haben. Das ist ein extrem gutes Denkmodell auch für temporäre Arenen oder Messehallen.

Praktisch heißt das:

- Ticketing/POS nicht im selben Segment wie Gast-WLAN
    
- CCTV nicht einfach „irgendwo ins Staff-Netz“
    
- Controller/Management aus dem Produktionsdatenweg herauslösen
    
- DNS, DHCP, AAA/RADIUS, Logging und NTP/PTP als eigene Service-Dienste behandeln
    

Cisco sagt im High-Density-WLAN-Guide ausdrücklich, dass **Core-Services wie DHCP, Radius/ISE mit der Clientbasis mit skalieren müssen**. Viele Event-Ausfälle sind keine RF-Probleme, sondern DHCP-Lease-Stürme, zu kleine RADIUS-Server, DNS-Timeouts oder überforderte Captive Portals.

## 7. High-Density-WLAN: das eigentliche Problem bei Konferenzen, Stadien und Messen

Sobald viele mobile Clients im Spiel sind, verschiebt sich die Hauptaufgabe vom Kabel-Design zur Funkzellplanung. Cisco definiert High Client Density als Umgebungen mit hoher Konzentration von Clients wie Konferenzräume, Auditorien, Arenen und Conference Halls. Das Ziel ist nicht „maximale Reichweite“, sondern **saubere, kleine Zellen mit möglichst guter Wiederverwendung des Spektrums**.

Daraus folgen ein paar wichtige technische Regeln:

- **20 MHz** ist in dichten 5-GHz-Umgebungen meist besser als breite Kanäle, weil du mehr Channel Reuse bekommst. Cisco sagt das 2024 sehr direkt.
    
- **TX Power** ist ein Werkzeug, aber nicht die ganze Lösung. Einfach „alles leise drehen“ reicht nicht, weil Clients unterschiedlich stark senden und empfangen. Cisco erklärt genau diese Falle.
    
- **Minimum Mandatory Data Rates** formen die effektive Zellgröße und können Clients zwingen, näher am AP zu sein; höhere Mindestdatenraten erhöhen auch die Effizienz, weil Management- und Beacon-Traffic schneller gesendet wird.
    
- **RX-SOP** kann helfen, in dichter Umgebung weiter entfernte Co-Channel-Sender auszublenden und dadurch Airtime besser nutzbar zu machen. Cisco beschreibt das ausdrücklich als High-Density-Werkzeug.
    

Wichtig: Unter-Seat-APs sind möglich, aber kein Zaubertrick. Cisco beschreibt sie für schnelle, temporäre Installationen als machbar, sagt aber auch klar, dass sie oft ungleichmäßige Zellen erzeugen und als letzte Option gelten sollten.

Für Controller-basierte Designs ist außerdem die **Skalierung des WLAN-Controllers** selbst Teil der Topologie. Cisco beschreibt bei Catalyst 9800 die Bedeutung von RF Profiles, Site Tags, WNCd-Verteilung und Lastbalance; große Public-Venue-Deployments brauchen also nicht nur genug APs, sondern auch eine Controller-Architektur, die die AP-/Client-Last sauber verteilt.

## 8. Hardware: Was dort real tatsächlich steht

Hersteller variieren, aber die Rollen sind erstaunlich konstant. Aus den Quellen ergeben sich diese realen Klassen:

- **Edge-Router**: CAGGTUS nutzt zwei redundante Juniper MX204 am Internetrand.
    
- **Core-/Datacenter-Switches**: CAGGTUS nennt geo-redundante Datacenter-Switches und 100-Gbit-Anbindungen; Cisco nennt für Riot Nexus 9000 als zentrale Broadcast-/Event-Infrastruktur.
    
- **Access-/Hallenswitches**: CAGGTUS nennt Extreme 5420M und 48-Port-Blockswitches mit doppelten Uplinks; Cisco beschreibt generell Access-Switches für APs, Kameras, POS, Ticketing und IP-Telefonie.
    
- **Server**: Riot nennt portable Bare-Metal-Game-Server; Cisco nennt UCS-Server als Teil der Riot-Infrastruktur.
    
- **WLAN**: CAGGTUS nennt pro Halle 36 APs; Cisco beschreibt High-Density-WLAN als eigene Disziplin und verweist auf Controller-/RF-Optimierung.
    
- **Client-Hardware**: Riot nennt als Beispiel für Worlds-Practice-Rooms sieben Player Stations pro Raum mit Alienware Aurora R8 und 240-Hz-Monitoren; das ist ein historisches Beispiel, kein allgemeiner Industriestandard, aber zeigt die Größenordnung und die dedizierte Infrastruktur pro Team.
    

## 9. Software- und Betriebsseite: oft unsichtbar, aber entscheidend

Ein Event-Netz ist nicht nur Hardware. In der Praxis brauchst du mindestens diese Softwareschichten:

- Routing-/Switching-Betriebssysteme auf Routern/Switches
    
- WLAN-Controller oder Cloud-Management
    
- Authentifizierung/AAA/RADIUS
    
- DHCP/DNS/NTP/PTP
    
- Monitoring, Logging, Konfig-Sicherung
    
- für Broadcast: Orchestrierung der Flows und PTP-Überwachung
    

Cisco nennt für Riot zentralisierte Verwaltung über eine Cloud-Konsole, Intersight und Meraki; für High-Density-WLAN nennt Cisco WCAE als Werkzeug zur Validierung und Optimierung; für IP-Media nennt Cisco NDFC als zentrale Steuerungs- und Sichtbarkeitsinstanz.

Eine technisch saubere Betriebsstrategie sieht daher so aus:

1. **Out-of-Band- oder zumindest separates Management-Netz**  
    Management darf nicht am Gastnetz oder am Competition-VLAN hängen. Das ist eine abgeleitete Best Practice aus der beschriebenen Segmentierungs- und Service-Block-Architektur.
    
2. **Templating und standardisierte Profile**  
    Im WLAN z. B. RF Profiles, Site Tags, standardisierte SSID-/Policy-Profile; im Switching standardisierte VLAN-/ACL-/QoS-Bausteine. Cisco beschreibt genau diese Profil-Logik für Catalyst 9800.
    
3. **Pre-Staging im Labor**  
    Gerade bei temporären Events wird so viel wie möglich vorher gebaut, getestet und dokumentiert; vor Ort sollen nur noch Patchen, Labeln, Validieren und Fine-Tuning passieren. Das ist eine abgeleitete Folgerung aus Riots Load-in-Prozess und den temporären WLAN-Deployments im Cisco-Guide.
    
4. **Monitoring auf jeder Schicht**  
    Link-State, Errors, PTP-Lock, DHCP-Exhaustion, RADIUS-Latenz, Multicast-Flows, AP-Load, Channel Utilization, Controller-Health. Cisco adressiert für WLAN und Media explizit Monitoring/Visibility als Schlüsselfaktor.
    

## 10. Abgeleitetes Referenzdesign: E-Sport-WM in einer Arena

Jetzt die versprochene Musterarchitektur. Das hier ist **kein veröffentlichtes 1:1-Blueprint eines konkreten Turniers**, sondern ein technisch sauberes Referenzdesign, abgeleitet aus den oben genannten Praxisbausteinen.

Annahmen:

- 10 Teams
    
- Hauptbühne
    
- 10 Practice Rooms
    
- Broadcast-Regie vor Ort plus Remote-RBC
    
- mehrere tausend Zuschauer
    
- Staff, Presse, Ticketing, POS
    
- Gast-WLAN für Publikum
    
- lokaler Turnierbetrieb mit möglichst internetunabhängigem Match-Pfad
    

### 10.1 Physische Zieltopologie

                    ┌─────────────────────────────────────────┐  
                    │             WAN / Carrier              │  
                    └─────────────────────────────────────────┘  
                         |                           |  
                    [ISP A]                     [ISP B]  
                         |                           |  
                  [Edge Router A]             [Edge Router B]  
                         \                         /  
                          \                       /  
                       [Firewall / Edge Security Pair]  
                                 |  
                  =========================================  
                  ||        Core / Distribution Pair      ||  
                  =========================================  
                    |         |          |         |      |  
                    |         |          |         |      |  
              [Comp Core] [Ops Core] [WLAN] [Media A/B] [Mgmt/OOB]  
                    |         |          |         |      |  
         ------------         |        APs      Cameras   |  
         |         |          |          |      Replay    |  
   [Stage A]  [Practice]   Ticketing   Guest   Audio     |  
      |            |        POS/Staff   WiFi   Graphics  |  
 Spieler        Team-Räume    CCTV      Press  Encoders  |  
 Referees          |                                |     |  
 Observer     lokale Room-SW                         \    |  
      \            /                                  \   |  
       \          /                             [Contribution]  
        [Local Match Servers]                         |  
               |                               [Remote Broadcast Center]  
         [Tournament Tools]

### 10.2 Zonen

- **Competition**: Stage, Referees, Match Server, Observer, Spectator Feed
    
- **Practice**: Teamräume getrennt vom Live-Match
    
- **Broadcast**: Kamera, Replay, Audio, Grafik, Contribution
    
- **Operations**: Ticketing, POS, Staff, Security, Voice
    
- **Guest/Press**: strikt isoliert
    
- **Management**: Switch/AP/Controller/Server-Management
    

Diese Trennung folgt direkt aus den von Riot und Cisco beschriebenen VLAN-/Service-Block-Architekturen und aus den unterschiedlichen Betriebsrisiken der jeweiligen Zonen.

### 10.3 Hardware-Rollen

Für so ein Event würde ich technisch in Rollen statt in exakten Modellnamen denken:

- 2 Edge-Router mit Dual-ISP-Fähigkeit
    
- 2 Firewalls/Edge-Security-Systeme
    
- 2 Core/Distribution-Switches mit genügend 25/100-GbE-Uplinks
    
- mehrere IDF-/Zone-Switches für Stage, Practice, Ops
    
- dedizierte Media-Fabric-Switches, wenn ST 2110/IP-Produktion im Spiel ist
    
- Controller-Paar oder cloud-/controllerbasierte WLAN-Steuerung
    
- lokale Bare-Metal-Match-Server
    
- Service-Server für DHCP/DNS/AAA/NTP/PTP/Monitoring
    
- genügend PoE-Budget für APs, Kameras, IP-Phones, Scanner
    

Dass diese Rollen real sind, zeigen die Beispiele aus Riot, Cisco und CAGGTUS; die konkrete Größe und Modellwahl ist dann ein Capacity-/Budget-Thema.

### 10.4 Routing-Entscheidung

Meine klare Empfehlung für so ein Event wäre:

- **L3 im Core immer**
    
- **L3 bis in Access/Zone-Switches, wo möglich**
    
- **L2 nur dort, wo es technisch wirklich gebraucht wird**, z. B. für bestimmte Broadcast-/Legacy-Anwendungen
    

Das stützt sich auf Ciscos Venue-Architektur mit zwei Core-Design-Optionen und auf die generelle Routed-Access-Empfehlung in Campus-Designs. Für Medien- oder Spezialprotokolle kann L2-Verlängerung nötig sein; für Standard-Event-IT ist Routed Access meist robuster.

### 10.5 QoS

QoS ist hier kein Luxus, sondern Ordnungssystem. Priorisieren würde ich:

1. PTP/Timing und kritische Media-Control-Flows
    
2. Competition-/Game-Verkehr
    
3. Intercom/Voice
    
4. Broadcast-Contribution
    
5. Staff-/Operations-Traffic
    
6. Best-Effort Guest Internet
    

Cisco nennt für mobile Stadion-Videoübertragung ausdrücklich das Zusammenspiel aus RF Fine Tuning, Multicast und QoS; für ST 2110 betonen SMPTE und Cisco die präzise, echtzeitfähige Übertragung über gemanagte IP-Netze.

### 10.6 Sicherheit

Für ein Event dieser Größe würde ich logisch mindestens so härten:

- Management strikt separieren
    
- Competition nur für definierte Gegenstellen öffnen
    
- Spielserver nur für kleinste Admin-Gruppe erreichbar machen
    
- Guest-WLAN nur Richtung Internet, nicht intern
    
- Staff-/POS-/Ticketing voneinander trennen
    
- Port-basierte Authentifizierung oder gleichwertige NAC-Mechanismen für kritische Anschlüsse
    

Dass 802.1X portbasierte Authentifizierung unautorisierte Geräte vom LAN fernhalten soll, beschreibt Cisco ausdrücklich. Die konkrete Event-Policy ist mein abgeleitetes Design, nicht eine veröffentlichte Turnierkonfiguration.

## 11. Wie sich Topologien je nach Event verschieben

Jetzt der Lernpunkt, der dir fürs Verstehen am meisten bringt:  
Die **Bausteine** bleiben ähnlich, aber die **Priorität** verschiebt sich.

### E-Sport-WM

Hier dominiert die Competition-Fabric: lokal, kabelgebunden, stark segmentiert, internetunabhängiger Match-Pfad. Riot ist dafür das klarste Praxisbeispiel.

### Große LAN-Party

Hier dominiert Portdichte, Access-Skalierung und paralleler Internetverkehr. CAGGTUS zeigt das exemplarisch mit redundanten Routern, 400-Gbit-Hallenanbindung, 2x10-Gbit-Unterverteilung, 48-Port-Blockswitches und separatem Guest-Server-Bereich.

### Konferenz / Messe

Hier dominiert High-Density-WLAN: Funkzellgröße, Airtime, Channel-Reuse, Auth-Onboarding, Controller-Skalierung. Cisco behandelt genau diese Umgebungen im High-Density-Guide.

### Stadion

Hier dominiert die Koexistenz vieler Dienste: Staff, Ticketing, POS, Video, Gäste, mobile Apps. Cisco Connected Stadium beschreibt exakt diese Service-Mischung.

### Remote Broadcast / TV-Produktion

Hier dominiert die Media-Fabric: ST 2110, Multicast, PTP, A/B-Redundanz, Orchestrierung und WAN-Contribution in Broadcast Center oder Cloud. Riot Project Stryker und Cisco IP Fabric for Media zeigen genau diese Richtung.

## 12. Die häufigsten Denkfehler

Erstens: **„Mehr Internet löst alles.“**  
Nein. Bei E-Sport ist der lokale, kontrollierte Pfad oft wichtiger als rohe Internetbandbreite. Riot begründet genau deshalb Offline-Game-Server.

Zweitens: **„WLAN ist nur langsameres Ethernet.“**  
Nein. In dichten Umgebungen ist WLAN vor allem ein Airtime- und Interferenzproblem. Cisco sagt klar, dass High Density durch korrekte Größenordnung, Tuning und Zellgestaltung gewonnen wird, nicht durch bloß „mehr Funksignal“.

Drittens: **„Ein großes VLAN ist einfacher.“**  
Kurzfristig vielleicht, operativ fast nie. Cisco und Riot beschreiben beide Segmentierung als Grundprinzip, und Venue-Designs gewinnen genau dadurch an Fault Isolation und Wartbarkeit.

Viertens: **„Broadcast ist einfach nur viel Bandbreite.“**  
Nein. Moderne Media-Fabrics brauchen Timing, Multicast, Redundanz und Orchestrierung. ST 2110 und Ciscos IP-Fabric-for-Media-Dokumente machen genau das deutlich.

## Zusammenfassung

Das technisch sauberste mentale Modell ist dieses:  
Ein großes Event-Netz ist eine **modulare, hierarchische Infrastruktur**, in der **Competition**, **Operations** und **Media** logisch getrennt, physisch redundant und je nach Aufgabe unterschiedlich priorisiert werden. Für E-Sport ist der Kern ein **lokales, kabelgebundenes, stark geschütztes Realtime-Netz** mit möglichst internetunabhängigen Match-Servern; für Konferenzen und Stadien rückt dagegen **High-Density-WLAN** in den Vordergrund; für moderne Produktionen kommt zusätzlich eine **Media-Fabric mit ST 2110, Multicast und PTP** dazu.

## 1) Ausgangslage und Zielbild

**Annahmen für das Referenzdesign:**

- 10 Teams
    
- Hauptbühne mit Live-Match
    
- 10 Practice Rooms
    
- lokale Regie plus Remote-Broadcast-Center
    
- 5.000–12.000 Zuschauer in der Arena
    
- Staff, Presse, Ticketing, POS, Security
    
- Gast-WLAN für Publikum
    
- das eigentliche Match soll **möglichst unabhängig vom Internet** funktionieren
    

Diese Priorität ist technisch plausibel, weil Riot für große Events bewusst lokale, internetunabhängige Game-Server einsetzt, um Latenz, Jitter und externe Routingrisiken zu minimieren. Für große Public-WLANs beschreibt Cisco dagegen tausende unbekannte Clients als Hauptproblem; das ist also eine **zweite**, getrennte Baustelle.

---

## 2) Das Gesamtbild: ein Event ist mehrere Netze gleichzeitig

**Mein Referenzdesign** besteht aus sechs logisch getrennten Bereichen:

1. **Competition-Fabric**  
    Spieler, Referees, Observer, Match-Server
    
2. **Practice-Fabric**  
    Teamräume, Scrims, Analysten, Warm-up
    
3. **Broadcast-/Media-Fabric**  
    Kameras, Replay, Audio, Grafik, Encoder
    
4. **Operations-Fabric**  
    Ticketing, POS, Staff, VoIP, Security, Drucker
    
5. **Guest-/Press-Fabric**  
    Zuschauer-WLAN, Presse-WLAN, Vendor-Internet
    
6. **Management-/OOB-Fabric**  
    Switch-Management, AP-Management, Monitoring, Controller
    

Diese Trennung ist keine Spielerei, sondern der Kern guter Event-Topologien: Riot nennt VLANs für unterschiedliche Hardware, Services und Anwendungen; Cisco empfiehlt in Venue-/Campus-Designs die Trennung von Services und den Einsatz modularer Blöcke; Broadcast über ST 2110 bringt zusätzlich eigene Anforderungen wie Multicast und PTP mit.

                                  ┌──────────────────────────────┐  
                                  │         WAN / Internet       │  
                                  └──────────────────────────────┘  
                                      |                    |  
                                   [Carrier A]         [Carrier B]  
                                      |                    |  
                                 [Edge Router A]     [Edge Router B]  
                                      \                /  
                                       \              /  
                                   [Firewall / Edge Security HA]  
                                                |  
                           =================================================  
                           ||      Core / Distribution Pair (L3)         ||  
                           =================================================  
                              |            |            |           |      |  
                              |            |            |           |      |  
                        [Competition]   [Practice]   [Ops]     [Media]  [Mgmt/OOB]  
                              |            |            |           |      |  
                       +------+---+    +---+-----+   +--+---+   +---+--+  |  
                       |          |    |         |   |      |   |      |  |  
                    [Stage]  [Observers] [Team Rooms] POS/Tix APs   Replay |  
                       |                    |            |      |    Audio  |  
                    Player PCs           Practice PCs   Staff  WiFi  Gfx    |  
                    Referees             Analysts       Phones Users Enc     |  
                       \                    /                               |  
                        \                  /                           [Contribution]  
                         \                /                                 |  
                          [Local Match / Tournament Servers]        [Remote Broadcast]

---

## 3) Physische Topologie: welche Geräteklassen du wirklich brauchst

Die **konkreten Modellnamen** hängen vom Hersteller ab. Sauber planen solltest du aber in **Rollen**.

### 3.1 WAN- und Edge-Schicht

**Ziel:** zwei unabhängige Internetpfade, idealerweise mit getrennten Trassen.

2x Carrier / ISP  
2x Edge Router  
2x Firewalls im HA-Paar  
1x DDoS-/Scrubbing-Konzept für öffentliche Dienste

BGP-Multihoming ist dafür der klassische Ansatz. Cisco beschreibt BGP mit zwei ISPs als Standardfall für redundante Internetanbindung. CAGGTUS zeigt in der Praxis zwei Provider und zwei redundante Router am Rand.

### 3.2 Core / Distribution

**Ziel:** keine Single Points of Failure im Kern.

2x Core/Distribution-Switches  
25/40/100 GbE Uplinks zwischen Core, Media und größeren Zonen  
L3 im Core, idealerweise auch L3 bis in die Verteilerschicht

Cisco empfiehlt für Campus-/Venue-Architekturen einen hierarchischen Aufbau; Routed Access mit OSPF oder EIGRP liefert laut Cisco bessere Konvergenz, Fault Tolerance und Skalierbarkeit als stark Layer-2-lastige Designs.

### 3.3 Zonen-/IDF-Schicht

**Ziel:** die Arena in physische Bereiche teilen.

Typische Unterverteiler:

- IDF-Stage
    
- IDF-Practice-West
    
- IDF-Practice-East
    
- IDF-Backstage/Ops
    
- IDF-Concourse/POS
    
- IDF-Media
    
- IDF-WLAN-Bowl/Audience
    

CAGGTUS zeigt genau dieses Denkmodell in real: Datacenter/Core → Hallenverteiler → Tisch-/Zonen-Switches.

### 3.4 Access-Schicht

Hier hängen die Endgeräte wirklich dran:

- Spieler-PCs
    
- Referee-PCs
    
- Observer-PCs
    
- Practice-PCs
    
- Kameras
    
- Replay-Systeme
    
- Audio-Stageboxen
    
- APs
    
- Ticket-Scanner
    
- Kassen
    
- VoIP-Telefone
    
- Sicherheitstechnik
    

Für Practice Rooms beschreibt Riot sogar konkret den Einsatz eines gemeinsamen lokalen Switches pro Raum.

---

## 4) Konkreter Hardware-Plan als Beispiel-BOM

Das hier ist **mein Beispiel**, nicht eine offizielle Riot- oder Cisco-Stückliste.

WAN / Edge  
- 2x Edge-Router mit BGP-Fähigkeit  
- 2x Firewalls im HA-Paar  
- 2x Internet-Uplinks (je 10–40 Gbit/s, je nach Eventgröße)  
  
Core / Distribution  
- 2x Core-Switches, je:  
  - 48x 10/25G SFP+  
  - 6–8x 40/100G Uplinks  
  - L3, OSPF/BGP, ACL, QoS, VRF  
- 2x Management-Switches  
  
Zone / IDF  
- 6–10x Verteilerswitches  
- Uplinks je Zone redundant, mindestens 2x10G, für Media eher 25G/40G  
  
Access  
- Stage: 2–4x 48-Port Access-Switches  
- Practice Rooms: 10x 24-Port oder 10x 48-Port  
- Ops/Ticketing/POS: 4–8x 48-Port PoE-Switches  
- Audience/AP: ausreichend PoE++ / mGig-fähige Switches  
- Media: dedizierte 25/100G-fähige Switches  
  
Server  
- 2–4x lokale Match-Server (Bare Metal)  
- 2x Monitoring/Logging  
- 2x DHCP/DNS/AAA  
- 2x NTP/PTP (PTP vor allem für Media)  
- 1x Jump-Host / Bastion  
- optional 1x Backup/Config-Server

Dass lokale Match-Server auf Bare Metal sinnvoll sind, wird durch Riot direkt gestützt; für Broadcast/IP-Media beschreibt Cisco IP-Media-Fabrics mit Multicast/PTP und höherer Portdichte.

---

## 5) Logische Segmentierung: VLANs, VRFs und IP-Plan

Jetzt der eigentliche Kern.  
Ich gebe dir ein **vollständiges Beispiel-Schema**. Die VLAN-IDs und IP-Bereiche sind **didaktische Beispielwerte**, technisch sauber, aber natürlich frei wählbar.

### 5.1 VRF-Plan

Ich würde hier **VRFs** einsetzen, nicht nur VLANs, weil die Trennung klarer und sicherer ist.

VRF MGMT  
VRF COMP  
VRF PRACTICE  
VRF MEDIA  
VRF OPS  
VRF GUEST

Warum?  
Weil du dann getrennte Routing-Tabellen, getrennte Default-Wege und saubere Policy-Punkte bekommst. Das ist eine abgeleitete Architekturentscheidung; sie passt sehr gut zu Ciscos modularen Campus-/Venue-Designprinzipien und Riots strikter Segmentierung.

### 5.2 VLAN- und Subnetzplan

VRF MGMT  
  VLAN 10   NET-MGMT-SW        10.10.10.0/24  
  VLAN 11   NET-MGMT-AP        10.10.11.0/24  
  VLAN 12   NET-MGMT-OOB       10.10.12.0/24  
  VLAN 13   MONITORING         10.10.13.0/24  
  VLAN 14   INFRA-SERVICES     10.10.14.0/24  
  
VRF COMP  
  VLAN 100  STAGE-PLAYERS-A    10.20.100.0/24  
  VLAN 101  STAGE-PLAYERS-B    10.20.101.0/24  
  VLAN 110  REFEREES           10.20.110.0/24  
  VLAN 120  OBSERVERS          10.20.120.0/24  
  VLAN 130  MATCH-SERVERS      10.20.130.0/24  
  VLAN 140  TOURNAMENT-TOOLS   10.20.140.0/24  
  
VRF PRACTICE  
  VLAN 200  PRACTICE-RM-01     10.30.1.0/24  
  VLAN 201  PRACTICE-RM-02     10.30.2.0/24  
  VLAN 202  PRACTICE-RM-03     10.30.3.0/24  
  VLAN 203  PRACTICE-RM-04     10.30.4.0/24  
  VLAN 204  PRACTICE-RM-05     10.30.5.0/24  
  VLAN 205  PRACTICE-RM-06     10.30.6.0/24  
  VLAN 206  PRACTICE-RM-07     10.30.7.0/24  
  VLAN 207  PRACTICE-RM-08     10.30.8.0/24  
  VLAN 208  PRACTICE-RM-09     10.30.9.0/24  
  VLAN 209  PRACTICE-RM-10     10.30.10.0/24  
  VLAN 220  ANALYSTS           10.30.20.0/24  
  
VRF MEDIA  
  VLAN 300  CAMERAS            10.40.10.0/24  
  VLAN 301  REPLAY             10.40.11.0/24  
  VLAN 302  AUDIO              10.40.12.0/24  
  VLAN 303  GRAPHICS           10.40.13.0/24  
  VLAN 304  ENCODERS           10.40.14.0/24  
  VLAN 305  COMMS/INTERCOM     10.40.15.0/24  
  VLAN 306  PTP-INFRA          10.40.16.0/24  
  VLAN 307  CONTROL/NMOS       10.40.17.0/24  
  
VRF OPS  
  VLAN 400  STAFF              10.50.10.0/24  
  VLAN 401  PRESS              10.50.11.0/24  
  VLAN 402  TICKETING          10.50.12.0/24  
  VLAN 403  POS                10.50.13.0/24  
  VLAN 404  SECURITY/CCTV      10.50.14.0/24  
  VLAN 405  VOICE              10.50.15.0/24  
  VLAN 406  PRINTERS           10.50.16.0/24  
  
VRF GUEST  
  VLAN 500  GUEST-WIFI         10.60.10.0/23  
  VLAN 501  PRESS-WIFI         10.60.12.0/24  
  VLAN 502  VENDOR-WIFI        10.60.13.0/24

### 5.3 Warum /24 und /23?

Das ist **mein Designentscheid**:

- /24 ist für die meisten Funktionszonen übersichtlich
    
- Practice Rooms separat zu halten macht Troubleshooting leichter
    
- GUEST-WIFI bekommt /23, weil dort deutlich mehr Clients zu erwarten sind
    
- größere Arenen würden Guest ggf. in mehrere Pools oder zentrale Client-Subnetze aufteilen
    

Cisco beschreibt für große öffentliche WLANs explizit tausende unbekannte/unmanaged Clients. Daraus folgt logisch, dass Gastnetze viel größer gedacht werden müssen als z. B. Referee- oder Match-Server-Netze.

---

## 6) Routing-Design: so würde ich es tatsächlich bauen

## 6.1 Edge-Routing

Internet Edge: eBGP zu Carrier A und Carrier B  
Default- oder Partial-Routes vom Provider  
Optional Provider-communities / local-pref für Primär-/Sekundärweg

BGP am Rand ist der normale Weg für Dual-ISP-Designs.

## 6.2 Internes Routing

Ich würde intern **OSPF** verwenden.

OSPF Area 0 im Core  
OSPF zu allen IDF-/Zonenswitches  
Summaries je Zonenblock

Warum OSPF?  
Weil es breit verstanden, stabil und für ein Event dieser Größe gut beherrschbar ist. Cisco beschreibt Routed Access mit OSPF oder EIGRP explizit als Designmuster; der Vorteil ist schnellere Konvergenz und bessere Skalierbarkeit gegenüber Layer-2-lastigen Konstruktionen.

## 6.3 L3 bis wohin?

Meine Empfehlung:

- **Core ↔ IDF = Layer 3**
    
- **IDF ↔ Access = dort Layer 3, wo möglich**
    
- **Layer 2 nur gezielt**, zum Beispiel:
    
    - innerhalb eines Practice Rooms
        
    - für bestimmte Legacy-Media- oder Discovery-Anforderungen
        
    - bei Voice-/Daisy-Chain-Endgeräten, wenn nötig
        

Der Grund ist einfach: weniger große Broadcast-Domänen, weniger STP-Abhängigkeit, bessere Fehlereingrenzung. Genau dafür empfiehlt Cisco Routed-Access-Ansätze.

### 6.4 Default-Gateways

Bei echtem Routed Access liegen die Gateways auf dem jeweiligen L3-Gerät in der Zone.  
Bei zentralerem Design liegen sie als SVIs auf dem Core-Paar.

**Für dieses Referenzdesign:**

- Competition/Practice/OPS: Gateways auf dem Core/Distribution-Paar
    
- Media: je nach Fabric-Design ggf. eigene Gateways im dedizierten Media-Core
    
- MGMT: nur über dedizierte Admin-Sprungpunkte erreichbar
    

---

## 7) Security-Design: wer darf mit wem reden?

Das ist der Unterschied zwischen „funktioniert im Labor“ und „funktioniert im echten Event“.

### 7.1 Grundregel

**Default Deny zwischen VRFs**, dann gezielte Freigaben.

COMP -> MATCH-SERVERS           allow  
COMP -> TOURNAMENT-TOOLS        allow  
COMP -> Internet                minimal / controlled  
PRACTICE -> MATCH-SERVERS       allow (nur scrim / update scopes)  
PRACTICE -> COMP                deny  
GUEST -> anything internal      deny  
PRESS-WIFI -> PRESS services    limited allow  
OPS -> Ticketing/POS backends   allow  
OPS -> COMP                     deny except admin jump hosts  
MGMT -> all infrastructure      allow from bastion/admin subnets only  
MEDIA -> Contribution/WAN       allow  
MEDIA -> COMP                   deny except observer-approved flows

Riots geschützte Game-Server mit minimalem Inbound-Zugriff stützen diese Richtung direkt. Cisco beschreibt 802.1X als Mechanismus, um unautorisierte Geräte vom Netz fernzuhalten.

### 7.2 Port-Sicherheit / NAC

Für kritische Ports würde ich verwenden:

- 802.1X für Staff-/Ops-Zugänge
    
- MAB oder vergleichbare Fallbacks für Geräte ohne Supplicant
    
- Port-Security / MAC-Limits für Spezialgeräte
    
- kein freies Patchen in Competition oder Media
    

802.1X verhindert laut Cisco unautorisierte Geräte am Netz.

### 7.3 Management-Sicherheit

Management nur aus MGMT-VRF  
kein Switch-/AP-Management aus GUEST oder OPS  
Jump-Host / Bastion erforderlich  
AAA zentral  
Syslog zentral  
Config-Backups zentral

---

## 8) QoS: Prioritäten richtig setzen

QoS ist hier Pflicht, nicht Kür.

### 8.1 Empfohlene Prioritäten

Class 1  Network Control / Routing / PTP  
Class 2  Competition Traffic  
Class 3  Media Timing / Control / Intercom  
Class 4  Broadcast Contribution / Encoders  
Class 5  Ops Critical (Ticketing / POS / Voice)  
Class 6  Best Effort  
Class 7  Guest / Bulk / Updates

Für ST 2110/IP-Media beschreibt Cisco ausdrücklich Multicast und PTP als zentrale Elemente; PTP ist laut SMPTE die Zeitbasis für die Synchronität separater Media-Streams.

### 8.2 Praktische Konsequenz

- Spielverkehr darf nicht in Best-Effort untergehen
    
- PTP darf nicht durch Burst-Traffic gestört werden
    
- Updates/Patches für Clients gehören in gedrosselte Klassen
    
- Guest-Traffic braucht Fairness, aber nicht Vorrang
    

---

## 9) Broadcast-/Media-Fabric: wenn du ST 2110 ernst nimmst

Falls die Produktion modern IP-basiert läuft, ist das **fast ein eigenes Netz**.

SMPTE ST 2110 definiert separate Audio-, Video- und Ancillary-Streams über IP; Cisco beschreibt dafür shared IP networks mit Multicast für Distribution und PTP für Synchronisation.

### 9.1 Media-Topologie

           [Camera GW]   [Replay]   [Audio]   [Graphics]  
                \           |          |          /  
                 \          |          |         /  
                 ================================  
                 ||       MEDIA FABRIC A       ||  
                 ================================  
                 ================================  
                 ||       MEDIA FABRIC B       ||  
                 ================================  
                         |              |  
                    [PTP GM A]      [PTP GM B]  
                         |              |  
                      [NMOS / Ctrl / Orchestration]  
                                |  
                          [Encoders / WAN]  
                                |  
                       [Remote Broadcast Center]

### 9.2 Warum A/B?

Weil Broadcast gerne mit **1+1-Redundanz** arbeitet.  
Cisco nennt in IP-Media-Kontexten explizit Standards wie ST 2022-7 für Redundanz; das passt genau zu einer A/B-Media-Fabric.

### 9.3 Wann du Media vom Rest trennst

Immer dann, wenn du:

- viele unkomprimierte Flows hast
    
- Multicast sauber kontrollieren musst
    
- PTP kritisch ist
    
- Produktionsausfälle extrem teuer wären
    

---

## 10) WLAN-Design: Zuschauerbereich ist ein eigenes Problem

Für das Match selbst: **kein WLAN**.  
Für Zuschauer und Presse: sehr wohl WLAN, aber als eigenes High-Density-Design.

Cisco beschreibt große öffentliche WLANs ausdrücklich als Netze mit tausenden unbekannten und unmanaged Clients.

### 10.1 SSIDs

SSID EVENT-GUEST  
  -> VRF GUEST / VLAN 500  
  -> Internet only  
  -> Rate limiting / client isolation  
  
SSID EVENT-PRESS  
  -> VRF GUEST or OPS-lite / VLAN 501  
  -> stärkere Policy, ggf. VPN erlaubt  
  
SSID EVENT-STAFF  
  -> VRF OPS / dynamische Zuweisung oder feste Staff-VLANs  
  -> 802.1X / Enterprise Auth  
  
SSID EVENT-VENDOR  
  -> VLAN 502  
  -> nur Internet + definierte SaaS-Ziele

### 10.2 RF-Grundsätze

Für dichte Hallen würde ich grundsätzlich so planen:

- 5 GHz / 6 GHz priorisieren, sofern Client-Mix das hergibt
    
- 20 MHz Kanäle als Ausgangspunkt
    
- kleine Zellen statt maximaler Reichweite
    
- AP-Dichte nach Sitz-/Stehbereich getrennt planen
    
- Concourse, Bühne, Backstage und Bowl getrennt betrachten
    

Cisco empfiehlt für Large Public Networks und High-Density-Umgebungen genau diesen Fokus auf Zellgröße, Kanalplanung und Client-Dichte statt bloß „mehr Sendeleistung“.

### 10.3 Management- und Service-Fallen

Viele WLAN-Probleme sind in Wahrheit:

- DHCP erschöpft
    
- RADIUS zu klein
    
- Captive Portal bricht weg
    
- DNS langsam
    
- Controller überlastet
    

Cisco weist ausdrücklich darauf hin, dass Core-Services wie DHCP und RADIUS mit der Client-Basis mitskalieren müssen.

---

## 11) Services und Software: ohne die läuft gar nichts

### 11.1 Pflichtdienste

2x DHCP  
2x DNS  
2x AAA / RADIUS  
2x NTP  
2x PTP Grandmaster (für Media)  
2x Syslog / SIEM / Event Collector  
1x Monitoring-Plattform  
1x Config-Backup / Source of Truth  
1x Bastion / Jump Host

### 11.2 Monitoring

Ich würde aktiv überwachen:

- Interface Errors / Drops
    
- Link State Changes
    
- CPU / Memory auf Core, Firewall, Controller
    
- DHCP Scope Utilization
    
- AAA/RADIUS Latency
    
- AP Client Count / Channel Utilization
    
- PTP Lock und Offset
    
- Match-Server Health
    
- WAN Path Utilization
    
- Multicast Group State im Media-Netz
    

Cisco betont sowohl für Large Public Wireless als auch für IP-Media Visibility und saubere Betriebsführung.

### 11.3 Logging

- zentraler Syslog
    
- NetFlow/IPFIX
    
- SNMP oder Streaming Telemetry
    
- Konfigurationsversionierung
    
- Event-Timeline für Incident Review
    

---

## 12) Beispiel für Routing- und Policy-Logik

Nicht als produktionsreife Herstellerkonfiguration, sondern als **didaktisches Pseudokonzept**.

EDGE  
- eBGP zu ISP-A  
- eBGP zu ISP-B  
- Local Preference: ISP-A primär, ISP-B sekundär  
- Default-Route in VRF GUEST und VRF OPS  
- selektive Default/Policy in COMP  
  
CORE  
- OSPF Area 0 zu allen IDFs  
- Summarization:  
  10.20.0.0/16 -> COMP  
  10.30.0.0/16 -> PRACTICE  
  10.40.0.0/16 -> MEDIA  
  10.50.0.0/16 -> OPS  
  10.60.0.0/16 -> GUEST  
  
FIREWALL / VRF LEAKING  
- COMP <-> MATCH-SERVERS allow  
- PRACTICE <-> MATCH-SERVERS allow scoped  
- GUEST -> Internet only  
- OPS -> SaaS / Ticketing / POS backends allow  
- MGMT -> Infrastructure only  
- MEDIA -> Remote contribution / approved services

---

## 13) Ausfallverhalten: was passiert, wenn etwas kaputtgeht?

### Fall 1: Carrier A fällt aus

- Edge-Router B bleibt aktiv
    
- Internet für GUEST/OPS/Broadcast läuft weiter, ggf. mit weniger Kapazität
    
- lokales Match bleibt unbeeinflusst, weil Competition-Fabric lokal läuft
    

Das ist genau der Sinn von Riots lokalem Match-Pfad.

### Fall 2: ein Core-Switch fällt aus

- zweiter Core übernimmt
    
- OSPF konvergiert neu
    
- dual-homed IDFs bleiben online
    
- einzelne Sessions können kurz neu aufbauen
    

Cisco begründet Routed Access unter anderem mit besserer Fault Tolerance und Konvergenz.

### Fall 3: ein Practice-Room-Switch fällt aus

- nur ein Teamraum betroffen
    
- kein Einfluss auf Bühne oder Guest-WLAN
    
- Fault Domain bleibt klein
    

Genau deshalb segmentiert man physisch und logisch.

### Fall 4: WLAN überlastet

- Zuschauer leiden
    
- Match läuft weiter
    
- Ticketing/POS bleibt im eigenen Bereich stabil
    

Auch das ist ein Topologie-Lerneffekt: gute Netze trennen **kritisch** von **komfortrelevant**.

---

## 14) Bau- und Inbetriebnahme-Reihenfolge

So würde ich das in der Praxis aufbauen:

### Phase 1: Pre-Staging

- VLANs, VRFs, Routing, ACLs im Lab testen
    
- AP- und Switch-Templates vorbereiten
    
- Server vorkonfigurieren
    
- Monitoring/AAA/DHCP/DNS testen
    

### Phase 2: Venue Core

- Edge, Firewall, Core, Management zuerst
    
- Internet und Redundanz testen
    
- Out-of-Band-Pfade prüfen
    

### Phase 3: Zonen

- Stage
    
- Practice
    
- Ops
    
- Media
    
- WLAN
    

### Phase 4: Dienste

- DHCP/DNS/AAA
    
- Match-Server
    
- Broadcast-Control
    
- Ticketing/POS
    
- Captive Portal / Guest
    

### Phase 5: Abnahmetests

- Failover WAN
    
- Failover Core
    
- ACL-Tests
    
- Client-Authentifizierung
    
- PTP / Media-Timing
    
- Match-Latency
    
- WLAN-Loadtests
    

Riot beschreibt die Load-in-Phase als Aufbau durch Systems-, Network- und Security-Teams direkt vor Ort; das stützt genau dieses staged Vorgehen.

---

## 15) Warum dieses Design didaktisch gut ist

Weil du daran fast alle wichtigen Netzwerkideen gleichzeitig sehen kannst:

- **Hierarchie**: Edge → Core → IDF → Access
    
- **Segmentierung**: VLANs, VRFs, ACLs
    
- **Routing**: BGP außen, OSPF innen
    
- **Redundanz**: Carrier, Core, Uplinks, Media A/B
    
- **Realtime**: lokale Match-Server
    
- **Wireless**: eigenes High-Density-Design
    
- **Security**: Default Deny, NAC, Bastion
    
- **Operations**: DHCP/DNS/AAA/Monitoring
    
- **Broadcast**: Multicast, PTP, Contribution
    

---

## Zusammenfassung

Das vollständige Referenzdesign sieht so aus:  
Ein **redundanter WAN-Edge** mit zwei Providern, dahinter ein **L3-Core/Distribution-Paar**, daran mehrere **physische Zonen** für Competition, Practice, Ops, Media und WLAN. Logisch wird das über **VRFs und VLANs** getrennt, intern per **OSPF** geroutet, nach außen per **BGP** angebunden. Das eigentliche Match läuft über **lokale, kabelgebundene Competition-Pfade mit Bare-Metal-Match-Servern**, während Zuschauer und Presse in einer **eigenen High-Density-WLAN-Welt** hängen. Broadcast wird bei moderner IP-Produktion als **eigene Media-Fabric mit Multicast und PTP** behandelt.



