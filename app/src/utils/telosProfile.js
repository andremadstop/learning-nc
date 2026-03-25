export const TELOS_EXPERIENCE_OPTIONS = Object.freeze(['beginner', 'intermediate', 'advanced'])
export const TELOS_LEARNING_STYLE_OPTIONS = Object.freeze(['solo', 'group', 'mixed'])
export const TELOS_VISIBILITY_OPTIONS = Object.freeze(['private', 'course', 'public'])

export function createTelosForm() {
  return {
    telos: {
      role: '',
      experience_level: 'beginner',
      strengths: '',
      weaknesses: '',
      target_cert: '',
      target_date: '',
      hours_per_week: '',
      learning_style: 'solo',
      motivation: '',
      notes: '',
    },
    bio: '',
    help_offer: '',
    help_wanted: '',
    visibility: 'private',
  }
}

export function applyTelosToForm(profile) {
  const form = createTelosForm()
  if (!profile || typeof profile !== 'object') {
    return form
  }

  const telos = profile.telos && typeof profile.telos === 'object' ? profile.telos : {}
  form.telos.role = stringValue(telos.role)
  form.telos.experience_level = TELOS_EXPERIENCE_OPTIONS.includes(telos.experience_level) ? telos.experience_level : 'beginner'
  form.telos.strengths = joinListValue(telos.strengths)
  form.telos.weaknesses = joinListValue(telos.weaknesses)
  form.telos.target_cert = stringValue(telos.target_cert)
  form.telos.target_date = stringValue(telos.target_date)
  form.telos.hours_per_week = telos.hours_per_week !== undefined && telos.hours_per_week !== null
    ? String(telos.hours_per_week)
    : ''
  form.telos.learning_style = TELOS_LEARNING_STYLE_OPTIONS.includes(telos.learning_style) ? telos.learning_style : 'solo'
  form.telos.motivation = stringValue(telos.motivation)
  form.telos.notes = stringValue(telos.notes)
  form.bio = stringValue(profile.bio)
  form.help_offer = joinListValue(profile.help_offer)
  form.help_wanted = joinListValue(profile.help_wanted)
  form.visibility = TELOS_VISIBILITY_OPTIONS.includes(profile.visibility) ? profile.visibility : 'private'

  return form
}

export function buildTelosPayload(form) {
  const source = form && typeof form === 'object' ? form : createTelosForm()
  const hoursValue = parseFloat(String(source.telos?.hours_per_week ?? '').replace(',', '.'))

  return {
    telos: {
      role: stringValue(source.telos?.role),
      experience_level: TELOS_EXPERIENCE_OPTIONS.includes(source.telos?.experience_level) ? source.telos.experience_level : 'beginner',
      strengths: splitListValue(source.telos?.strengths),
      weaknesses: splitListValue(source.telos?.weaknesses),
      target_cert: stringValue(source.telos?.target_cert),
      target_date: stringValue(source.telos?.target_date),
      hours_per_week: Number.isFinite(hoursValue) ? hoursValue : 0,
      learning_style: TELOS_LEARNING_STYLE_OPTIONS.includes(source.telos?.learning_style) ? source.telos.learning_style : 'solo',
      motivation: stringValue(source.telos?.motivation),
      notes: stringValue(source.telos?.notes),
    },
    bio: stringValue(source.bio),
    help_offer: splitListValue(source.help_offer),
    help_wanted: splitListValue(source.help_wanted),
    visibility: TELOS_VISIBILITY_OPTIONS.includes(source.visibility) ? source.visibility : 'private',
  }
}

export function splitListValue(value) {
  return String(value || '')
    .split(/[\n,;]+/)
    .map((entry) => entry.trim())
    .filter(Boolean)
}

export function joinListValue(value) {
  if (!Array.isArray(value)) {
    return ''
  }
  return value
    .map((entry) => String(entry || '').trim())
    .filter(Boolean)
    .join(', ')
}

function stringValue(value) {
  return String(value || '').trim()
}
