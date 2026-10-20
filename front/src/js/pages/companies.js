// Lógica de Companies.vue: listagem de empresas (/events/{e}/companies) ou de desafios (/events/{e}/challenges).
// Sem exclusão: empresa inativa via status (página da empresa); desafio muda de status na página do desafio.
import { computed } from 'vue'
import { fetchAll } from '@/js/api/pagination'
import { usePaged, useRequest } from '@/js/composables/async'
import { label, options, tone } from '@/js/labels'
import { useChallengeForm } from '@/js/pages/challenge-form'
import { useCompanyForm } from '@/js/pages/company-form'
import { go } from '@/js/router'
import { hasPermission } from '@/js/stores/auth'
import { eventContext } from '@/js/stores/event-context'
import { listChallenges } from '@/js/services/challenges'
import { listCompanies } from '@/js/services/companies'

export function useCompanies(props) {
  const tab = computed(() => (props.mode === 'desafios' ? 'desafios' : 'empresas'))
  const canManageCompanies = computed(() => hasPermission('companies.manage'))
  const canManageChallenges = computed(() => hasPermission('challenges.manage'))
  const canViewCompanies = computed(() => hasPermission('companies.view'))
  const eventId = () => eventContext.activeEventId

  // A página é recriada ao trocar de modo (rota diferente): só a lista do modo atual é carregada.
  const list = tab.value === 'empresas'
    ? usePaged((query, opts) => listCompanies(eventId(), query, opts), {
      filters: { search: '', status: '', type: '' },
      perPage: 25,
      watchSources: eventId,
    })
    : usePaged((query, opts) => listChallenges(eventId(), query, opts), {
      filters: { search: '', status: '', company_id: '', has_team: '' },
      perPage: 25,
      watchSources: eventId,
    })

  // Empresas para o filtro de desafios (uma consulta, só no modo desafios e com permissão).
  const companyFilter = useRequest(
    () => (tab.value === 'desafios' && canViewCompanies.value && eventId() ? fetchAll((query) => listCompanies(eventId(), query)) : []),
    { initial: [], immediate: tab.value === 'desafios' },
  )

  const companyForm = useCompanyForm({
    onSaved: async (company, created) => {
      if (created) go(`empresa?id=${company.id}&aba=reps`)
      else await list.load()
    },
  })
  const challengeForm = useChallengeForm({ onSaved: () => list.load() })

  return {
    tab,
    list,
    companyFilter,
    companyForm,
    challengeForm,
    canManageCompanies,
    canManageChallenges,
    go,
    label,
    tone,
    options,
  }
}
