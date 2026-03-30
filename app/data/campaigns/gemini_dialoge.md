# Kampagne: Der grosse Ausfall

### Szene: s1_freitagabend (start)

**Narrativ:**
Es ist Freitag, 16:30 Uhr. Die Sonne scheint schräg durch die Jalousien deines neuen Büros, und du kannst den Duft von Freiheit und dem bevorstehenden Wochenende fast schon riechen. Du hast gerade deine letzte Dokumentation für heute abgespeichert, als plötzlich ein unnatürliches, tiefes Summen durch den Raum geht. In einer makabren Choreografie wechseln alle Monitorwände im Network Operations Center gleichzeitig von beruhigendem Blau auf ein aggressiv pulsierendes Alarm-Rot.

### Szene: s2_brenner_panik (manager_panik)

**Narrativ:**
Die Tür fliegt mit so viel Schwung auf, dass die Klinke eine Delle in die Wand schlägt. Herr Brenner stürzt herein, seine Krawatte hängt schief, und er hält sein Smartphone hoch, als wäre es eine heilige Reliquie, die den Geist aufgegeben hat. Er sieht aus, als hätte er gerade einen Geist gesehen - oder schlimmer: als hätte er gerade erfahren, dass sein LinkedIn-Profil gelöscht wurde.

**NPC-Dialog:**
Herr Brenner: "Hören Sie das? Das ist das Geräusch des digitalen Untergangs! Meine Cloud ist weg! Einfach weg! Ich wollte gerade die Synergie-Matrix für das Board-Meeting hochladen, aber der Browser sagt 'DNS_PROBE_FINISHED_NO_INTERNET'. Was bedeutet das überhaupt? Ist das Internet jetzt... leer? Können wir nicht einfach die Cloud neustarten oder den Hyperlink wieder einstecken? In meinem letzten Unternehmen hatten wir sowas nicht, da hat das WLAN einfach... geatmet!"

**Entscheidungen:**
- "Herr Brenner, beruhigen Sie sich. Ich prüfe sofort die Core-Switches." -> Management-Rep +1
- "Klingt nach einem massiven Layer-1 oder Layer-2 Problem. Ich brauche Zugriff auf die Logs." -> Technik-Rep +1
- "Haben Sie schon versucht, das Gerät aus- und wieder einzuschalten?" -> Humor-Rep +1

### Szene: s3_erste_diagnose (erste_diagnose)

**Narrativ:**
Du setzt dich an die Konsole und die Textzeilen rasen an deinen Augen vorbei wie im Vorspann eines Hacker-Films aus den 90ern. Deine Finger fliegen über die Tastatur, während du versuchst, das Herzstück des Netzwerks zu erreichen. Die Diagnose ist eindeutig: Die Firewall blockiert alles, was nicht bei drei auf den Bäumen ist, und der DNS-Server scheint zu glauben, dass das gesamte lokale Netzwerk jetzt in der Antarktis liegt.

**NPC-Dialog:**
Sabine: "Spar dir den Wireshark-Scan für später. Die Port-LEDs am Core-Switch blinken im Takt von 'SOS' - und nein, das ist keine neue Feature-Animation."

### Szene: s4_klaus_gestaendnis (klaus_gestaendnis)

**Narrativ:**
Klaus schleicht mit hängenden Schultern ins Büro, seine Hände tief in den Taschen seines Hoodies vergraben. Er vermeidet jeden Augenkontakt und starrt so intensiv auf seine Sneaker, als hoffte er, sie würden ihn per Teleportation direkt nach Hause bringen.

**NPC-Dialog:**
Klaus: "Also... ich wollte eigentlich nur helfen, echt jetzt! Der Switch im Serverraum hat so komisch orange geblinkt, fast so wie die Warnleuchte an Papas altem Diesel. Ich dachte mir, wenn ich den einmal kurz hart vom Strom trenne und wieder einstecke, dann kalibriert sich das Bit-Fließen vielleicht neu. Ich hab dabei vielleicht... ganz aus Versehen... auch das dicke schwarze Kabel kurz ausgesteckt, weil es im Weg war. Soll ich vielleicht die Kabel nach Farbe sortieren, damit das nicht nochmal passiert?"

