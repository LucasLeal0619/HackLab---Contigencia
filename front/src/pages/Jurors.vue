<script setup>
import Badge from '../components/Badge.vue'
import Drawer from '../components/Drawer.vue'
import Empty from '../components/Empty.vue'
import Field from '../components/Field.vue'
import FilterPanel from '../components/FilterPanel.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import Pager from '../components/Pager.vue'
import Search from '../components/Search.vue'
import Tabs from '../components/Tabs.vue'
import { useJurors } from '@/js/pages/jurors'

defineProps({
  params: { type: Object, default: () => ({}) },
})

const {
  tabs, tab, canView, canManage, canManageCriteria, canPeople,
  list, companies, selectableCompanies, ensureCompanies,
  modal, selected, form, personSearch, people, searchPeople,
  openCreate, openEdit, create, update, changeStatus, askStatus,
  detail, detailAssignments, openDetail,
  assignJuror, assignSelection, assignCurrent, teams, assignableTeams, revokedHistory, assignChanges,
  openAssignments, toggleTeam, syncAssignments, askSaveAssignments, teamName,
  criteria, criteriaItems, locked, totalWeight, criterionForm, editingCriterion, openCriterion, saveCriterion,
  criterionTarget, toggleCriterion, askToggleCriterion, scaleOf, weightOf, accessLabel,
  label, tone, options, formatDateTime,
} = useJurors()
const { filters, page, items, meta, loading, error, loaded } = list
</script>

