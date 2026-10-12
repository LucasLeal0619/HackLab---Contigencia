<script setup>
import Badge from '../components/Badge.vue'
import Drawer from '../components/Drawer.vue'
import Field from '../components/Field.vue'
import FilterPanel from '../components/FilterPanel.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import Pager from '../components/Pager.vue'
import Search from '../components/Search.vue'
import Tabs from '../components/Tabs.vue'
import { usePeople } from '@/js/pages/people'

const {
  tab, tabs, list, classes, teams, activeClasses, activeTeams, ensureSelectors, menu, viewingId, detail, history, openDetail, closeDetail,
  modal, form, editing, personSearch, people, searchPeople, openCreate, openEdit, save, update,
  statusTarget, statusAlsoRemove, askStatus, changeStatus, moveTeamId, assignTeam, askRemoveTeam, removeFromTeam, openTeam,
  classList, classForm, classEditing, classTarget, openClassCreate, openClassEdit, saveClass, askClassStatus, toggleClass, closeModal,
  canManage, canClasses, canManageClasses, canTeams, canManageTeams, canPeople, canEditPerson, noteFor, label, tone, options, formatDateTime,
} = usePeople()
const { filters, page, items, meta, loading, error, loaded } = list
const statusVerb = { AVAILABLE: 'Marcar como disponível', UNAVAILABLE: 'Marcar como indisponível', WITHDRAWN: 'Marcar como desistente' }
</script>

