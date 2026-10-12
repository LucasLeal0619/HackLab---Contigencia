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
import Stat from '../components/Stat.vue'
import Tabs from '../components/Tabs.vue'
import { useAttendance } from '@/js/pages/attendance'

const props = defineProps({
  params: { type: Object, default: () => ({}) },
})
const {
  BOOL_OPTIONS, canManage, canSync, canMaterialize, readOnly, sectionTabs, tab, setTab,
  status, integration, configured, modal, configForm, configWarnings, openConfigure, configure, checkConnection,
  ticketFilters, tickets, categoryOptions, ticketChoices, selectedTicket, mappingForm, openMapping, mapping,
  runs, syncForm, openSync, sync, runDetailId, runDetail, openRun, closeRun, counterRows, warningCodeRows, warningTotal, duration,
  registrations, registrationId, registrationDetail, openRegistration, closeRegistration, attendanceDay,
  materializeResult, openMaterialize, materialize, attendance, dayTabs, yesNo, label, tone, options, formatDate, formatDateTime, formatNumber,
} = useAttendance(props)
const regFilters = registrations.filters
const attFilters = attendance.filters
</script>

<template>
  <Page title="Inscrições e Presença" subtitle="Inscrições, entradas e check-ins vêm da Even3 pelo backend. O credenciamento é feito na Even3.">
    <template #actions>
      <Badge v-if="readOnly">Consulta</Badge>
    </template>

    <Empty v-if="!sectionTabs.length" title="Sem acesso a esta área" text="Seu perfil não consulta a integração, as inscrições nem a presença." />

    <Tabs v-else class="attendance-sections" :tabs="sectionTabs" :model-value="tab" @update:model-value="setTab" />

    <!-- Integração -->
    <section v-if="tab === 'integracao'" aria-label="Integração">
      <LoadState :loading="status.loading.value" :loaded="status.loaded.value" :error="status.error.value" @retry="status.load()">
        <template v-if="integration">
          <div v-if="!integration.configured" class="banner warn integration-banner">
            <div>
              <b>Integração Even3 não configurada para este evento.</b>
              <p>Sem a integração, não há entradas, inscrições nem presença sincronizadas.<template v-if="canManage"> Informe o ID do evento na Even3 para começar.</template></p>
            </div>
          </div>
          <div v-else-if="integration.status === 'DISABLED'" class="banner warn integration-banner">
            <div><b>Integração desativada.</b><p>Sincronizações e webhooks deste evento estão suspensos até a integração ser ativada.</p></div>
          </div>
          <div v-if="configWarnings.length" class="banner warn integration-banner" role="status">
            <div>
              <b>Avisos da última configuração</b>
              <ul class="integration-warnings"><li v-for="item in configWarnings" :key="item">{{ item }}</li></ul>
            </div>
          </div>

          <div class="grid cols-4 integration-stats">
            <Stat icon="ticket" label="Integração" :value="integration.configured ? label('integrationStatus', integration.status) : 'Não configurada'" :hint="integration.external_event_id ? `Evento Even3 ${integration.external_event_id}` : 'Sem evento externo'" />
            <Stat icon="users" label="Inscrições" :value="formatNumber(integration.registration_count)" :hint="`${formatNumber(integration.confirmed_count)} confirmada(s)`" />
            <Stat icon="grid" label="Entradas" :value="formatNumber(integration.ticket_count)" :hint="integration.unmapped_ticket_count ? `${formatNumber(integration.unmapped_ticket_count)} ativa(s) sem mapeamento` : 'Todas as entradas ativas mapeadas'" />
            <Stat icon="check" label="Check-ins" :value="formatNumber(integration.attendance_count)" hint="Registrados na Even3" />
          </div>

          <article class="card integration-card">
            <h3>Integração Even3</h3>
            <dl class="detail-list">
              <dt>Integração no servidor</dt><dd><Badge :tone="integration.enabled ? 'ok' : 'warn'">{{ integration.enabled ? 'Habilitada' : 'Desabilitada' }}</Badge></dd>
              <dt>Token da API</dt><dd><Badge :tone="integration.token_configured ? 'ok' : 'warn'">{{ integration.token_configured ? 'Configurado' : 'Não configurado' }}</Badge></dd>
              <dt>Webhook</dt><dd><Badge :tone="integration.webhook_configured ? 'ok' : ''">{{ integration.webhook_configured ? 'Configurado' : 'Não configurado' }}</Badge></dd>
              <dt>Configurada no evento</dt><dd>{{ yesNo(integration.configured) }}</dd>
              <template v-if="integration.configured">
                <dt>ID do evento na Even3</dt><dd><span class="ticket-code">{{ integration.external_event_id }}</span></dd>
                <dt>Nome na Even3</dt><dd>{{ integration.external_event_title || '—' }}</dd>
                <dt>Status</dt><dd><Badge :tone="tone('integrationStatus', integration.status)">{{ label('integrationStatus', integration.status) }}</Badge></dd>
                <dt>Versão da API</dt><dd>{{ integration.api_version || '—' }}</dd>
                <dt>Última verificação</dt>
                <dd>
                  {{ formatDateTime(integration.last_connection_checked_at) }}
                  <Badge v-if="integration.last_connection_checked_at" :tone="integration.connected ? 'ok' : 'danger'">{{ integration.connected ? 'Conectada' : 'Sem conexão' }}</Badge>
                </dd>
                <dt>Última sincronização</dt>
                <dd>
                  {{ formatDateTime(integration.last_sync_at) }}
                  <Badge v-if="integration.last_sync_status" :tone="tone('syncStatus', integration.last_sync_status)">{{ label('syncStatus', integration.last_sync_status) }}</Badge>
                </dd>
                <template v-if="integration.last_error_code || integration.connection_error">
                  <dt>Último erro</dt><dd><span class="ticket-code">{{ integration.connection_error || integration.last_error_code }}</span></dd>
                </template>
              </template>
            </dl>
            <p class="stat-hint integration-note">Token, segredo e cabeçalho do webhook ficam só no servidor e nunca são exibidos aqui.</p>
            <div v-if="canManage" class="row-actions integration-actions">
              <button class="btn" type="button" @click="openConfigure">{{ integration.configured ? 'Editar configuração' : 'Configurar integração' }}</button>
              <button v-if="integration.configured" class="btn ghost" type="button" :disabled="checkConnection.pending.value || !integration.token_configured" @click="checkConnection.run()">
                {{ checkConnection.pending.value ? 'Verificando…' : 'Verificar conexão' }}
              </button>
            </div>
          </article>
        </template>
      </LoadState>
    </section>

    <!-- Entradas -->
    <section v-else-if="tab === 'entradas'" aria-label="Entradas">
      <div class="banner integration-banner">
        <div>Mapear uma entrada só classifica as inscrições dela. Não concede perfil e não cria Jurado, Empresa nem Usuário.</div>
      </div>
      <LoadState v-if="!configured" :loading="status.loading.value" :loaded="status.loaded.value" :error="status.error.value" empty empty-title="Integração não configurada" empty-text="As entradas aparecem depois que a integração Even3 for configurada e sincronizada." @retry="status.load()" />
      <template v-else>
        <div class="attendance-bar">
          <select v-model="ticketFilters.active" class="input attendance-filter" aria-label="Situação na Even3">
            <option value="">Ativas e inativas</option>
            <option value="true">Só ativas</option>
            <option value="false">Só inativas</option>
          </select>
          <select v-model="ticketFilters.mapped" class="input attendance-filter" aria-label="Mapeamento">
            <option value="">Mapeadas ou não</option>
            <option value="true">Só mapeadas</option>
            <option value="false">Sem mapeamento</option>
          </select>
        </div>
        <LoadState :loading="tickets.loading.value" :loaded="tickets.loaded.value" :error="tickets.error.value" :empty="!tickets.data.value.items.length" empty-title="Nenhuma entrada encontrada" empty-text="Execute uma sincronização para trazer as entradas da Even3." @retry="tickets.load()">
          <div class="table-wrap attendance-table-wrap">
            <table class="attendance-table">
              <thead><tr><th>Entrada na Even3</th><th>Inscrições</th><th>Situação</th><th>Categoria interna</th><th>Vista por último</th><th v-if="canManage">Ações</th></tr></thead>
              <tbody>
                <tr v-for="item in tickets.data.value.items" :key="item.id">
                  <td>{{ item.external_name }}<br /><small class="stat-hint">ID externo <span class="ticket-code">{{ item.external_ticket_id }}</span></small></td>
                  <td>{{ formatNumber(item.registrations_count) }}</td>
                  <td><Badge :tone="item.active ? 'ok' : ''">{{ item.active ? 'Ativa' : 'Inativa' }}</Badge></td>
                  <td>
                    <Badge v-if="item.internal_category" :tone="tone('ticketCategory', item.internal_category)">{{ label('ticketCategory', item.internal_category) }}</Badge>
                    <Badge v-else tone="warn">Sem mapeamento</Badge>
                  </td>
                  <td>{{ formatDateTime(item.last_seen_at) }}</td>
                  <td v-if="canManage"><button class="btn ghost small" type="button" @click="openMapping(item)">Mapear</button></td>
                </tr>
              </tbody>
            </table>
          </div>
          <ul class="attendance-cards">
            <li v-for="item in tickets.data.value.items" :key="item.id" class="card attendance-card">
              <div class="attendance-card-head">
                <b>{{ item.external_name }}</b>
                <Badge v-if="item.internal_category" :tone="tone('ticketCategory', item.internal_category)">{{ label('ticketCategory', item.internal_category) }}</Badge>
                <Badge v-else tone="warn">Sem mapeamento</Badge>
              </div>
              <p>{{ formatNumber(item.registrations_count) }} inscrição(ões) · {{ item.active ? 'Ativa' : 'Inativa' }}</p>
              <div v-if="canManage" class="attendance-card-actions"><button class="btn ghost" type="button" @click="openMapping(item)">Mapear</button></div>
            </li>
          </ul>
        </LoadState>
      </template>
    </section>

    <!-- Sincronização -->
    <section v-else-if="tab === 'sincronizacao'" aria-label="Sincronização">
      <LoadState v-if="!configured" :loading="status.loading.value" :loaded="status.loaded.value" :error="status.error.value" empty empty-title="Integração não configurada" empty-text="Configure a integração Even3 para sincronizar evento, entradas e inscritos." @retry="status.load()" />
      <template v-else>
        <div class="attendance-bar">
          <select v-model="runs.filters.status" class="input attendance-filter" aria-label="Status da sincronização">
            <option value="">Todos os status</option>
            <option v-for="[code, name] in options('syncStatus')" :key="code" :value="code">{{ name }}</option>
          </select>
          <button v-if="canSync" class="btn" type="button" :disabled="integration?.status !== 'ACTIVE'" :title="integration?.status !== 'ACTIVE' ? 'Ative a integração antes de sincronizar.' : ''" @click="openSync">Sincronizar agora</button>
        </div>
        <p v-if="canSync && integration?.status !== 'ACTIVE'" class="stat-hint">A integração está desativada: ative-a na aba Integração antes de sincronizar.</p>
        <LoadState :loading="runs.loading.value" :loaded="runs.loaded.value" :error="runs.error.value" :empty="runs.items.value.length === 0" empty-title="Nenhuma sincronização registrada" @retry="runs.load()">
          <div class="table-wrap attendance-table-wrap">
            <table class="attendance-table">
              <thead><tr><th>Início</th><th>Escopo</th><th>Origem</th><th>Status</th><th>Duração</th><th>Avisos</th><th>Ações</th></tr></thead>
              <tbody>
                <tr v-for="run in runs.items.value" :key="run.id">
                  <td>{{ formatDateTime(run.started_at) }}</td>
                  <td>{{ label('syncScope', run.scope) }}</td>
                  <td>{{ label('syncTrigger', run.trigger) }}</td>
                  <td><Badge :tone="tone('syncStatus', run.status)">{{ label('syncStatus', run.status) }}</Badge></td>
                  <td>{{ duration(run.duration_ms) }}</td>
                  <td>{{ warningTotal(run) || '—' }}</td>
                  <td><button class="btn ghost small" type="button" @click="openRun(run)">Ver</button></td>
                </tr>
              </tbody>
            </table>
          </div>
          <ul class="attendance-cards">
            <li v-for="run in runs.items.value" :key="run.id" class="card attendance-card">
              <div class="attendance-card-head">
                <b>{{ formatDateTime(run.started_at) }}</b>
                <Badge :tone="tone('syncStatus', run.status)">{{ label('syncStatus', run.status) }}</Badge>
              </div>
              <p>{{ label('syncScope', run.scope) }} · {{ label('syncTrigger', run.trigger) }} · {{ duration(run.duration_ms) }}</p>
              <div class="attendance-card-actions"><button class="btn ghost" type="button" @click="openRun(run)">Ver detalhes</button></div>
            </li>
          </ul>
          <Pager :meta="runs.meta.value" :disabled="runs.loading.value" @page="runs.page.value = $event" />
        </LoadState>
      </template>
    </section>

    <!-- Inscrições -->
    <section v-else-if="tab === 'inscricoes'" aria-label="Inscrições">
      <div class="attendance-bar">
        <FilterPanel class="attendance-filters" :active="registrations.activeFilters.value - (regFilters.search ? 1 : 0)" @clear="registrations.reset()">
          <template #search><Search v-model="regFilters.search" placeholder="Buscar por nome, e-mail ou código de check-in" /></template>
          <select v-model="regFilters.status" class="input attendance-filter" aria-label="Status da inscrição">
            <option value="">Todos os status</option>
            <option v-for="[code, name] in options('registrationStatus')" :key="code" :value="code">{{ name }}</option>
          </select>
          <select v-model="regFilters.confirmed" class="input attendance-filter" aria-label="Confirmada">
            <option value="">Confirmadas ou não</option>
            <option value="true">Só confirmadas</option>
            <option value="false">Não confirmadas</option>
          </select>
          <select v-model="regFilters.internal_category" class="input attendance-filter" aria-label="Categoria interna">
            <option value="">Todas as categorias</option>
            <option v-for="[code, name] in categoryOptions" :key="code" :value="code">{{ name }}</option>
          </select>
          <select v-model="regFilters.external_ticket_type_id" class="input attendance-filter" aria-label="Entrada" :disabled="!ticketChoices.data.value.length">
            <option value="">Todas as entradas</option>
            <option v-for="item in ticketChoices.data.value" :key="item.id" :value="String(item.id)">{{ item.external_name }}</option>
          </select>
          <select v-model="regFilters.unmapped" class="input attendance-filter" aria-label="Mapeamento da entrada">
            <option value="">Mapeadas ou não</option>
            <option value="true">Entrada sem mapeamento</option>
            <option value="false">Entrada mapeada</option>
          </select>
          <select v-model="regFilters.has_participant" class="input attendance-filter" aria-label="Participante">
            <option value="">Com ou sem participante</option>
            <option v-for="[code, name] in BOOL_OPTIONS" :key="code" :value="code">{{ code === 'true' ? 'Já é participante' : 'Ainda não é participante' }}</option>
          </select>
          <select v-model="regFilters.identity_mismatch" class="input attendance-filter" aria-label="Divergência de identidade">
            <option value="">Com ou sem divergência</option>
            <option value="true">Com divergência de identidade</option>
            <option value="false">Sem divergência</option>
          </select>
        </FilterPanel>
        <button v-if="canMaterialize" class="btn" type="button" :disabled="!configured" :title="configured ? '' : 'Configure a integração Even3 primeiro.'" @click="openMaterialize">Materializar participantes</button>
      </div>
      <LoadState :loading="registrations.loading.value" :loaded="registrations.loaded.value" :error="registrations.error.value" :empty="registrations.items.value.length === 0"
        :empty-title="registrations.activeFilters.value ? 'Nenhuma inscrição para estes filtros' : 'Nenhuma inscrição sincronizada'"
        :empty-text="registrations.activeFilters.value ? '' : 'As inscrições chegam da Even3 pela sincronização ou pelo webhook.'" @retry="registrations.load()">
        <div class="table-wrap attendance-table-wrap">
          <table class="attendance-table">
            <thead><tr><th>Pessoa</th><th>Entrada</th><th>Categoria</th><th>Status</th><th>Vínculos no HackLab</th><th>Inscrita em</th><th>Ações</th></tr></thead>
            <tbody>
              <tr v-for="item in registrations.items.value" :key="item.id">
                <td>
                  {{ item.person?.full_name || item.source?.name || '—' }}
                  <Badge v-if="item.identity_mismatch" tone="warn">Divergência</Badge>
                  <br /><small class="stat-hint">{{ item.person?.email || item.source?.email || '—' }}</small>
                </td>
                <td>{{ item.ticket?.external_name || item.external?.category_label || '—' }}</td>
                <td>
                  <Badge v-if="item.ticket?.internal_category" :tone="tone('ticketCategory', item.ticket.internal_category)">{{ label('ticketCategory', item.ticket.internal_category) }}</Badge>
                  <Badge v-else tone="warn">Sem mapeamento</Badge>
                </td>
                <td><Badge :tone="tone('registrationStatus', item.status)">{{ label('registrationStatus', item.status) }}</Badge></td>
                <td>
                  <div class="registration-links">
                    <Badge v-if="item.links?.participant" tone="info">Participante</Badge>
                    <Badge v-if="item.links?.juror" tone="orange">Jurado</Badge>
                    <Badge v-if="item.links?.user">Conta {{ label('role', item.links.user.role) }}</Badge>
                    <span v-if="!item.links?.participant && !item.links?.juror && !item.links?.user" class="stat-hint">Nenhum</span>
                  </div>
                </td>
                <td>{{ formatDateTime(item.external?.registered_at) }}</td>
                <td><button class="btn ghost small" type="button" @click="openRegistration(item)">Ver</button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <ul class="attendance-cards">
          <li v-for="item in registrations.items.value" :key="item.id" class="card attendance-card">
            <div class="attendance-card-head">
              <b>{{ item.person?.full_name || item.source?.name || '—' }}</b>
              <Badge :tone="tone('registrationStatus', item.status)">{{ label('registrationStatus', item.status) }}</Badge>
            </div>
            <p>{{ item.ticket?.external_name || '—' }} · {{ item.ticket?.internal_category ? label('ticketCategory', item.ticket.internal_category) : 'Sem mapeamento' }}</p>
            <div class="attendance-card-actions"><button class="btn ghost" type="button" @click="openRegistration(item)">Ver inscrição</button></div>
          </li>
        </ul>
        <Pager :meta="registrations.meta.value" :disabled="registrations.loading.value" @page="registrations.page.value = $event" />
      </LoadState>
    </section>

    <!-- Presença (somente leitura) -->
    <section v-else-if="tab === 'presenca'" aria-label="Presença">
      <p class="stat-hint presence-note">Check-ins feitos na Even3 e recebidos pela sincronização ou pelo webhook. O HackLab não registra presença.</p>
      <div class="attendance-bar">
        <Tabs v-if="dayTabs.length > 1" :tabs="dayTabs" :model-value="String(attFilters.event_day_id)" @update:model-value="(value) => (attFilters.event_day_id = value)" />
        <FilterPanel class="attendance-filters" :active="[attFilters.internal_category, attFilters.status].filter(Boolean).length" @clear="attFilters.internal_category = ''; attFilters.status = ''">
          <template #search><Search v-model="attFilters.search" placeholder="Buscar por nome" /></template>
          <select v-model="attFilters.internal_category" class="input attendance-filter" aria-label="Categoria interna">
            <option value="">Todas as categorias</option>
            <option v-for="[code, name] in categoryOptions" :key="code" :value="code">{{ name }}</option>
          </select>
          <select v-model="attFilters.status" class="input attendance-filter" aria-label="Status da inscrição">
            <option value="">Todos os status</option>
            <option v-for="[code, name] in options('registrationStatus')" :key="code" :value="code">{{ name }}</option>
          </select>
        </FilterPanel>
      </div>
      <p v-if="attendance.loaded.value" class="attendance-summary" aria-live="polite">
        <b>{{ dayTabs.find((item) => item.id === String(attFilters.event_day_id))?.label || 'Todos os dias' }}</b>
        <span>Pessoas com check-in <b>{{ formatNumber(attendance.meta.value.total) }}</b></span>
      </p>
      <LoadState :loading="attendance.loading.value" :loaded="attendance.loaded.value" :error="attendance.error.value" :empty="attendance.items.value.length === 0"
        :empty-title="attFilters.event_day_id || attFilters.search || attFilters.internal_category || attFilters.status ? 'Nenhum check-in para estes filtros' : 'Nenhum check-in registrado'"
        empty-text="A presença aparece aqui quando houver check-ins na Even3." @retry="attendance.load()">
        <div class="table-wrap attendance-table-wrap">
          <table class="attendance-table">
            <thead><tr><th>Pessoa</th><th>Entrada</th><th>Status</th><th>Dias presentes</th><th>Check-ins</th><th>Último check-in</th><th>Ações</th></tr></thead>
            <tbody>
              <tr v-for="row in attendance.items.value" :key="row.registration_id">
                <td>{{ row.person?.full_name || '—' }}</td>
                <td>
                  {{ row.ticket?.external_name || '—' }}
                  <template v-if="row.ticket?.internal_category"><br /><small class="stat-hint">{{ label('ticketCategory', row.ticket.internal_category) }}</small></template>
                </td>
                <td><Badge :tone="tone('registrationStatus', row.status)">{{ label('registrationStatus', row.status) }}</Badge></td>
                <td>
                  <div class="registration-links">
                    <Badge v-for="day in row.days_present" :key="day.event_day_id" tone="ok">{{ day.label || formatDate(day.date) }}</Badge>
                    <span v-if="!row.days_present?.length" class="stat-hint">Fora dos dias do evento</span>
                  </div>
                </td>
                <td>{{ formatNumber(row.check_ins) }}</td>
                <td>{{ formatDateTime(row.last_observed_at) }}</td>
                <td><button v-if="canRegistrations" class="btn ghost small" type="button" @click="openRegistration({ id: row.registration_id })">Ver inscrição</button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <ul class="attendance-cards">
          <li v-for="row in attendance.items.value" :key="row.registration_id" class="card attendance-card">
            <div class="attendance-card-head">
              <b>{{ row.person?.full_name || '—' }}</b>
              <Badge tone="ok">{{ formatNumber(row.check_ins) }} check-in(s)</Badge>
            </div>
            <p>{{ row.ticket?.external_name || '—' }} · {{ (row.days_present || []).map((day) => day.label || formatDate(day.date)).join(', ') || 'Fora dos dias do evento' }}</p>
            <p>Último: {{ formatDateTime(row.last_observed_at) }}</p>
            <div v-if="canRegistrations" class="attendance-card-actions"><button class="btn ghost" type="button" @click="openRegistration({ id: row.registration_id })">Ver inscrição</button></div>
          </li>
        </ul>
        <Pager :meta="attendance.meta.value" :disabled="attendance.loading.value" @page="attendance.page.value = $event" />
      </LoadState>
    </section>

    <!-- Modais -->
    <Modal v-if="modal === 'configure'" title="Configurar integração Even3" subtitle="Vincula este evento a um evento da Even3. O token fica só no servidor." @close="modal = null">
      <form class="form-grid" novalidate @submit.prevent="configure.run()">
        <Field label="ID do evento na Even3" required class-name="span-2" hint="Só letras, números, hífen e sublinhado." :error="configure.fieldErrors.value.external_event_id">
          <input v-model="configForm.external_event_id" class="input" maxlength="64" autocomplete="off" required />
        </Field>
        <Field label="Status" required :error="configure.fieldErrors.value.status">
          <select v-model="configForm.status" class="input">
            <option v-for="[code, name] in options('integrationStatus')" :key="code" :value="code">{{ name }}</option>
          </select>
        </Field>
      </form>
      <p v-if="configure.fieldErrors.value.integration" class="banner warn">{{ configure.fieldErrors.value.integration }}</p>
      <p class="stat-hint">Ao salvar, o servidor consulta o evento na Even3 para conferir o vínculo. Isso não importa inscrições: use a aba Sincronização.</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="configure.pending.value" @click="configure.run()">{{ configure.pending.value ? 'Salvando…' : 'Salvar integração' }}</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'mapping' && selectedTicket" title="Mapear entrada" :subtitle="selectedTicket.external_name" @close="modal = null">
      <Field label="Categoria interna" :error="mapping.fieldErrors.value.internal_category">
        <select v-model="mappingForm.internal_category" class="input">
          <option value="">Sem categoria (remover mapeamento)</option>
          <option v-for="[code, name] in categoryOptions" :key="code" :value="code">{{ name }}</option>
        </select>
      </Field>
      <p class="banner warn">A categoria só classifica as inscrições desta entrada. Não concede perfil, não cria Jurado, Empresa nem Usuário.</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="mapping.pending.value || (mappingForm.internal_category || null) === (selectedTicket.internal_category || null)" @click="mapping.run()">
          {{ mapping.pending.value ? 'Salvando…' : 'Salvar mapeamento' }}
        </button>
      </template>
    </Modal>

    <Modal v-if="modal === 'sync'" title="Sincronizar com a Even3?" subtitle="O servidor consulta a Even3 e atualiza os dados locais. Pode levar alguns minutos." @close="sync.pending.value || (modal = null)">
      <Field label="Escopo" :error="sync.fieldErrors.value.scope">
        <select v-model="syncForm.scope" class="input" :disabled="sync.pending.value">
          <option v-for="[code, name] in options('syncScope')" :key="code" :value="code">{{ name }}</option>
        </select>
      </Field>
      <p class="stat-hint">Completo: evento → entradas → inscritos. A sincronização é repetível e não duplica pessoas nem inscrições; não cria participantes, jurados, empresas nem contas.</p>
      <p v-if="sync.fieldErrors.value.integration" class="banner warn">{{ sync.fieldErrors.value.integration }}</p>
      <template #footer>
        <button class="btn ghost" type="button" :disabled="sync.pending.value" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="sync.pending.value" @click="sync.run()">{{ sync.pending.value ? 'Sincronizando…' : 'Sincronizar' }}</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'materialize'" title="Materializar participantes?" subtitle="Cria participantes a partir das inscrições confirmadas em entradas da categoria Participante." @close="materialize.pending.value || (modal = null)">
      <template v-if="!materializeResult">
        <p>Para cada inscrição <b>confirmada</b> em entrada mapeada como <b>Participante</b>, o HackLab cria um participante disponível, sem turma, se ele ainda não existir.</p>
        <p class="stat-hint">Participantes existentes não mudam (status, turma, equipe e desafio). A operação pode ser repetida sem duplicar.</p>
      </template>
      <dl v-else class="detail-list" aria-live="polite">
        <dt>Elegíveis</dt><dd>{{ formatNumber(materializeResult.eligible) }}</dd>
        <dt>Criados agora</dt><dd>{{ formatNumber(materializeResult.created) }}</dd>
        <dt>Já existiam</dt><dd>{{ formatNumber(materializeResult.already_existing) }}</dd>
      </dl>
      <template #footer>
        <template v-if="!materializeResult">
          <button class="btn ghost" type="button" :disabled="materialize.pending.value" @click="modal = null">Cancelar</button>
          <button class="btn" type="button" :disabled="materialize.pending.value" @click="materialize.run()">{{ materialize.pending.value ? 'Materializando…' : 'Materializar' }}</button>
        </template>
        <button v-else class="btn" type="button" @click="modal = null">Fechar</button>
      </template>
    </Modal>

    <!-- Detalhe da sincronização -->
    <Drawer v-if="runDetailId" title="Sincronização" :subtitle="runDetail.data.value ? `${label('syncScope', runDetail.data.value.scope)} · ${formatDateTime(runDetail.data.value.started_at)}` : ''" @close="closeRun">
      <LoadState :loading="runDetail.loading.value" :loaded="Boolean(runDetail.data.value)" :error="runDetail.error.value" @retry="runDetail.load()">
        <template v-if="runDetail.data.value">
          <dl class="detail-list">
            <dt>Status</dt><dd><Badge :tone="tone('syncStatus', runDetail.data.value.status)">{{ label('syncStatus', runDetail.data.value.status) }}</Badge></dd>
            <dt>Escopo</dt><dd>{{ label('syncScope', runDetail.data.value.scope) }}</dd>
            <dt>Origem</dt><dd>{{ label('syncTrigger', runDetail.data.value.trigger) }}</dd>
            <dt>Início</dt><dd>{{ formatDateTime(runDetail.data.value.started_at) }}</dd>
            <dt>Fim</dt><dd>{{ formatDateTime(runDetail.data.value.finished_at) }}</dd>
            <dt>Duração</dt><dd>{{ duration(runDetail.data.value.duration_ms) }}</dd>
            <template v-if="runDetail.data.value.error_code"><dt>Erro</dt><dd><span class="ticket-code">{{ runDetail.data.value.error_code }}</span></dd></template>
            <template v-if="runDetail.data.value.error_message"><dt>Mensagem</dt><dd>{{ runDetail.data.value.error_message }}</dd></template>
          </dl>
          <h3 class="ops-title">Contadores</h3>
          <p v-if="!counterRows.length" class="stat-hint">Sem contadores registrados.</p>
          <dl v-else class="detail-list">
            <template v-for="row in counterRows" :key="row.key"><dt>{{ row.label }}</dt><dd>{{ formatNumber(row.value) }}</dd></template>
          </dl>
          <h3 class="ops-title">Avisos</h3>
          <p v-if="!warningCodeRows.length" class="stat-hint">Nenhum aviso.</p>
          <dl v-else class="detail-list">
            <template v-for="row in warningCodeRows" :key="row.code"><dt><span class="ticket-code">{{ row.code }}</span></dt><dd>{{ formatNumber(row.count) }}</dd></template>
          </dl>
          <ul v-if="runDetail.data.value.warnings?.length" class="audit-changes sync-warnings">
            <li v-for="(item, index) in runDetail.data.value.warnings" :key="index">
              <span>{{ item.code }}<template v-if="item.external_id"> · ID externo {{ item.external_id }}</template></span>
              <p>{{ item.message }}</p>
            </li>
          </ul>
          <p v-else-if="warningCodeRows.length && !canManage" class="stat-hint">O detalhe dos avisos é visível só para quem administra a integração.</p>
        </template>
      </LoadState>
      <template #footer><button class="btn ghost" type="button" @click="closeRun">Fechar</button></template>
    </Drawer>

    <!-- Detalhe da inscrição -->
    <Drawer v-if="registrationId" title="Inscrição" :subtitle="registrationDetail.data.value?.person?.full_name || registrationDetail.data.value?.source?.name || ''" @close="closeRegistration">
      <LoadState :loading="registrationDetail.loading.value" :loaded="Boolean(registrationDetail.data.value?.status)" :error="registrationDetail.error.value" @retry="registrationDetail.load()">
        <template v-if="registrationDetail.data.value?.status">
          <dl class="detail-list">
            <dt>Status</dt><dd><Badge :tone="tone('registrationStatus', registrationDetail.data.value.status)">{{ label('registrationStatus', registrationDetail.data.value.status) }}</Badge></dd>
            <dt>Status desde</dt><dd>{{ formatDateTime(registrationDetail.data.value.status_changed_at) }}</dd>
            <dt>Pessoa no HackLab</dt><dd>{{ registrationDetail.data.value.person?.full_name || '—' }}</dd>
            <dt>E-mail</dt><dd>{{ registrationDetail.data.value.person?.email || '—' }}</dd>
            <dt>Entrada</dt><dd>{{ registrationDetail.data.value.ticket?.external_name || registrationDetail.data.value.external?.category_label || '—' }}</dd>
            <dt>Categoria interna</dt>
            <dd>
              <Badge v-if="registrationDetail.data.value.ticket?.internal_category" :tone="tone('ticketCategory', registrationDetail.data.value.ticket.internal_category)">{{ label('ticketCategory', registrationDetail.data.value.ticket.internal_category) }}</Badge>
              <Badge v-else tone="warn">Sem mapeamento</Badge>
            </dd>
            <dt>Inscrita em</dt><dd>{{ formatDateTime(registrationDetail.data.value.external?.registered_at) }}</dd>
            <dt>Código de check-in</dt><dd><span class="ticket-code">{{ registrationDetail.data.value.external?.check_in_code || '—' }}</span></dd>
            <dt>Última sincronização</dt><dd>{{ formatDateTime(registrationDetail.data.value.last_synced_at) }}</dd>
          </dl>
          <p v-if="registrationDetail.data.value.identity_mismatch" class="banner warn">Os dados da Even3 divergem da pessoa vinculada no HackLab. Confira antes de usar esta inscrição.</p>

          <h3 class="ops-title">Dados informados na Even3</h3>
          <dl class="detail-list">
            <dt>Nome</dt><dd>{{ registrationDetail.data.value.source?.name || '—' }}</dd>
            <dt>Nome no crachá</dt><dd>{{ registrationDetail.data.value.source?.badge_name || '—' }}</dd>
            <dt>E-mail</dt><dd>{{ registrationDetail.data.value.source?.email || '—' }}</dd>
            <dt>Telefone</dt><dd>{{ registrationDetail.data.value.source?.phone || '—' }}</dd>
            <dt>Documento</dt><dd>{{ registrationDetail.data.value.source?.document || '—' }}</dd>
            <dt>ID do inscrito</dt><dd><span class="ticket-code">{{ registrationDetail.data.value.external?.attendee_id || '—' }}</span></dd>
            <dt>ID da inscrição</dt><dd><span class="ticket-code">{{ registrationDetail.data.value.external?.registration_id || '—' }}</span></dd>
          </dl>

          <h3 class="ops-title">Vínculos no HackLab</h3>
          <p class="stat-hint">Resumo da mesma pessoa. A inscrição não concede perfil nem vínculo.</p>
          <dl class="detail-list">
            <dt>Participante</dt><dd>{{ registrationDetail.data.value.links?.participant ? label('participantStatus', registrationDetail.data.value.links.participant.status) : 'Não' }}</dd>
            <dt>Jurado</dt><dd>{{ registrationDetail.data.value.links?.juror ? label('jurorStatus', registrationDetail.data.value.links.juror.status) : 'Não' }}</dd>
            <dt>Conta</dt><dd>{{ registrationDetail.data.value.links?.user ? `${label('role', registrationDetail.data.value.links.user.role)} · ${label('activeStatus', registrationDetail.data.value.links.user.status)}` : 'Sem conta' }}</dd>
          </dl>

          <template v-if="registrationDetail.data.value.attendance">
            <h3 class="ops-title">Check-ins</h3>
            <p v-if="!registrationDetail.data.value.attendance.length" class="stat-hint">Nenhum check-in registrado na Even3.</p>
            <dl v-else class="ticket-days">
              <template v-for="record in registrationDetail.data.value.attendance" :key="record.id">
                <dt>{{ attendanceDay(record) }}</dt>
                <dd>
                  <Badge tone="ok">{{ label('attendanceKind', record.kind) }}</Badge>
                  <span class="stat-hint">{{ formatDateTime(record.checked_in_at || record.observed_at) }} · {{ label('attendanceSource', record.source) }}</span>
                </dd>
              </template>
            </dl>
          </template>
        </template>
      </LoadState>
      <template #footer><button class="btn ghost" type="button" @click="closeRegistration">Fechar</button></template>
    </Drawer>
  </Page>
</template>
