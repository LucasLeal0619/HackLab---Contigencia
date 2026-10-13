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
import Stat from '../components/Stat.vue'
import Tabs from '../components/Tabs.vue'
import { useVotingAdmin } from '@/js/pages/voting-admin'

const {
  list, canManage, canEligibility, canAggregates, canTeams, canUsers, selectedId, detail, session, isDraft, tab, tabs, setTab,
  voters, turnout, counting, openSession, closeDrawer, refreshSession, modal, form, startCreate, startRename, save,
  editing, choice, teams, teamOptions, toggle, selectAll, clearChoice, editCandidates, saveCandidates,
  voterAccounts, voterSearch, voterOptions, loadingCurrent, editEligibles, saveEligibles,
  confirm, reason, reasonRequired, ask, transition, confirmText, percent, label, tone, options, formatDateTime, readiness, addPublic,
} = useVotingAdmin()
const { filters, page, items, meta, loading, error, loaded } = list
</script>

<template>
  <Page title="Votação" subtitle="Escolha do Público: sessões de votação com equipes candidatas e eleitores elegíveis definidos explicitamente.">
    <template #actions>
      <button v-if="canManage" class="btn" type="button" @click="startCreate">Nova sessão</button>
      <Badge v-else>Consulta</Badge>
    </template>

    <p class="banner">A votação do público é independente do resultado técnico dos jurados. Os dois resultados não são somados. Nenhuma tela mostra em quem cada eleitor votou.</p>

    <FilterPanel :active="list.activeFilters.value" @clear="list.reset()">
      <template #search><Search v-model="filters.search" placeholder="Buscar sessão pelo nome" /></template>
      <select v-model="filters.status" class="input" aria-label="Status">
        <option value="">Todos os status</option>
        <option v-for="[code, name] in options('votingStatus')" :key="code" :value="code">{{ name }}</option>
      </select>
    </FilterPanel>

    <LoadState :loading="loading" :loaded="loaded" :error="error" :empty="items.length === 0" empty-title="Nenhuma sessão de votação" :empty-text="canManage ? 'Crie uma sessão, defina as equipes candidatas e os eleitores elegíveis e abra a votação.' : ''" @retry="list.load()">
      <div class="table-wrap">
        <table>
          <thead><tr><th>Sessão</th><th>Status</th><th>Candidatas</th><th>Elegíveis</th><th>Votos</th><th>Aberta em</th><th>Encerrada em</th><th>Ações</th></tr></thead>
          <tbody>
            <tr v-for="item in items" :key="item.id">
              <td>{{ item.name }}</td>
              <td><Badge :tone="tone('votingStatus', item.status)">{{ label('votingStatus', item.status) }}</Badge></td>
              <td>{{ item.candidates_count ?? '—' }}</td>
              <td>{{ item.eligible_voters_count ?? '—' }}</td>
              <td>{{ item.votes_cast_count ?? '—' }}</td>
              <td>{{ formatDateTime(item.opened_at) }}</td>
              <td>{{ formatDateTime(item.closed_at || item.cancelled_at) }}</td>
              <td><button class="btn ghost small" type="button" @click="openSession(item)">{{ canManage ? 'Gerenciar' : 'Ver' }}</button></td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pager :meta="meta" :disabled="loading" @page="page = $event" />
    </LoadState>

    <Drawer v-if="selectedId && session" :title="session.name" :subtitle="`${label('votingStatus', session.status)} · ${session.candidates_count ?? 0} candidata(s) · ${session.eligible_voters_count ?? 0} elegível(is)`" @close="closeDrawer">
      <LoadState :loading="detail.loading.value" :loaded="detail.loaded.value" :error="detail.error.value" @retry="detail.load()">
        <div class="vote-admin">
          <div class="row-between">
            <Badge :tone="tone('votingStatus', session.status)">{{ label('votingStatus', session.status) }}</Badge>
            <button class="btn ghost small" type="button" :disabled="detail.loading.value" @click="refreshSession">{{ detail.loading.value ? 'Atualizando…' : 'Atualizar' }}</button>
          </div>
          <p class="stat-hint">
            Aberta em {{ formatDateTime(session.opened_at) }} · Encerrada em {{ formatDateTime(session.closed_at) }}
            <template v-if="session.cancelled_at"> · Cancelada em {{ formatDateTime(session.cancelled_at) }}</template>
          </p>
          <p v-if="session.cancel_reason" class="stat-hint">Motivo do cancelamento: {{ session.cancel_reason }}</p>
          <p v-if="session.status === 'DRAFT'" class="stat-hint">Em rascunho: candidatas e eleitores podem ser alterados. Ao abrir, a configuração fica travada.</p>
          <p v-else-if="session.status === 'OPEN'" class="stat-hint">Votação aberta. Os eleitores elegíveis votam pela própria conta; a contagem por equipe só aparece depois do encerramento.</p>
          <div v-if="canManage" class="page-actions">
            <button v-if="isDraft" class="btn ghost small" type="button" @click="startRename">Renomear</button>
            <button v-if="isDraft" class="btn small" type="button" @click="ask('open')">Abrir votação</button>
            <button v-if="session.status === 'OPEN'" class="btn small" type="button" @click="ask('close')">Encerrar votação</button>
            <button v-if="isDraft || session.status === 'OPEN'" class="btn danger small" type="button" @click="ask('cancel')">Cancelar sessão</button>
          </div>
        </div>

        <Tabs class="mt" :tabs="tabs" :model-value="tab" @update:model-value="setTab" />

        <section v-if="tab === 'candidatas'">
          <template v-if="editing === 'candidatas'">
            <LoadState :loading="teams.loading.value" :loaded="teams.loaded.value" :error="teams.error.value" :empty="teamOptions.length === 0" empty-title="Nenhuma equipe ativa no evento" @retry="teams.load()">
              <div class="row-between">
                <p class="stat-hint">{{ choice.size }} de {{ teamOptions.length }} selecionada(s). Só equipes ativas do evento podem ser candidatas.</p>
                <div class="row-actions">
                  <button class="btn ghost small" type="button" @click="selectAll(teamOptions.filter((item) => !item.stale))">Todas</button>
                  <button class="btn ghost small" type="button" @click="clearChoice">Nenhuma</button>
                </div>
              </div>
              <div class="menu-list vote-pick">
                <label v-for="item in teamOptions" :key="item.id" class="check">
                  <input type="checkbox" :checked="choice.has(item.id)" @change="toggle(item.id)" />
                  {{ item.name }} <Badge v-if="item.stale" tone="danger">Inativa — retire antes de abrir</Badge>
                </label>
              </div>
            </LoadState>
            <p v-if="saveCandidates.fieldErrors.value.team_ids" class="field-error">{{ saveCandidates.fieldErrors.value.team_ids }}</p>
            <div class="page-actions">
              <button class="btn ghost" type="button" @click="editing = null">Cancelar</button>
              <button class="btn" type="button" :disabled="saveCandidates.pending.value || !teams.loaded.value" @click="saveCandidates.run()">{{ saveCandidates.pending.value ? 'Salvando…' : 'Salvar candidatas' }}</button>
            </div>
          </template>
          <template v-else>
            <div class="row-between">
              <p class="stat-hint">Equipes que aparecem na cédula{{ isDraft ? ' quando a votação for aberta' : '' }}.</p>
              <button v-if="canManage && isDraft && canTeams" class="btn small" type="button" @click="editCandidates">Definir candidatas</button>
            </div>
            <p v-if="!(session.candidates || []).length" class="stat-hint">Nenhuma equipe candidata.</p>
            <div v-else class="table-wrap">
              <table>
                <thead><tr><th>Equipe</th><th>Status da equipe</th></tr></thead>
                <tbody>
                  <tr v-for="item in session.candidates" :key="item.id">
                    <td>{{ item.name }}</td>
                    <td><Badge :tone="tone('teamStatus', item.status)">{{ label('teamStatus', item.status) }}</Badge></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </section>

        <section v-if="tab === 'elegiveis'">
          <template v-if="editing === 'elegiveis'">
            <LoadState :loading="loadingCurrent || voterAccounts.loading.value" :loaded="!loadingCurrent && voterAccounts.loaded.value" :error="voterAccounts.error.value" :empty="(voterAccounts.data.value || []).length === 0" empty-title="Nenhum usuário com perfil Votante" empty-text="Cadastre as contas de Votante em Usuários e Permissões." @retry="voterAccounts.load()">
              <Search v-model="voterSearch" placeholder="Filtrar por nome ou e-mail" />
              <div class="row-between">
                <p class="stat-hint">{{ choice.size }} eleitor(es) selecionado(s). Só contas ativas com perfil Votante podem ser elegíveis.</p>
                <div class="row-actions">
                  <button class="btn ghost small" type="button" @click="selectAll(voterOptions)">Todos os listados</button>
                  <button class="btn ghost small" type="button" @click="clearChoice">Nenhum</button>
                </div>
              </div>
              <div class="menu-list vote-pick">
                <label v-for="item in voterOptions" :key="item.id" class="check">
                  <input type="checkbox" :checked="choice.has(item.id)" :disabled="item.disabled" @change="toggle(item.id)" />
                  {{ item.name }} <small class="stat-hint">{{ item.email }}</small>
                  <Badge v-if="item.status !== 'ACTIVE'" tone="danger">{{ label('activeStatus', item.status) }}</Badge>
                </label>
              </div>
            </LoadState>
            <p v-if="saveEligibles.fieldErrors.value.user_ids" class="field-error">{{ saveEligibles.fieldErrors.value.user_ids }}</p>
            <div class="page-actions">
              <button class="btn ghost" type="button" @click="editing = null">Cancelar</button>
              <button class="btn" type="button" :disabled="saveEligibles.pending.value || loadingCurrent || !voterAccounts.loaded.value" @click="saveEligibles.run()">{{ saveEligibles.pending.value ? 'Salvando…' : 'Salvar eleitores' }}</button>
            </div>
          </template>
          <template v-else>
            <section v-if="isDraft" class="card vote-readiness" aria-labelledby="vote-readiness-title">
              <div class="row-between">
                <h3 id="vote-readiness-title">Votantes do público</h3>
                <button v-if="canEligibility" class="btn small" type="button" :disabled="addPublic.pending.value" @click="addPublic.run()">{{ addPublic.pending.value ? 'Adicionando…' : 'Adicionar votantes ativados' }}</button>
              </div>
              <p class="stat-hint">Adiciona à sessão as contas Votante já ativadas por inscritos da categoria Público. Não remove ninguém da lista e usa os dados já sincronizados no HackLab.</p>
              <LoadState :loading="readiness.loading.value" :loaded="readiness.loaded.value" :error="readiness.error.value" :empty="!readiness.data.value" @retry="readiness.load()">
                <dl v-if="readiness.data.value" class="vote-readiness-grid">
                  <div><dt>Inscritos do público confirmados</dt><dd>{{ readiness.data.value.public_confirmed_people }}</dd></div>
                  <div><dt>Contas Votante já ativadas</dt><dd>{{ readiness.data.value.activated_voter_accounts }}</dd></div>
                  <div><dt>Já elegíveis nesta sessão</dt><dd>{{ readiness.data.value.already_eligible }}</dd></div>
                  <div><dt>Ainda sem conta ativada</dt><dd>{{ readiness.data.value.public_without_account }}</dd></div>
                </dl>
                <p v-if="readiness.data.value?.public_with_other_account" class="stat-hint">{{ readiness.data.value.public_with_other_account }} inscrito(s) do público têm conta inativa ou com outro perfil e não entram automaticamente.</p>
              </LoadState>
              <p class="stat-hint">Os votantes precisam ativar o acesso antes da abertura: depois de aberta, a lista de elegíveis fica congelada.</p>
            </section>
            <div class="row-between">
              <p class="stat-hint">Mostra só se cada eleitor já votou, nunca em quem.</p>
              <button v-if="canEligibility && isDraft && canUsers" class="btn small" type="button" @click="editEligibles">Definir eleitores</button>
            </div>
            <FilterPanel :active="voters.activeFilters.value" @clear="voters.reset()">
              <template #search><Search v-model="voters.filters.search" placeholder="Buscar eleitor por nome ou e-mail" /></template>
              <select v-model="voters.filters.has_voted" class="input" aria-label="Situação do voto">
                <option value="">Todos</option>
                <option value="true">Já votaram</option>
                <option value="false">Ainda não votaram</option>
              </select>
            </FilterPanel>
            <LoadState :loading="voters.loading.value" :loaded="voters.loaded.value" :error="voters.error.value" :empty="voters.items.value.length === 0" empty-title="Nenhum eleitor elegível" @retry="voters.load()">
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Eleitor</th><th>Conta</th><th>Voto</th></tr></thead>
                  <tbody>
                    <tr v-for="item in voters.items.value" :key="item.id">
                      <td>{{ item.user?.person?.full_name || '—' }}<br /><small class="stat-hint">{{ item.user?.email }}</small></td>
                      <td><Badge :tone="tone('activeStatus', item.user?.status)">{{ label('activeStatus', item.user?.status) }}</Badge></td>
                      <td><Badge :tone="item.has_voted ? 'ok' : 'warn'">{{ item.has_voted ? 'Votou' : 'Ainda não votou' }}</Badge></td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <Pager :meta="voters.meta.value" :disabled="voters.loading.value" @page="voters.page.value = $event" />
            </LoadState>
          </template>
        </section>

        <section v-if="tab === 'participacao' && canAggregates">
          <LoadState :loading="turnout.loading.value" :loaded="turnout.loaded.value" :error="turnout.error.value" :empty="!turnout.data.value" @retry="turnout.load()">
            <div v-if="turnout.data.value" class="grid cols-2">
              <Stat icon="users" label="Eleitores elegíveis" :value="turnout.data.value.eligible_voters" />
              <Stat icon="check" label="Votos registrados" :value="turnout.data.value.votes_cast" />
              <Stat icon="alert" label="Ainda não votaram" :value="turnout.data.value.pending_voters" />
              <Stat icon="chart" label="Participação" :value="percent(turnout.data.value.turnout_percent)" />
            </div>
            <p class="stat-hint">Só totais. A contagem por equipe aparece depois do encerramento.</p>
          </LoadState>
        </section>

        <section v-if="tab === 'contagem' && canAggregates">
          <p v-if="session.status !== 'CLOSED'" class="banner warn">A contagem por equipe só existe com a votação encerrada (status atual: {{ label('votingStatus', session.status) }}).</p>
          <LoadState v-else :loading="counting.loading.value" :loaded="counting.loaded.value" :error="counting.error.value" :empty="!counting.data.value" @retry="counting.load()">
            <template v-if="counting.data.value">
              <p class="stat-hint">{{ counting.data.value.note }}</p>
              <p v-if="!counting.data.value.teams?.length" class="stat-hint">Nenhuma equipe candidata.</p>
              <div v-else class="table-wrap results-block">
                <table>
                  <thead><tr><th>Posição</th><th>Equipe</th><th>Votos</th><th>Percentual</th></tr></thead>
                  <tbody>
                    <tr v-for="item in counting.data.value.teams" :key="item.team.id">
                      <td>{{ item.rank ? `${item.rank}º` : '—' }}</td>
                      <td>{{ item.team.name }} <Badge v-if="item.tied" tone="warn">Empate</Badge></td>
                      <td>{{ item.vote_count }}</td>
                      <td>{{ percent(item.percentage) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p class="stat-hint">
                Total de votos: {{ counting.data.value.total_votes }}<template v-if="counting.data.value.turnout"> de {{ counting.data.value.turnout.eligible_voters }} eleitores ({{ percent(counting.data.value.turnout.turnout_percent) }})</template>.
                <template v-if="counting.data.value.has_ties"> Há empate.</template>
              </p>
            </template>
          </LoadState>
        </section>
      </LoadState>
      <template #footer>
        <button class="btn ghost" type="button" @click="closeDrawer">Fechar</button>
      </template>
    </Drawer>

    <Modal v-if="modal === 'form'" :title="form.mode === 'rename' ? 'Renomear sessão' : 'Nova sessão de votação'" :subtitle="form.mode === 'rename' ? 'O nome só pode mudar enquanto a sessão está em rascunho.' : 'A sessão começa em rascunho. Depois defina candidatas e eleitores e abra a votação.'" @close="modal = null">
      <form class="form-grid" novalidate @submit.prevent="save.run()">
        <Field label="Nome da sessão" required class-name="span-2" :error="save.fieldErrors.value.name || save.fieldErrors.value.status">
          <input v-model="form.name" class="input" maxlength="120" required />
        </Field>
      </form>
      <template #footer>
        <button class="btn ghost" type="button" @click="modal = null">Cancelar</button>
        <button class="btn" type="button" :disabled="save.pending.value || !form.name.trim()" @click="save.run()">{{ save.pending.value ? 'Salvando…' : form.mode === 'rename' ? 'Salvar nome' : 'Criar sessão' }}</button>
      </template>
    </Modal>

    <Modal v-if="confirm && session" :title="confirmText.title" :subtitle="confirmText.subtitle" @close="confirm = null">
      <p><b>{{ session.name }}</b></p>
      <p v-if="confirmText.body">{{ confirmText.body }}</p>
      <template v-if="confirm === 'open'">
        <dl class="vote-readiness-grid">
          <div><dt>Candidatas</dt><dd>{{ session.candidates_count ?? 0 }}</dd></div>
          <div><dt>Eleitores elegíveis</dt><dd>{{ session.eligible_voters_count ?? 0 }}</dd></div>
          <div><dt>Inscritos do público sem acesso ativado</dt><dd>{{ readiness.loading.value && !readiness.data.value ? '…' : (readiness.data.value?.public_without_account ?? '—') }}</dd></div>
        </dl>
        <p v-if="readiness.data.value?.public_without_account" class="banner warn" role="alert">
          Existem {{ readiness.data.value.public_without_account }} inscritos do público que ainda não ativaram o acesso.
          Após abrir a votação, novos eleitores não poderão ser incluídos nesta sessão.
        </p>
        <p class="stat-hint">Depois da abertura, candidatas e elegíveis ficam congelados.</p>
      </template>
      <Field v-if="confirm === 'cancel'" label="Motivo" :required="reasonRequired" :hint="reasonRequired ? '' : 'Opcional.'" :error="transition.fieldErrors.value.reason">
        <textarea v-model="reason" class="input" rows="3" maxlength="2000" />
      </Field>
      <p v-for="(message, field) in transition.fieldErrors.value" v-show="field !== 'reason'" :key="field" class="field-error">{{ message }}</p>
      <template #footer>
        <button class="btn ghost" type="button" @click="confirm = null">Voltar</button>
        <button :class="confirmText.danger ? 'btn danger' : 'btn'" type="button" :disabled="transition.pending.value || (confirm === 'cancel' && reasonRequired && !reason.trim())" @click="transition.run(confirm)">
          {{ transition.pending.value ? 'Salvando…' : confirmText.button }}
        </button>
      </template>
    </Modal>
  </Page>
</template>