**DauBot:**
Fehlerbeschreibung: "Klaus hat den Core-Switch neugestartet und dabei wohl ein Loopback-Szenario geschaffen, weil er dachte, die Redundanzkabel wären 'Deko'."
Fehler-Optionen:
1. Switch-Neustart hat die Spanning Tree Konvergenz ausgelöst und einen Broadcast Storm verursacht <- RICHTIG
2. Klaus hat die IP-Adresse des Switches gelöscht
3. Das VLAN 1 wurde versehentlich zum Management-VLAN
4. Die MAC-Adresstabelle ist durch das Ausstecken explodiert
Fix-Optionen:
1. Spanning Tree Konvergenz abwarten, Port-States prüfen und unnötige Brücken entfernen <- RICHTIG
2. Den Switch komplett auf Werkseinstellungen zurücksetzen
3. Alle Patchkabel tauschen, da Klaus sie eventuell verbogen hat
4. Eine neue Firmware einspielen, während der Switch noch bootet

### Szene: s5_helpdesk_chaos (helpdesk_chaos)

**Narrativ:**
Sabine balanciert ein Headset auf einem Ohr und ein Telefon am anderen, während sie gleichzeitig zwei verschiedene Leute im Chat abwimmelt. Der Stapel ungelesener Post-its an ihrem Monitor wächst schneller als die CPU-Auslastung bei einem DDoS-Angriff.

**NPC-Dialog:**
Sabine: "Ticket #48: 'Mein Bildschirm ist schwarz.' - Ja Frau Mueller, der Monitor ist AUS. Ich hab ihr gesagt, sie soll die 'Strom-App' aktivieren, dann ging es wieder. Und Ticket #52: Jemand hat gefragt, ob man das WLAN auch in Tupperware-Dosen für den Heimweg abfüllen kann. Wenn das hier erledigt ist, kündige ich. Oder auch nicht, das sage ich jeden Freitag. Hier, nimm den Kaffee. Glaub mir, du brauchst das mehr als Brenner, aber er braucht es, um funktionsfähig zu bleiben. Nimm ihn einfach, bevor ich ihn selbst trinke und einen Herzinfarkt bekomme."

**Entscheidungen:**
- Kaffee an Herrn Brenner geben -> Management-Rep +2
- Den Kaffee selbst trinken -> Fokus-Bonus für die nächste Szene
- Sabine den Kaffee lassen -> Team-Rep +2

### Szene: s6_server_raum (server_raum)

**Narrativ:**
Die schwere Stahltür zum Serverraum schwingt auf und eine Wand aus trockener, heißer Luft schlägt dir entgegen. Das normalerweise beruhigende Rauschen der Lüfter ist einem hysterischen Kreischen gewichen. Überall siehst du rote LEDs, die dich wie böse Augen in der Dunkelheit anstarren. Es riecht dezent nach überhitztem Plastik und Verzweiflung.

### Szene: s7_log_analyse (log_analyse)

**Narrativ:**
Du schließt dein Notebook an den Wartungs-Port an. Die Logs sind ein Schlachtfeld. Zwischen den üblichen Fehlermeldungen sticht ein Zeitstempel hervor: 15:47 Uhr. Ein massiver Traffic-Spike, gefolgt von einer kryptischen Signatur, die nach einem automatisierten Firmware-Rollout aussieht. Jemand hat hier tief im System gewühlt, kurz bevor die Lichter ausgingen.

**NPC-Dialog:**
Torsten (über Lautsprecher): "Hör mal, Kleiner, ich seh gerade von hier aus den Ping-Loss. Hast du schon mal versucht, die ARP-Tabelle alphabetisch zu sortieren? Das hilft manchmal beim Routing-Gefühl."

### Szene: s8_torsten_anruf (torsten_anruf)

**Narrativ:**
Dein Telefon klingelt. Im Hintergrund hörst du das Klirren von Gläsern und das fröhliche Gemurmel eines Biergartens. Torsten klingt verdächtig entspannt, was in direktem Kontrast zu der technischen Apokalypse steht, in der du dich gerade befindest.

**NPC-Dialog:**
Torsten: "Also NORMALERWEISE hätte ich das in 5 Minuten gefixt, aber ich bin gerade auf einer sehr wichtigen... äh... Fortbildung zum Thema 'Flüssige Logistik'. Hör mal, hast du den Cache geleert? Alle Caches? Auch den von der Kaffeemaschine? Ich hab gestern Abend zwar noch ein ganz kleines, winziges Firmware-Update eingespielt, aber das hat DAMIT absolut nichts zu tun. Das war quasi nur Kosmetik für die Bits. Schau lieber mal nach, ob Klaus nicht wieder seinen Joghurt auf dem Switch gelagert hat."

