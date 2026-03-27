import { ar, de, en, ru } from '../l10n/virtuprof-strings.js'

const ALLOWED = ['de', 'en', 'ru', 'ar']
const STORAGE_PREFIX = 'learning:virtuprof:language'

const DICTS = {
  de,
  en,
  ru,
  ar,
}

export function normalizeVirtuProfLanguage(lang) {
  return ALLOWED.includes(String(lang || '').toLowerCase()) ? String(lang).toLowerCase() : ''
}

export function virtuProfLanguageStorageKey() {
  const uid = (typeof OC !== 'undefined' && typeof OC.getCurrentUser === 'function' && OC.getCurrentUser())
    ? (OC.getCurrentUser().uid || 'user')
    : 'user'
  return `${STORAGE_PREFIX}:${uid}`
}

export function loadVirtuProfLanguagePreference() {
  try {
    return normalizeVirtuProfLanguage(window.localStorage.getItem(virtuProfLanguageStorageKey()))
  } catch (e) {
    return ''
  }
}

export function persistVirtuProfLanguagePreference(lang) {
  const normalized = normalizeVirtuProfLanguage(lang)
  try {
    if (normalized) {
      window.localStorage.setItem(virtuProfLanguageStorageKey(), normalized)
    } else {
      window.localStorage.removeItem(virtuProfLanguageStorageKey())
    }
  } catch (e) {
    // Ignore storage failures and keep the in-memory switch responsive.
  }
  return normalized
}

export function detectVirtuProfLanguage(fallback = 'de') {
  const stored = loadVirtuProfLanguagePreference()
  if (stored) {
    return stored
  }
  const docLang = typeof document !== 'undefined'
    ? String((document.documentElement && document.documentElement.lang) || '').slice(0, 2).toLowerCase()
    : ''
  return normalizeVirtuProfLanguage(docLang) || fallback
}

export function translateVirtuProf(lang, key, params = {}) {
  const normalized = normalizeVirtuProfLanguage(lang) || detectVirtuProfLanguage()
  const source = String(key || '')
  const text = DICTS[normalized]?.[source]
    || DICTS.en?.[source]
    || DICTS.de?.[source]
    || source

  return text.replace(/\{(\w+)\}/g, (match, paramKey) => {
    return params[paramKey] !== undefined && params[paramKey] !== null
      ? String(params[paramKey])
      : match
  })
}

export const VIRTUPROF_LANGUAGE_OPTIONS = ['de', 'en', 'ru', 'ar']
