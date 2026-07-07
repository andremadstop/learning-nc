/**
 * Sprach-/Voice-Optionen und Browser-Sprach-Erkennung für VirtuProf (TTS/STT).
 * Reine Werte/Funktionen, aus VirtuProf.vue extrahiert (Zero-Behavior-Change).
 */

export const VOICE_LANGUAGE_OPTIONS = [
	{ value: 'de-DE', label: 'Deutsch' },
	{ value: 'en-US', label: 'English' },
	{ value: 'ru-RU', label: 'Russkii' },
	{ value: 'ar-SA', label: 'al arabiyya' },
	{ value: 'tr-TR', label: 'Turkce' },
	{ value: 'fr-FR', label: 'Francais' },
	{ value: 'es-ES', label: 'Espanol' },
	{ value: 'zh-CN', label: 'Zhongwen' },
	{ value: 'ja-JP', label: 'Nihongo' },
	{ value: 'ko-KR', label: 'Hanguk-eo' },
	{ value: 'pt-BR', label: 'Portugues (Brasil)' },
	{ value: 'it-IT', label: 'Italiano' },
	{ value: 'pl-PL', label: 'Polski' },
	{ value: 'nl-NL', label: 'Nederlands' },
	{ value: 'uk-UA', label: 'Ukrainska' },
]

/**
 * Ermittelt die Voice-Sprache aus der Browser-Sprache; Fallback 'de-DE'.
 * @returns {string} BCP-47-Wert aus VOICE_LANGUAGE_OPTIONS
 */
export function getBrowserVoiceLanguage() {
	if (typeof navigator === 'undefined') {
		return 'de-DE'
	}
	const browserLanguage = String(navigator.language || '').trim()
	const matchedOption = VOICE_LANGUAGE_OPTIONS.find((option) => option.value === browserLanguage)
	if (matchedOption) {
		return matchedOption.value
	}
	const baseLanguage = browserLanguage.slice(0, 2).toLowerCase()
	return VOICE_LANGUAGE_OPTIONS.find((option) => option.value.toLowerCase().startsWith(baseLanguage + '-'))?.value || 'de-DE'
}
