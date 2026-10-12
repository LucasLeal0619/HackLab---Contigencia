import { beforeEach, describe, expect, it, vi } from 'vitest'

async function loadUi() {
  vi.resetModules()
  return import('@/js/stores/ui')
}

describe('localStorage legado do protótipo', () => {
  beforeEach(() => localStorage.clear())

  it('apaga hacklab e hacklab.vote.cast e aproveita só a acessibilidade', async () => {
    localStorage.setItem('hacklab', JSON.stringify({
      a11y: { scale: 120, contrast: true, focus: true, motion: false, spacing: 'amplo' },
      session: { email: 'admin@senac.br', profile: 'Administrador' },
      users: [{ email: 'x@y', password: 'senha-antiga' }],
      students: [{ name: 'Fulano' }],
      voting: { ballots: [{ teamId: 1 }] },
      audit: [{ action: 'x' }],
    }))
    localStorage.setItem('hacklab.vote.cast', '1')
    const ui = await loadUi()
    expect(localStorage.getItem('hacklab')).toBeNull()
    expect(localStorage.getItem('hacklab.vote.cast')).toBeNull()
    expect(ui.ui.a11y).toEqual({ scale: 120, contrast: true, focus: true, motion: false, spacing: 'amplo' })
    const saved = JSON.parse(localStorage.getItem('hacklab.ui'))
    expect(Object.keys(saved).sort()).toEqual(['a11y', 'activeEventId'])
    expect(JSON.stringify(saved)).not.toMatch(/senac|password|Fulano|ballots|audit/)
  })

  it('preferências atuais têm prioridade sobre o legado', async () => {
    localStorage.setItem('hacklab.ui', JSON.stringify({ a11y: { scale: 110 }, activeEventId: 5 }))
    localStorage.setItem('hacklab', JSON.stringify({ a11y: { scale: 140 } }))
    const ui = await loadUi()
    expect(ui.ui.a11y.scale).toBe(110)
    expect(ui.ui.activeEventId).toBe(5)
    expect(localStorage.getItem('hacklab')).toBeNull()
  })

  it('valores inválidos são saneados', async () => {
    localStorage.setItem('hacklab.ui', JSON.stringify({ a11y: { scale: 999, spacing: 'gigante' }, activeEventId: 'abc' }))
    const ui = await loadUi()
    expect(ui.ui.a11y.scale).toBe(140)
    expect(ui.ui.a11y.spacing).toBe('padrao')
    expect(ui.ui.activeEventId).toBeNull()
  })

  it('JSON corrompido não quebra o app', async () => {
    localStorage.setItem('hacklab', '{quebrado')
    localStorage.setItem('hacklab.ui', '{quebrado')
    const ui = await loadUi()
    expect(ui.ui.a11y.scale).toBe(100)
    expect(localStorage.getItem('hacklab')).toBeNull()
  })

  it('lembrar e-mail guarda só o e-mail', async () => {
    const ui = await loadUi()
    ui.rememberEmail(' Admin@HackLab.local ', true)
    expect(localStorage.getItem('hacklab.remember')).toBe('admin@hacklab.local')
    ui.rememberEmail('admin@hacklab.local', false)
    expect(localStorage.getItem('hacklab.remember')).toBeNull()
  })
})
