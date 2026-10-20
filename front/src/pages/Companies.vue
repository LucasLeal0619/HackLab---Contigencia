<script setup>
import Badge from '../components/Badge.vue'
import Even3PageQueue from '../components/Even3PageQueue.vue'
import ChallengeFormModal from '../components/ChallengeFormModal.vue'
import CompanyFormModal from '../components/CompanyFormModal.vue'
import FilterPanel from '../components/FilterPanel.vue'
import LoadState from '../components/LoadState.vue'
import Page from '../components/Page.vue'
import Pager from '../components/Pager.vue'
import Search from '../components/Search.vue'
import { useCompanies } from '@/js/pages/companies'

const props = defineProps({
  mode: { type: String, default: 'empresas' },
  params: { type: Object, default: () => ({}) },
})
const { tab, list, companyFilter, companyForm, challengeForm, canManageCompanies, canManageChallenges, go, label, tone, options } = useCompanies(props)
const { filters, page, items, meta, loading, error, loaded } = list
</script>

<template>
  <Page
    :title="tab === 'empresas' ? 'Empresas' : 'Desafios'"
    :subtitle="tab === 'empresas' ? 'Cadastre e acompanhe as empresas participantes.' : 'Cadastre os desafios, associe a empresa e acompanhe a distribuição.'"
  >
    <template #actions>
      <template v-if="tab === 'empresas'">
        <button v-if="canManageCompanies" class="btn" type="button" @click="companyForm.start()">+ Cadastrar empresa</button>
        <Badge v-else>Consulta</Badge>
      </template>
      <template v-else>
        <button v-if="canManageChallenges" class="btn ghost" type="button" @click="go('distribuicao')">Distribuição</button>
        <button v-if="canManageChallenges" class="btn" type="button" @click="challengeForm.start()">+ Cadastrar desafio</button>
        <Badge v-else>Consulta</Badge>
      </template>
    </template>
    <Even3PageQueue v-if="tab === 'empresas'" fila="empresas" />

    <template v-if="tab === 'empresas'">
      <FilterPanel :active="list.activeFilters.value" @clear="list.reset()">
        <template #search><Search v-model="filters.search" placeholder="Buscar por nome, razão social ou CNPJ" /></template>
        <select v-model="filters.status" class="input" aria-label="Status">
          <option value="">Todos os status</option>
          <option v-for="[code, name] in options('companyStatus')" :key="code" :value="code">{{ name }}</option>
        </select>
        <select v-model="filters.type" class="input" aria-label="Tipo de participação">
          <option value="">Todos os tipos</option>
          <option v-for="[code, name] in options('companyType')" :key="code" :value="code">{{ name }}</option>
        </select>
      </FilterPanel>

      <LoadState
        :loading="loading" :loaded="loaded" :error="error" :empty="items.length === 0"
        :empty-title="list.activeFilters.value ? 'Nenhuma empresa encontrada' : 'Nenhuma empresa cadastrada'"
        :empty-text="list.activeFilters.value ? 'Ajuste a busca ou os filtros.' : 'Cadastre uma empresa para registrar os representantes e os desafios.'"
        @retry="list.load()"
      >
        <template v-if="canManageCompanies && !list.activeFilters.value" #empty-action>
          <button class="btn" type="button" @click="companyForm.start()">+ Cadastrar empresa</button>
        </template>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Empresa</th><th>Participação</th><th>Representantes</th><th>Desafios</th><th>Status</th><th>Ações</th></tr></thead>
            <tbody>
              <tr v-for="company in items" :key="company.id">
                <td>{{ company.name }}<br v-if="company.segment" /><small v-if="company.segment" class="stat-hint">{{ company.segment }}</small></td>
                <td>{{ label('companyType', company.type) }}</td>
                <td>{{ company.active_representatives_count || '—' }}</td>
                <td>{{ company.challenges_count || '—' }}</td>
                <td><Badge :tone="tone('companyStatus', company.status)">{{ label('companyStatus', company.status) }}</Badge></td>
                <td>
                  <div class="row-actions">
                    <button class="btn ghost small" type="button" @click="go(`empresa?id=${company.id}`)">Visualizar</button>
                    <button v-if="canManageCompanies" class="btn ghost small" type="button" @click="companyForm.start(company)">Editar</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <Pager :meta="meta" :disabled="loading" @page="page = $event" />
      </LoadState>
    </template>

    <template v-else>
      <FilterPanel :active="list.activeFilters.value" @clear="list.reset()">
        <template #search><Search v-model="filters.search" placeholder="Buscar desafio pelo título" /></template>
        <select v-model="filters.status" class="input" aria-label="Status">
          <option value="">Todos os status</option>
          <option v-for="[code, name] in options('challengeStatus')" :key="code" :value="code">{{ name }}</option>
        </select>
        <select v-model="filters.company_id" class="input" aria-label="Empresa">
          <option value="">Todas as empresas</option>
          <option v-for="item in companyFilter.data.value" :key="item.id" :value="item.id">{{ item.name }}</option>
        </select>
        <select v-model="filters.has_team" class="input" aria-label="Equipe">
          <option value="">Com ou sem equipe</option>
          <option value="1">Com equipe</option>
          <option value="0">Sem equipe</option>
        </select>
      </FilterPanel>

      <LoadState
        :loading="loading" :loaded="loaded" :error="error" :empty="items.length === 0"
        :empty-title="list.activeFilters.value ? 'Nenhum desafio encontrado' : 'Nenhum desafio cadastrado'"
        :empty-text="list.activeFilters.value ? 'Ajuste a busca ou os filtros.' : 'Cadastre um desafio proposto por uma empresa.'"
        @retry="list.load()"
      >
        <template v-if="canManageChallenges && !list.activeFilters.value" #empty-action>
          <button class="btn" type="button" @click="challengeForm.start()">+ Cadastrar desafio</button>
        </template>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Desafio</th><th>Empresa</th><th>Equipe</th><th>Status</th><th>Ações</th></tr></thead>
            <tbody>
              <tr v-for="item in items" :key="item.id">
                <td>{{ item.title }}</td>
                <td>{{ item.company?.name || '—' }}</td>
                <td>{{ item.team?.name || '—' }}</td>
                <td><Badge :tone="tone('challengeStatus', item.status)">{{ label('challengeStatus', item.status) }}</Badge></td>
                <td>
                  <div class="row-actions">
                    <button class="btn ghost small" type="button" @click="go(`desafio?id=${item.id}`)">Visualizar</button>
                    <button v-if="canManageChallenges" class="btn ghost small" type="button" @click="challengeForm.start(item)">Editar</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <Pager :meta="meta" :disabled="loading" @page="page = $event" />
      </LoadState>
      <p class="stat-hint">Rascunho → Recebido → Em análise → Aprovado → Distribuído → Em desenvolvimento → Finalizado.
        <button v-if="canManageChallenges" class="linkish" type="button" @click="go('distribuicao')">Ver distribuição</button>
      </p>
    </template>

    <CompanyFormModal v-if="companyForm.open.value" :ctrl="companyForm" />
    <ChallengeFormModal v-if="challengeForm.open.value" :ctrl="challengeForm" />
  </Page>
</template>
