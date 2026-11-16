/**
 * GitHub #75: the certificate validity in days, months or years. The server stores days
 * (cert_validity_days) and calendar months (cert_validity_months, a year = 12 months); months win
 * when set (IssuanceService::computeExpiry). "1 year" ends on the same date of the next year, and
 * 29 February falls back to 28 February — 365 days lose a day in every leap year.
 */

export const VALIDITY_UNITS = ['days', 'months', 'years']

/**
 * The form value of a course: years when the months are whole years.
 *
 * @param {*} days - cert_validity_days
 * @param {*} months - cert_validity_months (null/undefined = not set, the days govern)
 * @return {{amount: number, unit: 'days'|'months'|'years'}}
 */
export function validityFromCourse(days, months) {
	if (months !== null && months !== undefined && months !== '') {
		const m = Math.max(0, Math.floor(Number(months) || 0))
		if (m > 0 && m % 12 === 0) {
			return { amount: m / 12, unit: 'years' }
		}
		return { amount: m, unit: 'months' }
	}
	return { amount: Math.max(0, Math.floor(Number(days) || 0)), unit: 'days' }
}

/**
 * The fields of PUT /cert-config for a form value. Days clear the months (certValidityUnit 'days').
 *
 * @param {{amount: *, unit: string}} validity - form value
 * @return {object}
 */
export function validityPayload(validity) {
	const amount = Math.max(0, Math.floor(Number(validity?.amount) || 0))
	if (validity?.unit === 'years') {
		return { certValidityMonths: amount * 12, certValidityUnit: 'months' }
	}
	if (validity?.unit === 'months') {
		return { certValidityMonths: amount, certValidityUnit: 'months' }
	}
	return { certValidityDays: amount, certValidityUnit: 'days' }
}

/**
 * The expiry for a preview, like the server: calendar months clamped to the end of the target
 * month (31 Jan + 1 month = 28/29 Feb, 29 Feb + 1 year = 28 Feb), days as calendar days.
 *
 * @param {Date} from - issue date
 * @param {{amount: *, unit: string}} validity - form value
 * @return {Date|null} null = no expiry
 */
export function addValidity(from, validity) {
	const amount = Math.max(0, Math.floor(Number(validity?.amount) || 0))
	if (amount <= 0) {
		return null
	}
	const result = new Date(from.getTime())
	if (validity?.unit === 'days') {
		result.setDate(result.getDate() + amount)
		return result
	}
	const months = validity?.unit === 'years' ? amount * 12 : amount
	const day = result.getDate()
	result.setDate(1)
	result.setMonth(result.getMonth() + months)
	const lastDay = new Date(result.getFullYear(), result.getMonth() + 1, 0).getDate()
	result.setDate(Math.min(day, lastDay))
	return result
}