### Szene: s9_kuehlung_krise (kuehlung_krise)

**Narrativ:**
**TIMER: 120 SEKUNDEN**
Ein schriller Alarmton schneidet durch das Rauschen. Die Temperaturanzeige am Rack 4 kriecht unaufhaltsam in den roten Bereich. Der Kühlungs-Controller antwortet nicht auf Pings - vermutlich, weil die Default-Route ins Nirgendwo zeigt. Wenn die Server nicht in zwei Minuten kalte Luft bekommen, schaltet sich das gesamte Rechenzentrum als Selbstschutz ab.

**NPC-Dialog:**
Herr Brenner (über Funk): "Hier drüben ist es so warm, ich könnte Spiegeleier auf meinem Tablet braten! Ist das diese 'Hot-Fix'-Technologie, von der alle reden?"

**Ablauf des Timers:**
"Ein Server nach dem anderen fährt mit einem klagenden Piepsen herunter. Die Stille, die folgt, ist ohrenbetäubend. Du hörst nur noch das traurige Herunterfahren-Geräusch der letzten Festplatte."

### Szene: s10_klaus_firewall (klaus_firewall)

**Narrativ:**
Klaus taucht wieder auf, diesmal hält er ein Tablet in der Hand, auf dem das Firewall-Interface geöffnet ist. Er sieht stolz aus, was in diesem Kontext extrem beunruhigend ist.

**NPC-Dialog:**
Klaus: "Ich hab mir gedacht, wenn das Internet so gefährlich ist, dann schließe ich einfach alle Türen ab! Ich hab in der Firewall alles auf 'Verweigern' gestellt, was nach draußen will. So können keine Viren abhauen und wir sparen auch noch Bandbreite! Ich dachte, wenn ich alles blockiere, ist es einfach sicherer... wie eine digitale Ritterburg mit hochgezogener Zugbrücke!"

**DauBot:**
Fehlerbeschreibung: "Klaus hat alle Outbound-Regeln gelöscht und durch eine 'Deny All'-Regel ersetzt, um das Netzwerk 'virenfrei' zu halten."
Fehler-Optionen:
1. Klaus hat alle ausgehenden Verbindungen blockiert (Any-Any Outbound Deny) <- RICHTIG
2. Die Firewall wurde in den Bridge-Modus versetzt
3. Klaus hat das Admin-Passwort in '12345' geändert
4. Die NAT-Tabelle wurde komplett geleert
Fix-Optionen:
1. Outbound-Regeln auf den letzten bekannten 'Good State' oder Default zurücksetzen <- RICHTIG
2. Die Firewall für 10 Minuten ausschalten
3. Alle IP-Adressen im Subnetz händisch neu vergeben
4. Klaus das Tablet wegnehmen und ihn in den Archiv-Keller schicken

### Szene: s11_reputation_gabel (reputation_gabel)

**Narrativ:**
Klaus sieht dich erwartungsvoll an. Er weiß, dass er wieder Mist gebaut hat, aber sein Enthusiasmus ist fast schon ansteckend. Du stehst vor einer Entscheidung: Wie gehst du mit dem 'menschlichen Single-Point-of-Failure' um?

**Entscheidungen:**
- "Klaus, du hilfst mir ab jetzt. Ich erkläre dir Schritt für Schritt, was wir tun. Wir machen das zusammen." -> Team-Rep +2, Klaus wird Verbündeter.
- "Klaus, geh bitte Kaffee holen - ganz viel Kaffee - und fass ab jetzt bitte ABSOLUT NICHTS mehr an." -> Neutral, Klaus wirkt leicht beleidigt.
- "Ich muss das dem Chef melden, Klaus. Das geht so nicht weiter, wir riskieren hier das ganze Geschäft." -> Management-Rep +1, Team-Rep -1.

**Reaktion Klaus:**
(Bei Option A): "Echt? Cool! Ich hol meinen Notizblock! Ich wollte schon immer wissen, was dieses 'Routing' eigentlich für ein Sport ist!"

### Szene: s12_beweis_suche (beweis_suche)

