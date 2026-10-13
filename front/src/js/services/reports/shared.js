// Formatação comum dos templates de relatório. Funções puras: recebem o payload do backend, nada mais.
import { label } from '@/js/labels'
import { formatDate, formatDateTime, formatNumber } from '@/js/utils/format'

export const NOT_INFORMED = 'Não informado'

export function text(value, fallback = NOT_INFORMED) {
  if (value === null || value === undefined) return fallback
  const result = String(value).trim()
  return result === '' ? fallback : result
}

export function count(value) {
  return String(Number(value) || 0)
}

export function percent(value) {
  return value === null || value === undefined ? '—' : `${formatNumber(value, 1)}%`
}

export function yesNo(value) {
  return value ? 'Sim' : 'Não'
}

// Instantes no fuso do evento informado pelo backend (não do navegador).
export function dateTime(value, timezone) {
  return value ? formatDateTime(value, timezone || null) : NOT_INFORMED
}

export function date(value) {
  return value ? formatDate(value) : NOT_INFORMED
}

// "Dia 1 · 11/11/2026"
export function dayName(day) {
  if (!day) return NOT_INFORMED
  return [day.label || `Dia ${day.day_number}`, day.date ? formatDate(day.date) : null].filter(Boolean).join(' · ')
}

// Linhas [rótulo, quantidade] a partir de { CÓDIGO: n }, com rótulos pt-BR.
export function enumRows(group, counts) {
  return Object.entries(counts || {}).map(([code, total]) => [label(group, code), count(total)])
}

export function name(item, field = 'name') {
  return item ? text(item[field]) : '—'
}

export { label }
