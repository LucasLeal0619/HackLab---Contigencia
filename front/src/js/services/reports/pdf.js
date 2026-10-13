// Central de Relatórios: catálogo dos quatro modelos e geração do PDF a partir do payload do backend.
// O pdfmake e as logos só são carregados quando um PDF é gerado (import dinâmico).
import * as reportsApi from '@/js/services/reports'
import { buildDocument } from '@/js/services/reports/document'
import { dateTime, text } from '@/js/services/reports/shared'
import { closingReport, generalReport, operationsReport, participantsReport } from '@/js/services/reports/templates'
import { label } from '@/js/labels'
import { formatDate } from '@/js/utils/format'

export const REPORT_MODELS = [
  { id: 'general', title: 'Relatório Geral do Hackathon', text: 'Resumo executivo: participantes, equipes, empresas, gestão, inscrições, presença, avaliações e votação.', recommended: true, load: reportsApi.general, template: generalReport },
  { id: 'participants-presence', title: 'Participantes e Presença', text: 'Participantes com turma, status, equipe atual e presença registrada em cada dia do evento.', load: reportsApi.participantsPresence, template: participantsReport },
  { id: 'operations', title: 'Gestão e Operação', text: 'Setores, reuniões, empresas, desafios, equipes, pendências e ocorrências.', load: reportsApi.operations, template: operationsReport },
  { id: 'closing-results', title: 'Encerramento e Resultados', text: 'Progresso das avaliações, jurados, participação na votação e resultado oficial publicado.', load: reportsApi.closingResults, template: closingReport },
]

export function reportModel(id) {
  return REPORT_MODELS.find((item) => item.id === id) || null
}

let enginePromise
let logosPromise

// O pdfmake (~2 MB com fontes) só é baixado quando um relatório é gerado.
function engine() {
  enginePromise ||= Promise.all([import('pdfmake/build/pdfmake'), import('pdfmake/build/vfs_fonts')]).then(([lib, fonts]) => {
    const pdfMake = lib.default || lib
    pdfMake.vfs = fonts.default || fonts
    return pdfMake
  })
  return enginePromise
}

// Logos locais embutidas como data URL no próprio pacote (sem rede para gerar o PDF).
function logos() {
  logosPromise ||= Promise.all([import('@/assets/senac-logo-white.png?inline'), import('@/assets/senac-logo-navy.png?inline')])
    .then(([white, navy]) => ({ logoWhite: white.default, logoNavy: navy.default }))
    .catch(() => ({}))
  return logosPromise
}

// Data local AAAA-MM-DD de um instante no fuso informado.
function localDate(iso, timezone) {
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: timezone || undefined })
    .formatToParts(new Date(iso)).map((part) => [part.type, part.value]))
  return `${parts.year}-${parts.month}-${parts.day}`
}

// Nome seguro: só letras sem acento, números, "_" e "-"; nome do evento curto.
export function reportFileName(report, payload) {
  const slug = (value, max) => String(value || '')
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '')
    .slice(0, max).replace(/_+$/, '')
  const event = slug(payload.event?.name, 30)
  const day = localDate(payload.generated_at, payload.event?.timezone)
  return ['HackLab', report.file, event, day].filter(Boolean).join('_') + '.pdf'
}

// Textos de identificação do evento e da geração (data oficial = generated_at do backend, no fuso do evento).
export function reportContext(payload) {
  const event = payload.event || {}
  const tz = event.timezone
  const days = event.days || []
  return {
    event: {
      name: text(event.name),
      description: text(event.description),
      location: text(event.location),
      timezone: text(tz),
      status: label('eventStatus', event.status),
      period: event.start_date ? `${formatDate(event.start_date)} a ${formatDate(event.end_date)}` : text(null),
      days: days.length ? `${days.length} (${days.map((day) => day.label || `Dia ${day.day_number}`).join(', ')})` : 'Nenhum dia cadastrado',
    },
    generatedAt: dateTime(payload.generated_at, tz),
    author: { name: text(payload.generated_by?.name), role: payload.generated_by?.role?.name || '' },
  }
}

// Monta a definição do documento (pura) a partir do payload. Exportada para testes.
export function reportDefinition(id, payload, images = {}) {
  const model = reportModel(id)
  const report = model.template(payload)
  return { report, definition: buildDocument(report, { ...reportContext(payload), ...images }), fileName: reportFileName(report, payload) }
}

export async function renderPdf(id, payload) {
  const [pdfMake, images] = await Promise.all([engine(), logos()])
  const { report, definition, fileName } = reportDefinition(id, payload, images)
  const blob = await new Promise((resolve) => pdfMake.createPdf(definition).getBlob(resolve))
  return { blob, fileName, title: report.title }
}
