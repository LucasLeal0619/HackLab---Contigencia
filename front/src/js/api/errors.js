// Erro único das chamadas à API. As telas leem `kind`, `message` e `fieldErrors`; nunca o objeto bruto do fetch.

const DEFAULT_MESSAGES = {
  network: 'Não foi possível conectar ao servidor do HackLab. Verifique a conexão e tente novamente.',
  timeout: 'O servidor demorou para responder. Tente novamente.',
  aborted: 'Requisição cancelada.',
  unauthorized: 'Sua sessão terminou. Entre novamente.',
  forbidden: 'Você não tem permissão para esta ação.',
  not_found: 'Registro não encontrado.',
  csrf: 'Sua sessão expirou. Tente novamente.',
  validation: 'Revise os campos destacados.',
  conflict: 'A operação conflita com o estado atual do registro.',
  rate_limited: 'Muitas tentativas em pouco tempo. Aguarde alguns instantes e tente novamente.',
  server: 'O servidor encontrou um erro. Tente novamente em instantes.',
  http: 'Não foi possível concluir a operação.',
}

export function kindForStatus(status) {
  if (status === 401) return 'unauthorized'
  if (status === 403) return 'forbidden'
  if (status === 404) return 'not_found'
  if (status === 409) return 'conflict'
  if (status === 419) return 'csrf'
  if (status === 422) return 'validation'
  if (status === 429) return 'rate_limited'
  if (status >= 500) return 'server'
  return 'http'
}

// Mensagens genéricas do backend que não ajudam quem está na tela: usamos a nossa.
const GENERIC = new Set(['Acesso negado.', 'Não autenticado.', 'Erro interno do servidor.', 'Serviço indisponível.', 'Recurso não encontrado.', 'Sessão expirada ou token CSRF inválido.'])

export class ApiError extends Error {
  constructor({ status = 0, kind = 'http', message = '', errors = {}, data = null } = {}) {
    super(message || DEFAULT_MESSAGES[kind] || DEFAULT_MESSAGES.http)
    this.name = 'ApiError'
    this.status = status
    this.kind = kind
    this.errors = errors || {}
    this.data = data
  }

  // Primeiro erro de cada campo (formato do Laravel: { campo: [mensagens] }).
  get fieldErrors() {
    return Object.fromEntries(Object.entries(this.errors).map(([field, list]) => [field, Array.isArray(list) ? list[0] : String(list)]))
  }

  get retryable() {
    return ['network', 'timeout', 'server', 'rate_limited'].includes(this.kind)
  }

  static fromResponse(status, payload) {
    const kind = kindForStatus(status)
    const backend = typeof payload?.message === 'string' ? payload.message.trim() : ''
    let message = DEFAULT_MESSAGES[kind]
    if (backend && !GENERIC.has(backend) && kind !== 'server') message = backend
    if (kind === 'validation') {
      // O Laravel resume "x (and N more errors)"; mostramos só a primeira frase.
      const first = Object.values(payload?.errors || {})[0]
      message = (Array.isArray(first) ? first[0] : first) || backend || DEFAULT_MESSAGES.validation
    }
    return new ApiError({ status, kind, message, errors: payload?.errors || {}, data: payload })
  }

  static of(kind, message) {
    return new ApiError({ kind, message: message || DEFAULT_MESSAGES[kind] })
  }
}

export function isApiError(error, kind) {
  return error instanceof ApiError && (!kind || error.kind === kind)
}

export function errorMessage(error) {
  if (error instanceof ApiError) return error.message
  return DEFAULT_MESSAGES.http
}
