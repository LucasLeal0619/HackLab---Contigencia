// Coleções do Laravel: paginadas ({ data, links, meta }) ou simples ({ data }).

export function emptyPage() {
  return { items: [], meta: { current_page: 1, last_page: 1, per_page: 0, total: 0, from: null, to: null }, links: {}, paginated: false }
}

export function toPage(payload) {
  const items = Array.isArray(payload?.data) ? payload.data : Array.isArray(payload) ? payload : []
  const meta = payload?.meta && typeof payload.meta === 'object' ? payload.meta : null
  const paginated = Boolean(meta && 'current_page' in meta)
  return {
    items,
    meta: paginated
      ? { ...meta, current_page: Number(meta.current_page) || 1, last_page: Number(meta.last_page) || 1, total: Number(meta.total ?? items.length) }
      : { ...(meta || {}), current_page: 1, last_page: 1, per_page: items.length, total: items.length, from: items.length ? 1 : null, to: items.length || null },
    links: payload?.links || {},
    paginated,
  }
}

// Busca todas as páginas de uma listagem (para seletores pequenos: setores, turmas, equipes...).
// Limite de segurança: nunca mais que `maxPages` requisições.
export async function fetchAll(fetchPage, { perPage = 100, maxPages = 20 } = {}) {
  const items = []
  let page = 1
  let last = 1
  do {
    const result = toPage(await fetchPage({ page, per_page: perPage }))
    items.push(...result.items)
    last = result.meta.last_page
    page += 1
  } while (page <= last && page <= maxPages)
  return items
}
