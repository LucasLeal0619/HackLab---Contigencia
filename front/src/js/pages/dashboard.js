// Lógica do Dashboard: painel mínimo com contagens reais dos módulos permitidos.
// Uma requisição só (GET /events/{id}/dashboard): o backend aplica as mesmas Policies e o mesmo recorte
// setorial das listagens e devolve null no bloco que o perfil não pode consultar. Nenhum número inventado.
import { computed } from 'vue'
import { useRequest } from '@/js/composables/async'
import { auth } from '@/js/stores/auth'
import { activeDays, activeEvent, eventContext } from '@/js/stores/event-context'
import { getDashboard } from '@/js/services/events'
import { dayLabel, formatDate } from '@/js/utils/format'
import { label, tone } from '@/js/labels'

// Cada linha: chave do payload ([bloco, contagem]), rótulo e rota de destino (a listagem que mostra o mesmo número).
const DOMAINS = [
  {
    title: 'Participantes e equipes',
    to: 'participantes',
    action: 'Ver participantes',
    lines: [
      { key: ['participants', 'total'], label: 'Participantes', to: 'participantes' },
      { key: ['participants', 'without_team'], label: 'Sem equipe', to: 'participantes' },
      { key: ['teams', 'total'], label: 'Equipes', to: 'equipes' },
    ],
  },
  {
    title: 'Empresas e desafios',
    to: 'desafios',
    action: 'Ver desafios',
    lines: [
      { key: ['companies', 'total'], label: 'Empresas', to: 'empresas' },
      { key: ['challenges', 'total'], label: 'Desafios', to: 'desafios' },
    ],
  },
  {
    title: 'Pendências',
    to: 'pendencias',
    action: 'Ver pendências',
    lines: [
      { key: ['tasks', 'pending'], label: 'Pendentes', to: 'pendencias' },
      { key: ['tasks', 'in_progress'], label: 'Em andamento', to: 'pendencias' },
      { key: ['tasks', 'overdue'], label: 'Atrasadas', to: 'pendencias' },
    ],
  },
  {
    title: 'Ocorrências',
    to: 'ocorrencias',
    action: 'Ver ocorrências',
    lines: [
      { key: ['occurrences', 'open'], label: 'Abertas', to: 'ocorrencias' },
      { key: ['occurrences', 'in_progress'], label: 'Em andamento', to: 'ocorrencias' },
      { key: ['occurrences', 'urgent_open'], label: 'Urgentes abertas', to: 'ocorrencias' },
    ],
  },
]

// Payload → blocos da tela. Bloco null (sem permissão) some; domínio sem linha nenhuma também.
export function toBlocks(summary) {
  if (!summary) return []
  return DOMAINS
    .map((domain) => ({
      title: domain.title,
      to: domain.to,
      action: domain.action,
      items: domain.lines
        .filter(({ key: [block] }) => summary[block])
        .map(({ key: [block, count], label: text, to }) => ({ label: text, to, value: Number(summary[block][count] ?? 0) })),
    }))
    .filter((domain) => domain.items.length)
}

export function useDashboard() {
  // Troca de evento recarrega; sair da tela cancela a requisição (useRequest aborta no unmount).
  const summary = useRequest(({ signal }) => {
    const eventId = eventContext.activeEventId
    return eventId ? getDashboard(eventId, { signal }) : null
  }, { watchSources: () => eventContext.activeEventId })

  const blocks = computed(() => toBlocks(summary.data.value))
  const greeting = computed(() => auth.user?.person?.full_name?.split(' ')[0] || '')

  return { summary, blocks, greeting, activeEvent, activeDays, sector: computed(() => auth.user?.sector?.name || ''), dayLabel, formatDate, label, tone }
}
