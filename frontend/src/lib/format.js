// German money & spec date formats: "€ 125.000", "13 Nov 2026".

const de0 = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 0 })
const de2 = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

export const money = (v, { cents = false } = {}) =>
  v === null || v === undefined || v === '' ? '—' : `€ ${(cents ? de2 : de0).format(Number(v))}`

/** "€ 1,2 Mio" / "€ 450k" for tight spaces (charts, cards). */
export function moneyShort(v) {
  if (v === null || v === undefined) return '—'
  const n = Number(v)
  if (Math.abs(n) >= 1_000_000) return `€ ${new Intl.NumberFormat('de-DE', { maximumFractionDigits: 1 }).format(n / 1_000_000)} Mio`
  if (Math.abs(n) >= 10_000) return `€ ${de0.format(Math.round(n / 1000))}k`
  return money(n)
}

export function budgetRange(budget) {
  if (!budget) return '—'
  const { min, max } = budget
  if (min && max && min !== max) return `${money(min)} – ${de0.format(max)}`
  if (max) return money(max)
  if (min) return `from ${money(min)}`
  return '—'
}

/** "€ 350k – 480k" for table cells. */
export function budgetRangeShort(budget) {
  if (!budget) return '—'
  const { min, max } = budget
  if (min && max && min !== max) return `${moneyShort(min)} – ${moneyShort(max).replace('€ ', '')}`
  return max || min ? moneyShort(max || min) : '—'
}

export const number = (v) => (v === null || v === undefined ? '—' : de0.format(v))

const toDate = (v) => (v instanceof Date ? v : v ? new Date(v) : null)

export function date(v) {
  const d = toDate(v)
  return d && !isNaN(d) ? `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}` : '—'
}

export function dateTime(v) {
  const d = toDate(v)
  if (!d || isNaN(d)) return '—'
  return `${date(d)}, ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

export function timeAgo(v) {
  const d = toDate(v)
  if (!d) return '—'
  const s = Math.round((Date.now() - d.getTime()) / 1000)
  const future = s < 0
  const a = Math.abs(s)
  const fmt = (n, unit) => (future ? `in ${n}${unit}` : `${n}${unit} ago`)
  if (a < 45) return future ? 'in a moment' : 'just now'
  if (a < 3600) return fmt(Math.round(a / 60), 'm')
  if (a < 86400) return fmt(Math.round(a / 3600), 'h')
  if (a < 86400 * 7) return fmt(Math.round(a / 86400), 'd')
  return date(d)
}

export function minutes(m) {
  if (m === null || m === undefined) return '—'
  if (m < 60) return `${m} min`
  const h = Math.floor(m / 60)
  return h < 48 ? `${h}h ${m % 60 ? `${m % 60}m` : ''}`.trim() : `${Math.round(h / 24)} days`
}

export const pct = (v, digits = 0) => (v === null || v === undefined ? '—' : `${Number(v).toFixed(digits).replace('.', ',')} %`)

export function greeting(d = new Date()) {
  const h = d.getHours()
  return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening'
}
