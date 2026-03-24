# Nmap -- Noob-Anleitung

> Nmap (Network Mapper) ist ein Netzwerk-Scanner: er findet Hosts, offene Ports und laufende Dienste.
> Diese Anleitung bezieht sich auf ein Lab-Setup (Linux, Subnetz 10.0.0.0/24).

---

## Installation

```bash
# Arch / Manjaro
sudo pacman -S nmap arp-scan
```

---

## Grundprinzip

```
nmap findet heraus:
  1. Welche Geraete im Netz sind          (Host Discovery)
  2. Welche Ports auf einem Geraet offen   (Port Scan)
  3. Welche Dienste/Versionen dort laufen (Service Detection)
  4. Welches OS ein Geraet hat             (OS Detection)
```

---

## Dein Netz kennenlernen

```bash
# Eigene IP und Subnetz anzeigen
ip route
# -> default via 10.0.0.1 dev eno1 ... src 10.0.0.10

# Daraus liest du ab:
# - Dein Subnetz:  10.0.0.0/24
# - Dein Gateway:  10.0.0.1 (lab-router)
# - Deine IP:      10.0.0.10
```

---

## Scan-Stufen -- vom sanften Ping bis zum Tiefen-Scan

### Stufe 1 -- Host Discovery (wer ist da?)

```bash
# Ping-Scan: nur pruefen welche Hosts antworten, KEINE Ports
nmap -sn 10.0.0.0/24

# Alternative: arp-scan (schneller im lokalen Netz, braucht root)
sudo arp-scan --localnet
```

**Was du siehst:** Liste aller Geraete mit IP, MAC-Adresse und Hersteller.
**Lerneffekt:** ARP ist Layer 2 -- Geraete im selben Netz antworten direkt.

### Stufe 2 -- Top-Ports (schneller Ueberblick)

```bash
# Die 100 haeufigsten Ports im ganzen Netz
sudo nmap -sS -sV --top-ports 100 10.0.0.0/24
```

**Was du siehst:** Offene Ports pro Host + erkannte Dienste.
**Dauer:** ~2-5 Minuten fuer /24.

### Stufe 3 -- Vollstaendiger Port-Scan (einzelner Host)

```bash
# ALLE 65535 TCP-Ports auf dem App-Server
sudo nmap -p- -sS -sV 10.0.0.100
```

**Was du siehst:** Jeder offene Port, auch ungewoehnliche.
**Dauer:** ~5-15 Minuten pro Host.

### Stufe 4 -- UDP-Scan (gezielt, weil langsam)

```bash
# Top 50 UDP-Ports auf dem DNS-Server
sudo nmap -sU --top-ports 50 10.0.0.30
```

**Was du siehst:** DNS (53), DHCP (67/68), SNMP (161), etc.
**Hinweis:** UDP-Scans sind prinzipbedingt langsam -- nur gezielt einsetzen.

### Stufe 5 -- OS-Erkennung

```bash
sudo nmap -O 10.0.0.100
```

**Was du siehst:** Vermutetes Betriebssystem + Kernel-Version.

---

## Die wichtigsten Flags

| Flag | Bedeutung | Wann nutzen |
|------|-----------|-------------|
| `-sn` | Ping-Scan, keine Ports | Host Discovery |
| `-sS` | SYN-Scan (Stealth, braucht root) | Standard-Port-Scan |
| `-sT` | TCP Connect (ohne root moeglich) | Wenn kein root verfuegbar |
| `-sU` | UDP-Scan | DNS, DHCP, SNMP pruefen |
| `-sV` | Service/Version Detection | Dienste identifizieren |
| `-O` | OS Detection | Betriebssystem erkennen |
| `-p-` | Alle 65535 Ports | Gruendlicher Einzelhost-Scan |
| `-p 22,80,443` | Nur bestimmte Ports | Gezielter Schnellcheck |
| `--top-ports N` | Die N haeufigsten Ports | Schneller Ueberblick |
| `-A` | Aggressive: OS + Version + Scripts + Traceroute | Einzel-Host Komplett-Analyse |
| `-T4` | Schnellere Timing-Vorlage | Standard fuer LAN |
| `-oN datei.txt` | Ergebnis in Textdatei | Ergebnisse aufheben |
| `-oX datei.xml` | Ergebnis als XML | Fuer Weiterverarbeitung |

---

## Lab-Rezepte

### Alle Geraete im LAN finden

```bash
sudo nmap -sn 10.0.0.0/24
```

### Was laeuft auf dem App-Server?

```bash
sudo nmap -sS -sV -p- 10.0.0.100
# Erwartung: 22 (SSH), 8080 (Nextcloud/Apache)
```

### DNS-Server pruefen

```bash
sudo nmap -sU -sV -p 53 10.0.0.30
# Erwartung: 53/udp open domain
```

### Hypervisor Web-Interface + SSH

