-- Heuristische Kapitel-Zuordnung fuer bestehende CompTIA Network+ Fragen
-- Quelle der Kapitelstruktur: Kammermann CompTIA Network+ (9. Auflage, 2024)
-- Zielmenge: Pools 59, 60, 67, 81 mit exam_key = 'n10-009'
--
-- Ansatz:
-- 1. Frage + Antwortoptionen zu einem Suchtext zusammenfassen
-- 2. Suchtext normalisieren (lowercase, Umlaute -> ASCII, Satzzeichen -> Leerzeichen)
-- 3. Konservativ auf Kammermann-Kapitel mappen
-- 4. Rest als 'Kapiteluebergreifend' markieren statt falsch zuzuspitzen

BEGIN;

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
    WHERE q.pool_id IN (59, 60, 67, 81)
      AND q.exam_key = 'n10-009'
),
chapter_rules AS (
    SELECT
        id,
        CASE
            WHEN question_haystack LIKE ANY (ARRAY[
                '% malware %',
                '% ransomware %',
                '% trojan %',
                '% virus %',
                '% worm %',
                '% phishing %',
                '% smishing %',
                '% vishing %',
                '% tailgating %',
                '% social engineering %',
                '% ddos %',
                '% denial of service %',
                '% spoofing %',
                '% man in the middle %',
                '% evil twin %',
                '% rogue access point %',
                '% rogue dhcp %',
                '% watering hole %',
                '% typosquatting %',
                '% domain hijacking %',
                '% exploit kit %',
                '% buffer overflow %',
                '% arp poisoning %',
                '% brute force %',
                '% dictionary attack %'
            ]) THEN 'ch14-angriffe'

            WHEN question_haystack LIKE ANY (ARRAY[
                '% firewall %',
                '% acl %',
                '% acls %',
                '% allow list %',
                '% deny list %',
                '% content filter %',
                '% inhaltsfilter %',
                '% web filter %',
                '% dns sinkhole %',
                '% proxy %',
                '% segmentation %',
                '% microsegmentation %',
                '% air gap %',
                '% port security %',
                '% dhcp snooping %',
                '% dynamic arp inspection %',
                '% dai %',
                '% bpdu guard %',
                '% root guard %',
                '% storm control %',
                '% hardening %',
                '% haertung %',
                '% patch management %',
                '% vulnerability scan %',
                '% penetration test %',
                '% backup %',
                '% restore %',
                '% disaster recovery %',
                '% business continuity %',
                '% first responder %',
                '% dmz %',
                '% badge %',
                '% biometric %',
                '% mantrap %',
                '% cctv %',
                '% video surveillance %',
                '% unbefugte person %',
                '% sichere einrichtung %',
                '% climate control %',
                '% ups %',
                '% generator %',
                '% hot site %',
                '% warm site %',
                '% cold site %',
                '% containment %',
                '% honeypot %',
                '% honeynet %',
                '% quarantine %',
                '% wids %',
                '% wips %',
                '% ra guard %',
                '% haerten %',
                '% geraetehaertung %',
                '% minimalem privilegrecht %',
                '% minimal privilege %',
                '% byod %',
                '% aup %'
            ]) THEN 'ch15-verteidigung'

            WHEN question_haystack LIKE ANY (ARRAY[
                '% zero trust %',
                '% sase %',
                '% single sign on %',
                '% sso %',
                '% multi factor %',
                '% mfa %',
                '% eap %',
                '% radius %',
                '% kerberos %',
                '% tacacs %',
                '% chap %',
                '% pap %',
                '% certificate %',
                '% certificates %',
                '% csr %',
                '% pki %',
                '% tls %',
                '% ssl %',
                '% aes %',
                '% des %',
                '% 3des %',
                '% rsa %',
                '% hash %',
                '% hmac %',
                '% encryption %',
                '% cipher %',
                '% digital signature %',
                '% non repudiation %',
                '% confidentiality %',
                '% integrity %',
                '% availability %',
                '% data at rest %',
                '% data in transit %',
                '% data in use %',
                '% authentication %',
                '% authorization %',
                '% 802 1x %',
                '% portbasierten authentifizierungsrahmen %',
                '% auf einen benutzer zurueckverfolgt %',
                '% saml %',
                '% oauth %',
                '% verschluessel %',
                '% verschluesselung %',
                '% ldaps %'
            ]) THEN 'ch13-sicherheitsverfahren'

            WHEN question_haystack LIKE ANY (ARRAY[
                '% vpn %',
                '% remote access %',
                '% client to site %',
                '% site to site %',
                '% full tunnel %',
                '% split tunnel %',
                '% always on %',
                '% clientless %',
                '% terminal server %',
                '% rdp %',
                '% vdi %',
                '% citrix %',
                '% bastion host %',
                '% jump box %',
                '% fernzugriff %',
                '% sprungbox %'
            ]) THEN 'ch16-remote-access'

            WHEN question_haystack LIKE ANY (ARRAY[
                '% wlan %',
                '% wireless %',
                '% wi fi %',
                '% ssid %',
                '% bssid %',
                '% access point %',
                '% antenna %',
                '% mimo %',
                '% mu mimo %',
                '% beamforming %',
                '% roaming %',
                '% wps %',
                '% wpa %',
                '% eapol %',
                '% captive portal %',
                '% site survey %',
                '% heatmap %',
                '% co channel %',
                '% adjacent channel %',
                '% rssi %',
                '% 2 4 ghz %',
                '% 5 ghz %',
                '% 6 ghz %',
                '% bluetooth %',
                '% zigbee %',
                '% z wave %',
                '% rfid %',
                '% nfc %',
                '% wap %',
                '% ad hoc %',
                '% wireless lan controller %',
                '% feldnetzwerk %',
                '% abdeckung %',
                '% geraetesaettigung %',
                '% band steering %'
            ]) THEN 'ch7-drahtlos'

            WHEN question_haystack LIKE ANY (ARRAY[
                '% wan %',
                '% sd wan %',
                '% mpls %',
                '% metro e %',
                '% broadband %',
                '% dsl %',
                '% adsl %',
                '% vdsl %',
                '% docsis %',
                '% cable modem %',
                '% pon %',
                '% ftth %',
                '% sonet %',
                '% sdh %',
                '% atm %',
                '% isdn %',
                '% pots %',
                '% satellite %',
                '% lpwan %',
                '% cellular %',
                '% 4g %',
                '% 5g %',
                '% lte %',
                '% leased line %',
                '% pppoe %',
                '% ppp %',
                '% gre %',
                '% t1 %',
                '% e1 %'
            ]) THEN 'ch8-wan'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% ospf %',
                '% bgp %',
                '% rip %',
                '% vrrp %',
                '% hsrp %',
                '% fhrp %',
                '% dhcp %',
                '% dns %',
                '% wins %',
                '% dynamic dns %',
                '% http %',
                '% https %',
                '% smtp %',
                '% imap %',
                '% pop3 %',
                '% ftp %',
                '% tftp %',
                '% ssh %',
                '% telnet %',
                '% ntp %',
                '% zeitsynchronisation %',
                '% voip %',
                '% sip %',
                '% web server %',
                '% mail server %',
                '% mx record %',
                '% aaaa record %',
                '% srv record %',
                '% mdns %',
                '% resolver %',
                '% ldap %',
                '% sichere e mail %'
            ]) THEN 'ch11-dienste'

            WHEN question_haystack LIKE ANY (ARRAY[
                '% snmp %',
                '% snmpv1 %',
                '% snmpv2c %',
                '% snmpv3 %',
                '% community strings %',
                '% mib %',
                '% oid %',
                '% network management %',
                '% inventory %',
                '% asset %',
                '% change management %',
                '% rollback plan %',
                '% configuration management %',
                '% lifecycle management %',
                '% diagram %',
                '% documentation %',
                '% as built %',
                '% logical diagram %',
                '% rack diagram %',
                '% api %',
                '% rest %',
                '% json %',
                '% xml %',
                '% ansible %',
                '% automation %',
                '% orchestration %',
                '% infrastructure as code %',
                '% iac %',
                '% version control %',
                '% out of band %',
                '% in band management %',
                '% config backup %',
                '% config backups %',
                '% idempotent %',
                '% pre deployment %',
                '% eol %',
                '% eos %',
                '% mtbf %'
            ]) THEN 'ch17-management'

            WHEN question_haystack LIKE ANY (ARRAY[
                '% siem %',
                '% syslog %',
                '% telemetry %',
                '% flow telemetrie %',
                '% monitoring %',
                '% baseline %',
                '% threshold %',
                '% alerting %',
                '% alert %',
                '% packet capture %',
                '% pcap %',
                '% paketanalyse %',
                '% wireshark %',
                '% network monitor %',
                '% mrtg %',
                '% netflow %',
                '% sflow %',
                '% span %',
                '% port mirroring %',
                '% tap %',
                '% performance monitor %',
                '% sla %',
                '% jitter %',
                '% latency %',
                '% packet loss %',
                '% throughput %',
                '% utilization %',
                '% sensor %',
                '% rtt %',
                '% top talker %',
                '% top host %',
                '% traffic analysis %'
            ]) THEN 'ch18-ueberwachung'

            WHEN question_haystack LIKE ANY (ARRAY[
                '% troubleshoot %',
                '% fehlersuche %',
                '% problem %',
                '% symptom %',
                '% root cause %',
                '% duplicate ip %',
                '% apipa %',
                '% reachability %',
                '% ping %',
                '% traceroute %',
                '% tcpdump %',
                '% netstat %',
                '% nslookup %',
                '% dig %',
                '% crc %',
                '% duplex mismatch %',
                '% split pair %',
                '% flapping %',
                '% intermittent %',
                '% timed out %',
                '% cable tester %',
                '% tone generator %',
                '% toner probe %',
                '% strukturierter troubleshooting ansatz %',
                '% erste incident massnahme %',
                '% hypothesis %',
                '% bottom up %',
                '% next step %',
                '% theorie %',
                '% validierung %',
                '% dokumentiere die loesung %'
            ]) THEN 'ch19-fehlersuche'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% vlan %',
                '% trunk %',
                '% switch %',
                '% switching %',
                '% spanning tree %',
                '% stp %',
                '% rstp %',
                '% mstp %',
                '% bpdu %',
                '% portfast %',
                '% etherchannel %',
                '% lacp %',
                '% link aggregation %',
                '% 802 1q %',
                '% dot1q %',
                '% 802 3ad %',
                '% poe %',
                '% power over ethernet %',
                '% structured cabling %',
                '% patch panel %',
                '% patchkabel %',
                '% cat5 %',
                '% cat6 %',
                '% cat6a %',
                '% fiber optic %',
                '% transceiver %',
                '% 10g sr %',
                '% 10g lr %',
                '% sfp %',
                '% qsfp %',
                '% mdf %',
                '% idf %',
                '% uplink %',
                '% broadcast storm %',
                '% loop prevention %',
                '% mac address table %',
                '% jumbo frame %',
                '% svi %',
                '% full duplex %',
                '% ethernet %'
            ]) THEN 'ch6-ieee'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% icmp %',
                '% igmp %',
                '% arp %',
                '% nat %',
                '% pat %',
                '% upnp %',
                '% tcp %',
                '% udp %',
                '% ephemeral port %',
                '% three way handshake %',
                '% window size %',
                '% mtu %',
                '% mss %',
                '% ecn %',
                '% dscp %',
                '% qos %',
                '% diffserv %',
                '% class of service %',
                '% cos %',
                '% multicast %',
                '% broadcast %',
                '% anycast %',
                '% unicast %',
                '% shaping %',
                '% policing %',
                '% queueing %',
                '% datagram %',
                '% portadressuebersetzung %',
                '% port address translation %'
            ]) THEN 'ch10-tcpip'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% ipv4 %',
                '% ipv6 %',
                '% cidr %',
                '% subnet %',
                '% subnetting %',
                '% vlsm %',
                '% flsm %',
                '% prefix length %',
                '% private ip %',
                '% public ip %',
                '% default gateway %',
                '% routing table %',
                '% route summarization %',
                '% longest prefix %',
                '% eui 64 %',
                '% slaac %',
                '% router advertisement %',
                '% neighbor discovery %',
                '% link local %',
                '% multihoming %',
                '% vxlan %',
                '% vrf %',
                '% rfc1918 %',
                '% as number %',
                '% autonomous system %',
                '% classless %',
                '% supernet %',
                '% netzadresse %',
                '% maske %',
                '% 255 255 %',
                '% 169 254 %'
            ]) OR raw_combined_haystack ~ '(^|[^0-9])/([12]?[0-9]|3[0-2])([^0-9]|$)' THEN 'ch9-ip'

            WHEN question_haystack LIKE ANY (ARRAY[
                '% workgroup %',
                '% domain controller %',
                '% client server %',
                '% nos %',
                '% linux %',
                '% windows server %',
                '% smb %',
                '% share %',
                '% permissions %',
                '% group policy %',
                '% virtualization %',
                '% hypervisor %',
                '% vmware %',
                '% cloud %',
                '% saas %',
                '% paas %',
                '% iaas %',
                '% public cloud %',
                '% private cloud %',
                '% hybrid cloud %',
                '% storage %',
                '% nas %',
                '% san %',
                '% printer %',
                '% print server %',
                '% user account %',
                '% group membership %',
                '% vpc %',
                '% peering %',
                '% load balanc %',
                '% session persist %',
                '% sticky session %',
                '% vswitch %',
                '% east west %'
            ]) THEN 'ch12-betreiben'

            WHEN combined_haystack LIKE ANY (ARRAY[
                '% nic %',
                '% network card %',
                '% modem %',
                '% repeater %',
                '% hub %',
                '% bridge %',
                '% media converter %',
                '% copper %',
                '% coax %',
                '% connector %',
                '% rj45 %',
                '% rj11 %',
                '% bnc %',
                '% db9 %',
                '% db25 %',
                '% plenum %',
                '% multimode %',
                '% single mode %',
                '% lc %',
                '% sc %',
                '% st %',
                '% straight through %',
                '% crossover cable %',
                '% rollover cable %',
                '% attenuation %',
                '% emi %',
                '% rfi %',
                '% rack %',
                '% schalttiefe %',
                '% glasfasersteckertypen %'
            ]) THEN 'ch4-hardware'

            WHEN question_haystack LIKE ANY (ARRAY[
                '% topology %',
                '% star topology %',
                '% mesh topology %',
                '% bus topology %',
                '% ring topology %',
                '% point to point %',
                '% multipoint %',
                '% baseband %',
                '% broadband transmission %',
                '% circuit switched %',
                '% packet switched %'
            ]) THEN 'ch5-topologie'

            WHEN question_haystack LIKE ANY (ARRAY[
                '% osi model %',
                '% tcp ip model %',
                '% encapsulation %',
                '% decapsulation %',
                '% layer 2 %',
                '% layer 3 %',
                '% layer 4 %',
                '% layer 7 %',
                '% anwendungsschicht %'
            ]) THEN 'ch2-modelle'

            ELSE 'cross-book'
        END AS chapter_key
    FROM candidate_questions
),
chapter_lookup AS (
    SELECT *
    FROM (VALUES
        ('ch2-modelle', 'Entwicklungen und Modelle', 2),
        ('ch4-hardware', 'Hardware im lokalen Netzwerk', 4),
        ('ch5-topologie', 'Topologie und Verbindungsaufbau', 5),
        ('ch6-ieee', 'Die Standards der IEEE-802.x-Reihe', 6),
        ('ch7-drahtlos', 'Netzwerk ohne Kabel: Drahtlostechnologien', 7),
        ('ch8-wan', 'WAN-Datentechniken auf OSI-Layer 1 bis 3', 8),
        ('ch9-ip', 'Mein Name ist IP - Internet Protocol', 9),
        ('ch10-tcpip', 'Weitere Protokolle im TCP/IP-Stack', 10),
        ('ch11-dienste', 'Stets zu Diensten', 11),
        ('ch12-betreiben', 'Netzwerke betreiben', 12),
        ('ch13-sicherheitsverfahren', 'Sicherheitsverfahren im Netzwerkverkehr', 13),
        ('ch14-angriffe', 'Verschiedene Angriffsformen im Netzwerk', 14),
        ('ch15-verteidigung', 'Die Verteidigung des Netzwerks', 15),
        ('ch16-remote-access', 'Remote Access Networks', 16),
        ('ch17-management', 'Netzwerkmanagement', 17),
        ('ch18-ueberwachung', 'Ueberwachung', 18),
        ('ch19-fehlersuche', 'Fehlersuche im Netzwerk', 19),
        ('cross-book', 'Kapiteluebergreifend', 999)
    ) AS lookup(chapter_key, chapter_title, chapter_order)
),
resolved AS (
    SELECT
        r.id,
        l.chapter_key,
        l.chapter_title,
        l.chapter_order
    FROM chapter_rules r
    JOIN chapter_lookup l ON l.chapter_key = r.chapter_key
)
UPDATE oc_learning_questions q
SET
    chapter_key = r.chapter_key,
    chapter_title = r.chapter_title,
    chapter_order = r.chapter_order
FROM resolved r
WHERE q.id = r.id;

COMMIT;

-- Verification
SELECT
    chapter_order,
    chapter_title,
    COUNT(*) AS question_count
FROM oc_learning_questions
WHERE pool_id IN (59, 60, 67, 81)
  AND exam_key = 'n10-009'
GROUP BY chapter_order, chapter_title
ORDER BY chapter_order, chapter_title;
