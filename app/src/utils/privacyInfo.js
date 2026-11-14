import { translate as t } from '@nextcloud/l10n'
import privacyData from '../../data/privacy-info.json'
import consentText from '../../data/ai-consent.json'

/**
 * Plain text for {{ }}: no HTML escaping or sanitizing (DOMPurify would turn "&" into "&amp;",
 * which the template then shows literally).
 */
const RAW = { escape: false, sanitize: false }

/**
 * The cards of the "Data categories" section. The titles used to be hard-coded German without
 * t() (GitHub #38, 38-d); each category says which card it belongs to by id, so the grouping no
 * longer depends on the (now translated) names.
 */
function categoryGroups() {
	return [
		{ id: 'learning', title: t('learning', 'Lerndaten', undefined, undefined, RAW) },
		{ id: 'ai', title: t('learning', 'Persönlichkeitsprofil / Telos & KI-Interaktionen', undefined, undefined, RAW) },
		{ id: 'social', title: t('learning', 'Schwarmgedächtnis', undefined, undefined, RAW) },
		{ id: 'audit', title: t('learning', 'Audit & Moderation', undefined, undefined, RAW) },
		{ id: 'gamification', title: t('learning', 'Gamification', undefined, undefined, RAW) },
		{ id: 'assessment', title: t('learning', 'Prüfungsdaten', undefined, undefined, RAW) },
		{ id: 'external', title: t('learning', 'Externe Dienste', undefined, undefined, RAW) },
	]
}

function untilAccountDeleted() {
	return t('learning', 'Solange dein Account existiert', undefined, undefined, RAW)
}

/**
 * The Art. 13 texts per data category, in the reader's language. They were German entries in
 * privacy-info.json that the page rendered without t(), so every language read them in German
 * (GitHub #38, 38-d); as literal t() calls the parity, placeholder and coverage gates see them.
 * The gamification texts hold for instances with gamification on and off alike ("sofern
 * Gamification aktiviert ist"); the values are recalculated in the background either way.
 *
 * @param {string} provider the AI recipient in force, '' when unknown
 * @return {Array<object>}
 */