```bash
sudo nmap -sS -sV -p 22,8006 10.0.0.20
# Erwartung: 22 (SSH), 8006 (Hypervisor Web UI)
```

### Alle Webserver im LAN finden

```bash
sudo nmap -sS -p 80,443,8080,8443 10.0.0.0/24
```

### Lab-Router Ports

```bash
sudo nmap -sS -sV -p 80,443,53,5060 10.0.0.1
```

---

## Ergebnisse lesen

### Port-Status

| Status | Bedeutung |
|--------|-----------|
| `open` | Port antwortet, Dienst laeuft |
| `closed` | Port antwortet mit RST -- kein Dienst, aber erreichbar |
| `filtered` | Keine Antwort -- Firewall blockt vermutlich |
| `open\|filtered` | Nmap kann nicht unterscheiden (typisch bei UDP) |

### Beispiel-Output

```
PORT     STATE SERVICE VERSION
22/tcp   open  ssh     OpenSSH 9.2p1 Debian
80/tcp   closed http
8080/tcp open  http    Apache httpd 2.4.62
```

Liest sich so: "SSH und Apache auf 8080 laufen, Port 80 ist erreichbar aber kein Dienst aktiv."

---

## Aktuelle Verbindungen sehen (kein nmap)

Nmap zeigt was **moeglich** ist. Fuer das was **gerade passiert**:

```bash
# Alle aktiven TCP/UDP-Verbindungen auf deinem PC
ss -tunap

# Nur ESTABLISHED
ss -tunap state established

# Nur Listening (welche Ports sind lokal offen?)
ss -tlnp
```

---

## Lernuebungen im Lab

### Uebung 1 -- Netzwerk-Inventar

```bash
sudo nmap -sn 10.0.0.0/24 -oN ~/nmap-inventar.txt
```
**Aufgabe:** Zaehle die Geraete. Kannst du jedem eine Funktion zuordnen?
**Lerneffekt:** Du lernst dein eigenes Netz kennen.

### Uebung 2 -- Port-Scan vs. Wireshark

```bash
# Terminal 1: Wireshark starten, Filter: ip.addr == 10.0.0.100 and tcp
# Terminal 2: nmap starten
sudo nmap -sS -p 22,8080 10.0.0.100
```
**Was du in Wireshark siehst:** SYN-Pakete fuer jeden Port, SYN-ACK fuer offene, RST fuer geschlossene.
**Lerneffekt:** Ein SYN-Scan sendet nur SYN und wertet die Antwort aus -- kein vollstaendiger Handshake.

### Uebung 3 -- Dienste identifizieren

```bash
sudo nmap -sV -p 22,8080 10.0.0.100
```
**Aufgabe:** Vergleiche die erkannten Versionen mit der Realitaet (`ssh -V`, `apache2 -v` im Container).
**Lerneffekt:** Service Detection schickt Probes und matcht Antworten gegen eine Datenbank.

### Uebung 4 -- UDP am Beispiel DNS

```bash
sudo nmap -sU -sV -p 53 10.0.0.30
# Vergleiche mit:
dig @10.0.0.30 google.com
```
**Lerneffekt:** DNS laeuft auf UDP 53 -- du siehst den Port offen und kannst ihn direkt nutzen.

### Uebung 5 -- Vorher/Nachher mit Firewall

```bash
# Scan VORHER
sudo nmap -sS -p 1-1024 10.0.0.100 -oN ~/scan-vorher.txt
# Jetzt einen Port per iptables blocken (im Container):
# iptables -A INPUT -p tcp --dport 8080 -j DROP
# Scan NACHHER
sudo nmap -sS -p 1-1024 10.0.0.100 -oN ~/scan-nachher.txt
# Vergleichen:
diff ~/scan-vorher.txt ~/scan-nachher.txt
```
**Lerneffekt:** Firewall-Regeln veraendern was nmap sieht -- `open` wird `filtered`.

---

## Wichtig: Ethik & Recht

- **Nur eigene Netze scannen.** Port-Scans auf fremde Systeme koennen strafbar sein (Paragraph 202a/b/c StGB).
- Dein Lab (10.0.0.0/24) gehoert dir -- darfst du scannen.
- Arbeitgeber-Netz: nur mit schriftlicher Genehmigung.
- Cloud-Provider (AWS, Hetzner): haben eigene Scan-Policies, vorher lesen.

---

## Naechste Schritte

Wenn du die Grundlagen sitzt:
- **NSE Scripts** (`--script`) -- Nmap Scripting Engine fuer gezielte Vulnerability-Checks
- **Zenmap** -- GUI fuer nmap (visuelles Netzwerk-Mapping)
- **masscan** -- Extrem schneller Port-Scanner fuer grosse Netze
- **Wireshark + nmap kombinieren** -- Scan starten und gleichzeitig in Wireshark beobachten (Uebung 2)

---

Verlinkung: siehe 00-Lehrplan.md | siehe Wireshark-Anleitung.md
