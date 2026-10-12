<script setup>
import Badge from '../components/Badge.vue'
import ChallengeFormModal from '../components/ChallengeFormModal.vue'
import CompanyFormModal from '../components/CompanyFormModal.vue'
import Empty from '../components/Empty.vue'
import Field from '../components/Field.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import Tabs from '../components/Tabs.vue'
import { DETAIL_TABS, useCompanyDetail } from '@/js/pages/company-detail'

const props = defineProps({
  params: { type: Object, default: () => ({}) },
})
const {
  id, tab, detail, company, reps, challenges, primary, inactive, canManage, canManageChallenges, canSearchPeople,
  companyForm, challengeForm, addChallenge, modal, nextStatus, openStatus, changeStatus, STATUS_HINT,
  rep, selectedRep, personSearch, people, searchPeople, openAddRep, addRep, openEditRep, editRep, togglePrimary, askDeactivate, repStatus,
  go, label, tone, options,
} = useCompanyDetail(props)
</script>

<template>
  <Page v-if="!id" title="Empresa">
    <Empty title="Empresa não informada." text="Abra a empresa a partir da lista.">
      <template #action><button class="btn" type="button" @click="go('empresas')">Voltar</button></template>
    </Empty>
  </Page>
  <Page
    v-else
    :crumbs="`HackLab / Organização / Empresas / ${company?.name || 'Empresa'}`"
    :title="company?.name || 'Empresa'"
    :subtitle="company ? `${label('companyType', company.type)}${company.segment ? ` · ${company.segment}` : ''}` : 'Detalhes da empresa.'"
  >
    <template #actions>
      <button class="btn ghost" type="button" @click="go('empresas')">Voltar</button>
      <template v-if="company">
        <Badge :tone="tone('companyStatus', company.status)">{{ label('companyStatus', company.status) }}</Badge>
        <button v-if="canManage" class="btn ghost" type="button" @click="openStatus">Alterar status</button>
        <button v-if="canManage" class="btn ghost" type="button" @click="companyForm.start(company)">Editar</button>
        <button v-if="canManageChallenges && !inactive" class="btn" type="button" @click="addChallenge">Adicionar desafio</button>
      </template>
    </template>

    <LoadState :loading="detail.loading.value" :loaded="detail.loaded.value" :error="detail.error.value" :empty="detail.loaded.value && !company" empty-title="Empresa não encontrada" @retry="detail.load()">
      <template v-if="company">
        <div v-if="inactive" class="banner warn">Empresa inativa: não recebe novos desafios nem representantes. O histórico é preservado.</div>
        <Tabs :tabs="DETAIL_TABS" :model-value="tab" @update:model-value="tab = $event" />

        <div v-if="tab === 'geral'" class="card">
          <dl class="kv">
            <dt>Razão social</dt><dd>{{ company.legal_name || '—' }}</dd>
            <dt>CNPJ / documento</dt><dd>{{ company.document || '—' }}</dd>
            <dt>Segmento</dt><dd>{{ company.segment || '—' }}</dd>
            <dt>Participação</dt><dd>{{ label('companyType', company.type) }}</dd>
            <dt>E-mail</dt><dd>{{ company.email || '—' }}</dd>
            <dt>Telefone</dt><dd>{{ company.phone || '—' }}</dd>
            <dt>Site</dt><dd>{{ company.website || '—' }}</dd>
            <dt>Representante principal</dt><dd>{{ primary?.person?.full_name || '—' }}</dd>
            <dt>Desafios</dt><dd>{{ company.challenges_count ?? challenges.length }}</dd>
          </dl>
          <p class="company-description">{{ company.description || 'Sem descrição.' }}</p>
        </div>

        <div v-else-if="tab === 'reps'" class="card">
          <div class="row-between">
            <h3>Representantes</h3>
            <button v-if="canManage && !inactive" class="btn small" type="button" @click="openAddRep">+ Adicionar representante</button>
          </div>
          <Empty v-if="reps.length === 0" title="Nenhum representante" text="Representantes são pessoas ligadas à empresa." />
          <div v-else class="table-wrap">
            <table>
              <thead><tr><th>Pessoa</th><th>Cargo</th><th>Principal</th><th>Status</th><th v-if="canManage">Ações</th></tr></thead>
              <tbody>
                <tr v-for="item in reps" :key="item.id">
                  <td>{{ item.person?.full_name || '—' }}<template v-if="item.person?.email || item.person?.phone"><br /><small class="stat-hint">{{ [item.person?.email, item.person?.phone].filter(Boolean).join(' · ') }}</small></template></td>
                  <td>{{ item.title || '—' }}</td>
                  <td><Badge v-if="item.is_primary" tone="info">Principal</Badge><template v-else>—</template></td>
                  <td><Badge :tone="item.active ? 'ok' : 'danger'">{{ item.active ? 'Ativo' : 'Inativo' }}</Badge></td>
                  <td v-if="canManage">
                    <div class="row-actions">
                      <button class="btn ghost small" type="button" @click="openEditRep(item)">Editar</button>
                      <button v-if="item.active" class="btn ghost small" type="button" :disabled="togglePrimary.pending.value" @click="togglePrimary.run(item)">{{ item.is_primary ? 'Desmarcar principal' : 'Tornar principal' }}</button>
                      <button v-if="item.active" class="btn ghost small" type="button" @click="askDeactivate(item)">Desativar</button>
                      <button v-else-if="!inactive" class="btn ghost small" type="button" :disabled="repStatus.pending.value" @click="repStatus.run(item, true)">Reativar</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="stat-hint">Representantes são pessoas ligadas à empresa, não a própria empresa. Não recebem acesso ao HackLab por estarem aqui.</p>
        </div>

        <div v-else-if="tab === 'desafios'" class="card">
          <div class="row-between">
            <h3>Desafios</h3>
            <button v-if="canManageChallenges && !inactive" class="btn small" type="button" @click="addChallenge">+ Adicionar desafio</button>
          </div>
          <Empty v-if="challenges.length === 0" title="Nenhum desafio" text="Os desafios propostos por esta empresa aparecem aqui." />
          <div v-else class="table-wrap">
            <table>
              <thead><tr><th>Desafio</th><th>Equipe</th><th>Status</th><th>Ações</th></tr></thead>
              <tbody>
                <tr v-for="item in challenges" :key="item.id">
                  <td>{{ item.title }}</td>
                  <td>{{ item.team?.name || '—' }}</td>
                  <td><Badge :tone="tone('challengeStatus', item.status)">{{ label('challengeStatus', item.status) }}</Badge></td>
                  <td><button class="btn ghost small" type="button" @click="go(`desafio?id=${item.id}`)">Visualizar</button></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </LoadState>

    <CompanyFormModal v-if="companyForm.open.value" :ctrl="companyForm" />
    <ChallengeFormModal v-if="challengeForm.open.value" :ctrl="challengeForm" />

    <Modal v-if="modal === 'status'" title="Alterar status da empresa" :subtitle="company.name" @close="modal = null">
      <Field label="Novo status" :error="changeStatus.fieldErrors.value.status">
        <select v-model="nextStatus" class="input">
          <option v-for="[code, name] in options('companyStatus')" :key="code" :value="code" :disabled="code === company.status">{{ name }}{{ code === company.status ? ' (atual)' : '' }}</option>
        </select>
      </Field>
      <p :class="nextStatus === 'INACTIVE' ? 'banner warn' : 'stat-hint'">{{ STATUS_HINT[nextStatus] }}</p>
      <template #footer>
        <button class="btn ghost" type="button" :disabled="changeStatus.pending.value" @click="modal = null">Cancelar</button>
        <button class="btn" :class="{ danger: nextStatus === 'INACTIVE' }" type="button" :disabled="changeStatus.pending.value || nextStatus === company.status" @click="changeStatus.run()">
          {{ changeStatus.pending.value ? 'Salvando…' : nextStatus === 'INACTIVE' ? 'Inativar empresa' : 'Confirmar' }}
        </button>
      </template>
    </Modal>

    <Modal v-if="modal === 'rep-add'" title="Adicionar representante" :subtitle="`Pessoa ligada à empresa ${company.name}.`" @close="modal = null">
      <form class="form-grid" novalidate @submit.prevent="addRep.run()">
        <div v-if="canSearchPeople" class="field span-2">
          <span>Pessoa</span>
          <div class="chips">
            <button type="button" class="chip" :class="{ on: rep.personMode === 'new' }" @click="rep.personMode = 'new'">Nova pessoa</button>
            <button type="button" class="chip" :class="{ on: rep.personMode === 'existing' }" @click="rep.personMode = 'existing'">Pessoa já cadastrada</button>
          </div>
        </div>
        <template v-if="rep.personMode === 'new'">
          <Field label="Nome completo" required :error="addRep.fieldErrors.value['person.full_name'] || addRep.fieldErrors.value.person"><input v-model="rep.full_name" class="input" maxlength="255" required /></Field>
          <Field label="E-mail" :error="addRep.fieldErrors.value['person.email']"><input v-model="rep.email" class="input" type="email" maxlength="255" /></Field>
          <Field label="Telefone" :error="addRep.fieldErrors.value['person.phone']"><input v-model="rep.phone" class="input" maxlength="32" /></Field>
          <Field label="Documento" hint="Opcional." :error="addRep.fieldErrors.value['person.document']"><input v-model="rep.document" class="input" maxlength="32" /></Field>
        </template>
        <template v-else>
          <Field label="Buscar pessoa" class-name="span-2" :error="addRep.fieldErrors.value.person_id" hint="Pessoas já vinculadas a esta empresa não aparecem.">
            <input :value="personSearch" class="input" placeholder="Nome ou e-mail" @input="searchPeople($event.target.value)" />
          </Field>
          <div class="span-2 menu-list">
            <p v-if="people.loading.value" class="stat-hint">Buscando…</p>
            <p v-else-if="people.error.value" class="stat-hint">{{ people.error.value.message }}</p>
            <p v-else-if="personSearch && !people.data.value.length" class="stat-hint">Nenhuma pessoa encontrada.</p>
            <button v-for="item in people.data.value" :key="item.id" type="button" class="chip" :class="{ on: rep.person?.id === item.id }" @click="rep.person = item">
              {{ item.full_name }}<template v-if="item.email"> · {{ item.email }}</template>
            </button>
          </div>
        </template>
        <Field label="Cargo" :error="addRep.fieldErrors.value.title"><input v-model="rep.title" class="input" maxlength="120" /></Field>
        <Field label="Principal" :error="addRep.fieldErrors.value.is_primary" :hint="primary ? `Principal atual: ${primary.person?.full_name}. Desmarque-o antes.` : 'A empresa ainda não tem representante principal.'">
          <span class="check"><input v-model="rep.is_primary" type="checkbox" :disabled="Boolean(primary)" /> Representante principal</span>
        </Field>
        <Field label="Observações" class-name="span-2" :error="addRep.fieldErrors.value.notes"><textarea v-model="rep.notes" class="input" maxlength="2000" /></Field>
        <p v-if="addRep.fieldErrors.value.company" class="span-2 field-error">{{ addRep.fieldErrors.value.company }}</p>
      </form>
      <template #footer>
        <button class="btn ghost" type="button" :disabled="addRep.pending.value" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="addRep.pending.value || (rep.personMode === 'existing' && !rep.person)" @click="addRep.run()">{{ addRep.pending.value ? 'Salvando…' : 'Adicionar representante' }}</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'rep-edit'" title="Editar representante" :subtitle="selectedRep.person?.full_name" @close="modal = null">
      <form class="form-grid" novalidate @submit.prevent="editRep.run()">
        <Field label="Cargo" :error="editRep.fieldErrors.value.title"><input v-model="rep.title" class="input" maxlength="120" /></Field>
        <Field label="Principal" :error="editRep.fieldErrors.value.is_primary" :hint="selectedRep.active ? (primary && primary.id !== selectedRep.id ? `Principal atual: ${primary.person?.full_name}. Desmarque-o antes.` : '') : 'Representante inativo não pode ser o principal.'">
          <span class="check"><input v-model="rep.is_primary" type="checkbox" :disabled="!selectedRep.active || Boolean(primary && primary.id !== selectedRep.id)" /> Representante principal</span>
        </Field>
        <Field label="Observações" class-name="span-2" :error="editRep.fieldErrors.value.notes"><textarea v-model="rep.notes" class="input" maxlength="2000" /></Field>
        <p class="span-2 stat-hint">Nome, e-mail e telefone pertencem ao cadastro da pessoa.</p>
      </form>
      <template #footer>
        <button class="btn ghost" type="button" :disabled="editRep.pending.value" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="editRep.pending.value" @click="editRep.run()">{{ editRep.pending.value ? 'Salvando…' : 'Salvar alterações' }}</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'rep-off'" title="Desativar representante?" subtitle="O vínculo fica no histórico e pode ser reativado depois." @close="modal = null">
      <p>{{ selectedRep.person?.full_name }} deixa de ser representante ativo de {{ company.name }}.<template v-if="selectedRep.is_primary"> A marca de principal também é removida.</template></p>
      <template #footer>
        <button class="btn ghost" type="button" :disabled="repStatus.pending.value" @click="modal = null">Cancelar</button>
        <button class="btn danger" type="button" :disabled="repStatus.pending.value" @click="repStatus.run(selectedRep, false)">{{ repStatus.pending.value ? 'Salvando…' : 'Desativar' }}</button>
      </template>
    </Modal>
  </Page>
</template>
