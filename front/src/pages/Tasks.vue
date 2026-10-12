<script setup>
import Badge from '../components/Badge.vue'
import DemandActivity from '../components/DemandActivity.vue'
import Drawer from '../components/Drawer.vue'
import Field from '../components/Field.vue'
import FilterPanel from '../components/FilterPanel.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import Pager from '../components/Pager.vue'
import Search from '../components/Search.vue'
import { useTasks } from '@/js/pages/tasks'

const props = defineProps({
  params: { type: Object, default: () => ({}) },
})
const {
  list, detailId, detail, task, abilities, closed, refresh, form, openNew, openEdit, chooseOrigin, chooseResponsible, chooseInvolved,
  involvedOptions, toggleInvolved, sectorNameById, save, acting, openAction, runAction, setStatus, openOccurrence, dueInfo, DUE_FILTERS,
  canCreate, globalScope, allSectors, activeSectors, assignees, assigneesLoading, label, tone, options, formatDateTime,
} = useTasks(props)
const { filters, page, items, meta, loading, error, loaded } = list
</script>

<template>
  <Page crumbs="Gestão / Pendências" title="Pendências" :subtitle="globalScope ? 'Acompanhe o que precisa ser feito pelos setores da organização.' : 'Pendências do seu setor e das demandas em que ele está envolvido.'">
    <template #actions>
      <button v-if="canCreate" class="btn" type="button" @click="openNew()">+ Nova pendência</button>
      <Badge v-else>Consulta</Badge>
    </template>

    <FilterPanel class="task-filters" :active="list.activeFilters.value - (filters.search ? 1 : 0)" @clear="list.reset()">
      <template #search><Search v-model="filters.search" placeholder="Buscar por título ou código" /></template>
      <select v-model="filters.responsible_sector_id" class="input" aria-label="Setor responsável">
        <option value="">Setor responsável</option>
        <option v-for="item in allSectors" :key="item.id" :value="item.id">{{ item.name }}</option>
      </select>
      <select v-model="filters.involved_sector_id" class="input" aria-label="Setor envolvido">
        <option value="">Setor envolvido</option>
        <option v-for="item in allSectors" :key="item.id" :value="item.id">{{ item.name }}</option>
      </select>
      <select v-model="filters.status" class="input" aria-label="Status">
        <option value="">Status</option>
        <option v-for="[code, name] in options('taskStatus')" :key="code" :value="code">{{ name }}</option>
      </select>
      <select v-model="filters.priority" class="input" aria-label="Prioridade">
        <option value="">Prioridade</option>
        <option v-for="[code, name] in options('priority')" :key="code" :value="code">{{ name }}</option>
      </select>
      <select v-model="filters.prazo" class="input" aria-label="Prazo">
        <option value="">Prazo</option>
        <option v-for="[code, name] in DUE_FILTERS" :key="code" :value="code">{{ name }}</option>
      </select>
    </FilterPanel>
    <p v-if="loaded" class="task-summary">Total <b>{{ meta.total }}</b></p>

    <LoadState :loading="loading" :loaded="loaded" :error="error" :empty="items.length === 0" :empty-title="list.activeFilters.value ? 'Nenhuma pendência para estes filtros.' : 'Nenhuma pendência registrada.'" empty-text="Pendências são o que ainda precisa ser feito. Fatos e problemas ocorridos ficam em Ocorrências." @retry="list.load()">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Pendência</th><th>Setor</th><th>Responsável</th><th>Prazo</th><th>Status</th><th>Ações</th></tr></thead>
          <tbody>
            <tr v-for="item in items" :key="item.id">
              <td>{{ item.title }} <Badge :tone="tone('priority', item.priority)">{{ label('priority', item.priority) }}</Badge><br /><small class="stat-hint">{{ item.reference }}</small></td>
              <td>{{ item.responsible_sector?.name || '—' }}</td>
              <td>{{ item.assigned_user?.name || 'Não definido' }}</td>
              <td>
                <span :class="`due ${dueInfo(item).tone}`">{{ dueInfo(item).label }}</span>
                <template v-if="item.due_at"><br /><small>{{ formatDateTime(item.due_at) }}</small></template>
              </td>
              <td><Badge :tone="tone('taskStatus', item.status)">{{ label('taskStatus', item.status) }}</Badge></td>
              <td>
                <div class="row-actions">
                  <button class="btn ghost small" type="button" @click="detailId = item.id">Visualizar</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pager :meta="meta" :disabled="loading" @page="page = $event" />
    </LoadState>

    <Drawer v-if="detailId" :title="task?.title || 'Pendência'" :subtitle="task ? `${task.reference} · ${label('taskStatus', task.status)}` : ''" @close="detailId = null">
      <LoadState :loading="detail.loading.value" :loaded="Boolean(task)" :error="detail.error.value" @retry="detail.load()">
        <template v-if="task">
          <dl class="kv task-kv">
            <dt>Descrição</dt><dd class="task-text">{{ task.description || '—' }}</dd>
            <dt>Responsável</dt><dd>{{ task.assigned_user?.name || 'Não definido' }}</dd>
            <dt>Prazo</dt><dd><span :class="`due ${dueInfo(task).tone}`">{{ dueInfo(task).label }}</span>{{ task.due_at ? ` · ${formatDateTime(task.due_at)}` : '' }}</dd>
            <dt>Prioridade</dt><dd><Badge :tone="tone('priority', task.priority)">{{ label('priority', task.priority) }}</Badge></dd>
            <dt>Origem</dt><dd>{{ task.source_occurrence ? `Ocorrência ${task.source_occurrence.reference}` : 'Cadastro direto' }}</dd>
            <dt>Status</dt><dd><Badge :tone="tone('taskStatus', task.status)">{{ label('taskStatus', task.status) }}</Badge></dd>
            <dt>Concluída em</dt><dd>{{ task.resolved_at ? formatDateTime(task.resolved_at) : '—' }}</dd>
            <dt>Criada por</dt><dd>{{ task.created_by?.name || '—' }} · {{ formatDateTime(task.created_at) }}</dd>
            <dt>Código</dt><dd>{{ task.reference }}</dd>
          </dl>
          <template v-if="task.source_occurrence">
            <h3 class="ops-title">Originada da ocorrência</h3>
            <ul class="related-list">
              <li>
                <span><b>{{ task.source_occurrence.reference }}</b></span>
                <button class="btn ghost small" type="button" @click="openOccurrence(task.source_occurrence.id)">Ver ocorrência</button>
              </li>
            </ul>
          </template>
          <DemandActivity :key="task.id" kind="task" :demand="task" :abilities="abilities" :sectors="activeSectors" :closed="closed" @changed="refresh" />
        </template>
      </LoadState>
      <template #footer>
        <template v-if="task">
          <button v-if="abilities.update_structure" class="btn ghost" type="button" @click="openEdit">Editar</button>
          <template v-if="abilities.change_status">
            <button v-if="task.status === 'PENDING'" class="btn ghost" type="button" :disabled="setStatus.pending.value" @click="setStatus.run('IN_PROGRESS')">{{ setStatus.pending.value ? 'Salvando…' : 'Iniciar atendimento' }}</button>
            <button v-else-if="task.status === 'IN_PROGRESS'" class="btn ghost" type="button" :disabled="setStatus.pending.value" @click="setStatus.run('PENDING')">{{ setStatus.pending.value ? 'Salvando…' : 'Voltar para pendente' }}</button>
            <button v-if="closed" class="btn ghost" type="button" @click="openAction('reopen')">Reabrir</button>
            <button v-else class="btn" type="button" @click="openAction('complete')">Marcar como concluída</button>
          </template>
        </template>
        <button v-if="!task || !abilities.change_status" class="btn ghost" type="button" @click="detailId = null">Fechar</button>
      </template>
    </Drawer>

    <Modal v-if="form" :title="form.id ? 'Editar pendência' : 'Nova pendência'" :subtitle="form.id ? 'Para mudar o setor responsável, use Encaminhar.' : 'O que precisa ser feito. Fatos ocorridos pertencem a Ocorrências.'" wide @close="form = null">
      <form class="form-grid" novalidate @submit.prevent="save.run()">
        <Field label="Título" required class-name="span-2" :error="save.fieldErrors.value.title"><input v-model="form.title" class="input" maxlength="255" /></Field>
        <Field label="Descrição" required class-name="span-2" :error="save.fieldErrors.value.description"><textarea v-model="form.description" class="input" maxlength="10000" /></Field>
        <Field v-if="!form.id && chooseOrigin" label="Setor de origem" required :error="save.fieldErrors.value.origin_sector_id">
          <select v-model="form.origin_sector_id" class="input">
            <option value="">Selecione o setor</option>
            <option v-for="item in activeSectors" :key="item.id" :value="item.id">{{ item.name }}</option>
          </select>
        </Field>
        <Field v-else label="Setor de origem"><input class="input" :value="sectorNameById(form.origin_sector_id)" disabled /></Field>
        <Field v-if="!form.id && chooseResponsible" label="Setor responsável" required :error="save.fieldErrors.value.responsible_sector_id">
          <select v-model="form.responsible_sector_id" class="input">
            <option value="">Selecione o setor</option>
            <option v-for="item in activeSectors" :key="item.id" :value="item.id">{{ item.name }}</option>
          </select>
        </Field>
        <Field v-else label="Setor responsável" :error="save.fieldErrors.value.responsible_sector_id"><input class="input" :value="sectorNameById(form.responsible_sector_id)" disabled /></Field>
        <div v-if="chooseInvolved" class="field span-2">
          <span>Setores envolvidos</span>
          <small class="stat-hint">Acompanham e colaboram. O setor responsável continua sendo um só.</small>
          <div class="chips">
            <button v-for="item in involvedOptions" :key="item.id" type="button" class="chip" :class="{ on: form.involved_sector_ids.includes(item.id) }" :aria-pressed="form.involved_sector_ids.includes(item.id)" @click="toggleInvolved(item.id)">{{ item.name }}</button>
            <small v-if="!involvedOptions.length" class="stat-hint">Nenhum outro setor disponível.</small>
          </div>
          <small v-if="save.fieldErrors.value.involved_sector_ids" class="field-error">{{ save.fieldErrors.value.involved_sector_ids }}</small>
        </div>
        <Field label="Responsável individual" :hint="assigneesLoading ? 'Carregando usuários…' : 'Usuário ativo do setor responsável.'" :error="save.fieldErrors.value.assigned_user_id">
          <select v-model="form.assigned_user_id" class="input">
            <option value="">Não definido</option>
            <option v-for="user in assignees" :key="user.id" :value="user.id">{{ user.name }}</option>
          </select>
        </Field>
        <Field label="Prazo" hint="Horário do evento." :error="save.fieldErrors.value.due_at"><input v-model="form.due_at" class="input" type="datetime-local" /></Field>
        <div class="field span-2">
          <span>Prioridade</span>
          <div class="chips">
            <button v-for="[code, name] in options('priority')" :key="code" type="button" class="chip" :class="{ on: form.priority === code }" :aria-pressed="form.priority === code" @click="form.priority = code">{{ name }}</button>
          </div>
          <small v-if="save.fieldErrors.value.priority" class="field-error">{{ save.fieldErrors.value.priority }}</small>
        </div>
      </form>
      <template #footer>
        <button class="btn ghost" type="button" @click="form = null">Cancelar</button>
        <button class="btn" type="button" :disabled="save.pending.value" @click="save.run()">{{ save.pending.value ? 'Salvando…' : form.id ? 'Salvar alterações' : 'Criar pendência' }}</button>
      </template>
    </Modal>

    <Modal v-if="acting && task" :title="acting.type === 'complete' ? 'Concluir pendência?' : 'Reabrir pendência?'" :subtitle="acting.type === 'complete' ? 'A pendência passa para Concluída e fica registrada no histórico.' : 'A pendência volta para Em andamento.'" @close="acting = null">
      <p><b>{{ task.reference }}</b> · {{ task.title }}</p>
      <Field :label="acting.type === 'complete' ? 'Observação' : 'Motivo'" hint="Opcional." :error="runAction.fieldErrors.value.message || runAction.fieldErrors.value.status">
        <textarea v-model="acting.message" class="input" rows="3" maxlength="2000" />
      </Field>
      <template #footer>
        <button class="btn ghost" type="button" @click="acting = null">Cancelar</button>
        <button class="btn" type="button" :disabled="runAction.pending.value" @click="runAction.run()">{{ runAction.pending.value ? 'Salvando…' : acting.type === 'complete' ? 'Concluir' : 'Reabrir' }}</button>
      </template>
    </Modal>
  </Page>
</template>
