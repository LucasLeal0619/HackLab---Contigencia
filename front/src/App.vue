<script setup>
import { computed } from 'vue'
import BootScreen from './components/BootScreen.vue'
import EventGate from './components/EventGate.vue'
import FocusFrame from './components/FocusFrame.vue'
import FocusShell from './components/FocusShell.vue'
import Icon from './components/Icon.vue'
import Shell from './components/Shell.vue'
import Login from './pages/Login.vue'
import ActivateAccount from './pages/ActivateAccount.vue'
import FirstAccess from './pages/FirstAccess.vue'
import ForgotPassword from './pages/ForgotPassword.vue'
import ResetPassword from './pages/ResetPassword.vue'
import { useApp } from '@/js/components/app'
import { FRAMES, PAGES } from '@/js/pages'

const { path, params, phase, layout, focus, needsEvent, ui, eventContext, retryBoot } = useApp()
const entry = computed(() => PAGES[path.value] || null)
const pageKey = computed(() => `${path.value}:${entry.value?.key ? entry.value.key(params.value) : ''}:${eventContext.version}`)
const frame = computed(() => FRAMES[path.value] || null)
const PUBLIC_PAGES = { 'primeiro-acesso': FirstAccess, 'ativar-conta': ActivateAccount, 'esqueci-senha': ForgotPassword, 'redefinir-senha': ResetPassword }
</script>

<template>
  <component :is="PUBLIC_PAGES[path]" v-if="phase === 'public'" :params="params" />
  <BootScreen v-else-if="phase === 'boot'" />
  <BootScreen v-else-if="phase === 'boot-error'" error title="Não foi possível conectar ao HackLab" @retry="retryBoot" />
  <BootScreen v-else-if="phase === 'event-error'" error title="Não foi possível carregar os eventos" :message="eventContext.error?.message" @retry="retryBoot" />
  <BootScreen v-else-if="phase === 'no-access'" denied />
  <Login v-else-if="phase === 'login'" />
  <template v-else-if="entry">
    <FocusShell v-if="layout === 'focus'" :title="focus.title">
      <EventGate>
        <component :is="entry.component" :key="pageKey" :params="params" v-bind="entry.props" />
      </EventGate>
    </FocusShell>
    <template v-else-if="layout === 'bare'">
      <FocusFrame v-if="frame" :title="frame.title" :exit-to="frame.exitTo">
        <EventGate>
          <component :is="entry.component" :key="pageKey" :params="params" v-bind="entry.props" />
        </EventGate>
      </FocusFrame>
      <EventGate v-else>
        <component :is="entry.component" :key="pageKey" :params="params" v-bind="entry.props" />
      </EventGate>
    </template>
    <Shell v-else :path="path">
      <EventGate v-if="needsEvent">
        <component :is="entry.component" :key="pageKey" :params="params" v-bind="entry.props" />
      </EventGate>
      <component :is="entry.component" v-else :key="pageKey" :params="params" v-bind="entry.props" />
    </Shell>
  </template>
  <div v-if="ui.toast" class="toast" :class="{ err: ui.toast.type === 'err' }" role="status" aria-live="polite">
    <Icon :name="ui.toast.type === 'err' ? 'info' : 'check'" :size="16" />
    <span>{{ ui.toast.text }}</span>
  </div>
</template>