export function buildPrivacyCategories(provider = '') {
	const name = typeof provider === 'string' ? provider.trim() : ''
	return [
		{
			id: 'learning-data',
			group: 'learning',
			name: t('learning', 'Lerndaten', undefined, undefined, RAW),
			items: t('learning', 'Leitner-Box, Sessions, Scores, XP, Badges, Streak, Missionen, Duell/Gameshow, Prüfungsergebnisse', undefined, undefined, RAW),
			purpose: t('learning', 'Spaced Repetition, Fortschrittsverfolgung und, sofern Gamification aktiviert ist, Gamification', undefined, undefined, RAW),
			who_sees: t('learning', 'Du selbst + Dozent (aggregiert). Sofern Gamification aktiviert ist, sind XP und Badges im Leaderboard für alle sichtbar.', undefined, undefined, RAW),
			retention: untilAccountDeleted(),
			legal_basis: t('learning', 'Berechtigtes Interesse (Art. 6(1)f) — Lernzweck', undefined, undefined, RAW),
		},
		{
			id: 'telos',
			group: 'ai',
			name: t('learning', 'Persönlichkeitsprofil (Telos)', undefined, undefined, RAW),
			items: t('learning', 'Lernziele, Stärken, Schwächen, Erfahrungslevel, Hilfe-Angebote/-Gesuche', undefined, undefined, RAW),
			purpose: t('learning', 'Personalisierte KI-Erklärungen, Buddy Matching', undefined, undefined, RAW),
			who_sees: t('learning', 'Du + VirtuProf. Bei Sichtbarkeit „Kurs“ oder „Öffentlich“: Kursteilnehmer sehen Hilfe-Themen + Bio, die Kursleitung sieht dein Prüfungsziel mit Datum und Erfahrungsstufe und Lernstunden zusammengefasst. Wenn du der KI-Nutzung zugestimmt hast, gehen Teile des Profils (Ziele, Motivation, erkannte Stärken und Schwächen) an den KI-Dienst.', undefined, undefined, RAW),
			retention: t('learning', 'Solange dein Account existiert, Opt-in via Onboarding', undefined, undefined, RAW),
			legal_basis: t('learning', 'Einwilligung (Art. 6(1)a) — Telos ist opt-in', undefined, undefined, RAW),
		},
		{
			id: 'swarm',
			group: 'social',
			name: t('learning', 'Schwarmgedächtnis-Beiträge', undefined, undefined, RAW),
			items: t('learning', 'Eigene Erklärungen, Merkhilfen, Notizen zu Fragen', undefined, undefined, RAW),
			purpose: t('learning', 'Gemeinsamer Wissenspool für alle Kursteilnehmer', undefined, undefined, RAW),
			who_sees: t('learning', 'Dozent sieht Autor. Studenten sehen nur den freigegebenen Text.', undefined, undefined, RAW),
			retention: t('learning', 'Bei Löschung des Accounts anonymisiert (Inhalt bleibt, Name wird entfernt)', undefined, undefined, RAW),
			legal_basis: t('learning', 'Einwilligung (Art. 6(1)a)', undefined, undefined, RAW),
		},
		{
			id: 'ai-interaction',
			group: 'ai',
			name: t('learning', 'KI-Interaktion (KI-Dienst des Betreibers)', undefined, undefined, RAW),
			items: t('learning', 'Deine Nachrichten an VirtuProf und andere Texte, die du in KI-Funktionen eingibst; Lernkontext (Frage, Antwortmöglichkeiten, Erklärung); Ausschnitte aus Kursmaterial; Informationen aus deinem Lernprofil; dein Lernstand; deine letzten Nachrichten an VirtuProf mit den Antworten darauf, die auf dem Server gespeichert sind und bei späteren Anfragen wieder mitgeschickt werden (ältere Chats als Zusammenfassung); einmal pro Woche dein schwächstes Thema mit falsch beantworteten Fragen', undefined, undefined, RAW),
			purpose: t('learning', 'KI-gestützte Erklärungen, Lernnotizen, Lernpläne und Fragen-Erzeugung', undefined, undefined, RAW),
			// The recipient goes in after translation: a provider name is no key, and t() would escape it.
			who_sees: name
				? fillProvider(t('learning', '{provider} verarbeitet die Anfrage. Name, E-Mail und Kontodaten werden nicht automatisch mitgeschickt; was du selbst in eine Nachricht schreibst, geht an den KI-Dienst.', undefined, undefined, RAW), name)
				: t('learning', 'Der vom Betreiber eingerichtete KI-Dienst verarbeitet die Anfrage. Name, E-Mail und Kontodaten werden nicht automatisch mitgeschickt; was du selbst in eine Nachricht schreibst, geht an den KI-Dienst.', undefined, undefined, RAW),
			retention: t('learning', 'Deine letzten Nachrichten an VirtuProf mit den Antworten und die Zusammenfassung älterer Chats liegen auf diesem Server, solange dein Account existiert; du kannst sie im VirtuProf-Fenster löschen. Wie lange der KI-Dienst Daten speichert, legt der Betreiber mit dem Dienst fest.', undefined, undefined, RAW),
			legal_basis: t('learning', 'Einwilligung (Art. 6(1)a) — Dialog „KI-Funktionen einschalten“, widerrufbar in den Einstellungen. Liegt der Empfänger außerhalb der EU, willigst du ausdrücklich auch in diese Übermittlung ein.', undefined, undefined, RAW),
		},
		{
			id: 'audit-log',
			group: 'audit',
			name: t('learning', 'Audit-Protokolle', undefined, undefined, RAW),
			items: t('learning', 'Login-Zeitpunkte, Moderations-Entscheidungen, Löschvorgänge, Consent-Versionen, KI-Anfragen (nur Zeitpunkt, Zweck, Empfänger, Modell und Token-Zahl — kein Inhalt)', undefined, undefined, RAW),
			purpose: t('learning', 'Nachvollziehbarkeit, DSGVO-Rechenschaftspflicht', undefined, undefined, RAW),
			who_sees: t('learning', 'Nur Admins', undefined, undefined, RAW),
			retention: untilAccountDeleted(),
			legal_basis: t('learning', 'Rechtliche Verpflichtung (Art. 6(1)c)', undefined, undefined, RAW),
		},
		{
			id: 'gamification',
			group: 'gamification',
			name: t('learning', 'Gamification', undefined, undefined, RAW),
			items: t('learning', 'Badges, XP, Streak-Zähler, Leaderboard-Rang, Arena-Ergebnisse, Abenteuer-Fortschritt', undefined, undefined, RAW),
			purpose: t('learning', 'Motivation, Lernfortschritt sichtbar machen (sofern Gamification aktiviert ist)', undefined, undefined, RAW),
			who_sees: t('learning', 'Sofern Gamification aktiviert ist: du selbst + Kursteilnehmer (Leaderboard), der Dozent sieht eine aggregierte Statistik. Ist Gamification ausgeschaltet, zeigt die Lernoberfläche diese Werte niemandem an; in deinem Datenexport sind sie weiterhin enthalten.', undefined, undefined, RAW),
			retention: untilAccountDeleted(),
			legal_basis: t('learning', 'Berechtigtes Interesse (Art. 6(1)f) — Lernmotivation', undefined, undefined, RAW),
		},
		{
			id: 'assessment',
			group: 'assessment',
			name: t('learning', 'Prüfungsdaten', undefined, undefined, RAW),
			items: t('learning', 'Simulator-Sessions, PBQ-Ergebnisse, Exam-Readiness-Score, Kursabschluss-Status', undefined, undefined, RAW),
			purpose: t('learning', 'Prüfungsvorbereitung, Kompetenz-Nachweis', undefined, undefined, RAW),
			who_sees: t('learning', 'Du selbst + Dozent', undefined, undefined, RAW),
			retention: untilAccountDeleted(),
			legal_basis: t('learning', 'Berechtigtes Interesse (Art. 6(1)f) — Ausbildungszweck', undefined, undefined, RAW),
		},
		{
			id: 'external-services',
			group: 'external',
			name: t('learning', 'Externe Dienste', undefined, undefined, RAW),
			items: t('learning', 'Nextcloud-Account, Talk-Chat-Nachrichten, Deck-Aufgaben, geteilte Dateien', undefined, undefined, RAW),
			purpose: t('learning', 'Kollaborative Lernumgebung, Kommunikation, Aufgabenverwaltung', undefined, undefined, RAW),
			who_sees: t('learning', 'Kursteilnehmer + Dozent (je nach Freigabe). Admin sieht alles.', undefined, undefined, RAW),
			retention: untilAccountDeleted(),
			legal_basis: t('learning', 'Berechtigtes Interesse (Art. 6(1)f) — Lerninfrastruktur', undefined, undefined, RAW),
		},
		{
			id: 'external-videos',
			group: 'external',
			name: t('learning', 'Externe Videos (Vimeo / YouTube)', undefined, undefined, RAW),
			items: t('learning', 'Beim Abspielen: deine IP-Adresse und die üblichen Browserdaten beim Anbieter; in dieser App: welche Abschnitte des Videos du angesehen hast (nur bis zum Abschluss) und ob du es abgeschlossen hast', undefined, undefined, RAW),
			purpose: t('learning', 'Pflichtvideos eines Kurses abspielen und ihren Abschluss für die Kursfreigabe nachweisen', undefined, undefined, RAW),
			who_sees: t('learning', 'Erst nach deinem Klick auf „Laden und ansehen“ lädt die Seite den Player von Vimeo bzw. YouTube (youtube-nocookie.com) und das Player-Skript des Anbieters (player.vimeo.com bzw. www.youtube.com). Der Anbieter erhält dabei deine IP-Adresse und kann eigene Daten erheben. Das Skript meldet der App, welche Abschnitte du angesehen hast.', undefined, undefined, RAW),
			retention: t('learning', 'Die angesehenen Abschnitte werden beim Abschluss gelöscht, gespeichert bleiben nur Abschlussstatus und -zeitpunkt. Was der Anbieter speichert, legt der Anbieter fest.', undefined, undefined, RAW),
			legal_basis: t('learning', 'Einwilligung (Art. 6(1)a) für die Verbindung zum Anbieter — Klick auf „Laden und ansehen“, vor dem nichts geladen wird', undefined, undefined, RAW),
		},
	]
}

