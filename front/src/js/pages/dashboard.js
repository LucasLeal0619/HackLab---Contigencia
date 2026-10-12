// Lógica do Dashboard: painel mínimo com contagens reais (meta.total das listagens) dos módulos permitidos.
// Nenhum número inventado: o que o perfil não pode consultar não aparece.
import { computed, ref } from 'vue'
import { useRequest } from '@/js/composables/async'
import { auth, hasPermission } from '@/js/stores/auth'
import { activeDays, activeEvent, eventContext } from '@/js/stores/event-context'
import { listChallenges } from '@/js/services/challenges'
import { listCompanies } from '@/js/services/companies'
import { listOccurrences } from '@/js/services/occurrences'
import { listParticipants } from '@/js/services/participants'
import { listTasks } from '@/js/services/tasks'
import { listTeams } from '@/js/services/teams'
import { dayLabel, formatDate } from '@/js/utils/format'
import { label, tone } from '@/js/labels'

// Cada linha: permissão, rótulo, rota de destino e a consulta de contagem (per_page=1 → só meta.total).
const DOMAINS = [
  {
    title: 'Participantes e equipes',
    to: 'participantes',
    action: 'Ver participantes',
    lines: [
      { permission: 'participants.view', label: 'Participantes', to: 'participantes', count: (id, signal) => listParticipants(id, { per_page: 1 }, { signal }) },
      { permission: 'participants.view', label: 'Sem equipe', to: 'participantes', count: (id, signal) => listParticipants(id, { per_page: 1, has_team: 'false', status: 'AVAILABLE' }, { signal }) },
      { permission: 'teams.view', label: 'Equipes', to: 'equipes', count: (id, signal) => listTeams(id, { per_page: 1 }, { signal }) },
    ],
  },
  {
    title: 'Empresas e desafios',
    to: 'desafios',
    action: 'Ver desafios',
    lines: [
      { permission: 'companies.view', label: 'Empresas', to: 'empresas', count: (id, signal) => listCompanies(id, { per_page: 1 }, { signal }) },
      { permission: 'challenges.view', label: 'Desafios', to: 'desafios', count: (id, signal) => listChallenges(id, { per_page: 1 }, { signal }) },
    ],
  },
  {
    title: 'Pendências',
    to: 'pendencias',
    action: 'Ver pendências',
    lines: [
      { permission: 'tasks.view', label: 'Pendentes', to: 'pendencias', count: (id, signal) => listTasks(id, { per_page: 1, status: 'PENDING' }, { signal }) },
      { permission: 'tasks.view', label: 'Em andamento', to: 'pendencias', count: (id, signal) => listTasks(id, { per_page: 1, status: 'IN_PROGRESS' }, { signal }) },
      { permission: 'tasks.view', label: 'Atrasadas', to: 'pendencias', count: (id, signal) => listTasks(id, { per_page: 1, overdue: 1 }, { signal }) },
    ],
  },
  {
    title: 'Ocorrências',
    to: 'ocorrencias',
    action: 'Ver ocorrências',
    lines: [
      { permission: 'occurrences.view', label: 'Abertas', to: 'ocorrencias', count: (id, signal) => listOccurrences(id, { per_page: 1, status: 'OPEN' }, { signal }) },
      { permission: 'occurrences.view', label: 'Em andamento', to: 'ocorrencias', count: (id, signal) => listOccurrences(id, { per_page: 1, status: 'IN_PROGRESS' }, { signal }) },
      { permission: 'occurrences.view', label: 'Urgentes abertas', to: 'ocorrencias', count: (id, signal) => listOccurrences(id, { per_page: 1, status: 'OPEN', priority: 'URGENT' }, { signal }) },
    ],
  },
]

export function useDashboard() {
  const progress = ref([])
  const visible = computed(() => DOMAINS
    .map((domain) => ({ ...domain, lines: domain.lines.filter((line) => hasPermission(line.permission)) }))
    .filter((domain) => domain.lines.length))

  // Em sequência (não em paralelo): cada contagem é uma consulta leve e o servidor não fica com fila longa.
  // Os blocos aparecem conforme chegam.
  const counts = useRequest(async ({ signal }) => {
    const eventId = eventContext.activeEventId
    if (!eventId) return []
    const blocks = []
    for (const domain of visible.value) {
      const items = []
      for (const line of domain.lines) {
        // Saiu da tela: para o laço (as consultas restantes não ocupam o servidor).
        if (signal.aborted) return blocks
        const payload = await line.count(eventId, signal)
        items.push({ label: line.label, to: line.to, value: payload?.meta?.total ?? payload?.data?.length ?? 0 })
      }
      blocks.push({ title: domain.title, to: domain.to, action: domain.action, items })
      progress.value = [...blocks]
    }
    return blocks
  }, { initial: [], watchSources: () => eventContext.activeEventId })

  const greeting = computed(() => auth.user?.person?.full_name?.split(' ')[0] || '')

  return { counts, progress, greeting, activeEvent, activeDays, sector: computed(() => auth.user?.sector?.name || ''), dayLabel, formatDate, label, tone }
}
