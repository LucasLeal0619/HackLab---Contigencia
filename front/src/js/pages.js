// Rota → página (carregada sob demanda). Props extras por rota em `props`.
import { defineAsyncComponent } from 'vue'

const lazy = (loader) => defineAsyncComponent(loader)

const Companies = lazy(() => import('@/pages/Companies.vue'))
const Attendance = lazy(() => import('@/pages/Attendance.vue'))

export const PAGES = {
  dashboard: { component: lazy(() => import('@/pages/Dashboard.vue')) },
  config: { component: lazy(() => import('@/pages/EventConfig.vue')) },
  usuarios: { component: lazy(() => import('@/pages/Users.vue')) },
  participantes: { component: lazy(() => import('@/pages/People.vue')) },
  equipes: { component: lazy(() => import('@/pages/Teams.vue')) },
  montar: { component: lazy(() => import('@/pages/TeamBuild.vue')) },
  empresas: { component: Companies, props: { mode: 'empresas' } },
  desafios: { component: Companies, props: { mode: 'desafios' } },
  empresa: { component: lazy(() => import('@/pages/CompanyDetail.vue')) },
  desafio: { component: lazy(() => import('@/pages/ChallengeDetail.vue')) },
  distribuicao: { component: lazy(() => import('@/pages/Distribution.vue')) },
  setores: { component: lazy(() => import('@/pages/Sectors.vue')) },
  reunioes: { component: lazy(() => import('@/pages/Meetings.vue')) },
  reuniao: { component: lazy(() => import('@/pages/MeetingDetail.vue')) },
  pendencias: { component: lazy(() => import('@/pages/Tasks.vue')) },
  ocorrencias: { component: lazy(() => import('@/pages/Occurrences.vue')) },
  presenca: { component: Attendance },
  jurados: { component: lazy(() => import('@/pages/Jurors.vue')) },
  avaliacoes: { component: lazy(() => import('@/pages/Evaluations.vue')) },
  'votacao-gestao': { component: lazy(() => import('@/pages/VotingAdmin.vue')) },
  resultados: { component: lazy(() => import('@/pages/ResultsAdmin.vue')) },
  painel: { component: lazy(() => import('@/pages/Results.vue')) },
  auditoria: { component: lazy(() => import('@/pages/Audit.vue')) },
  relatorios: { component: lazy(() => import('@/pages/Reports.vue')) },
  'area-jurado': { component: lazy(() => import('@/pages/JudgeArea.vue')) },
  avaliar: { component: lazy(() => import('@/pages/Evaluate.vue')), key: (params) => params.id || '' },
  votacao: { component: lazy(() => import('@/pages/PublicVote.vue')) },
  apresentacao: { component: lazy(() => import('@/pages/Presentation.vue')) },
}

// Tela cheia/focada aberta por perfis administrativos: título e para onde volta.
export const FRAMES = {
  'area-jurado': { title: 'Área do Jurado', exitTo: 'avaliacoes' },
  avaliar: { title: 'Área do Jurado', exitTo: 'area-jurado' },
  votacao: { title: 'Votação do Público', exitTo: 'dashboard' },
}
