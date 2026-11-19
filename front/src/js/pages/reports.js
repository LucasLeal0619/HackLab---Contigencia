// Lógica da Central de Relatórios (Reports.vue): 1 relatório = 1 endpoint do backend → PDF no navegador.
// Cada modelo tem carregamento, erro e repetição próprios; um falhar não trava os outros.
import { onBeforeUnmount, reactive, ref, watch } from 'vue'
import { ApiError } from '@/js/api/errors'
import { REPORT_MODELS, renderPdf, reportModel } from '@/js/services/reports/pdf'
import { eventContext } from '@/js/stores/event-context'

export function useReports({ render = renderPdf, download = saveFile } = {}) {
  // id → { busy: '' | 'preview' | 'pdf', error, result: { url, fileName, title, eventId } }
  const rows = reactive(Object.fromEntries(REPORT_MODELS.map((item) => [item.id, { busy: '', error: null, result: null, last: '', accepted: false, signatureDraft: '', signature: '', signedAt: '' }])))
  const preview = ref(null)
  let generation = 0

  function confirmSignature(id) {
    const row = rows[id]
    const name = row?.signatureDraft.trim()
    if (!name) return false
    row.signature = name
    return true
  }

  function acceptTerm(id) {
    const row = rows[id]
    if (!row?.accepted || !row.signature.trim() || row.signature !== row.signatureDraft.trim()) return false
    row.signedAt = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date())
    return true
  }

  function save(result) {
    const row = rows[result?.id]
    if (!row?.accepted || !row.signature.trim() || row.signature !== row.signatureDraft.trim() || row.signature !== result.signature) return
    download(result)
  }

  function revoke(result) {
    if (result?.url) URL.revokeObjectURL(result.url)
  }

  // Troca de evento: descarta tudo do evento anterior (payloads, preview e Blob URLs).
  function invalidateAll() {
    generation += 1
    preview.value = null
    Object.values(rows).forEach((row) => {
      revoke(row.result)
      Object.assign(row, { busy: '', error: null, result: null, accepted: false, signatureDraft: '', signature: '', signedAt: '' })
    })
  }

  async function build(id, mode) {
    const row = rows[id]
    if (row.busy) return null
    const eventId = eventContext.activeEventId
    const mine = generation
    row.busy = mode
    row.error = null
    row.last = mode
    try {
      const payload = await reportModel(id).load(eventId)
      // O PDF sai só do payload, e só se ele é do evento que está na tela.
      if (mine !== generation || payload?.event?.id !== eventId) return null
      const { blob, fileName, title } = await render(id, payload)
      if (mine !== generation) return null
      revoke(row.result)
      row.result = { url: URL.createObjectURL(blob), fileName, title, eventId }
      return row.result
    } catch (error) {
      if (mine === generation) row.error = error instanceof ApiError ? error : ApiError.of('http', 'Não foi possível gerar o relatório. Tente novamente.')
      return null
    } finally {
      if (mine === generation) row.busy = ''
    }
  }

  async function view(id) {
    if (!acceptTerm(id)) return null
    const result = await build(id, 'preview')
    if (result) preview.value = { id, ...result, signature: rows[id].signature, signedAt: rows[id].signedAt }
  }

  async function exportPdf(id) {
    if (!acceptTerm(id)) return null
    const row = rows[id]
    const result = row.result?.eventId === eventContext.activeEventId ? row.result : await build(id, 'pdf')
    if (result) download(result)
  }

  function retry(id) {
    return rows[id].last === 'pdf' ? exportPdf(id) : view(id)
  }

  watch(() => eventContext.activeEventId, invalidateAll)
  onBeforeUnmount(invalidateAll)

  return { REPORT_MODELS, rows, preview, view, exportPdf, retry, save, confirmSignature }
}

function saveFile(result) {
  const link = document.createElement('a')
  link.href = result.url
  link.download = result.fileName
  document.body.appendChild(link)
  link.click()
  link.remove()
}
