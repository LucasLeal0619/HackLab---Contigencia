// Datas e números em pt-BR. A API envia instantes em ISO 8601 (UTC) e datas puras em AAAA-MM-DD.
// Instantes são exibidos no fuso do evento (Event.timezone); sem fuso, no fuso do navegador.
import { eventTimezone } from '@/js/stores/event-context'

const LOCALE = 'pt-BR'

function tz(timeZone) {
  return timeZone === undefined ? eventTimezone.value : timeZone || undefined
}

function toDate(value) {
  if (!value) return null
  const date = value instanceof Date ? value : new Date(value)
  return Number.isNaN(date.getTime()) ? null : date
}

// Data pura (AAAA-MM-DD) não tem fuso: formata os números sem converter.
export function formatDate(value) {
  if (!value) return '—'
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value))
  if (match) return `${match[3]}/${match[2]}/${match[1]}`
  const date = toDate(value)
  return date ? new Intl.DateTimeFormat(LOCALE, { dateStyle: 'short', timeZone: tz() }).format(date) : '—'
}

export function formatDateTime(value, timeZone) {
  const date = toDate(value)
  return date ? new Intl.DateTimeFormat(LOCALE, { dateStyle: 'short', timeStyle: 'short', timeZone: tz(timeZone) }).format(date) : '—'
}

export function formatTime(value, timeZone) {
  const date = toDate(value)
  return date ? new Intl.DateTimeFormat(LOCALE, { timeStyle: 'short', timeZone: tz(timeZone) }).format(date) : '—'
}

export function formatNumber(value, digits = 0) {
  if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) return '—'
  return new Intl.NumberFormat(LOCALE, { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(Number(value))
}

export function formatScore(value) {
  return formatNumber(value, 2)
}

// Rótulo de um EventDay: "Dia 1 · 14/10/2026".
export function dayLabel(day) {
  if (!day) return '—'
  return [day.label || `Dia ${day.day_number}`, formatDate(day.date)].join(' · ')
}

// "2026-10-14T13:00" → valor de <input type="datetime-local"> no fuso do evento e de volta.
export function toLocalInput(value, timeZone) {
  const date = toDate(value)
  if (!date) return ''
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
    year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: tz(timeZone),
  }).formatToParts(date).map((part) => [part.type, part.value]))
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`
}

// Valor de datetime-local interpretado no fuso do evento → ISO 8601 com offset.
export function fromLocalInput(value, timeZone) {
  if (!value) return null
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value)
  if (!match) return null
  const [, y, mo, d, h, mi] = match.map(Number)
  const guess = Date.UTC(y, mo - 1, d, h, mi)
  const zone = tz(timeZone)
  // Offset do fuso naquele instante (duas passadas cobrem a troca de horário de verão).
  const offsetAt = (ms) => {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
      year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23', timeZone: zone,
    }).formatToParts(new Date(ms)).map((part) => [part.type, Number(part.value)]))
    return Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second) - ms
  }
  let ms = guess - offsetAt(guess)
  ms = guess - offsetAt(ms)
  return new Date(ms).toISOString()
}

export function initials(name = '') {
  return String(name).trim().split(/\s+/).slice(0, 2).map((part) => part[0] || '').join('').toUpperCase()
}
