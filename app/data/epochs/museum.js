/**
 * Museum facts for "Hack Through Time" — historical events per epoch.
 *
 * Each epoch has 3-5 fact objects with: year, title, people, impact, funFact (optional).
 * Displayed in museum interludes between gameplay scenes.
 *
 * @module data/epochs/museum
 */

const MUSEUM_FACTS = Object.freeze({
	phone_phreaking: Object.freeze([
		Object.freeze({
			id: 'pp_1',
			year: 1957,
			title: 'Joe Engressia entdeckt 2600 Hz',
			people: ['Joe Engressia (Joybubbles)'],
			impact: 'Ein blinder Teenager entdeckt, dass ein 2600-Hz-Pfeifton die AT&T-Vermittlung steuert.',
			funFact: 'Engressia hatte absolutes Gehoer und pfiff den Ton einfach nach.',
		}),
		Object.freeze({
			id: 'pp_2',
			year: 1971,
			title: 'Captain Crunch und die Blue Box',
			people: ['John Draper (Captain Crunch)'],
			impact: 'Eine Spielzeugpfeife aus einer Cerealienpackung erzeugt exakt 2600 Hz — kostenlose Ferngspraeche weltweit.',
			funFact: 'Steve Wozniak und Steve Jobs bauten Blue Boxes und verkauften sie an Kommilitonen.',
		}),
		Object.freeze({
			id: 'pp_3',
			year: 1975,
			title: 'ARPANET waechst',
			people: ['Vint Cerf', 'Bob Kahn'],
			impact: 'TCP/IP verbindet erstmals verschiedene Netzwerke — das Internet entsteht, ohne Sicherheit im Design.',
		}),
		Object.freeze({
			id: 'pp_4',
			year: 1978,
			title: 'Das 2600 Magazine',
			people: ['Emmanuel Goldstein (Eric Corley)'],
			impact: 'Die erste Hacker-Zeitschrift wird zum Sprachrohr der Phreaker- und Hacker-Community.',
		}),
	]),

	wargames: Object.freeze([
		Object.freeze({
			id: 'wg_1',
			year: 1983,
			title: 'WarGames im Kino',
			people: ['Matthew Broderick (Filmrolle)'],
			impact: 'Der Film sensibilisiert die Öffentlichkeit fuer Computer-Sicherheit — Reagan fragt seine Berater, ob das wirklich passieren kann.',
			funFact: 'Das Pentagon änderte tatsaechlich Zugangscodes nach dem Filmstart.',
		}),
		Object.freeze({
			id: 'wg_2',
			year: 1984,
			title: 'Chaos Computer Club gegruendet',
			people: ['Wau Holland', 'Steffen Wernery'],
			impact: 'Europas größter Hacker-Verein entsteht in Hamburg — Hacking als gesellschaftliche Kraft.',
			funFact: 'Der CCC hackte 1984 den BTX-Dienst der Bundespost und überwies sich 134.000 DM — und gab alles zurück.',
		}),
		Object.freeze({
			id: 'wg_3',
			year: 1986,
			title: 'Das KGB-Hack-Netzwerk',
			people: ['Markus Hess', 'Cliff Stoll'],
			impact: 'Deutsche Hacker brechen in US-Militaerrechner ein und verkaufen Daten an den KGB — Stoll verfolgt sie mit einer Honigfalle.',
		}),
		Object.freeze({
			id: 'wg_4',
			year: 1988,
			title: 'Kevin Mitnick auf der Flucht',
			people: ['Kevin Mitnick'],
			impact: 'Der beruechtigtste Social-Engineer Amerikas wird zum meistgesuchten Hacker des FBI.',
			funFact: 'Mitnick behauptete, er habe nie finanziell profitiert — es ging nur um die Herausforderung.',
		}),
	]),

	morris_worm: Object.freeze([
		Object.freeze({
			id: 'mw_1',
			year: 1988,
			title: 'Der Morris-Worm',
			people: ['Robert Tappan Morris'],
			impact: 'Der erste Internet-Wurm infiziert 6.000 Rechner (10% des damaligen Internets) und legt sie lahm.',
			funFact: 'Morris wollte nur die Größe des Internets messen — ein Bug im Re-Infektionsschutz machte den Wurm destruktiv.',
		}),
		Object.freeze({
			id: 'mw_2',
			year: 1988,
			title: 'CERT wird gegruendet',
			people: ['DARPA'],
			impact: 'Als direkte Reaktion auf den Morris-Worm gruendet DARPA das erste Computer Emergency Response Team.',
		}),
		Object.freeze({
			id: 'mw_3',
			year: 1990,
			title: 'Erste Verurteilung nach CFAA',
			people: ['Robert Tappan Morris'],
			impact: 'Morris wird als erster Mensch unter dem Computer Fraud and Abuse Act verurteilt — 3 Jahre Bewaehrung.',
			funFact: 'Morris wurde spaeter MIT-Professor und Mitgruender von Y Combinator.',
		}),
		Object.freeze({
			id: 'mw_4',
			year: 1995,
			title: 'Mitnick gefasst',
			people: ['Kevin Mitnick', 'Tsutomu Shimomura'],
			impact: 'Nach 2 Jahren auf der Flucht wird Mitnick durch IP-Spoofing-Gegenangriff von Shimomura lokalisiert.',
		}),
	]),

	sql_injection: Object.freeze([
		Object.freeze({
			id: 'si_1',
			year: 2001,
			title: 'OWASP gegruendet',
			people: ['Mark Curphey'],
			impact: 'Das Open Web Application Security Project entsteht — die OWASP Top 10 werden zum globalen Standard.',
		}),
		Object.freeze({
			id: 'si_2',
			year: 2001,
			title: 'Code Red Wurm',
			people: [],
			impact: 'Code Red infiziert 359.000 IIS-Server in 14 Stunden und defaced Webseiten mit "Hacked by Chinese!".',
			funFact: 'Der Wurm war nach einem Mountain-Dew-Getraenk benannt, das die Entdecker gerade tranken.',
		}),
		Object.freeze({
			id: 'si_3',
			year: 2005,
			title: 'Samy — der MySpace-Wurm',
			people: ['Samy Kamkar'],
			impact: 'Der schnellste Wurm aller Zeiten: Eine Million Freundschaftsanfragen in 20 Stunden via XSS.',
			funFact: 'Samys Profil zeigte danach: "but most of all, samy is my hero".',
		}),
		Object.freeze({
			id: 'si_4',
			year: 2007,
			title: 'SQL Injection wird #1',
			people: [],
			impact: 'SQL Injection fuehrt erstmals die OWASP Top 10 an — Bobby Tables wird zum Meme.',
		}),
		Object.freeze({
			id: 'si_5',
			year: 2009,
			title: 'Heartland Payment Systems Breach',
			people: ['Albert Gonzalez'],
			impact: '130 Millionen Kreditkartennummern gestohlen durch SQL Injection — der größte Datendiebstahl seiner Zeit.',
		}),
	]),

	apt_nation_states: Object.freeze([
		Object.freeze({
			id: 'an_1',
			year: 2010,
			title: 'Stuxnet',
			people: ['NSA', 'Unit 8200 (Israel)'],
			impact: 'Die erste Cyberwaffe zerstört physisch iranische Uranzentrifugen — Cyberkrieg wird Realität.',
			funFact: 'Stuxnet nutzte 4 Zero-Days gleichzeitig — ein bis dahin unvorstellbarer Aufwand.',
		}),
		Object.freeze({
			id: 'an_2',
			year: 2013,
			title: 'Snowden-Enthuellungen',
			people: ['Edward Snowden'],
			impact: 'NSA-Massenüberwachungsprogramme (PRISM, XKeyscore) werden öffentlich — globale Krypto-Debatte.',
		}),
		Object.freeze({
			id: 'an_3',
			year: 2016,
			title: 'Shadow Brokers leaken NSA-Tools',
			people: ['The Shadow Brokers'],
			impact: 'NSA-Exploits wie EternalBlue werden veröffentlicht — Grundlage fuer WannaCry und NotPetya.',
		}),
		Object.freeze({
			id: 'an_4',
			year: 2017,
			title: 'WannaCry & NotPetya',
			people: ['Lazarus Group (Nordkorea)', 'GRU (Russland)'],
			impact: 'WannaCry legt 200.000 Rechner in 150 Ländern lahm. NotPetya verursacht 10 Mrd. USD Schaden.',
			funFact: 'Ein 22-jaehriger Blogger (MalwareTech) stoppte WannaCry zufaellig durch Domain-Registrierung.',
		}),
	]),

	supply_chain: Object.freeze([
		Object.freeze({
			id: 'sc_1',
			year: 2020,
			title: 'SolarWinds Hack',
			people: ['APT29 (Cozy Bear)'],
			impact: 'Russische Hacker kompromittieren SolarWinds-Updates — 18.000 Organisationen betroffen, inkl. US-Regierung.',
			funFact: 'Der kompromittierte Code war monatelang in offiziellen Updates — niemand bemerkte es.',
		}),
		Object.freeze({
			id: 'sc_2',
			year: 2021,
			title: 'Log4Shell (CVE-2021-44228)',
			people: ['Chen Zhaojun (Alibaba)'],
			impact: 'Eine Luecke in Log4j betrifft Millionen Java-Anwendungen weltweit — CVSS 10.0.',
			funFact: 'Der Fix wurde von 3 ehrenamtlichen Maintainern geschrieben, die das Projekt nebenbei pflegten.',
		}),
		Object.freeze({
			id: 'sc_3',
			year: 2023,
			title: 'ChatGPT Prompt Injection',
			people: [],
			impact: 'Prompt Injection wird als neue Angriffskategorie erkannt — KI-Systeme sind systematisch manipulierbar.',
		}),
		Object.freeze({
			id: 'sc_4',
			year: 2024,
			title: 'xz Utils Backdoor',
			people: ['Jia Tan (Pseudonym)'],
			impact: 'Ein jahrelang eingeschleuster Maintainer platziert eine Backdoor in xz — entdeckt durch 500ms SSH-Latenz.',
			funFact: 'Andres Freund entdeckte die Backdoor beim Micro-Benchmarking seiner PostgreSQL-Instanz.',
		}),
	]),

	quantum_threat: Object.freeze([
		Object.freeze({
			id: 'qt_1',
			year: 1994,
			title: 'Shors Algorithmus',
			people: ['Peter Shor'],
			impact: 'Shor zeigt theoretisch: Ein Quantencomputer kann RSA und ECC in Polynomialzeit brechen.',
		}),
		Object.freeze({
			id: 'qt_2',
			year: 2024,
			title: 'NIST Post-Quantum Standards',
			people: ['NIST'],
			impact: 'FIPS 203/204/205 standardisieren ML-KEM, ML-DSA und SLH-DSA — die Post-Quantum-Aera beginnt offiziell.',
		}),
		Object.freeze({
			id: 'qt_3',
			year: 2025,
			title: 'Harvest Now, Decrypt Later',
			people: [],
			impact: 'Geheimdienste sammeln heute verschlüsselte Daten, um sie mit künftigen Quantencomputern zu entschlüsseln.',
			funFact: 'Die NSA warnte 2022 alle US-Behoerden, bis 2035 auf Post-Quantum umzusteigen.',
		}),
		Object.freeze({
			id: 'qt_4',
			year: 2030,
			title: 'Quantum Key Distribution (QKD)',
			people: [],
			impact: 'Quantenschlüsselaustausch verspricht physikalisch sichere Kommunikation — noch nicht skalierbar.',
		}),
	]),
})

// Support both ESM and CJS consumption
if (typeof module !== 'undefined' && module.exports) {
	module.exports = { MUSEUM_FACTS }
}

export { MUSEUM_FACTS }