/** Puts the AI recipient into a text; the texts name nobody themselves (spec 3.6). */
function fillProvider(text, provider) {
	return typeof text === 'string' ? text.split('{provider}').join(provider) : ''
}

const RTL = ['ar', 'he', 'fa', 'ur']

/** "uk_UA" / "pt-BR" -> a language the consent catalog has, English otherwise (as the dialog does). */
function consentLanguage(locale) {
	const short = String(locale || '').toLowerCase().split(/[-_]/)[0]
	return consentText.locales[short] ? short : 'en'
}

function stripTags(html) {
	return typeof html === 'string' ? html.replace(/<[^>]+>/g, '').trim() : ''
}

function listItems(html) {
	return typeof html === 'string'
		? [...html.matchAll(/<li>(.*?)<\/li>/g)].map((m) => stripTags(m[1]))
		: []
}

/**
 * The AI notice as the page shows it, for the recipient in force, in the reader's language. The
 * text is the consent text (ai-consent.json, six languages, guarded by check-consent-locales.py):
 * the privacy page says what the dialog says, and nobody reads the disclosure in a language they
 * did not choose.
 *
 * @param {object} _data privacy-info.json (unused since the text comes from the consent catalog)
 * @param {{active: boolean, provider?: string, region?: string}|null} notice null while unknown:
 *   nothing is shown rather than a guess
 * @param {string} locale the reader's language (getLanguage())
 * @returns {object|null}
 */
