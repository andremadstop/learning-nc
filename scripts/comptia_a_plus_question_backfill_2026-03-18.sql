-- Heuristische Exam- und Kapitel-Zuordnung fuer bestehende CompTIA A+ Fragen
-- Quelle der Kapitelstruktur: Kammermann CompTIA A+ (6. Auflage, 2022)
-- Zielmenge: Course 24 / Pools 23, 26, 29, 55, 56
--
-- Vorgehen:
-- 1. A+-Pools erhalten Handbook-Metadaten auf Pool-Ebene
-- 2. Fragen werden auf Exam-Key (220-1101 / 220-1102) und Kapitel gemappt
-- 3. Nicht sauber zuordenbare Fragen bleiben bewusst "Kapiteluebergreifend"

BEGIN;

UPDATE oc_learning_pools
SET
    handbook_key = 'kammermann-a-plus-6ed-2022',
    handbook_title = 'Kammermann CompTIA A+ (6. Auflage, 2022)',
    chapter_key = 'cross-book',
    chapter_title = 'Kapiteluebergreifend',
    chapter_order = 999
WHERE id IN (23, 26, 29, 55, 56);

WITH answer_texts AS (
    SELECT
        question_id,
        string_agg(text, ' ' ORDER BY id) AS answer_blob
    FROM oc_learning_answers
    GROUP BY question_id
),
candidate_questions AS (
    SELECT
        q.id,
        replace(
            replace(
                replace(
                    replace(lower(q.text || ' ' || COALESCE(a.answer_blob, '')), 'ä', 'ae'),
                    'ö',
                    'oe'
                ),
                'ü',
                'ue'
            ),
            'ß',
            'ss'
        ) AS raw_combined_haystack,
        ' ' || regexp_replace(
            replace(
                replace(
                    replace(
                        replace(lower(q.text), 'ä', 'ae'),
                        'ö',
                        'oe'
                    ),
                    'ü',
                    'ue'
                ),
                'ß',
                'ss'
            ),
            '[^a-z0-9]+',
            ' ',
            'g'
        ) || ' ' AS question_haystack,
        ' ' || regexp_replace(
            replace(
                replace(
                    replace(
                        replace(lower(q.text || ' ' || COALESCE(a.answer_blob, '')), 'ä', 'ae'),
                        'ö',
                        'oe'
                    ),
                    'ü',
                    'ue'
                ),
                'ß',
                'ss'
            ),
            '[^a-z0-9]+',
            ' ',
            'g'
        ) || ' ' AS combined_haystack
    FROM oc_learning_questions q
    LEFT JOIN answer_texts a ON a.question_id = q.id
    WHERE q.pool_id IN (23, 26, 29, 55, 56)
),
chapter_rules AS (
    SELECT
        id,
        CASE
            WHEN combined_haystack LIKE ANY (ARRAY[
                '% backup %',
                '% restore %',
                '% recovery %',
                '% rpo %',
                '% rto %',
                '% datenloesch %',
                '% sichere datenloeschung %',
                '% shredding %',
                '% wipe %',
                '% recycling %',
                '% entsorgung %',
                '% urheberrecht %',
                '% copyright %',
                '% illegalen inhalten %',
                '% datensicherheit %',
                '% acceptable use policy %',
                '% benutzerverhaltensregeln %'
            ]) THEN 'ch30-datenschutz'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% trusted platform module %',
                '% tpm %',
                '% bitlocker %',
                '% filevault %',
                '% edr %',
                '% ngfw %',
                '% swg %',
                '% casb %',
                '% dlp %',
                '% data loss prevention %',
                '% firewall %',
                '% proxy %',
                '% proxyserver %',
                '% intrusion detection %',
                '% secure boot %',
                '% bios passwort %',
                '% wpa2 %',
                '% wpa3 %',
                '% ssid %',
                '% aaa %',
                '% authentication %',
                '% authorization %',
                '% antivirus %',
                '% antimalware %',
                '% malware entfernen %',
                '% virus entfernen %',
                '% browser richtig konfigurieren %',
                '% starkes passwort %',
                '% bios-passwort %',
                '% sichere verbindung zu einem verzeichnisdienst %'
            ]) THEN 'ch29-systemschutz'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% zwei faktor %',
                '% 2fa %',
                '% two factor %',
                '% mfa %',
                '% vpn %',
                '% ssh %',
                '% remote monitoring %',
                '% rmm %',
                '% mobile device management %',
                '% mdm %',
                '% zertifikat %',
                '% certificate %',
                '% tls %',
                '% ssl %',
                '% least privilege %',
                '% minimalen privileg %',
                '% zugriffskontrolle %',
                '% badge %',
                '% mantrap %',
                '% physische sicherheit %',
                '% fingerabdruck %',
                '% netzhautscan %',
                '% biometr %',
                '% datenverschluessel %',
                '% key fob %',
                '% schloss %',
                '% byod %',
                '% cobo %',
                '% cope %',
                '% cyod %'
            ]) THEN 'ch28-sicherheitsmassnahmen'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% ransomware %',
                '% malware %',
                '% virus %',
                '% worm %',
                '% trojan %',
                '% keylogger %',
                '% social engineering %',
                '% phishing %',
                '% spoofing %',
                '% sql injection %',
                '% cross site scripting %',
                '% xss %',
                '% brute force %',
                '% evil twin %',
                '% tailgating %',
                '% vishing %',
                '% smishing %',
                '% dos %',
                '% ddos %',
                '% replay %',
                '% on path %',
                '% man in the middle %'
            ]) THEN 'ch27-bedrohungen'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% linux %',
                '% ubuntu %',
                '% apt %',
                '% yum %',
                '% dnf %',
                '% rpm %',
                '% bash %',
                '% grep %',
                '% chmod %',
                '% chown %',
                '% sudo %',
                '% fsck %',
                '% ext4 %',
                '% xfs %',
                '% btrfs %',
                '% zfs %'
            ]) THEN 'ch26-linux'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% macos %',
                '% mac os %',
                '% monterey %',
                '% finder %',
                '% dock %',
                '% icloud %',
                '% keychain %',
                '% time machine %',
                '% apple id %',
                '% mission control %',
                '% launchpad %'
            ]) THEN 'ch25-macos'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% chkdsk %',
                '% event viewer %',
                '% ereignisanzeige %',
                '% systemwiederherstellung %',
                '% blue screen %',
                '% bsod %',
                '% dism %',
                '% diskpart %',
                '% defragment %',
                '% dxdiag %',
                '% msconfig %',
                '% startup repair %',
                '% bootloader %',
                '% treiberproblem %',
                '% kompatibilitaetsproblem %',
                '% registry %',
                '% registrierung %',
                '% gpupdate %',
                '% sfc scannow %',
                '% recovery console %',
                '% safe mode %',
                '% abgesicherter modus %'
            ]) THEN 'ch24-windows-wartung'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% windows 11 %'
            ]) THEN 'ch23-windows11'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% windows 10 %'
            ]) THEN 'ch22-windows10'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% clean install %',
                '% in place upgrade %',
                '% upgrade option %',
                '% installat%vorbereitung %',
                '% 32 bit %',
                '% 64 bit %',
                '% arbeitsgruppe %',
                '% domaene %',
                '% domain join %',
                '% online konto %',
                '% bootable usb %',
                '% installationsmedien %',
                '% image deployment %',
                '% sysprep %'
            ]) THEN 'ch21-windows-installation'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% dateisystem %',
                '% mbr %',
                '% gpt %',
                '% pbr %',
                '% bootmanager %',
                '% chromeos %',
                '% prozess %',
                '% kommandozeile %',
                '% skript %',
                '% script %',
                '% host betriebssystem %',
                '% gastbetriebssystem %',
                '% betriebssystem %',
                '% partition %',
                '% sandbox %',
                '% kompatibilitaetsmodus %',
                '% ghost image %'
            ]) THEN 'ch20-betriebssysteme'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% ipconfig %',
                '% pathping %',
                '% tracert %',
                '% traceroute %',
                '% netstat %',
                '% nbtstat %',
                '% nslookup %',
                '% kabeltester %',
                '% cable tester %',
                '% crimp %',
                '% crimper %',
                '% punchdown %',
                '% auflegewerkzeug %',
                '% loopback %',
                '% throughput tester %',
                '% tone generator %',
                '% toner probe %',
                '% intermitt %',
                '% dead zone %',
                '% signalstaerke %',
                '% netzwerklatenz %',
                '% namensaufloesung %',
                '% 169.254 %',
                '% apipa %',
                '% ping %',
                '% eingeschraenkte netzwerkkonnektivitaet %',
                '% 1000base t %',
                '% cat 5e %',
                '% das kabel nicht richtig sitzt %'
            ]) THEN 'ch19-netzwerk-fehlersuche'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% static ip %',
                '% statische ip %',
                '% dhcp %',
                '% nat %',
                '% dnat %',
                '% upnp %',
                '% iot %',
                '% wps %',
                '% native vlan %',
                '% management vlan %',
                '% vlan %',
                '% routereinstellungen %',
                '% mailverbindung %',
                '% internetverbindung %',
                '% wlan aufbauen %',
                '% backup-netzwerkverbindung %',
                '% verdrahteten netzwerks %'
            ]) THEN 'ch18-netzwerke-einrichten'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% tcp ip %',
                '% osi %',
                '% dod4 %',
                '% ipv4 %',
                '% ipv6 %',
                '% subnet %',
                '% subnetz %',
                '% cidr %',
                '% host adressen %',
                '% ldap %',
                '% smb %',
                '% cifs %',
                '% ftp %',
                '% http %',
                '% https %',
                '% ntp %',
                '% telnet %',
                '% smtp %',
                '% pop3 %',
                '% imap %',
                '% snmp %',
                '% webserver %',
                '% mailserver %',
                '% directory %',
                '% measured service %',
                '% resource pooling %',
                '% rapid elasticity %',
                '% community cloud %',
                '% public cloud %',
                '% private cloud %',
                '% hybrid cloud %',
                '% on demand self service %',
                '% iaas %',
                '% paas %',
                '% saas %',
                '% daas %',
                '% vdi %',
                '% hypervisor %',
                '% cloud modell %',
                '% servicemodell %',
                '% measured usage %',
                '% metered billing %',
                '% getakteten abrechnung %',
                '% portnummern wird fuer dateiuebertragungen %',
                '% webanwendungen %',
                '% webservers %',
                '% dateiuebertragungen %',
                '% drei wege handshake %'
            ]) OR raw_combined_haystack ~ '(^|[^0-9])/([12]?[0-9]|3[0-2])([^0-9]|$)' THEN 'ch17-protokolle-dienste'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% utp %',
                '% stp %',
                '% koax %',
                '% coax %',
                '% fiber %',
                '% glasfaser %',
                '% lc %',
                '% sc %',
                '% st %',
                '% wlan %',
                '% 802 11 %',
                '% mimo %',
                '% backhaul %',
                '% bluetooth %',
                '% rfid %',
                '% nfc %',
                '% zigbee %',
                '% z wave %',
                '% switch %',
                '% router %',
                '% modem %',
                '% hub %',
                '% repeater %',
                '% bridge %',
                '% poe %',
                '% wan %',
                '% dsl %',
                '% satellit %',
                '% ftth %',
                '% voip %',
                '% nic %',
                '% netzwerkkarte %',
                '% bnc %',
                '% rj45 %',
                '% ethernet %',
                '% lan %',
                '% patchkabel %',
                '% netzwerkbuchse %',
                '% cat 5 %',
                '% cat 6 %',
                '% cat 7 %',
                '% cat 8 %'
            ]) THEN 'ch16-netzwerk-welt'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% kundenzufriedenheit %',
                '% wuetende kunde %',
                '% fragetechnik %',
                '% offene fragen %',
                '% geschlossene fragen %',
                '% telefon %',
                '% kommunikation mit dem kunden %',
                '% support-stufen %',
                '% it-support %',
                '% deeskalation %'
            ]) THEN 'ch15-support-kommunikation'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% post %',
                '% boot loop %',
                '% boot schleife %',
                '% rechner startet %',
                '% schaltet wieder aus %',
                '% friert ein %',
                '% bios fehlermeld %',
                '% uefi %',
                '% kein bild %',
                '% display problem %',
                '% grafikkarte problem %',
                '% projektor %',
                '% raid problem %',
                '% stromversorgung %',
                '% periodisch %',
                '% intermittent %',
                '% black screen %',
                '% ueberhitzt %',
                '% schmierflecken %',
                '% fehleinzuegen %',
                '% papierstau %',
                '% vertikale linie %',
                '% vertikale streifen %',
                '% nicht startet %',
                '% burn in %'
            ]) THEN 'ch14-hardware-fehlersuche'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% touchscreen %',
                '% stylus %',
                '% stift %',
                '% smartphone %',
                '% tablet %',
                '% akku %',
                '% battery %',
                '% synchronisation %',
                '% lokationsdienst %',
                '% touchpad %',
                '% mobile geraete %',
                '% hard reset %',
                '% soft reset %',
                '% factory reset %',
                '% mobiltelefon %',
                '% android %',
                '% ios %',
                '% display break %',
                '% screen replacement %',
                '% app installieren %'
            ]) THEN 'ch13-mobile-reparatur'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% toner %',
                '% druckqualitaet %',
                '% fehleinzug %',
                '% bildtrommel %',
                '% drum %',
                '% fuser %',
                '% transfer belt %',
                '% maintenance kit %',
                '% druckkopf %',
                '% roller %',
                '% transferband %',
                '% trommel %',
                '% papierstau %',
                '% laserdrucker %',
                '% tintenstrahl %',
                '% nadeldrucker %',
                '% thermodrucker %',
                '% 3d drucker %',
                '% mfp %',
                '% adf %',
                '% kopieren und scannen %',
                '% endbearbeitung %',
                '% vertikale linie auf dem ausdruck %',
                '% ausgabe eines laserdruckers %'
            ]) THEN 'ch12-drucker-unterhalt'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% headset %',
                '% dock %',
                '% port replicator %',
                '% mobilgeraetezubehoer %',
                '% wwan %',
                '% hotspot %',
                '% tethering %',
                '% mobiles arbeiten %',
                '% privacy filter %',
                '% kameraabdeckung %',
                '% gps %',
                '% mobile systeme %',
                '% tap payment %',
                '% esim %',
                '% sim karte %'
            ]) THEN 'ch11-mobile-erweitern'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% mainboard %',
                '% motherboard %',
                '% bios %',
                '% uefi %',
                '% cpu %',
                '% prozessor %',
                '% waermeleitpaste %',
                '% kuehlkoerper %',
                '% luefter %',
                '% ram %',
                '% ddr3 %',
                '% ddr4 %',
                '% ddr5 %',
                '% sodimm %',
                '% netzteil %',
                '% psu %',
                '% raid %',
                '% expansion card %',
                '% add on card %',
                '% pcie %',
                '% capture karte %',
                '% vm host %',
                '% thin client %',
                '% workstation %',
                '% cmos %',
                '% virtualisierungssupport %',
                '% aufruesten %',
                '% upgrade %',
                '% sata %',
                '% nvme %',
                '% scsi %',
                '% speicheranforderungen des motherboards %'
            ]) THEN 'ch10-system-aufruesten'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% ticketsystem %',
                '% ticket %',
                '% asset management %',
                '% asset %',
                '% dokumentation %',
                '% wiki %',
                '% knowledge base %',
                '% change management %',
                '% incident response %',
                '% lizenz %',
                '% garantie %',
                '% warranty %',
                '% doa %',
                '% green it %',
                '% support prozess %',
                '% hypothese %',
                '% problem identifizieren %',
                '% verifizierung der loesung %',
                '% dokumentieren %',
                '% comptia fehlerbehebung %',
                '% rollback %'
            ]) THEN 'ch9-betriebskonzepte'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% drucker %',
                '% druckauftrag %',
                '% duplex %',
                '% plotter %',
                '% printer %',
                '% print server %',
                '% virtuelles drucken %',
                '% papierformat %',
                '% tonerkartusche %',
                '% scanner %'
            ]) THEN 'ch8-drucker'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% kvm %',
                '% monitor %',
                '% lcd %',
                '% crt %',
                '% projektor %',
                '% webcam %',
                '% digitalkamera %',
                '% grafikkarte %',
                '% audiokarte %',
                '% videokarte %',
                '% barcode %',
                '% touchscreen %',
                '% trackpad %',
                '% maus %',
                '% tastatur %',
                '% gamepad %',
                '% biometrisch %',
                '% displayport %',
                '% hdmi %',
                '% aktualisierungsrate %',
                '% refresh rate %',
                '% 720p %',
                '% 1080p %',
                '% ultra hd %',
                '% 4k %',
                '% oled %',
                '% ips %',
                '% tn %',
                '% va %',
                '% eingabestift %',
                '% capture karte %'
            ]) THEN 'ch7-ein-ausgabe'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% hdd %',
                '% ssd %',
                '% hard drive %',
                '% solid state %',
                '% optical disc %',
                '% cd %',
                '% dvd %',
                '% blu ray %',
                '% bandlaufwerk %',
                '% tape %',
                '% nas %',
                '% smart %',
                '% hybrid drive %',
                '% speichermedienlaufwerk %',
                '% speicherlaufwerk %'
            ]) THEN 'ch6-speichergeraete'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% usb %',
                '% usb c %',
                '% thunderbolt %',
                '% firewire %',
                '% serial %',
                '% parallel %',
                '% esata %',
                '% pc card %',
                '% pcmcia %',
                '% expresscard %',
                '% sas %',
                '% lightning %',
                '% alt mode %',
                '% serieller kabel %',
                '% rs 232 %',
                '% db 9 %'
            ]) THEN 'ch5-schnittstellen'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% single core %',
                '% dual core %',
                '% cache %',
                '% chipset %',
                '% dma %',
                '% interrupt %',
                '% taktgeber %',
                '% rom %',
                '% bus %',
                '% soc %',
                '% dimm %',
                '% sodimm %',
                '% steigenden und fallenden taktflanken %'
            ]) THEN 'ch4-systemarchitektur'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% smart display %',
                '% wearable %',
                '% desktop %',
                '% laptop %',
                '% server %',
                '% gaming pc %',
                '% personal computer %',
                '% all in one %'
            ]) THEN 'ch3-computersysteme'

            ELSE 'cross-book'
        END AS chapter_key
    FROM candidate_questions
),
exam_rules AS (
    SELECT
        c.id,
        c.chapter_key,
        CASE
            WHEN c.chapter_key IN (
                'ch9-betriebskonzepte',
                'ch15-support-kommunikation',
                'ch20-betriebssysteme',
                'ch21-windows-installation',
                'ch22-windows10',
                'ch23-windows11',
                'ch24-windows-wartung',
                'ch25-macos',
                'ch26-linux',
                'ch27-bedrohungen',
                'ch28-sicherheitsmassnahmen',
                'ch29-systemschutz',
                'ch30-datenschutz'
            ) THEN '220-1102'
            WHEN c.chapter_key <> 'cross-book' THEN '220-1101'
            WHEN q.combined_haystack LIKE ANY (ARRAY[
                '% windows %',
                '% linux %',
                '% ubuntu %',
                '% macos %',
                '% security %',
                '% passwort %',
                '% aaa %',
                '% authentication %',
                '% authorization %',
                '% phishing %',
                '% malware %',
                '% social engineering %',
                '% backup %',
                '% restore %',
                '% datenschutz %',
                '% ticket %',
                '% dokumentation %',
                '% acceptable use %',
                '% byod %',
                '% cobo %',
                '% cope %',
                '% cyod %',
                '% kund %',
                '% support %'
            ]) THEN '220-1102'
            ELSE '220-1101'
        END AS exam_key
    FROM chapter_rules c
    JOIN candidate_questions q ON q.id = c.id
),
chapter_lookup AS (
    SELECT *
    FROM (VALUES
        ('ch3-computersysteme', 'Vom Bit bis zum Personal Computer', 3),
        ('ch4-systemarchitektur', 'Einblick in die Systemarchitektur', 4),
        ('ch5-schnittstellen', 'Externe Schnittstellen', 5),
        ('ch6-speichergeraete', 'Interne und externe Geräte', 6),
        ('ch7-ein-ausgabe', 'Ein- und Ausgabegeräte', 7),
        ('ch8-drucker', 'Drucker', 8),
        ('ch9-betriebskonzepte', 'Grundlegende Betriebskonzepte', 9),
        ('ch10-system-aufruesten', 'Ein Computersystem aufrüsten', 10),
        ('ch11-mobile-erweitern', 'Mobile Systeme erweitern', 11),
        ('ch12-drucker-unterhalt', 'Unterhalt von Druckern', 12),
        ('ch13-mobile-reparatur', 'Mobile Systeme unterhalten und reparieren', 13),
        ('ch14-hardware-fehlersuche', 'Aus die Maus - was nun?', 14),
        ('ch15-support-kommunikation', 'Häh? - Kommunikation im Support', 15),
        ('ch16-netzwerk-welt', 'Einführung in die Welt der Netzwerke', 16),
        ('ch17-protokolle-dienste', 'Netzwerkprotokolle und -dienste', 17),
        ('ch18-netzwerke-einrichten', 'Netzwerke einrichten', 18),
        ('ch19-netzwerk-fehlersuche', 'Netzwerkunterhalt und Fehlersuche', 19),
        ('ch20-betriebssysteme', 'Was betreibt ein Betriebssystem?', 20),
        ('ch21-windows-installation', 'Die Installation von Windows', 21),
        ('ch22-windows10', 'Die Konfiguration von Windows 10', 22),
        ('ch23-windows11', 'Die Konfiguration von Windows 11', 23),
        ('ch24-windows-wartung', 'Unterhalt und Wartung für Windows', 24),
        ('ch25-macos', 'Installation und Konfiguration von macOS', 25),
        ('ch26-linux', 'Installation und Konfiguration von Linux', 26),
        ('ch27-bedrohungen', 'Die Welt ist böse', 27),
        ('ch28-sicherheitsmassnahmen', 'Sicherheitsmaßnahmen ergreifen', 28),
        ('ch29-systemschutz', 'So schützen Sie Ihre Systeme', 29),
        ('ch30-datenschutz', 'Datenschutz und Datensicherheit', 30),
        ('cross-book', 'Kapiteluebergreifend', 999)
    ) AS lookup(chapter_key, chapter_title, chapter_order)
),
resolved AS (
    SELECT
        e.id,
        e.exam_key,
        l.chapter_key,
        l.chapter_title,
        l.chapter_order
    FROM exam_rules e
    JOIN chapter_lookup l ON l.chapter_key = e.chapter_key
)
UPDATE oc_learning_questions q
SET
    handbook_key = 'kammermann-a-plus-6ed-2022',
    handbook_title = 'Kammermann CompTIA A+ (6. Auflage, 2022)',
    exam_key = r.exam_key,
    chapter_key = r.chapter_key,
    chapter_title = r.chapter_title,
    chapter_order = r.chapter_order
FROM resolved r
WHERE q.id = r.id;

COMMIT;

-- Verification
SELECT
    exam_key,
    chapter_order,
    chapter_title,
    COUNT(*) AS question_count
FROM oc_learning_questions
WHERE pool_id IN (23, 26, 29, 55, 56)
GROUP BY exam_key, chapter_order, chapter_title
ORDER BY exam_key, chapter_order, chapter_title;
