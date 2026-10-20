<script setup>
import Badge from '../components/Badge.vue'
import Drawer from '../components/Drawer.vue'
import Empty from '../components/Empty.vue'
import Even3Result from '../components/Even3Result.vue'
import Field from '../components/Field.vue'
import Icon from '../components/Icon.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import Pager from '../components/Pager.vue'
import Search from '../components/Search.vue'
import Stat from '../components/Stat.vue'
import { useAttendance } from '@/js/pages/attendance'

const props = defineProps({
  params: { type: Object, default: () => ({}) },
})
const {
  ATTENTION, canView, canManage, canSync, canRegistrations, canLink, even3, busy, lastSync, sync,
  attention, companies, chosenCompany, link, people, registrationId, registrationDetail, openRegistration, closeRegistration, attendanceDay,
  settingsOpen, openSettings, closeSettings, status, tickets, runs, metadata, divergences,
  modal, configForm, configWarnings, openConfigure, configure, checkConnection, categoryOptions, mapping,
  go, label, tone, options, formatDate, formatDateTime, formatNumber,
} = useAttendance(props)
const filters = people.filters
</script>

<template>
  <Page title="Presença" subtitle="Inscritos na Even3 e check-ins do evento. O credenciamento é feito na Even3.">
    <template #actions>
      <span v-if="canView && even3.configured" class="even3-inline" :class="{ busy }"><span class="even3-dot" aria-hidden="true" /> Even3 · {{ busy ? 'Sincronizando…' : lastSync }}</span>
      <button v-if="canSync && even3.configured" class="btn" type="button" :disabled="busy || sync.pending.value || even3.integrationStatus !== 'ACTIVE'" @click="sync.run()">
        {{ busy || sync.pending.value ? 'Sincronizando…' : 'Sincronizar' }}
      </button>
      <button v-if="canView" class="btn ghost" type="button" aria-label="Configuração Even3" @click="openSettings"><Icon name="sliders" :size="16" /> Configuração</button>
    </template>

    <Empty v-if="canView && even3.loaded && !even3.configured" title="Even3 não configurada" text="Vincule este evento ao evento da Even3 para ver inscritos e presença.">
      <template v-if="canManage" #action><button class="btn" type="button" @click="openSettings">Configurar</button></template>
    </Empty>

    <template v-else>
      <Even3Result v-if="even3.lastResult" :result="even3.lastResult" />

      <div v-if="even3.totals" class="grid cols-4 integration-stats">
        <Stat icon="users" label="Inscritos" :value="formatNumber(even3.totals.registered)" hint="Inscrições confirmadas" />
        <Stat icon="check" label="Presentes" :value="formatNumber(even3.totals.present)" hint="Com check-in na Even3" />
        <Stat icon="calendar" label="Sem registro" :value="formatNumber(even3.totals.without_record)" hint="Sem check-in até agora" />
        <Stat icon="alert" label="Pendências" :value="formatNumber(even3.attention?.total || 0)" hint="Precisam de decisão" />
      </div>

      <!-- Precisa de atenção: só exceções reais -->
      <section v-if="attention.data.value.length" class="card even3-attention-card" aria-labelledby="attention-title">
        <h3 id="attention-title">Precisa de atenção ({{ attention.data.value.length }})</h3>
        <ul class="recon-list">
          <li v-for="item in attention.data.value" :key="`${item.type}-${item.person?.id || item.ticket?.id}`" class="recon-item">
            <div>
              <Badge tone="warn">{{ ATTENTION[item.type]?.title || item.type }}</Badge>
              <b v-if="item.person"> {{ item.person.full_name }}</b>
              <b v-else-if="item.ticket"> {{ item.ticket.name }}</b>
              <small class="stat-hint">
                <template v-if="item.person">{{ item.person.email || 'sem e-mail' }}<template v-if="item.category"> · {{ item.category }}</template></template>
                <template v-else-if="item.ticket">{{ item.ticket.registrations }} inscrição(ões)</template>
              </small>
              <small class="stat-hint">{{ ATTENTION[item.type]?.hint }}</small>
            </div>
            <div class="recon-item-actions">
              <template v-if="item.type === 'COMPANY_UNLINKED' && canLink">
                <select v-model="chosenCompany[item.person.id]" class="input" :aria-label="`Empresa de ${item.person.full_name}`">
                  <option :value="undefined">Empresa…</option>
                  <option v-for="company in companies.data.value.filter((c) => c.status !== 'INACTIVE')" :key="company.id" :value="company.id">{{ company.name }}</option>
                </select>
                <button class="btn small" type="button" :disabled="!chosenCompany[item.person.id] || link.pending.value" @click="link.run(item)">Vincular</button>
              </template>
              <button v-if="item.type === 'PARTICIPANT_NOT_FOUND' && canManage" class="btn ghost small" type="button" @click="go('participantes')">Participantes</button>
              <button v-if="item.type === 'UNMAPPED_TICKET' && canManage" class="btn small" type="button" @click="openSettings">Mapear</button>
              <button v-if="item.registration_id" class="btn ghost small" type="button" @click="openRegistration(item.registration_id)">Detalhes</button>
            </div>
          </li>
        </ul>
      </section>

      <!-- Inscritos com presença -->
      <section v-if="canRegistrations" aria-label="Inscritos">
        <div class="attendance-bar">
          <Search v-model="filters.search" placeholder="Buscar pessoa…" />
          <div class="chips" role="tablist" aria-label="Presença">
            <button v-for="[id, name] in [['', 'Todos'], ['present', 'Presentes'], ['none', 'Sem registro']]" :key="id" type="button" class="chip" :class="{ on: filters.presence === id }" @click="filters.presence = id">{{ name }}</button>
          </div>
        </div>
        <LoadState :loading="people.loading.value" :loaded="people.loaded.value" :error="people.error.value" :empty="people.items.value.length === 0"
          :empty-title="filters.search || filters.presence ? 'Ninguém com estes filtros' : 'Nenhum inscrito ainda'"
          empty-text="Os inscritos chegam da Even3 pela sincronização ou pelo webhook." @retry="people.load()">
          <div class="table-wrap attendance-table-wrap">
            <table class="attendance-table">
              <thead><tr><th>Nome</th><th>Categoria</th><th>Presença</th></tr></thead>
              <tbody>
                <tr v-for="item in people.items.value" :key="item.id" class="clickable" @click="openRegistration(item.id)">
                  <td>{{ item.person?.full_name || item.source?.name || '—' }}<br /><small class="stat-hint">{{ item.person?.email || '—' }}</small></td>
                  <td>{{ item.ticket?.internal_category ? label('ticketCategory', item.ticket.internal_category) : (item.ticket?.external_name || '—') }}</td>
                  <td><Badge :tone="item.check_ins ? 'ok' : ''">{{ item.check_ins ? 'Presente' : 'Sem registro' }}</Badge></td>
                </tr>
              </tbody>
            </table>
          </div>
          <ul class="attendance-cards">
            <li v-for="item in people.items.value" :key="item.id" class="card attendance-card" @click="openRegistration(item.id)">
              <div class="attendance-card-head">
                <b>{{ item.person?.full_name || item.source?.name || '—' }}</b>
                <Badge :tone="item.check_ins ? 'ok' : ''">{{ item.check_ins ? 'Presente' : 'Sem registro' }}</Badge>
              </div>
              <p>{{ item.ticket?.internal_category ? label('ticketCategory', item.ticket.internal_category) : (item.ticket?.external_name || '—') }}</p>
            </li>
          </ul>
          <Pager :meta="people.meta.value" :disabled="people.loading.value" @page="people.page.value = $event" />
        </LoadState>
      </section>
    </template>

    <!-- Configuração Even3 (discreta) -->
    <Drawer v-if="settingsOpen" title="Configuração Even3" subtitle="Vínculo, entradas e histórico. O token fica só no servidor." @close="closeSettings">
      <LoadState :loading="status.loading.value" :loaded="status.loaded.value" :error="status.error.value" @retry="status.load()">
        <template v-if="status.data.value">
          <dl class="detail-list">
            <dt>Evento externo</dt><dd><span class="ticket-code">{{ status.data.value.external_event_id || '—' }}</span> {{ status.data.value.external_event_title || '' }}</dd>
            <dt>Status</dt><dd><Badge v-if="status.data.value.configured" :tone="tone('integrationStatus', status.data.value.status)">{{ label('integrationStatus', status.data.value.status) }}</Badge><span v-else>Não configurada</span></dd>
            <dt>Última sincronização</dt><dd>{{ formatDateTime(status.data.value.last_sync_at) }} <Badge v-if="status.data.value.last_sync_status" :tone="tone('syncStatus', status.data.value.last_sync_status)">{{ label('syncStatus', status.data.value.last_sync_status) }}</Badge></dd>
            <dt>Entradas mapeadas</dt><dd>{{ even3.tickets ? `${even3.tickets.mapped}/${even3.tickets.active}` : '—' }}</dd>
            <dt>Token / webhook</dt><dd>{{ status.data.value.token_configured ? 'Token configurado' : 'Token não configurado' }} · {{ status.data.value.webhook_configured ? 'webhook configurado' : 'webhook não configurado' }}</dd>
          </dl>
          <div v-if="configWarnings.length" class="banner warn"><ul class="integration-warnings"><li v-for="item in configWarnings" :key="item">{{ item }}</li></ul></div>
          <div v-if="canManage" class="row-actions">
            <button class="btn ghost small" type="button" @click="openConfigure">{{ status.data.value.configured ? 'Editar vínculo' : 'Configurar' }}</button>
            <button v-if="status.data.value.configured" class="btn ghost small" type="button" :disabled="checkConnection.pending.value" @click="checkConnection.run()">{{ checkConnection.pending.value ? 'Verificando…' : 'Verificar conexão' }}</button>
          </div>
        </template>
      </LoadState>

      <template v-if="even3.configured">
        <h3 class="ops-title">Entradas</h3>
        <p class="stat-hint">Diga uma vez o que cada entrada da Even3 significa. Não concede perfil nem cria empresa ou conta.</p>
        <ul class="even3-tickets">
          <li v-for="ticket in tickets.data.value" :key="ticket.id">
            <span>{{ ticket.external_name }} <small class="stat-hint">· {{ ticket.registrations_count }} inscrição(ões){{ ticket.active ? '' : ' · inativa' }}</small></span>
            <select v-if="canManage" class="input" :value="ticket.internal_category || ''" :disabled="mapping.pending.value" :aria-label="`Categoria de ${ticket.external_name}`" @change="mapping.run(ticket, $event.target.value)">
              <option value="">Sem mapeamento</option>
              <option v-for="[code, name] in categoryOptions" :key="code" :value="code">{{ name }}</option>
            </select>
            <Badge v-else :tone="ticket.internal_category ? 'info' : 'warn'">{{ ticket.internal_category ? label('ticketCategory', ticket.internal_category) : 'Sem mapeamento' }}</Badge>
          </li>
        </ul>

        <template v-if="divergences.length">
          <h3 class="ops-title">Evento diferente na Even3</h3>
          <p class="stat-hint">Só aviso: nome e datas do evento são definidos no HackLab (página Evento).</p>
          <dl class="detail-list">
            <template v-for="row in divergences" :key="row.field">
              <dt>{{ { name: 'Nome', start_date: 'Data inicial', end_date: 'Data final' }[row.field] }}</dt>
              <dd>HackLab: {{ row.field === 'name' ? row.hacklab : formatDate(row.hacklab) }} · Even3: {{ row.field === 'name' ? row.even3 : formatDate(row.even3) }}</dd>
            </template>
          </dl>
        </template>

        <h3 class="ops-title">Histórico</h3>
        <p v-if="!runs.data.value.length" class="stat-hint">Nenhuma sincronização ainda.</p>
        <ul v-else class="even3-history">
          <li v-for="run in runs.data.value" :key="run.id">
            <span>{{ formatDateTime(run.started_at) }} <small class="stat-hint">· {{ run.trigger === 'SCHEDULED' ? 'automática' : 'manual' }}</small></span>
            <Badge :tone="tone('syncStatus', run.status)">{{ label('syncStatus', run.status) }}</Badge>
          </li>
        </ul>
      </template>
      <template #footer><button class="btn ghost" type="button" @click="closeSettings">Fechar</button></template>
    </Drawer>

    <Modal v-if="modal === 'configure'" title="Vínculo com a Even3" subtitle="Vincula este evento a um evento da Even3. O token fica só no servidor." @close="modal = null">
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
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="configure.pending.value" @click="configure.run()">{{ configure.pending.value ? 'Salvando…' : 'Salvar' }}</button>
      </template>
    </Modal>

    <!-- Detalhe da inscrição -->
    <Drawer v-if="registrationId" title="Inscrição" :subtitle="registrationDetail.data.value?.person?.full_name || registrationDetail.data.value?.source?.name || ''" @close="closeRegistration">
      <LoadState :loading="registrationDetail.loading.value" :loaded="Boolean(registrationDetail.data.value?.status)" :error="registrationDetail.error.value" @retry="registrationDetail.load()">
        <template v-if="registrationDetail.data.value?.status">
          <dl class="detail-list">
            <dt>Status</dt><dd><Badge :tone="tone('registrationStatus', registrationDetail.data.value.status)">{{ label('registrationStatus', registrationDetail.data.value.status) }}</Badge></dd>
            <dt>Pessoa no HackLab</dt><dd>{{ registrationDetail.data.value.person?.full_name || '—' }} · {{ registrationDetail.data.value.person?.email || '—' }}</dd>
            <dt>Entrada</dt><dd>{{ registrationDetail.data.value.ticket?.external_name || registrationDetail.data.value.external?.category_label || '—' }}</dd>
            <dt>Categoria</dt>
            <dd>
              <Badge v-if="registrationDetail.data.value.ticket?.internal_category" :tone="tone('ticketCategory', registrationDetail.data.value.ticket.internal_category)">{{ label('ticketCategory', registrationDetail.data.value.ticket.internal_category) }}</Badge>
              <Badge v-else tone="warn">Sem mapeamento</Badge>
            </dd>
            <dt>Inscrita em</dt><dd>{{ formatDateTime(registrationDetail.data.value.external?.registered_at) }}</dd>
            <dt>ID na Even3</dt><dd><span class="ticket-code">{{ registrationDetail.data.value.external?.attendee_id || '—' }}</span></dd>
            <dt>Vínculos</dt>
            <dd>
              {{ registrationDetail.data.value.links?.participant ? 'Participante' : '' }}
              {{ registrationDetail.data.value.links?.juror ? 'Jurado' : '' }}
              {{ registrationDetail.data.value.links?.user ? `Conta ${label('role', registrationDetail.data.value.links.user.role)}` : '' }}
              <template v-if="!registrationDetail.data.value.links?.participant && !registrationDetail.data.value.links?.juror && !registrationDetail.data.value.links?.user">Nenhum</template>
            </dd>
          </dl>
          <p v-if="registrationDetail.data.value.identity_mismatch" class="banner warn">O e-mail ou documento da Even3 é diferente do cadastro no HackLab ({{ registrationDetail.data.value.source?.email || 'e-mail não informado' }}). Confira antes de usar esta inscrição.</p>

          <template v-if="registrationDetail.data.value.attendance">
            <h3 class="ops-title">Check-ins</h3>
            <p v-if="!registrationDetail.data.value.attendance.length" class="stat-hint">Sem registro de check-in.</p>
            <dl v-else class="ticket-days">
              <template v-for="record in registrationDetail.data.value.attendance" :key="record.id">
                <dt>{{ attendanceDay(record) }}</dt>
                <dd><Badge tone="ok">{{ label('attendanceKind', record.kind) }}</Badge> <span class="stat-hint">{{ formatDateTime(record.checked_in_at || record.observed_at) }}</span></dd>
              </template>
            </dl>
          </template>
        </template>
      </LoadState>
      <template #footer><button class="btn ghost" type="button" @click="closeRegistration">Fechar</button></template>
    </Drawer>
  </Page>
</template>