<template>
  <Page title="Participantes" subtitle="Gerencie os participantes do Hackathon e as turmas do evento.">
    <template #actions>
      <template v-if="tab === 'participantes'">
        <button v-if="canManage" class="btn" type="button" @click="openCreate">+ Cadastrar participante</button>
        <Badge v-else>Consulta</Badge>
      </template>
      <template v-else>
        <button v-if="canManageClasses" class="btn" type="button" @click="openClassCreate">+ Nova turma</button>
        <Badge v-else>Consulta</Badge>
      </template>
    </template>

    <Tabs v-if="tabs.length > 1" v-model="tab" :tabs="tabs" />

    <!-- Participantes -->
    <template v-if="tab === 'participantes'">
      <FilterPanel :active="list.activeFilters.value" @clear="list.reset()">
        <template #search><Search v-model="filters.search" placeholder="Buscar por nome ou e-mail" /></template>
        <select v-if="canClasses" v-model="filters.class_id" class="input" aria-label="Turma" @focus="ensureSelectors">
          <option value="">Todas as turmas</option>
          <option v-for="item in classes.data.value" :key="item.id" :value="item.id">{{ item.name }}{{ item.active ? '' : ' (inativa)' }}</option>
        </select>
        <select v-model="filters.status" class="input" aria-label="Status">
          <option value="">Todos os status</option>
          <option v-for="[code, name] in options('participantStatus')" :key="code" :value="code">{{ name }}</option>
        </select>
        <select v-model="filters.team" class="input" aria-label="Equipe" @focus="ensureSelectors">
          <option value="">Todas as equipes</option>
          <option value="none">Sem equipe</option>
          <option value="any">Com equipe</option>
          <option v-for="item in teams.data.value" :key="item.id" :value="item.id">{{ item.name }}</option>
        </select>
      </FilterPanel>

      <LoadState
        :loading="loading" :loaded="loaded" :error="error" :empty="items.length === 0"
        :empty-title="list.activeFilters.value ? 'Nenhum participante encontrado' : 'Nenhum participante cadastrado ainda.'"
        :empty-text="list.activeFilters.value ? 'Ajuste a busca ou limpe os filtros.' : 'Cadastre os participantes para começar a formação das equipes.'"
        @retry="list.load()"
      >
        <template v-if="!list.activeFilters.value && canManage" #empty-action>
          <button class="btn" type="button" @click="openCreate">+ Cadastrar participante</button>
        </template>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Participante</th><th>Turma</th><th>Equipe</th><th>Status</th><th>Ações</th></tr></thead>
            <tbody>
              <tr v-for="item in items" :key="item.id">
                <td>{{ item.person?.full_name || '—' }}<br v-if="item.person?.email" /><small v-if="item.person?.email" class="stat-hint">{{ item.person.email }}</small></td>
                <td>{{ item.class?.name || '—' }}</td>
                <td>{{ item.team?.name || '—' }}</td>
                <td>
                  <Badge :tone="tone('participantStatus', item.status)">{{ label('participantStatus', item.status) }}</Badge><br />
                  <small>{{ noteFor(item) }}</small>
                </td>
                <td>
                  <div class="row-actions">
                    <button class="btn ghost small" type="button" @click="openDetail(item)">Visualizar</button>
                    <button v-if="canManage" class="btn ghost small" type="button" @click="openEdit(item)">Editar</button>
                    <span v-if="canManage || canTeams" class="row-menu">
                      <button class="btn ghost small" type="button" title="Mais opções" aria-label="Mais opções" :aria-expanded="menu === item.id" @mousedown.stop @click="menu = menu === item.id ? '' : item.id">⋯</button>
                      <div v-if="menu === item.id" class="menu-pop" @mousedown.stop>
                        <button v-if="canTeams" type="button" @click="openTeam(item)">{{ item.team ? 'Abrir equipe' : 'Ver equipes' }}</button>
                        <template v-if="canManage">
                          <button v-for="code in ['AVAILABLE', 'UNAVAILABLE', 'WITHDRAWN'].filter((value) => value !== item.status)" :key="code" type="button" @click="askStatus(item, code)">{{ statusVerb[code] }}</button>
                        </template>
                      </div>
                    </span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <Pager :meta="meta" :disabled="loading" @page="page = $event" />
      </LoadState>
    </template>

    <!-- Turmas -->
    <template v-else-if="tab === 'turmas' && canClasses">
      <FilterPanel :active="classList.activeFilters.value" @clear="classList.reset()">
        <select v-model="classList.filters.active" class="input" aria-label="Status da turma">
          <option value="">Todas as turmas</option>
          <option value="true">Ativas</option>
          <option value="false">Inativas</option>
        </select>
      </FilterPanel>
      <LoadState
        :loading="classList.loading.value" :loaded="classList.loaded.value" :error="classList.error.value" :empty="classList.items.value.length === 0"
        empty-title="Nenhuma turma encontrada" :empty-text="canManageClasses ? 'Cadastre as turmas para organizar os participantes.' : ''"
        @retry="classList.load()"
      >
        <template v-if="canManageClasses && !classList.activeFilters.value" #empty-action>
          <button class="btn" type="button" @click="openClassCreate">+ Nova turma</button>
        </template>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Turma</th><th>Participantes</th><th>Status</th><th v-if="canManageClasses">Ações</th></tr></thead>
            <tbody>
              <tr v-for="item in classList.items.value" :key="item.id">
                <td>{{ item.name }}</td>
                <td>{{ item.participants_count ?? '—' }}</td>
                <td><Badge :tone="item.active ? 'ok' : 'danger'">{{ item.active ? 'Ativa' : 'Inativa' }}</Badge></td>
                <td v-if="canManageClasses">
                  <div class="row-actions">
                    <button class="btn ghost small" type="button" @click="openClassEdit(item)">Renomear</button>
                    <button class="btn ghost small" type="button" @click="askClassStatus(item)">{{ item.active ? 'Inativar' : 'Ativar' }}</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <Pager :meta="classList.meta.value" :disabled="classList.loading.value" @page="classList.page.value = $event" />
      </LoadState>
    </template>

    <!-- Detalhe do participante -->
    <Drawer v-if="viewingId && detail.data.value" :title="detail.data.value.person?.full_name || 'Participante'" :subtitle="label('participantStatus', detail.data.value.status)" @close="closeDetail">
      <LoadState :loading="detail.loading.value" :loaded="detail.loaded.value" :error="detail.error.value" @retry="detail.load()">
        <dl class="kv">
          <dt>Status</dt><dd><Badge :tone="tone('participantStatus', detail.data.value.status)">{{ label('participantStatus', detail.data.value.status) }}</Badge> <small class="stat-hint">{{ noteFor(detail.data.value) }}</small></dd>
          <dt>Turma</dt><dd>{{ detail.data.value.class?.name || '—' }}<template v-if="detail.data.value.class && !detail.data.value.class.active"> (inativa)</template></dd>
          <dt>Equipe</dt><dd>{{ detail.data.value.team?.name || 'Ainda sem equipe.' }}</dd>
          <dt>E-mail</dt><dd>{{ detail.data.value.person?.email || '—' }}</dd>
          <dt>Telefone</dt><dd>{{ detail.data.value.person?.phone || '—' }}</dd>
          <dt>Observação</dt><dd>{{ detail.data.value.notes || '—' }}</dd>
          <dt>Cadastrado em</dt><dd>{{ formatDateTime(detail.data.value.created_at) }}</dd>
        </dl>

        <template v-if="canManageTeams">
          <h3 class="mt">Equipe</h3>
          <p v-if="detail.data.value.status !== 'AVAILABLE'" class="stat-hint">Só participantes disponíveis entram ou mudam de equipe.</p>
          <p v-else-if="teams.error.value" class="stat-hint">Não foi possível carregar as equipes. <button class="btn ghost small" type="button" :disabled="teams.loading.value" @click="teams.load()">Tentar novamente</button></p>
          <div v-else class="participant-team-move">
            <Field :label="detail.data.value.team ? 'Mover para a equipe' : 'Colocar na equipe'" :error="assignTeam.fieldErrors.value.team_id || assignTeam.fieldErrors.value.participant_id">
              <select v-model="moveTeamId" class="input">
                <option value="">Selecione</option>
                <option v-for="item in activeTeams.filter((team) => team.id !== detail.data.value.team?.id)" :key="item.id" :value="item.id">{{ item.name }}</option>
              </select>
            </Field>
            <button class="btn small" type="button" :disabled="!moveTeamId || assignTeam.pending.value" @click="assignTeam.run()">{{ assignTeam.pending.value ? 'Salvando…' : (detail.data.value.team ? 'Mover' : 'Adicionar') }}</button>
          </div>
          <button v-if="detail.data.value.team" class="btn ghost small" type="button" @click="askRemoveTeam">Remover da equipe {{ detail.data.value.team.name }}</button>
        </template>

        <h3 class="mt">Histórico de equipes</h3>
        <p v-if="!history.length" class="stat-hint">Nenhuma passagem por equipe registrada.</p>
        <ul v-else class="participant-history">
          <li v-for="entry in history" :key="entry.id">
            <b>{{ entry.team?.name || 'Equipe' }}</b>
            <Badge :tone="entry.active ? 'ok' : ''">{{ entry.active ? 'Atual' : 'Encerrada' }}</Badge>
            <small class="stat-hint">Entrada {{ formatDateTime(entry.joined_at) }}<template v-if="entry.left_at"> · saída {{ formatDateTime(entry.left_at) }}</template></small>
          </li>
        </ul>

        <template v-if="canManage">
          <h3 class="mt">Status</h3>
          <div class="chips">
            <button v-for="code in ['AVAILABLE', 'UNAVAILABLE', 'WITHDRAWN'].filter((value) => value !== detail.data.value.status)" :key="code" class="btn small" :class="code === 'WITHDRAWN' ? 'danger' : 'ghost'" type="button" @click="askStatus(detail.data.value, code)">{{ statusVerb[code] }}</button>
          </div>
        </template>
      </LoadState>
      <template #footer>
        <button class="btn ghost" type="button" @click="closeDetail">Fechar</button>
        <button v-if="canManage" class="btn" type="button" @click="openEdit(detail.data.value)">Editar</button>
      </template>
    </Drawer>

    <!-- Cadastro -->
    <Modal v-if="modal === 'create'" title="Cadastrar participante" subtitle="O participante é uma pessoa do HackLab: cadastre uma nova ou escolha uma pessoa já existente." @close="closeModal">
      <form class="form-grid" novalidate @submit.prevent="save.run()">
        <div v-if="canPeople" class="field span-2">
          <span>Pessoa</span>
          <div class="chips">
            <button type="button" class="chip" :class="{ on: form.personMode === 'new' }" @click="form.personMode = 'new'">Nova pessoa</button>
            <button type="button" class="chip" :class="{ on: form.personMode === 'existing' }" @click="form.personMode = 'existing'">Pessoa já cadastrada</button>
          </div>
        </div>
        <template v-if="form.personMode === 'new'">
          <Field label="Nome completo" required class-name="span-2" :error="save.fieldErrors.value['person.full_name'] || save.fieldErrors.value.person"><input v-model="form.full_name" class="input" required /></Field>
          <Field label="E-mail" hint="Opcional" :error="save.fieldErrors.value['person.email']"><input v-model="form.email" class="input" type="email" /></Field>
          <Field label="Telefone" hint="Opcional" :error="save.fieldErrors.value['person.phone']"><input v-model="form.phone" class="input" /></Field>
          <Field label="Documento / matrícula" hint="Opcional" :error="save.fieldErrors.value['person.document']"><input v-model="form.document" class="input" /></Field>
        </template>
        <template v-else>
          <Field label="Buscar pessoa" class-name="span-2" :error="save.fieldErrors.value.person_id" hint="Uma pessoa só pode ser participante uma vez por evento.">
            <input :value="personSearch" class="input" placeholder="Nome ou e-mail" @input="searchPeople($event.target.value)" />
          </Field>
          <div class="span-2 menu-list">
            <p v-if="people.loading.value" class="stat-hint">Buscando…</p>
            <p v-else-if="people.error.value" class="stat-hint">{{ people.error.value.message }}</p>
            <p v-else-if="personSearch && !people.data.value.length" class="stat-hint">Nenhuma pessoa encontrada.</p>
            <div class="chips">
              <button v-for="item in people.data.value" :key="item.id" type="button" class="chip" :class="{ on: form.person?.id === item.id }" @click="form.person = item">
                {{ item.full_name }}<template v-if="item.email"> · {{ item.email }}</template>
              </button>
            </div>
          </div>
        </template>
        <Field label="Turma" :error="save.fieldErrors.value.class_id" :hint="canClasses ? (activeClasses.length ? 'Somente turmas ativas.' : 'Nenhuma turma ativa cadastrada.') : ''">
          <select v-model="form.class_id" class="input">
            <option value="">Sem turma</option>
            <option v-for="item in activeClasses" :key="item.id" :value="item.id">{{ item.name }}</option>
          </select>
        </Field>
        <Field label="Status" :error="save.fieldErrors.value.status">
          <select v-model="form.status" class="input">
            <option v-for="[code, name] in options('participantStatus')" :key="code" :value="code">{{ name }}</option>
          </select>
        </Field>
        <Field label="Observação" class-name="span-2" :error="save.fieldErrors.value.notes"><textarea v-model="form.notes" class="input" rows="2" maxlength="2000" /></Field>
      </form>
      <template #footer>
        <button class="btn ghost" type="button" @click="closeModal">Cancelar</button>
        <button class="btn" type="button" :disabled="save.pending.value || (form.personMode === 'existing' && !form.person)" @click="save.run()">{{ save.pending.value ? 'Salvando…' : 'Cadastrar participante' }}</button>
      </template>
    </Modal>

    <!-- Edição -->
    <Modal v-if="modal === 'edit' && editing" title="Editar participante" :subtitle="editing.person?.full_name" @close="closeModal">
      <form class="form-grid" novalidate @submit.prevent="update.run()">
        <template v-if="canEditPerson">
          <Field label="Nome completo" required class-name="span-2" :error="update.fieldErrors.value.full_name"><input v-model="form.full_name" class="input" /></Field>
          <Field label="E-mail" :error="update.fieldErrors.value.email"><input v-model="form.email" class="input" type="email" /></Field>
          <Field label="Telefone" :error="update.fieldErrors.value.phone"><input v-model="form.phone" class="input" /></Field>
        </template>
        <Field label="Turma" :error="update.fieldErrors.value.class_id">
          <select v-model="form.class_id" class="input">
            <option value="">Sem turma</option>
            <option v-if="editing.class && !editing.class.active" :value="editing.class.id">{{ editing.class.name }} (inativa)</option>
            <option v-for="item in activeClasses" :key="item.id" :value="item.id">{{ item.name }}</option>
          </select>
        </Field>
        <Field label="Status" hint="Use as ações de status para alterar.">
          <input class="input" :value="label('participantStatus', editing.status)" disabled />
        </Field>
        <Field label="Observação" class-name="span-2" :error="update.fieldErrors.value.notes"><textarea v-model="form.notes" class="input" rows="2" maxlength="2000" /></Field>
      </form>
      <template #footer>
        <button class="btn ghost" type="button" @click="closeModal">Cancelar</button>
        <button class="btn" type="button" :disabled="update.pending.value" @click="update.run()">{{ update.pending.value ? 'Salvando…' : 'Salvar alterações' }}</button>
      </template>
    </Modal>

    <!-- Confirmação de status -->
    <Modal
      v-if="modal === 'status' && statusTarget"
      :title="statusTarget.status === 'WITHDRAWN' ? 'Marcar como desistente?' : `${statusVerb[statusTarget.status]}?`"
      :subtitle="statusTarget.item.person?.full_name"
      @close="closeModal"
    >
      <p v-if="statusTarget.status === 'WITHDRAWN'">O participante passa a constar como <b>Desistente</b>. O cadastro e o histórico continuam registrados; é possível reverter depois.</p>
      <p v-else>O status passa de <b>{{ label('participantStatus', statusTarget.item.status) }}</b> para <b>{{ label('participantStatus', statusTarget.status) }}</b>.</p>
      <template v-if="statusTarget.item.team && statusTarget.status !== 'AVAILABLE'">
        <p class="banner warn">Mudar o status não tira o participante da equipe <b>{{ statusTarget.item.team.name }}</b>.</p>
        <label v-if="canManageTeams" class="check">
          <input v-model="statusAlsoRemove" type="checkbox" /> Remover também da equipe {{ statusTarget.item.team.name }} (o histórico é preservado)
        </label>
      </template>
      <template #footer>
        <button class="btn ghost" type="button" @click="closeModal">Cancelar</button>
        <button class="btn" :class="{ danger: statusTarget.status === 'WITHDRAWN' }" type="button" :disabled="changeStatus.pending.value" @click="changeStatus.run()">{{ changeStatus.pending.value ? 'Salvando…' : 'Confirmar' }}</button>
      </template>
    </Modal>

    <!-- Remover da equipe -->
    <Modal v-if="modal === 'removeTeam' && detail.data.value?.team" title="Remover da equipe?" :subtitle="`${detail.data.value.person?.full_name} sai da equipe ${detail.data.value.team.name}.`" @close="closeModal">
      <p>O vínculo é encerrado e fica no histórico do participante. Ele pode ser colocado em outra equipe depois.</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="closeModal">Cancelar</button>
        <button class="btn danger" type="button" :disabled="removeFromTeam.pending.value" @click="removeFromTeam.run()">{{ removeFromTeam.pending.value ? 'Salvando…' : 'Remover da equipe' }}</button>
      </template>
    </Modal>

    <!-- Turma: criar / renomear -->
    <Modal v-if="modal === 'class'" :title="classEditing ? 'Renomear turma' : 'Nova turma'" subtitle="O nome da turma é único no evento." @close="closeModal">
      <form novalidate @submit.prevent="saveClass.run()">
        <Field label="Nome da turma" required :error="saveClass.fieldErrors.value.name"><input v-model="classForm.name" class="input" maxlength="120" required /></Field>
      </form>
      <template #footer>
        <button class="btn ghost" type="button" @click="closeModal">Cancelar</button>
        <button class="btn" type="button" :disabled="saveClass.pending.value" @click="saveClass.run()">{{ saveClass.pending.value ? 'Salvando…' : (classEditing ? 'Salvar' : 'Cadastrar turma') }}</button>
      </template>
    </Modal>

    <!-- Turma: ativar / inativar -->
    <Modal v-if="modal === 'classStatus' && classTarget" :title="classTarget.active ? 'Inativar turma?' : 'Ativar turma?'" :subtitle="classTarget.name" @close="closeModal">
      <p v-if="classTarget.active">A turma deixa de aparecer para novos cadastros. Os participantes que já estão nela continuam vinculados.</p>
      <p v-else>A turma volta a poder receber participantes.</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="closeModal">Cancelar</button>
        <button class="btn" :class="{ danger: classTarget.active }" type="button" :disabled="toggleClass.pending.value" @click="toggleClass.run()">{{ toggleClass.pending.value ? 'Salvando…' : (classTarget.active ? 'Inativar' : 'Ativar') }}</button>
      </template>
    </Modal>
  </Page>
</template>
