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
import { useOccurrences } from '@/js/pages/occurrences'

const props = defineProps({
  params: { type: Object, default: () => ({}) },
})
const {
  list, teams, teamsState, ensureTeams, days, detailId, detail, occurrence, abilities, closed, refresh, form, openNew, openEdit,
  chooseOrigin, chooseResponsible, chooseInvolved, involvedOptions, toggleInvolved, sectorNameById, save, acting, openAction, runAction,
  setStatus, generating, generateOrigin, openGenerate, generate, openTask, canCreate, allSectors, activeSectors,
  assignees, assigneesLoading, dayLabel, dayText, shortDay, label, tone, options, formatDateTime,
} = useOccurrences(props)
const { filters, page, items, meta, loading, error, loaded } = list
</script>

<template>
  <Page crumbs="Gestão / Ocorrências" title="Ocorrências" subtitle="Registre e acompanhe situações ocorridas durante a organização e realização do Hackathon.">
    <template #actions>
      <button v-if="canCreate" class="btn" type="button" @click="openNew()">+ Registrar ocorrência</button>
      <Badge v-else>Consulta</Badge>
    </template>

    <FilterPanel class="occ-filters" :active="list.activeFilters.value - (filters.search ? 1 : 0)" @clear="list.reset()">
      <template #search><Search v-model="filters.search" placeholder="Buscar por título ou código" /></template>
      <select v-model="filters.category" class="input" aria-label="Categoria">
        <option value="">Categoria</option>
        <option v-for="[code, name] in options('occurrenceCategory')" :key="code" :value="code">{{ name }}</option>
      </select>
      <select v-model="filters.responsible_sector_id" class="input" aria-label="Setor responsável">
        <option value="">Setor</option>
        <option v-for="item in allSectors" :key="item.id" :value="item.id">{{ item.name }}</option>
      </select>
      <select v-model="filters.involved_sector_id" class="input" aria-label="Setor envolvido">
        <option value="">Setor envolvido</option>
        <option v-for="item in allSectors" :key="item.id" :value="item.id">{{ item.name }}</option>
      </select>
      <select v-model="filters.priority" class="input" aria-label="Prioridade">
        <option value="">Prioridade</option>
        <option v-for="[code, name] in options('priority')" :key="code" :value="code">{{ name }}</option>
      </select>
      <select v-model="filters.status" class="input" aria-label="Status">
        <option value="">Status</option>
        <option v-for="[code, name] in options('occurrenceStatus')" :key="code" :value="code">{{ name }}</option>
      </select>
      <select v-model="filters.event_day_id" class="input" aria-label="Dia">
        <option value="">Dia</option>
        <option v-for="day in days" :key="day.id" :value="day.id">{{ dayLabel(day) }}</option>
      </select>
      <select v-model="filters.team_id" class="input" aria-label="Equipe" @focus="ensureTeams" @pointerdown="ensureTeams">
        <option value="">{{ teamsState === 'loading' ? 'Carregando equipes…' : 'Equipe' }}</option>
        <option v-for="team in teams" :key="team.id" :value="team.id">{{ team.name }}</option>
      </select>
    </FilterPanel>
    <p v-if="loaded" class="occ-summary">
      <span>Total <b>{{ meta.total }}</b></span>
      <button v-if="list.activeFilters.value" class="linkish" type="button" @click="list.reset()">Limpar filtros</button>
    </p>

    <LoadState :loading="loading" :loaded="loaded" :error="error" :empty="items.length === 0" :empty-title="list.activeFilters.value ? 'Nenhuma ocorrência para estes filtros.' : 'Nenhuma ocorrência registrada.'" empty-text="Ocorrências são fatos ou problemas que aconteceram. O que ainda precisa ser feito fica em Pendências." @retry="list.load()">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Ocorrência</th><th>Categoria</th><th>Local</th><th>Setor</th><th>Prioridade</th><th>Status</th><th>Ações</th></tr></thead>
          <tbody>
            <tr v-for="item in items" :key="item.id">
              <td>
                {{ item.title }} <small class="stat-hint">{{ item.reference }}</small>
                <template v-if="item.event_day || item.occurred_at"><br /><small class="stat-hint">{{ [item.event_day ? shortDay(item.event_day) : '', item.occurred_at ? formatDateTime(item.occurred_at) : ''].filter(Boolean).join(' · ') }}</small></template>
              </td>
              <td>{{ label('occurrenceCategory', item.category) }}</td>
              <td>{{ item.location || '—' }}</td>
              <td>{{ item.responsible_sector?.name || '—' }}</td>
              <td><Badge :tone="tone('priority', item.priority)">{{ label('priority', item.priority) }}</Badge></td>
              <td><Badge :tone="tone('occurrenceStatus', item.status)">{{ label('occurrenceStatus', item.status) }}</Badge></td>
              <td>
                <div class="row-actions">
                  <button class="btn ghost small" type="button" @click="detailId = item.id">Ver</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pager :meta="meta" :disabled="loading" @page="page = $event" />
    </LoadState>

    <Drawer v-if="detailId" :title="occurrence?.title || 'Ocorrência'" :subtitle="occurrence ? `${label('occurrenceCategory', occurrence.category)} · ${label('occurrenceStatus', occurrence.status)}` : ''" @close="detailId = null">
      <LoadState :loading="detail.loading.value" :loaded="Boolean(occurrence)" :error="detail.error.value" @retry="detail.load()">
        <template v-if="occurrence">
          <dl class="detail-list occ-detail">
            <dt>Descrição</dt><dd>{{ occurrence.description || '—' }}</dd>
            <dt>Categoria</dt><dd>{{ label('occurrenceCategory', occurrence.category) }}</dd>
            <dt>Dia</dt><dd>{{ dayText(occurrence.event_day) }}</dd>
            <dt>Data / horário</dt><dd>{{ occurrence.occurred_at ? formatDateTime(occurrence.occurred_at) : '—' }}</dd>
            <dt>Local</dt><dd>{{ occurrence.location || '—' }}</dd>
            <dt>Equipe</dt><dd>{{ occurrence.team?.name || '—' }}</dd>
            <dt>Prioridade</dt><dd><Badge :tone="tone('priority', occurrence.priority)">{{ label('priority', occurrence.priority) }}</Badge></dd>
            <dt>Responsável</dt><dd>{{ occurrence.assigned_user?.name || 'Não definido' }}</dd>
            <dt>Status</dt><dd><Badge :tone="tone('occurrenceStatus', occurrence.status)">{{ label('occurrenceStatus', occurrence.status) }}</Badge></dd>
            <dt>Solução</dt><dd>{{ occurrence.resolution || '—' }}</dd>
            <dt v-if="occurrence.resolved_at">Resolvida em</dt><dd v-if="occurrence.resolved_at">{{ formatDateTime(occurrence.resolved_at) }}</dd>
            <dt>Observação</dt><dd>{{ occurrence.notes || '—' }}</dd>
            <dt>Registrada por</dt><dd>{{ occurrence.created_by?.name || '—' }} · {{ formatDateTime(occurrence.created_at) }}</dd>
            <dt>Código</dt><dd>{{ occurrence.reference }}</dd>
          </dl>
          <template v-if="occurrence.generated_tasks?.length">
            <h3 class="ops-title">{{ occurrence.generated_tasks.length > 1 ? 'Pendências relacionadas' : 'Pendência relacionada' }}</h3>
            <ul class="related-list">
              <li v-for="item in occurrence.generated_tasks" :key="item.id">
                <span><b>{{ item.reference }}</b></span>
                <Badge :tone="tone('taskStatus', item.status)">{{ label('taskStatus', item.status) }}</Badge>
                <button class="btn ghost small" type="button" @click="openTask(item.id)">Ver pendência</button>
              </li>
            </ul>
          </template>
          <DemandActivity :key="occurrence.id" kind="occurrence" :demand="occurrence" :abilities="abilities" :sectors="activeSectors" :closed="closed" @changed="refresh" />
        </template>
      </LoadState>
      <template #footer>
        <template v-if="occurrence">
          <button v-if="abilities.update_structure" class="btn ghost" type="button" @click="openEdit">Editar</button>
          <button v-if="abilities.generate_task" class="btn ghost" type="button" @click="openGenerate">Gerar Pendência</button>
          <template v-if="abilities.change_status">
            <button v-if="occurrence.status === 'OPEN'" class="btn ghost" type="button" :disabled="setStatus.pending.value" @click="setStatus.run('IN_PROGRESS')">{{ setStatus.pending.value ? 'Salvando…' : 'Iniciar atendimento' }}</button>
            <button v-else-if="occurrence.status === 'IN_PROGRESS'" class="btn ghost" type="button" :disabled="setStatus.pending.value" @click="setStatus.run('OPEN')">{{ setStatus.pending.value ? 'Salvando…' : 'Voltar para aberta' }}</button>
            <button v-if="closed" class="btn ghost" type="button" @click="openAction('reopen')">Reabrir</button>
            <button v-else class="btn" type="button" @click="openAction('resolve')">Registrar solução</button>
          </template>
        </template>
        <button v-if="!occurrence || !abilities.change_status" class="btn ghost" type="button" @click="detailId = null">Fechar</button>
      </template>
    </Drawer>

    <Modal v-if="form" :title="form.id ? 'Editar ocorrência' : 'Registrar ocorrência'" :subtitle="form.id ? 'Para mudar o setor responsável, use Encaminhar.' : 'Registre o que aconteceu. Tarefas a fazer pertencem a Pendências.'" wide @close="form = null">
      <form class="form-grid" novalidate @submit.prevent="save.run()">
        <Field label="Título" required class-name="span-2" :error="save.fieldErrors.value.title"><input v-model="form.title" class="input" maxlength="255" /></Field>
        <Field label="Categoria" required :error="save.fieldErrors.value.category">
          <select v-model="form.category" class="input">
            <option v-for="[code, name] in options('occurrenceCategory')" :key="code" :value="code">{{ name }}</option>
          </select>
        </Field>
        <Field v-if="!form.id && chooseResponsible" label="Setor responsável" required :error="save.fieldErrors.value.responsible_sector_id">
          <select v-model="form.responsible_sector_id" class="input">
            <option value="">Selecione o setor</option>
            <option v-for="item in activeSectors" :key="item.id" :value="item.id">{{ item.name }}</option>
          </select>
        </Field>
        <Field v-else label="Setor responsável" :error="save.fieldErrors.value.responsible_sector_id"><input class="input" :value="sectorNameById(form.responsible_sector_id)" disabled /></Field>
        <Field v-if="!form.id && chooseOrigin" label="Setor de origem" required :error="save.fieldErrors.value.origin_sector_id">
          <select v-model="form.origin_sector_id" class="input">
            <option value="">Selecione o setor</option>
            <option v-for="item in activeSectors" :key="item.id" :value="item.id">{{ item.name }}</option>
          </select>
        </Field>
        <Field v-else label="Setor de origem"><input class="input" :value="sectorNameById(form.origin_sector_id)" disabled /></Field>
        <div v-if="chooseInvolved" class="field span-2">
          <span>Setores envolvidos</span>
          <small class="stat-hint">Acompanham e colaboram. O setor responsável continua sendo um só.</small>
          <div class="chips">
            <button v-for="item in involvedOptions" :key="item.id" type="button" class="chip" :class="{ on: form.involved_sector_ids.includes(item.id) }" :aria-pressed="form.involved_sector_ids.includes(item.id)" @click="toggleInvolved(item.id)">{{ item.name }}</button>
            <small v-if="!involvedOptions.length" class="stat-hint">Nenhum outro setor disponível.</small>
          </div>
          <small v-if="save.fieldErrors.value.involved_sector_ids" class="field-error">{{ save.fieldErrors.value.involved_sector_ids }}</small>
        </div>
        <Field label="Descrição" required class-name="span-2" :error="save.fieldErrors.value.description"><textarea v-model="form.description" class="input" maxlength="10000" /></Field>
        <Field label="Dia do evento" :error="save.fieldErrors.value.event_day_id">
          <select v-model="form.event_day_id" class="input">
            <option value="">Fora dos dias do evento</option>
            <option v-for="day in days" :key="day.id" :value="day.id">{{ dayLabel(day) }}</option>
          </select>
        </Field>
        <Field label="Data / horário" hint="Horário do evento." :error="save.fieldErrors.value.occurred_at"><input v-model="form.occurred_at" class="input" type="datetime-local" /></Field>
        <Field label="Local / sala" :error="save.fieldErrors.value.location"><input v-model="form.location" class="input" maxlength="255" /></Field>
        <Field label="Equipe relacionada" :error="save.fieldErrors.value.team_id" :hint="teamsState === 'error' ? 'Não foi possível carregar as equipes.' : ''">
          <select v-model="form.team_id" class="input">
            <option value="">{{ teamsState === 'loading' ? 'Carregando equipes…' : 'Nenhuma' }}</option>
            <option v-for="team in teams" :key="team.id" :value="team.id">{{ team.name }}</option>
          </select>
        </Field>
        <Field label="Responsável individual" :hint="assigneesLoading ? 'Carregando usuários…' : 'Usuário ativo do setor responsável.'" :error="save.fieldErrors.value.assigned_user_id">
          <select v-model="form.assigned_user_id" class="input">
            <option value="">Não definido</option>
            <option v-for="user in assignees" :key="user.id" :value="user.id">{{ user.name }}</option>
          </select>
        </Field>
        <div class="field span-2">
          <span>Prioridade</span>
          <div class="chips">
            <button v-for="[code, name] in options('priority')" :key="code" type="button" class="chip" :class="{ on: form.priority === code }" :aria-pressed="form.priority === code" @click="form.priority = code">{{ name }}</button>
          </div>
          <small v-if="save.fieldErrors.value.priority" class="field-error">{{ save.fieldErrors.value.priority }}</small>
        </div>
        <Field label="Observação" class-name="span-2" :error="save.fieldErrors.value.notes"><input v-model="form.notes" class="input" /></Field>
      </form>
      <template #footer>
        <button class="btn ghost" type="button" @click="form = null">Cancelar</button>
        <button class="btn" type="button" :disabled="save.pending.value" @click="save.run()">{{ save.pending.value ? 'Salvando…' : form.id ? 'Salvar alterações' : 'Registrar ocorrência' }}</button>
      </template>
    </Modal>

    <Modal v-if="acting && occurrence" :title="acting.type === 'resolve' ? 'Registrar solução' : 'Reabrir ocorrência?'" :subtitle="acting.type === 'resolve' ? 'A ocorrência passará para o status Resolvida.' : 'A ocorrência volta para o status Aberta.'" @close="acting = null">
      <p><b>{{ occurrence.reference }}</b> · {{ occurrence.title }}</p>
      <Field v-if="acting.type === 'resolve'" label="Solução adotada" required :error="runAction.fieldErrors.value.resolution || runAction.fieldErrors.value.status">
        <textarea v-model="acting.text" class="input" rows="4" maxlength="10000" />
      </Field>
      <Field v-else label="Motivo" hint="Opcional." :error="runAction.fieldErrors.value.message || runAction.fieldErrors.value.status">
        <textarea v-model="acting.text" class="input" rows="3" maxlength="2000" />
      </Field>
      <template #footer>
        <button class="btn ghost" type="button" @click="acting = null">Cancelar</button>
        <button class="btn" type="button" :disabled="runAction.pending.value || (acting.type === 'resolve' && !acting.text.trim())" @click="runAction.run()">{{ runAction.pending.value ? 'Salvando…' : acting.type === 'resolve' ? 'Salvar solução' : 'Reabrir' }}</button>
      </template>
    </Modal>

    <Modal v-if="generating && occurrence" title="Gerar Pendência" :subtitle="`A pendência fica ligada à ocorrência ${occurrence.reference}.`" @close="generating = null">
      <form class="form-grid" novalidate @submit.prevent="generate.run()">
        <Field label="Título" required class-name="span-2" :error="generate.fieldErrors.value.title"><input v-model="generating.title" class="input" maxlength="255" placeholder="Ex.: Providenciar projetor reserva" /></Field>
        <Field v-if="generateOrigin" label="Setor de origem" required :error="generate.fieldErrors.value.origin_sector_id">
          <select v-model="generating.origin_sector_id" class="input">
            <option value="">Selecione o setor</option>
            <option v-for="item in activeSectors" :key="item.id" :value="item.id">{{ item.name }}</option>
          </select>
        </Field>
        <Field label="Setor responsável" required :error="generate.fieldErrors.value.responsible_sector_id">
          <select v-model="generating.responsible_sector_id" class="input">
            <option value="">Selecione o setor</option>
            <option v-for="item in activeSectors" :key="item.id" :value="item.id">{{ item.name }}</option>
          </select>
        </Field>
        <Field label="Prioridade" :error="generate.fieldErrors.value.priority">
          <select v-model="generating.priority" class="input">
            <option v-for="[code, name] in options('priority')" :key="code" :value="code">{{ name }}</option>
          </select>
        </Field>
        <Field label="Prazo" hint="Horário do evento." :error="generate.fieldErrors.value.due_at"><input v-model="generating.due_at" class="input" type="datetime-local" /></Field>
        <Field label="Responsável individual" :hint="assigneesLoading ? 'Carregando usuários…' : ''" :error="generate.fieldErrors.value.assigned_user_id">
          <select v-model="generating.assigned_user_id" class="input">
            <option value="">Não definido</option>
            <option v-for="user in assignees" :key="user.id" :value="user.id">{{ user.name }}</option>
          </select>
        </Field>
        <Field label="Descrição" required class-name="span-2" :error="generate.fieldErrors.value.description"><textarea v-model="generating.description" class="input" rows="3" maxlength="10000" /></Field>
      </form>
      <template #footer>
        <button class="btn ghost" type="button" @click="generating = null">Cancelar</button>
        <button class="btn" type="button" :disabled="generate.pending.value" @click="generate.run()">{{ generate.pending.value ? 'Salvando…' : 'Criar Pendência' }}</button>
      </template>
    </Modal>
  </Page>
</template>
