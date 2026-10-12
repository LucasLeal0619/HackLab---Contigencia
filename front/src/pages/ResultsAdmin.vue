<script setup>
import Badge from '../components/Badge.vue'
import Empty from '../components/Empty.vue'
import Field from '../components/Field.vue'
import Icon from '../components/Icon.vue'
import LoadState from '../components/LoadState.vue'
import Modal from '../components/Modal.vue'
import Page from '../components/Page.vue'
import ResultSnapshot from '../components/ResultSnapshot.vue'
import Tabs from '../components/Tabs.vue'
import { useResultsAdmin } from '@/js/pages/results-admin'

const {
  canPreview, canPublish, canPublished, canSessions, tabs, tab, setTab, sessionId, sessions, changeSession, preview, publications, published,
  blockers, pending, current, modal, reason, viewing, askPublish, askRevoke, viewSnapshot, publish, revoke, label, tone, go, formatDateTime,
} = useResultsAdmin()
</script>

<template>
  <Page title="Resultados" subtitle="Resultado técnico dos jurados e Escolha do Público, em blocos separados e sem soma automática.">
    <template #actions>
      <button v-if="canPublished" class="btn ghost" type="button" @click="go('painel')">Painel de Resultados</button>
      <button v-if="canPublished" class="btn ghost" type="button" @click="go('apresentacao')">Modo Apresentação</button>
      <button v-if="canPublish && current" class="btn danger" type="button" @click="askRevoke">Revogar publicação</button>
    </template>

    <Tabs v-if="tabs.length > 1" :tabs="tabs" :model-value="tab" @update:model-value="setTab" />

    <section v-if="tab === 'previa' && canPreview">
      <p class="banner">Prévia administrativa: não é o resultado oficial. Só a publicação congela um resultado e o torna visível para os demais perfis.</p>
      <div class="row-between mt">
        <Field label="Sessão de votação (encerrada)" hint="A Escolha do Público publicada vem desta sessão.">
          <select class="input" :value="sessionId" :disabled="!canSessions || sessions.loading.value" @change="changeSession($event.target.value)">
            <option value="">{{ sessions.loading.value ? 'Carregando…' : 'Selecione' }}</option>
            <option v-for="item in sessions.data.value" :key="item.id" :value="String(item.id)">{{ item.name }} · encerrada em {{ formatDateTime(item.closed_at) }}</option>
          </select>
        </Field>
        <div class="row-actions">
          <button class="btn ghost" type="button" :disabled="preview.loading.value" @click="preview.load()">{{ preview.loading.value ? 'Atualizando…' : 'Atualizar prévia' }}</button>
          <button v-if="canPublish" class="btn" type="button" :disabled="!preview.data.value?.can_publish || preview.loading.value" @click="askPublish">Publicar resultado</button>
        </div>
      </div>
      <p v-if="sessions.loaded.value && !sessions.data.value.length" class="stat-hint">Nenhuma sessão de votação encerrada neste evento. Encerre a votação em Votação para publicar.</p>
      <p v-if="sessions.error.value" class="banner err">{{ sessions.error.value.message }}</p>

      <LoadState :loading="preview.loading.value" :loaded="preview.loaded.value" :error="preview.error.value" :empty="!preview.data.value" @retry="preview.load()">
        <template v-if="preview.data.value">
          <div v-if="blockers.length" class="banner warn mt" role="alert">
            <div>
              <strong>Publicação bloqueada</strong>
              <ul class="results-blockers">
                <li v-for="item in blockers" :key="item.code">{{ item.message }}</li>
              </ul>
            </div>
          </div>
          <p v-else class="banner ok mt">Tudo pronto para publicar.</p>

          <template v-if="pending.length">
            <h3 class="ops-title">Atribuições ativas sem avaliação enviada ({{ preview.data.value.completeness.pending_count }})</h3>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Equipe</th><th>Jurado</th><th>Situação da avaliação</th></tr></thead>
                <tbody>
                  <tr v-for="item in pending" :key="item.assignment_id">
                    <td>{{ item.team.name }}</td>
                    <td>{{ item.juror.name }}</td>
                    <td>{{ item.evaluation_status === 'NOT_STARTED' ? 'Não iniciada' : label('evaluationStatus', item.evaluation_status) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>

          <ResultSnapshot class="mt" :result="preview.data.value" />
        </template>
      </LoadState>
    </section>

    <section v-if="tab === 'publicacoes' && canPreview">
      <LoadState :loading="publications.loading.value" :loaded="publications.loaded.value" :error="publications.error.value" :empty="publications.data.value.length === 0" empty-title="Nenhuma publicação" empty-text="As versões publicadas e revogadas aparecem aqui." @retry="publications.load()">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Versão</th><th>Status</th><th>Votação</th><th>Publicado em</th><th>Revogado em</th><th>Motivo da revogação</th><th>Ações</th></tr></thead>
            <tbody>
              <tr v-for="item in publications.data.value" :key="item.id">
                <td>v{{ item.version }}</td>
                <td><Badge :tone="tone('publicationStatus', item.status)">{{ label('publicationStatus', item.status) }}</Badge></td>
                <td>{{ item.snapshot?.voting_session?.name || '—' }}</td>
                <td>{{ formatDateTime(item.published_at) }}</td>
                <td>{{ formatDateTime(item.revoked_at) }}</td>
                <td>{{ item.revoke_reason || '—' }}</td>
                <td><button class="btn ghost small" type="button" @click="viewSnapshot(item)">Ver resultado</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </LoadState>
    </section>

    <section v-if="tab === 'oficial' && canPublished">
      <LoadState :loading="published.loading.value" :loaded="published.loaded.value" :error="published.error.value" :empty="!current" @retry="published.load()">
        <template #empty>
          <div class="results-wait">
            <div class="results-mark" aria-hidden="true"><Icon name="trophy" :size="32" /></div>
            <h2>Resultados ainda não divulgados</h2>
            <p>Aguarde a publicação oficial dos resultados.</p>
          </div>
        </template>
        <template v-if="current">
          <p class="results-meta">
            <Badge tone="ok">Resultado oficial · versão {{ current.version }}</Badge>
            <span class="stat-hint">Publicado em {{ formatDateTime(current.published_at) }}</span>
          </p>
          <ResultSnapshot :result="current.snapshot" />
        </template>
      </LoadState>
    </section>

    <Empty v-if="!tabs.length" title="Sem acesso aos resultados" />

    <Modal v-if="modal === 'publish'" title="Publicar resultado oficial?" subtitle="O resultado é congelado nesta versão e passa a ser visto por todos os perfis. As operações técnicas ficam travadas até uma eventual revogação." @close="modal = null">
      <p>Votação do público: <b>{{ preview.data.value?.voting_session?.name || '—' }}</b></p>
      <p>O resultado técnico e a Escolha do Público são publicados em blocos separados, sem nota combinada.</p>
      <p v-for="(message, field) in publish.fieldErrors.value" :key="field" class="field-error">{{ message }}</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="publish.pending.value" @click="publish.run()">{{ publish.pending.value ? 'Publicando…' : 'Publicar resultado' }}</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'revoke'" title="Revogar publicação?" :subtitle="current ? `Versão ${current.version}. O resultado deixa de ser oficial; o registro continua no histórico.` : ''" @close="modal = null">
      <Field label="Motivo" required :error="revoke.fieldErrors.value.reason || revoke.fieldErrors.value.publication">
        <textarea v-model="reason" class="input" rows="3" maxlength="2000" />
      </Field>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn danger" type="button" :disabled="revoke.pending.value || !reason.trim()" @click="revoke.run()">{{ revoke.pending.value ? 'Revogando…' : 'Revogar publicação' }}</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'snapshot' && viewing" :title="`Resultado publicado · versão ${viewing.version}`" :subtitle="`${label('publicationStatus', viewing.status)} · publicado em ${formatDateTime(viewing.published_at)}`" wide @close="modal = null">
      <ResultSnapshot :result="viewing.snapshot || {}" />
      <template #footer><button class="btn" type="button" @click="modal = null">Fechar</button></template>
    </Modal>
  </Page>
</template>