<template>
  <Page title="Jurados" subtitle="Jurados do evento, equipes atribuídas a cada um e critérios da avaliação técnica.">
    <template #actions>
      <button v-if="tab === 'jurados' && canManage" class="btn" type="button" @click="openCreate">+ Adicionar jurado</button>
      <button v-if="tab === 'criterios' && canManageCriteria" class="btn" type="button" :disabled="locked || criteria.loading.value" :title="locked ? 'Já existem avaliações: a estrutura dos critérios está travada.' : ''" @click="openCriterion()">+ Adicionar critério</button>
      <Badge v-if="(tab === 'jurados' && !canManage) || (tab === 'criterios' && !canManageCriteria)">Consulta</Badge>
    </template>

    <Tabs v-if="tabs.length > 1" v-model="tab" :tabs="tabs" />

    <!-- Jurados -->
    <template v-if="tab === 'jurados' && canView">
      <FilterPanel :active="list.activeFilters.value" @clear="list.reset()">
        <template #search><Search v-model="filters.search" placeholder="Buscar jurado pelo nome" /></template>
        <select v-model="filters.status" class="input" aria-label="Status">
          <option value="">Todos os status</option>
          <option v-for="[code, name] in options('jurorStatus')" :key="code" :value="code">{{ name }}</option>
        </select>
        <select v-model="filters.company_id" class="input" aria-label="Empresa" @focus="ensureCompanies" @mousedown="ensureCompanies">
          <option value="">Todas as empresas</option>
          <option v-if="companies.loading.value" disabled value="loading">Carregando empresas…</option>
          <option v-for="item in companies.data.value" :key="item.id" :value="item.id">{{ item.name }}</option>
        </select>
      </FilterPanel>

      <LoadState :loading="loading" :loaded="loaded" :error="error" :empty="items.length === 0" empty-title="Nenhum jurado encontrado" :empty-text="list.activeFilters.value ? 'Ajuste os filtros da busca.' : 'Cadastre os jurados do evento e atribua a cada um as equipes que vai avaliar.'" @retry="list.load()">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Jurado</th><th>Empresa</th><th>Equipes atribuídas</th><th>Acesso</th><th>Status</th><th>Ações</th></tr></thead>
            <tbody>
              <tr v-for="item in items" :key="item.id">
                <td>{{ item.person?.full_name || '—' }}<br /><small class="stat-hint">{{ item.person?.email || 'Sem e-mail' }}</small></td>
                <td>{{ item.company?.name || 'Sem empresa' }}</td>
                <td>
                  <template v-if="item.active_assignments_count !== undefined">{{ item.active_assignments_count }}</template>
                  <button v-else class="btn ghost small" type="button" @click="openDetail(item)">Ver equipes</button>
                </td>
                <td><Badge :tone="item.account?.access_ready ? 'ok' : ''">{{ accessLabel(item.account) }}</Badge></td>
                <td><Badge :tone="tone('jurorStatus', item.status)">{{ label('jurorStatus', item.status) }}</Badge></td>
                <td>
                  <div class="row-actions">
                    <button class="btn ghost small" type="button" @click="openDetail(item)">Visualizar</button>
                    <template v-if="canManage">
                      <button class="btn ghost small" type="button" @click="openAssignments(item)">Equipes</button>
                      <button class="btn ghost small" type="button" @click="openEdit(item)">Editar</button>
                      <button class="btn ghost small" type="button" :disabled="changeStatus.pending.value" @click="askStatus(item)">{{ item.status === 'ACTIVE' ? 'Inativar' : 'Ativar' }}</button>
                    </template>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <Pager :meta="meta" :disabled="loading" @page="page = $event" />
      </LoadState>
      <p class="stat-hint mt">A empresa é só contexto: as equipes avaliadas são sempre as atribuídas explicitamente. O acesso à Área do Jurado depende de uma conta com perfil Jurado, criada em Usuários.</p>
    </template>

    <!-- Critérios -->
    <template v-if="tab === 'criterios'">
      <div v-if="locked" class="banner warn" role="status">
        Já existem avaliações neste evento: a estrutura dos critérios está travada. Só nome e descrição podem ser ajustados; escala, peso, ordem e status não mudam.
      </div>
      <LoadState :loading="criteria.loading.value" :loaded="criteria.loaded.value" :error="criteria.error.value" :empty="criteriaItems.length === 0" empty-title="Nenhum critério configurado" empty-text="Cadastre os critérios antes de os jurados começarem a avaliar." @retry="criteria.load()">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Ordem</th><th>Critério</th><th>Escala</th><th>Peso</th><th>Status</th><th v-if="canManageCriteria">Ações</th></tr></thead>
            <tbody>
              <tr v-for="item in criteriaItems" :key="item.id">
                <td>{{ item.sort_order }}</td>
                <td>{{ item.name }}<div v-if="item.description"><small class="stat-hint">{{ item.description }}</small></div></td>
                <td>{{ scaleOf(item) }}</td>
                <td>{{ weightOf(item) }}</td>
                <td><Badge :tone="tone('activeStatus', item.active ? 'ACTIVE' : 'INACTIVE')">{{ label('activeStatus', item.active ? 'ACTIVE' : 'INACTIVE') }}</Badge></td>
                <td v-if="canManageCriteria">
                  <div class="row-actions">
                    <button class="btn ghost small" type="button" @click="openCriterion(item)">Editar</button>
                    <button class="btn ghost small" type="button" :disabled="locked || toggleCriterion.pending.value" @click="askToggleCriterion(item)">{{ item.active ? 'Inativar' : 'Ativar' }}</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="stat-hint">Nota técnica = 100 × Σ(nota normalizada × peso) ÷ Σ(pesos), sobre os critérios ativos. Soma dos pesos ativos: {{ totalWeight }}.</p>
      </LoadState>
    </template>

    <!-- Cadastro de jurado -->
    <Modal v-if="modal === 'create'" title="Adicionar jurado" subtitle="Cadastre uma nova pessoa ou escolha uma já cadastrada (ex.: representante de empresa). Não cria conta de acesso." wide @close="modal = null">
      <form class="form-grid" novalidate @submit.prevent="create.run()">
        <div v-if="canPeople" class="field span-2">
          <span>Pessoa</span>
          <div class="chips">
            <button type="button" class="chip" :class="{ on: form.personMode === 'new' }" @click="form.personMode = 'new'">Nova pessoa</button>
            <button type="button" class="chip" :class="{ on: form.personMode === 'existing' }" @click="form.personMode = 'existing'">Pessoa já cadastrada</button>
          </div>
        </div>
        <template v-if="form.personMode === 'new'">
          <Field label="Nome completo" required :error="create.fieldErrors.value['person.full_name'] || create.fieldErrors.value.person"><input v-model="form.full_name" class="input" required /></Field>
          <Field label="E-mail" :error="create.fieldErrors.value['person.email']"><input v-model="form.email" class="input" type="email" /></Field>
          <Field label="Telefone" :error="create.fieldErrors.value['person.phone']"><input v-model="form.phone" class="input" /></Field>
          <Field label="Documento" hint="Opcional." :error="create.fieldErrors.value['person.document']"><input v-model="form.document" class="input" /></Field>
        </template>
        <template v-else>
          <Field label="Buscar pessoa" class-name="span-2" :error="create.fieldErrors.value.person_id" hint="Pessoas cadastradas no HackLab (com ou sem conta).">
            <input :value="personSearch" class="input" placeholder="Nome ou e-mail" @input="searchPeople($event.target.value)" />
          </Field>
          <div class="span-2 menu-list">
            <p v-if="people.loading.value" class="stat-hint">Buscando…</p>
            <p v-else-if="people.error.value" class="stat-hint">{{ people.error.value.message }}</p>
            <p v-else-if="personSearch && !people.data.value.length" class="stat-hint">Nenhuma pessoa encontrada.</p>
            <button v-for="item in people.data.value" :key="item.id" type="button" class="chip" :class="{ on: form.person?.id === item.id }" @click="form.person = item">
              {{ item.full_name }}<template v-if="item.email"> · {{ item.email }}</template>
            </button>
          </div>
        </template>
        <Field label="Empresa (opcional)" hint="Só contexto. Não define as equipes avaliadas." :error="create.fieldErrors.value.company_id">
          <select v-model="form.company_id" class="input">
            <option value="">Sem empresa</option>
            <option v-if="companies.loading.value" disabled value="loading">Carregando empresas…</option>
            <option v-for="item in selectableCompanies" :key="item.id" :value="item.id">{{ item.name }}</option>
          </select>
        </Field>
        <Field label="Observações" class-name="span-2" :error="create.fieldErrors.value.notes"><textarea v-model="form.notes" class="input" maxlength="2000" /></Field>
      </form>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="create.pending.value" @click="create.run()">{{ create.pending.value ? 'Salvando…' : 'Cadastrar jurado' }}</button>
      </template>
    </Modal>

    <!-- Edição de jurado -->
    <Modal v-if="modal === 'edit'" title="Editar jurado" :subtitle="selected?.person?.full_name" @close="modal = null">
      <form class="form-grid" novalidate @submit.prevent="update.run()">
        <Field label="Empresa (opcional)" class-name="span-2" hint="Só contexto. Não define as equipes avaliadas." :error="update.fieldErrors.value.company_id">
          <select v-model="form.company_id" class="input">
            <option value="">Sem empresa</option>
            <option v-if="selected?.company && selected.company.status === 'INACTIVE'" :value="selected.company.id">{{ selected.company.name }} (inativa)</option>
            <option v-if="companies.loading.value" disabled value="loading">Carregando empresas…</option>
            <option v-for="item in selectableCompanies" :key="item.id" :value="item.id">{{ item.name }}</option>
          </select>
        </Field>
        <Field label="Observações" class-name="span-2" :error="update.fieldErrors.value.notes"><textarea v-model="form.notes" class="input" maxlength="2000" /></Field>
      </form>
      <p class="stat-hint">Nome, e-mail e telefone são dados da pessoa e são alterados no cadastro de pessoas.</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="update.pending.value" @click="update.run()">{{ update.pending.value ? 'Salvando…' : 'Salvar alterações' }}</button>
      </template>
    </Modal>

    <!-- Inativar jurado -->
    <Modal v-if="modal === 'off'" title="Inativar jurado?" subtitle="O jurado deixa de poder avaliar. As atribuições continuam ativas (e esperadas no progresso) até serem revogadas." @close="modal = null">
      <p>{{ selected?.person?.full_name }}</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn danger" type="button" :disabled="changeStatus.pending.value" @click="changeStatus.run(selected, 'INACTIVE')">{{ changeStatus.pending.value ? 'Salvando…' : 'Inativar' }}</button>
      </template>
    </Modal>

    <!-- Atribuições explícitas -->
    <Modal v-if="modal === 'assign' || modal === 'assign-confirm'" title="Equipes atribuídas" :subtitle="`${assignJuror?.person?.full_name || ''} avalia somente as equipes marcadas. Uma equipe pode ter vários jurados.`" wide @close="modal = null">
      <template v-if="modal === 'assign'">
        <LoadState :loading="teams.loading.value || assignCurrent.loading.value" :loaded="teams.loaded.value && assignCurrent.loaded.value" :error="teams.error.value || assignCurrent.error.value" :empty="assignableTeams.length === 0" empty-title="Nenhuma equipe ativa no evento" @retry="openAssignments(assignJuror)">
          <p v-if="assignJuror?.status === 'INACTIVE'" class="banner warn">Jurado inativo não recebe novas equipes; você ainda pode revogar as atuais.</p>
          <div class="chips" role="group" aria-label="Equipes">
            <button v-for="team in assignableTeams" :key="team.id" type="button" class="chip" :class="{ on: assignSelection.includes(team.id) }" :aria-pressed="assignSelection.includes(team.id)" @click="toggleTeam(team.id)">
              {{ team.name }}<template v-if="team.status !== 'ACTIVE'"> (inativa)</template>
            </button>
          </div>
          <small v-if="syncAssignments.fieldErrors.value.team_ids" class="field-error">{{ syncAssignments.fieldErrors.value.team_ids }}</small>
          <p class="stat-hint">{{ assignSelection.length }} equipe(s) selecionada(s)<template v-if="assignChanges.added.length || assignChanges.removed.length"> · {{ assignChanges.added.length }} nova(s), {{ assignChanges.removed.length }} a revogar</template>.</p>
          <p v-if="revokedHistory.length" class="stat-hint">Revogadas anteriormente: {{ revokedHistory.map((item) => item.team?.name).join(', ') }}.</p>
        </LoadState>
      </template>
      <template v-else>
        <p>As atribuições abaixo serão revogadas. Avaliações dessas equipes por este jurado deixam de contar no resultado técnico (ficam no histórico).</p>
        <ul><li v-for="id in assignChanges.removed" :key="id">{{ teamName(id) }}</li></ul>
        <p v-if="assignChanges.added.length" class="stat-hint">Novas atribuições: {{ assignChanges.added.map(teamName).join(', ') }}.</p>
      </template>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal === 'assign-confirm' ? (modal = 'assign') : (modal = null)">{{ modal === 'assign-confirm' ? 'Voltar' : 'Cancelar' }}</button>
        <button v-if="modal === 'assign'" class="btn" type="button" :disabled="syncAssignments.pending.value || !assignCurrent.loaded.value" @click="askSaveAssignments">{{ syncAssignments.pending.value ? 'Salvando…' : 'Salvar atribuições' }}</button>
        <button v-else class="btn danger" type="button" :disabled="syncAssignments.pending.value" @click="syncAssignments.run()">{{ syncAssignments.pending.value ? 'Salvando…' : 'Confirmar e revogar' }}</button>
      </template>
    </Modal>

    <!-- Critério -->
    <Modal v-if="modal === 'criterion'" :title="editingCriterion ? 'Editar critério' : 'Novo critério'" :subtitle="locked ? 'Estrutura travada: só nome e descrição podem mudar.' : 'Nota numérica entre a mínima e a máxima; o peso entra na média ponderada.'" @close="modal = null">
      <form class="form-grid" novalidate @submit.prevent="saveCriterion.run()">
        <Field label="Nome" required class-name="span-2" :error="saveCriterion.fieldErrors.value.name"><input v-model="criterionForm.name" class="input" maxlength="120" /></Field>
        <Field label="Descrição" class-name="span-2" :error="saveCriterion.fieldErrors.value.description"><textarea v-model="criterionForm.description" class="input" maxlength="2000" /></Field>
        <Field label="Nota mínima" required :error="saveCriterion.fieldErrors.value.min_score"><input v-model="criterionForm.min_score" class="input" type="number" step="any" min="0" :disabled="locked" /></Field>
        <Field label="Nota máxima" required :error="saveCriterion.fieldErrors.value.max_score"><input v-model="criterionForm.max_score" class="input" type="number" step="any" :disabled="locked" /></Field>
        <Field label="Peso" required hint="Maior que zero." :error="saveCriterion.fieldErrors.value.weight"><input v-model="criterionForm.weight" class="input" type="number" step="any" min="0" :disabled="locked" /></Field>
        <Field label="Ordem" :hint="editingCriterion ? '' : 'Em branco: no fim da lista.'" :error="saveCriterion.fieldErrors.value.sort_order"><input v-model="criterionForm.sort_order" class="input" type="number" min="1" max="999" :disabled="locked" /></Field>
      </form>
      <small v-if="saveCriterion.fieldErrors.value.criteria" class="field-error">{{ saveCriterion.fieldErrors.value.criteria }}</small>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="saveCriterion.pending.value" @click="saveCriterion.run()">{{ saveCriterion.pending.value ? 'Salvando…' : editingCriterion ? 'Salvar alterações' : 'Criar critério' }}</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'criterion-off'" title="Inativar critério?" subtitle="Critério inativo não aparece para os jurados nem entra no cálculo da nota técnica." @close="modal = null">
      <p>{{ criterionTarget?.name }}</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn danger" type="button" :disabled="toggleCriterion.pending.value" @click="toggleCriterion.run(criterionTarget, false)">{{ toggleCriterion.pending.value ? 'Salvando…' : 'Inativar' }}</button>
      </template>
    </Modal>

    <!-- Detalhe do jurado -->
    <Drawer v-if="detail" :title="detail.person?.full_name || 'Jurado'" :subtitle="detail.company?.name || 'Sem empresa vinculada'" @close="detail = null">
      <p><b>Status</b><br /><Badge :tone="tone('jurorStatus', detail.status)">{{ label('jurorStatus', detail.status) }}</Badge></p>
      <p><b>E-mail</b><br />{{ detail.person?.email || '—' }}</p>
      <p><b>Telefone</b><br />{{ detail.person?.phone || '—' }}</p>
      <p><b>Acesso ao HackLab</b><br />{{ accessLabel(detail.account) }}</p>
      <p v-if="detail.notes"><b>Observações</b><br />{{ detail.notes }}</p>
      <h3 class="ops-title">Equipes atribuídas</h3>
      <LoadState :loading="detailAssignments.loading.value" :loaded="detailAssignments.loaded.value" :error="detailAssignments.error.value" :empty="detailAssignments.data.value.length === 0" empty-title="Nenhuma equipe atribuída" @retry="detailAssignments.load()">
        <ul class="menu-list">
          <li v-for="item in detailAssignments.data.value" :key="item.id">
            {{ item.team?.name || `Equipe ${item.team?.id}` }}
            <Badge :tone="tone('assignmentStatus', item.status)">{{ label('assignmentStatus', item.status) }}</Badge>
            <small class="stat-hint"> {{ item.status === 'REVOKED' ? `revogada em ${formatDateTime(item.revoked_at)}` : `desde ${formatDateTime(item.assigned_at)}` }}</small>
          </li>
        </ul>
      </LoadState>
      <p class="stat-hint">A empresa é apenas contexto: ela não define as equipes avaliadas.</p>
      <template v-if="canManage" #footer>
        <button class="btn ghost" type="button" @click="openEdit(detail); detail = null">Editar</button>
        <button class="btn" type="button" @click="openAssignments(detail); detail = null">Gerenciar equipes</button>
      </template>
    </Drawer>

    <Empty v-if="!tabs.length" title="Sem acesso" text="Seu perfil não consulta jurados nem critérios." />
  </Page>
</template>
