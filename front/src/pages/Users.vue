<script setup>
import Badge from '../components/Badge.vue'
import Field from '../components/Field.vue'
import FilterPanel from '../components/FilterPanel.vue'
import Icon from '../components/Icon.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import Pager from '../components/Pager.vue'
import Search from '../components/Search.vue'
import { useUsers } from '@/js/pages/users'

const {
  list, roles, activeSectors, roleOptions, matrix, modal, selected, form, showPassword, needsSector, personSearch, people, searchPeople,
  canManage, canRoles, openCreate, openDetail, openEdit, save, update, toggleStatus, label, tone, options, formatDateTime,
} = useUsers()
const { filters, page, items, meta, loading, error, loaded } = list
</script>

<template>
  <Page title="Usuários e Permissões" subtitle="Contas de acesso ao HackLab. Cada conta pertence a uma pessoa cadastrada.">
    <template #actions>
      <button v-if="canRoles" class="btn ghost" type="button" @click="modal = 'matriz'">Ver matriz de acesso</button>
      <button v-if="canManage" class="btn" type="button" @click="openCreate">Novo usuário</button>
      <Badge v-else>Consulta</Badge>
    </template>

    <FilterPanel :active="list.activeFilters.value" @clear="list.reset()">
      <template #search><Search v-model="filters.search" placeholder="Buscar por nome ou e-mail" /></template>
      <select v-model="filters.role" class="input" aria-label="Perfil">
        <option value="">Todos os perfis</option>
        <option v-for="[code, name] in roleOptions" :key="code" :value="code">{{ name }}</option>
      </select>
      <select v-model="filters.status" class="input" aria-label="Status">
        <option value="">Todos os status</option>
        <option v-for="[code, name] in options('activeStatus')" :key="code" :value="code">{{ name }}</option>
      </select>
      <select v-model="filters.sector_id" class="input" aria-label="Setor">
        <option value="">Todos os setores</option>
        <option v-for="item in activeSectors" :key="item.id" :value="item.id">{{ item.name }}</option>
      </select>
    </FilterPanel>

    <LoadState :loading="loading" :loaded="loaded" :error="error" :empty="items.length === 0" empty-title="Nenhum usuário encontrado" @retry="list.load()">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Usuário</th><th>Perfil</th><th>Setor</th><th>Último acesso</th><th>Status</th><th>Ações</th></tr></thead>
          <tbody>
            <tr v-for="item in items" :key="item.id">
              <td>{{ item.person?.full_name || '—' }}<br /><small class="stat-hint">{{ item.email }}</small></td>
              <td>{{ item.role?.name || label('role', item.role?.code) }}</td>
              <td>{{ item.sector?.name || '—' }}</td>
              <td>{{ formatDateTime(item.last_login_at) }}</td>
              <td><Badge :tone="tone('activeStatus', item.status)">{{ label('activeStatus', item.status) }}</Badge></td>
              <td>
                <button class="btn ghost small" type="button" @click="openDetail(item)">Ver</button>
                <button v-if="canManage" class="btn ghost small" type="button" @click="openEdit(item)">Editar</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pager :meta="meta" :disabled="loading" @page="page = $event" />
    </LoadState>

    <Modal v-if="modal === 'matriz'" title="Matriz de acesso" subtitle="Permissões efetivas de cada perfil, conforme o backend." wide @close="modal = null">
      <LoadState :loading="roles.loading.value" :loaded="roles.loaded.value" :error="roles.error.value" :empty="matrix.length === 0" @retry="roles.load()">
        <div class="table-wrap">
          <table class="matrix">
            <thead><tr><th>Módulo</th><th>Permissão</th><th v-for="role in roles.data.value" :key="role.code">{{ role.name }}</th></tr></thead>
            <tbody>
              <tr v-for="row in matrix" :key="row.code">
                <td>{{ row.module }}</td>
                <td><code>{{ row.code }}</code></td>
                <td v-for="(on, index) in row.cells" :key="index">{{ on ? 'Sim' : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </LoadState>
      <template #footer><button class="btn" type="button" @click="modal = null">Fechar</button></template>
    </Modal>

    <Modal v-if="modal === 'create'" title="Novo usuário" subtitle="A conta é ligada a uma pessoa: cadastre uma nova ou escolha uma pessoa já existente sem conta." @close="modal = null">
      <form class="form-grid" novalidate @submit.prevent="save.run()">
        <div class="field span-2">
          <span>Pessoa</span>
          <div class="chips">
            <button type="button" class="chip" :class="{ on: form.personMode === 'new' }" @click="form.personMode = 'new'">Nova pessoa</button>
            <button type="button" class="chip" :class="{ on: form.personMode === 'existing' }" @click="form.personMode = 'existing'">Pessoa já cadastrada</button>
          </div>
        </div>
        <template v-if="form.personMode === 'new'">
          <Field label="Nome completo" required :error="save.fieldErrors.value['person.full_name']"><input v-model="form.full_name" class="input" required /></Field>
          <Field label="Telefone" :error="save.fieldErrors.value['person.phone']"><input v-model="form.phone" class="input" /></Field>
          <Field label="Documento" hint="Opcional." :error="save.fieldErrors.value['person.document']"><input v-model="form.document" class="input" /></Field>
        </template>
        <template v-else>
          <Field label="Buscar pessoa" class-name="span-2" :error="save.fieldErrors.value.person_id" hint="Só aparecem pessoas que ainda não têm conta.">
            <input :value="personSearch" class="input" placeholder="Nome ou e-mail" @input="searchPeople($event.target.value)" />
          </Field>
          <div class="span-2 menu-list">
            <p v-if="people.loading.value" class="stat-hint">Buscando…</p>
            <p v-else-if="people.error.value" class="stat-hint">{{ people.error.value.message }}</p>
            <p v-else-if="personSearch && !people.data.value.length" class="stat-hint">Nenhuma pessoa sem conta encontrada.</p>
            <button v-for="item in people.data.value" :key="item.id" type="button" class="chip" :class="{ on: form.person?.id === item.id }" @click="form.person = item; if (!form.email && item.email) form.email = item.email">
              {{ item.full_name }}<template v-if="item.email"> · {{ item.email }}</template>
            </button>
          </div>
        </template>
        <Field label="E-mail de acesso" required :error="save.fieldErrors.value.email"><input v-model="form.email" class="input" type="email" autocomplete="off" required /></Field>
        <Field label="Perfil de acesso" required :error="save.fieldErrors.value.role">
          <select v-model="form.role" class="input">
            <option v-for="[code, name] in roleOptions" :key="code" :value="code">{{ name }}</option>
          </select>
        </Field>
        <Field v-if="needsSector" label="Setor" required :error="save.fieldErrors.value.sector_id" hint="Gestor e Editor atuam só no próprio setor.">
          <select v-model="form.sector_id" class="input">
            <option value="">Selecione</option>
            <option v-for="item in activeSectors" :key="item.id" :value="item.id">{{ item.name }}</option>
          </select>
        </Field>
        <Field label="Status" :error="save.fieldErrors.value.status">
          <select v-model="form.status" class="input">
            <option v-for="[code, name] in options('activeStatus')" :key="code" :value="code">{{ name }}</option>
          </select>
        </Field>
        <Field label="Senha" required class-name="span-2" hint="Mínimo de 8 caracteres, com letras e números." :error="save.fieldErrors.value.password">
          <div class="input-icon">
            <Icon name="lock" :size="16" />
            <input v-model="form.password" class="input" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" required />
            <button type="button" class="eye" :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'" @click="showPassword = !showPassword"><Icon name="eye" :size="16" /></button>
          </div>
        </Field>
      </form>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="save.pending.value" @click="save.run()">{{ save.pending.value ? 'Salvando…' : 'Cadastrar usuário' }}</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'edit'" title="Editar usuário" :subtitle="selected.email" @close="modal = null">
      <form class="form-grid" novalidate @submit.prevent="update.run()">
        <Field label="Nome completo" required :error="update.fieldErrors.value.full_name"><input v-model="form.full_name" class="input" /></Field>
        <Field label="Telefone" :error="update.fieldErrors.value.phone"><input v-model="form.phone" class="input" /></Field>
        <Field label="Documento" :error="update.fieldErrors.value.document"><input v-model="form.document" class="input" /></Field>
        <Field label="E-mail de acesso" required :error="update.fieldErrors.value.email"><input v-model="form.email" class="input" type="email" autocomplete="off" /></Field>
        <Field label="Perfil de acesso" required :error="update.fieldErrors.value.role">
          <select v-model="form.role" class="input">
            <option v-for="[code, name] in roleOptions" :key="code" :value="code">{{ name }}</option>
          </select>
        </Field>
        <Field v-if="needsSector" label="Setor" required :error="update.fieldErrors.value.sector_id">
          <select v-model="form.sector_id" class="input">
            <option value="">Selecione</option>
            <option v-for="item in activeSectors" :key="item.id" :value="item.id">{{ item.name }}</option>
          </select>
        </Field>
        <Field label="Status" :error="update.fieldErrors.value.status">
          <select v-model="form.status" class="input">
            <option v-for="[code, name] in options('activeStatus')" :key="code" :value="code">{{ name }}</option>
          </select>
        </Field>
        <Field label="Nova senha" class-name="span-2" hint="Deixe em branco para manter a senha atual." :error="update.fieldErrors.value.password">
          <div class="input-icon">
            <Icon name="lock" :size="16" />
            <input v-model="form.password" class="input" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" />
            <button type="button" class="eye" :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'" @click="showPassword = !showPassword"><Icon name="eye" :size="16" /></button>
          </div>
        </Field>
      </form>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="update.pending.value" @click="update.run()">{{ update.pending.value ? 'Salvando…' : 'Salvar alterações' }}</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'detail'" title="Detalhes do usuário" :subtitle="selected.email" @close="modal = null">
      <p>
        <b>Nome</b> {{ selected.person?.full_name || '—' }}<br />
        <b>Perfil</b> {{ selected.role?.name || label('role', selected.role?.code) }}<br />
        <b>Setor</b> {{ selected.sector?.name || '—' }}<br />
        <b>Último acesso</b> {{ formatDateTime(selected.last_login_at) }}<br />
        <b>Status</b> <Badge :tone="tone('activeStatus', selected.status)">{{ label('activeStatus', selected.status) }}</Badge>
      </p>
      <p v-if="selected.status === 'INACTIVE'">Este usuário não tem acesso ao HackLab enquanto estiver inativo.</p>
      <button v-if="canManage && selected.status === 'ACTIVE'" class="btn danger small" type="button" @click="modal = 'off'">Inativar usuário</button>
      <button v-if="canManage && selected.status === 'INACTIVE'" class="btn small" type="button" :disabled="toggleStatus.pending.value" @click="toggleStatus.run(selected, 'ACTIVE')">Ativar usuário</button>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Fechar</button>
        <button v-if="canManage" class="btn" type="button" @click="openEdit(selected)">Editar usuário</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'off'" title="Inativar usuário?" subtitle="O usuário deixa de ter acesso ao HackLab; o cadastro e o histórico continuam registrados." @close="modal = 'detail'">
      <p>{{ selected.person?.full_name || selected.email }} fica inativo e não consegue entrar no HackLab.</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = 'detail'">Cancelar</button>
        <button class="btn danger" type="button" :disabled="toggleStatus.pending.value" @click="toggleStatus.run(selected, 'INACTIVE')">Inativar</button>
      </template>
    </Modal>
  </Page>
</template>
