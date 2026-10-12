// Varredura do código-fonte: garante que o protótipo não voltou (demo, sessão local, auditoria local, voto local, Validador).
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { describe, expect, it } from 'vitest'

const SRC = join(__dirname, '..', 'src')

function files(dir) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    return statSync(path).isDirectory() ? files(path) : /\.(js|vue)$/.test(name) ? [path] : []
  })
}

const sources = files(SRC).map((path) => ({ path: relative(SRC, path).replaceAll('\\', '/'), text: readFileSync(path, 'utf8') }))

function offenders(pattern, allowed = []) {
  return sources.filter((file) => pattern.test(file.text) && !allowed.includes(file.path)).map((file) => file.path)
}

describe('código-fonte sem protótipo', () => {
  it('localStorage/sessionStorage só no store de preferências de UI', () => {
    expect(offenders(/localStorage|sessionStorage/, ['js/stores/ui.js'])).toEqual([])
  })

  it('sem modo demonstração nem contas demonstrativas', () => {
    expect(offenders(/buildDemo|loadDemo|resetAll|seedDemoCredentials|\bACCOUNTS\b|demo-badge|Modo demonstração/)).toEqual([])
  })

  it('sem troca de perfil simulada', () => {
    expect(offenders(/setProfile|setDemoSector|PROFILE_NAMES/)).toEqual([])
  })

  it('sem perfil Validador', () => {
    expect(offenders(/Validador|VALIDATOR/)).toEqual([])
  })

  it('sem auditoria local', () => {
    expect(offenders(/appendAudit|\buseAudit\b|audit-logger|state\.audit/)).toEqual([])
  })

  it('sem voto local', () => {
    expect(offenders(/hacklab\.vote\.cast|ballots|hasVoted/, ['js/stores/ui.js'])).toEqual([])
  })

  it('sem store do protótipo', () => {
    expect(offenders(/stores\/hack|useHack\(|js\/data\//)).toEqual([])
  })

  it('HTTP só pelo client (nenhum fetch/axios fora de js/api)', () => {
    expect(offenders(/\bfetch\(|axios/, ['js/api/client.js'])).toEqual([])
  })

  it('front nunca fala com a Even3 nem com o Supabase', () => {
    expect(offenders(/even3\.com|supabase/i)).toEqual([])
  })

  it('sem segredos ou senhas de seed', () => {
    expect(offenders(/hacklab123|EVEN3_API_TOKEN|EVEN3_WEBHOOK_SECRET|Bearer /)).toEqual([])
  })

  it('sem id de evento fixo da Even3 de teste', () => {
    expect(offenders(/793572/)).toEqual([])
  })
})
