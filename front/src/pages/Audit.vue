<script setup>
import Badge from '../components/Badge.vue'
import Drawer from '../components/Drawer.vue'
import FilterPanel from '../components/FilterPanel.vue'
import LoadState from '../components/LoadState.vue'
import Page from '../components/Page.vue'
import Pager from '../components/Pager.vue'
import Search from '../components/Search.vue'
import { useAuditPage } from '@/js/pages/audit'

const {
  list, filters, modules, actions, setModule, canUsers, canSectors, users, sectors, loadUsers, loadSectors, advancedActive, roleOptions, events,
  detailId, detail, summary, openDetail, closeDetail, who, actorName, show, pretty, clear, formatDateTime,
} = useAuditPage()
const { items, meta, loading, error, loaded } = list
</script>

<template>
  <Page title="Auditoria" subtitle="Ações relevantes registradas pelo backend do HackLab, da mais recente para a mais antiga. Somente leitura.">
    <FilterPanel :active="advancedActive" @clear="clear">
      <template #search><Search v-model="filters.search" placeholder="Buscar por ação, descrição, usuário ou registro" /></template>
      <select v-model="filters.event" class="input" aria-label="Evento">
        <option value="">Todos os eventos</option>
        <option value="none">Sem evento (ações globais)</option>
        <option value="any">Qualquer evento</option>
        <option v-for="item in events" :key="item.id" :value="String(item.id)">{{ item.name }}</option>
      </select>
      <select :value="filters.module" class="input" aria-label="Módulo" @change="setModule($event.target.value)">
        <option value="">Todos os módulos</option>
        <option v-for="item in modules" :key="item.code" :value="item.code">{{ item.label }}</option>
      </select>
      <select v-model="filters.action" class="input" aria-label="Ação">
        <option value="">Todas as ações</option>
        <option v-for="item in actions" :key="item.code" :value="item.code">{{ item.label }}</option>
      </select>
      <select v-if="canUsers" v-model="filters.actor_user_id" class="input" aria-label="Usuário" @focus="loadUsers" @mousedown="loadUsers">
        <option value="">Todos os usuários</option>
        <option v-if="users.loading.value" disabled value="__loading">Carregando…</option>
        <option v-for="item in users.data.value" :key="item.id" :value="String(item.id)">{{ item.person?.full_name || item.email }}</option>
      </select>
      <select v-model="filters.actor_role" class="input" aria-label="Perfil">
        <option value="">Todos os perfis</option>
        <option v-for="[code, name] in roleOptions" :key="code" :value="code">{{ name }}</option>
      </select>
      <select v-if="canSectors" v-model="filters.actor_sector_id" class="input" aria-label="Setor" @focus="loadSectors" @mousedown="loadSectors">
        <option value="">Todos os setores</option>
        <option v-if="sectors.loading.value" disabled value="__loading">Carregando…</option>
        <option v-for="item in sectors.data.value" :key="item.id" :value="String(item.id)">{{ item.name }}</option>
      </select>
      <input v-model="filters.entity_type" class="input" placeholder="Tipo de registro (ex.: Team)" aria-label="Tipo de registro" maxlength="64" />
      <input v-model="filters.entity_id" class="input" placeholder="ID do registro" aria-label="ID do registro" maxlength="64" />
      <input v-model="filters.ip_address" class="input" placeholder="Endereço IP" aria-label="Endereço IP" maxlength="45" />
      <label class="audit-date"><span>De</span><input v-model="filters.date_from" class="input" type="date" aria-label="Data inicial" /></label>
      <label class="audit-date"><span>Até</span><input v-model="filters.date_to" class="input" type="date" aria-label="Data final" :min="filters.date_from || undefined" /></label>
    </FilterPanel>
    <p v-if="loaded" class="attendance-summary" aria-live="polite"><span>Registros <b>{{ meta.total }}</b></span></p>

    <LoadState :loading="loading" :loaded="loaded" :error="error" :empty="items.length === 0"
      :empty-title="list.activeFilters.value ? 'Nenhum registro para estes filtros' : 'Nenhuma ação registrada'"
      empty-text="As ações relevantes do HackLab aparecem aqui, da mais recente para a mais antiga." @retry="list.load()">
      <div class="table-wrap">
        <table class="audit-table">
          <thead><tr><th>Data/Hora</th><th>Usuário</th><th>Perfil</th><th>Ação</th><th>Módulo</th><th>Registro</th><th>Detalhes</th></tr></thead>
          <tbody>
            <tr v-for="item in items" :key="item.id">
              <td class="audit-when">{{ formatDateTime(item.created_at) }}</td>
              <td>{{ actorName(item) }}<template v-if="item.actor?.email && item.actor.email !== item.actor.name"><br /><small class="stat-hint">{{ item.actor.email }}</small></template></td>
              <td>{{ who(item) }}</td>
              <td>{{ item.action?.label || item.action?.code }}</td>
              <td>{{ item.module?.label || item.module?.code }}</td>
              <td>{{ item.entity?.label || (item.entity ? `${item.entity.type} #${item.entity.id}` : '—') }}<template v-if="item.event"><br /><small class="stat-hint">{{ item.event.name || `Evento #${item.event.id}` }}</small></template></td>
              <td><button class="btn ghost small" type="button" @click="openDetail(item)">Ver detalhes</button></td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pager :meta="meta" :disabled="loading" @page="list.page.value = $event" />
    </LoadState>

    <Drawer v-if="detailId && summary" :title="summary.action?.label || summary.action?.code" :subtitle="formatDateTime(summary.created_at)" @close="closeDetail">
      <dl class="detail-list">
        <dt>Data e hora</dt><dd>{{ formatDateTime(summary.created_at) }}</dd>
        <dt>Usuário</dt><dd>{{ actorName(summary) }}</dd>
        <template v-if="summary.actor?.email"><dt>E-mail</dt><dd>{{ summary.actor.email }}</dd></template>
        <dt>Perfil</dt><dd>{{ summary.actor?.role?.name || '—' }}</dd>
        <dt>Setor</dt><dd>{{ summary.actor?.sector_name || '—' }}</dd>
        <dt>Ação</dt><dd>{{ summary.action?.label }} <span class="ticket-code">{{ summary.action?.code }}</span></dd>
        <dt>Módulo</dt><dd>{{ summary.module?.label || summary.module?.code }}</dd>
        <dt>Evento</dt><dd>{{ summary.event ? (summary.event.name || `Evento #${summary.event.id}`) : '—' }}</dd>
        <dt>Tipo de registro</dt><dd>{{ summary.entity?.type || '—' }}</dd>
        <dt>Identificador</dt><dd>{{ summary.entity ? `${summary.entity.label || ''} (#${summary.entity.id})` : '—' }}</dd>
        <dt>Descrição</dt><dd>{{ summary.description || '—' }}</dd>
      </dl>
      <LoadState :loading="detail.loading.value" :loaded="Boolean(detail.data.value)" :error="detail.error.value" loading-text="Carregando detalhes…" @retry="detail.load()">
        <template v-if="detail.data.value">
          <dl class="detail-list">
            <dt>Endereço IP</dt><dd>{{ detail.data.value.ip_address || '—' }}</dd>
            <dt>Navegador</dt><dd class="audit-agent">{{ detail.data.value.user_agent || '—' }}</dd>
          </dl>
          <h3 class="ops-title">Alterações</h3>
          <p v-if="!detail.data.value.changes?.length" class="stat-hint">Sem alterações de campo registradas.</p>
          <ul v-else class="audit-changes">
            <li v-for="change in detail.data.value.changes" :key="change.path">
              <span>{{ change.path }}</span>
              <p><s>{{ show(change.before, change.exists_before) }}</s> <span aria-hidden="true">→</span><span class="sr-only">para</span> <b>{{ show(change.after, change.exists_after) }}</b></p>
            </li>
          </ul>
          <details v-if="detail.data.value.before_data" class="audit-raw">
            <summary>Dados antes</summary>
            <pre>{{ pretty(detail.data.value.before_data) }}</pre>
          </details>
          <details v-if="detail.data.value.after_data" class="audit-raw">
            <summary>Dados depois</summary>
            <pre>{{ pretty(detail.data.value.after_data) }}</pre>
          </details>
          <p class="stat-hint audit-readonly"><Badge>Somente leitura</Badge> Registros de auditoria não podem ser alterados nem excluídos.</p>
        </template>
      </LoadState>
      <template #footer>
        <button class="btn ghost" type="button" @click="closeDetail">Fechar</button>
      </template>
    </Drawer>
  </Page>
</template>
