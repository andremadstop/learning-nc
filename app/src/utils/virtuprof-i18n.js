import { ar, de, en, fr, ru, uk, zh_CN } from '../l10n/virtuprof-strings.js'

const ALLOWED = ['de', 'en', 'fr', 'ru', 'ar', 'uk', 'zh', 'zh_cn', 'zh-cn']

const DICTS = {
  de,
  en,
  fr,
  ru,
  ar,
  uk,
  zh: zh_CN,
  zh_cn: zh_CN,
  'zh-cn': zh_CN,
}

function normalizeVirtuProfLanguage(lang) {
  return ALLOWED.includes(String(lang || '').toLowerCase()) ? String(lang).toLowerCase() : ''
}

export function detectVirtuProfLanguage(fallback = 'de') {
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