export function buildAiNotice(_data = privacyData, notice = null, locale = 'de') {
	if (!notice || typeof notice !== 'object') {
		return null
	}
	const lang = consentLanguage(locale)
	const l = consentText.locales[lang]
	const dir = RTL.includes(lang) ? 'rtl' : 'ltr'
	if (!notice.active) {
		return { active: false, off: l.notice_off, lang, dir }
	}
	const provider = typeof notice.provider === 'string' ? notice.provider.trim() : ''
	if (provider === '') {
		// an active recipient without a name: show nothing rather than a guess
		return null
	}
	const fill = (text) => fillProvider(text, provider)
	const regions = l.region_paragraphs || {}
	return {
		active: true,
		lang,
		dir,
		provider,
		intro: stripTags(l.intro_html),
		providerSentence: fill(l.provider_sentence),
		dataHeading: l.data_list_heading,
		dataList: listItems(l.data_list_html),
		identity: l.identity_note,
		region: fill(regions[notice.region] || regions.unknown),
		automation: fill(l.automation_paragraph),
		retention: l.retention_note,
		optOut: l.revocation_note,
	}
}

/**
 * The category cards with their entries, in the reader's language.
 *
 * @param {string} provider the AI recipient in force, '' when unknown
 * @return {Array<{id: string, title: string, entries: Array<object>}>}
 */
export function buildPrivacyCategoryGroups(provider = '') {
	const categories = buildPrivacyCategories(provider)
	return categoryGroups().map((group) => ({
		id: group.id,
		title: group.title,
		entries: categories.filter((category) => category.group === group.id),
	})).filter((group) => group.entries.length > 0)
}