**Narrativ:**
Du öffnest Wireshark und filterst den Traffic. Da ist es: Zwischen den zahllosen Retransmissions siehst du die Überreste eines gescheiterten TFTP-Transfers von gestern Nacht, 23:47 Uhr. Die IP-Adresse des Absenders? Eindeutig Torstens Workstation. Er hat versucht, eine experimentelle Beta-Firmware auf die Core-Router zu pushen, bevor er in den Biergarten verschwunden ist.

**NPC-Dialog:**
Sabine: "Na, hast du das Skelett im digitalen Schrank gefunden? Ich wette einen Monatsvorrat an Post-its, dass Torsten seine Finger im Spiel hatte."

### Szene: s13_torsten_konfrontation (torsten_konfrontation)

**Narrativ:**
Du rufst Torsten erneut an, diesmal mit den Log-Files als Beweis in der Hand. Die Biergarten-Atmosphäre im Hintergrund wirkt jetzt fast schon provozierend.

**NPC-Dialog:**
Torsten: "Was? Beweise? Das... das waren geplante Wartungsarbeiten! Ein sehr wichtiges Security-Audit! Na gut, okay... Ich wollte das eigentlich am Montag testen, aber dann gab es dieses 'Zwei-für-Eins'-Angebot auf Weizenbier und ich dachte, das Script läuft von alleine durch. Wer konnte denn ahnen, dass die Firmware die Paketgrößen nicht mag? Jetzt hab dich nicht so, bügel das einfach glatt, du bist doch der Neue."

### Szene: s14_live_patch (live_patch)

**Narrativ:**
**TIMER: 90 SEKUNDEN**
Im Konferenzraum direkt nebenan hat die CEO gerade ihre Quartalspräsentation vor den Investoren gestartet. Du musst den Firmware-Rollback jetzt live durchführen. Wenn die Verbindung auch nur für drei Sekunden abbricht, stürzt der VPN-Tunnel der Investoren ein und Herr Brenner wird vermutlich versuchen, das Internet persönlich zu verklagen.

**NPC-Dialog:**
Herr Brenner (flüsternd durch die Tür): "Machen Sie schon! Ich sehe die Ladebalken auf dem Beamer schwitzen! Wenn das Bild einfriert, friert auch mein Bonus ein!"

**Ablauf des Timers:**
"Der VPN-Tunnel bricht zusammen. Auf dem Flur hörst du den wütenden Aufschrei der CEO. Dein Handy vibriert: 12 entgangene Anrufe vom Vorstand. Das Wochenende ist offiziell gestrichen."

### Szene: s15_ende_gold (ende_gold)

**Narrativ:**
Ein grünes Häkchen erscheint auf deinem Bildschirm. Das Netzwerk atmet wieder. Die Pings kommen mit stabilen 1ms zurück. Klaus schaut dir über die Schulter und versteht zum ersten Mal, was ein VLAN-Tag macht. Torsten muss am Montag zum Rapport beim Chef antreten, und Sabine lehnt sich mit einem seltenen Lächeln zurück.

**NPC-Dialog:**
Sabine: "Wisst ihr was? Ich kündige vielleicht doch nicht. Heute nicht. Morgen sehen wir weiter. Aber nur, wenn du nächste Woche den Montag-Morgen-Dienst übernimmst."

### Szene: s16_ende_gut (ende_gut)

**Narrativ:**
Das Netzwerk läuft wieder, aber die Spurensuche blieb oberflächlich. Herr Brenner ist glücklich, solange seine 'Cloud' wieder da ist, und Torsten wird am Montag so tun, als hätte er aus der Ferne alles gerettet. Du hast den Tag gerettet, aber der Ruhm wird wohl woanders landen.

**NPC-Dialog:**
Herr Brenner: "Hervorragend! Ich wusste doch, dass ein kleiner Neustart der Synergie-Zentrale alles löst! Tolle Teamarbeit, Leute!"

### Szene: s17_ende_chaos (ende_chaos)

**Narrativ:**
Es hat nicht gereicht. Das Netzwerk ist ein Trümmerhaufen und Herr Brenner hat bereits einen externen Dienstleister für 400 Euro die Stunde gerufen, der erst morgen früh kommen kann. Das Büro sieht aus wie nach einer Schlacht, und der einzige Trost ist die Pizza, die Brenner für alle bestellt hat.

**NPC-Dialog:**
Sabine: "Wenigstens das Internet vom Pizzadienst funktioniert noch. Ich hab meine Kündigung schon mal als Entwurf gespeichert - lokal, sicher ist sicher."