/**
 * The data-subject rights the privacy page lists, in the reader's language. They used to be German
 * entries in privacy-info.json that the page rendered without t(), so every language showed them
 * in German (GitHub #38). The literal t() calls keep them visible to check-i18n-coverage.py.
 * Withdrawal (Art. 7(3)) and the complaint right (Art. 77) were missing (Art. 13(2)(c)/(d)).
 *
 * @return {Array<{id: string, name: string, article: string, description: string}>}
 */
export function buildRights() {
	return [
		{
			id: 'access',
			name: t('learning', 'Auskunft'),
			article: t('learning', 'Art. {n} DSGVO', { n: 15 }),
			description: t('learning', 'Du kannst jederzeit erfahren, welche Daten über dich gespeichert sind.'),
		},
		{
			id: 'rectification',
			name: t('learning', 'Berichtigung'),
			article: t('learning', 'Art. {n} DSGVO', { n: 16 }),
			description: t('learning', 'Unrichtige Daten können korrigiert werden.'),
		},
		{
			id: 'erasure',
			name: t('learning', 'Löschung'),
			article: t('learning', 'Art. {n} DSGVO', { n: 17 }),
			description: t('learning', 'Du kannst die Löschung deiner Daten verlangen.'),
		},
		{
			id: 'restriction',
			name: t('learning', 'Einschränkung'),
			article: t('learning', 'Art. {n} DSGVO', { n: 18 }),
			description: t('learning', 'Du kannst die Verarbeitung einschränken lassen.'),
		},
		{
			id: 'objection',
			name: t('learning', 'Widerspruch'),
			article: t('learning', 'Art. {n} DSGVO', { n: 21 }),
			description: t('learning', 'Du kannst der Verarbeitung widersprechen.'),
		},
		{
			id: 'portability',
			name: t('learning', 'Datenportabilität'),
			article: t('learning', 'Art. {n} DSGVO', { n: 20 }),
			description: t('learning', 'Du kannst deine Daten in einem gängigen Format erhalten.'),
		},
		// Art. 13(2)(c) and (d): withdrawal of consent and the complaint to a supervisory authority (#38, 38-f).
		// The withdrawal text names the way for each consent: AI consent and Telos reset in the personal
		// settings, the video consent is per opened video (VideoConsentOverlay keeps it in component state),
		// and the swarm memory has no student delete route, so it names the instructor and the controller.
		{
			id: 'withdrawal',
			name: t('learning', 'Widerruf der Einwilligung'),
			article: t('learning', 'Art. {n} Abs. {p} DSGVO', { n: 7, p: 3 }),
			description: t('learning', 'Eine erteilte Einwilligung kannst du jederzeit mit Wirkung für die Zukunft widerrufen. Die Verarbeitung bis zum Widerruf bleibt rechtmäßig. Die Einwilligung in die KI-Funktionen widerrufst du in den Einstellungen dieser App; dort setzt du auch dein Telos-Profil zurück. Die Einwilligung für ein externes Video gilt nur für das gerade geöffnete Video; beim nächsten Öffnen fragt die App erneut. Eigene Beiträge zum Schwarmgedächtnis kannst du in der App nicht selbst zurückziehen: Wende dich dafür an deinen Dozenten oder an den Verantwortlichen (siehe „Verantwortlich“ unten).'),
		},
		{
			id: 'complaint',
			name: t('learning', 'Beschwerde bei einer Aufsichtsbehörde'),
			article: t('learning', 'Art. {n} DSGVO', { n: 77 }),
			description: t('learning', 'Du kannst dich bei einer Datenschutz-Aufsichtsbehörde beschweren, insbesondere in dem EU-Mitgliedstaat deines Aufenthaltsorts, deines Arbeitsplatzes oder des mutmaßlichen Verstoßes.'),
		},
	]
}

export function getPrivacyInfoData() {
	return privacyData
}
