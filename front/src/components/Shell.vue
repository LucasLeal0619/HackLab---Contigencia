<script setup>
import Even3Center from './Even3Center.vue'
import Field from './Field.vue'
import Icon from './Icon.vue'
import Logo from './Logo.vue'
import ProfileMenu from './ProfileMenu.vue'
import { useShell } from '@/js/components/shell'

const props = defineProps({
  path: { type: String, default: '' },
})
const { ui, open, panel, nav, isOpen, toggleGroup, togglePanel, current, alerts, alertCount, canAlerts, spacingOptions, setA11y, resetA11y, eventContext, activeEvent, selectEvent, go } = useShell(props)
</script>

<template>
  <div class="shell">
    <a class="skip" href="#conteudo">Ir para o conteúdo</a>
    <!-- Tablet e celular: a navegação vira drawer, aberto pelo botão ☰ do cabeçalho. -->
    <div v-if="open" class="nav-backdrop" aria-hidden="true" @click="open = false" />
    <aside id="navegacao" class="sidebar" :class="{ open }" aria-label="Navegação">
      <div class="side-brand">
        <Logo />
        <button class="icon-btn nav-close" type="button" aria-label="Fechar menu" title="Fechar menu" @click="open = false"><Icon name="x" /></button>
      </div>
      <div v-for="group in nav" :key="group.group" class="nav-group">
        <p class="nav-label">{{ group.group }}</p>
        <template v-for="item in group.items" :key="item.id">
          <button
            v-if="item.children"
            type="button"
            class="nav-item"
            :class="{ open: isOpen(item.id), 'group-on': current.group === item.id }"
            :aria-expanded="isOpen(item.id)"
            @click="toggleGroup(item.id)"
          >
            <Icon :name="item.icon" />
            <span>{{ item.label }}</span>
            <Icon class="nav-caret" name="chevron" :size="16" />
          </button>
          <div v-if="item.children" class="nav-sub" :class="{ open: isOpen(item.id) }" :aria-hidden="isOpen(item.id) ? undefined : 'true'">
            <button
              v-for="child in item.children"
              :key="child.id"
              type="button"
              class="nav-item nav-child"
              :class="{ active: current.item === child.id }"
              :tabindex="isOpen(item.id) ? 0 : -1"
              :aria-current="current.item === child.id ? 'page' : undefined"
              @click="go(child.id); open = false; panel = ''"
            >
              {{ child.label }}
            </button>
          </div>
          <button
            v-else
            type="button"
            class="nav-item"
            :class="{ active: current.item === item.id }"
            :aria-current="current.item === item.id ? 'page' : undefined"
            @click="go(item.id); open = false; panel = ''"
          >
            <Icon :name="item.icon" /> {{ item.label }}
          </button>
        </template>
      </div>
      <div class="side-foot">{{ activeEvent ? activeEvent.name : 'Nenhum evento selecionado' }}</div>
    </aside>
    <div class="workspace">
      <header class="topbar">
        <button class="icon-btn menu-btn" type="button" aria-label="Abrir menu" title="Abrir menu" aria-controls="navegacao" :aria-expanded="open" @click="open = !open"><Icon name="menu" /></button>
        <span class="top-brand"><b>Hack</b><b class="lab">Lab</b></span>
        <!-- Centro Even3: único disparo visual da sincronização central (Administrador e Consultor). -->
        <Even3Center />
        <div class="top-tools">
          <label v-if="eventContext.events.length > 1" class="event-switch">
            <span class="event-switch-label stat-hint">Evento</span>
            <select class="input" :value="eventContext.activeEventId" aria-label="Evento ativo" @change="selectEvent($event.target.value)">
              <option v-for="item in eventContext.events" :key="item.id" :value="item.id">{{ item.name }}</option>
            </select>
          </label>
          <div v-if="canAlerts" class="tool">
            <button class="icon-btn" type="button" aria-label="Alertas" title="Alertas" @click="togglePanel('alerts')">
              <Icon name="bell" />
              <span v-if="alertCount > 0" class="dot" />
            </button>
            <div v-if="panel === 'alerts'" class="popover" role="dialog" aria-label="Alertas">
              <div class="row-between"><h3>Alertas</h3><button class="icon-btn" type="button" aria-label="Fechar" title="Fechar" @click="panel = ''"><Icon name="x" :size="16" /></button></div>
              <p v-if="alerts.loading && !alertCount" class="stat-hint">Carregando…</p>
              <p v-else-if="alerts.error" class="stat-hint">{{ alerts.error.message }}</p>
              <p v-else-if="alertCount === 0" class="stat-hint">Tudo certo! Nenhuma ocorrência urgente aberta nem pendência atrasada.</p>
              <div v-else class="menu-list">
                <p v-for="item in alerts.urgent" :key="`o${item.id}`">Ocorrência urgente · {{ item.title }}</p>
                <p v-for="item in alerts.overdue" :key="`t${item.id}`">Pendência atrasada · {{ item.title }}</p>
                <p v-if="alerts.urgentTotal + alerts.overdueTotal > alerts.urgent.length + alerts.overdue.length" class="stat-hint">
                  e mais {{ alerts.urgentTotal + alerts.overdueTotal - alerts.urgent.length - alerts.overdue.length }}…
                </p>
              </div>
              <div class="row-between">
                <button v-if="alerts.urgentTotal" class="btn ghost small" type="button" @click="panel = ''; go('ocorrencias')">Ver ocorrências</button>
                <button v-if="alerts.overdueTotal" class="btn ghost small" type="button" @click="panel = ''; go('pendencias')">Ver pendências</button>
              </div>
            </div>
          </div>
          <div class="tool">
            <button class="a11y-btn" type="button" aria-label="Acessibilidade" @click="togglePanel('a11y')"><Icon name="access" :size="16" /> <span class="a11y-label">Acessibilidade</span></button>
            <div v-if="panel === 'a11y'" class="popover" role="dialog" aria-label="Acessibilidade">
              <div class="row-between"><h3>Acessibilidade</h3><button class="icon-btn" type="button" aria-label="Fechar" title="Fechar" @click="panel = ''"><Icon name="x" :size="16" /></button></div>
              <Field label="Tamanho da fonte" hint="Aumentar ou diminuir o texto">
                <div class="row-between">
                  <button class="btn ghost small" type="button" @click="setA11y({ scale: Math.max(90, ui.a11y.scale - 10) })">A−</button>
                  <strong>{{ ui.a11y.scale }}%</strong>
                  <button class="btn ghost small" type="button" @click="setA11y({ scale: Math.min(140, ui.a11y.scale + 10) })">A+</button>
                </div>
              </Field>
              <label class="check"><input type="checkbox" :checked="ui.a11y.contrast" @change="setA11y({ contrast: $event.target.checked })" /> Alto contraste</label>
              <label class="check"><input type="checkbox" :checked="ui.a11y.focus" @change="setA11y({ focus: $event.target.checked })" /> Destacar foco</label>
              <label class="check"><input type="checkbox" :checked="ui.a11y.motion" @change="setA11y({ motion: $event.target.checked })" /> Reduzir animações</label>
              <Field label="Ajustar espaçamento">
                <div class="chips">
                  <button v-for="[id, label] in spacingOptions" :key="id" type="button" class="chip" :class="{ on: ui.a11y.spacing === id }" @click="setA11y({ spacing: id })">{{ label }}</button>
                </div>
              </Field>
              <button class="btn ghost small" type="button" @click="resetA11y()">Restaurar padrão</button>
            </div>
          </div>
          <ProfileMenu />
        </div>
      </header>
      <main id="conteudo" class="content"><slot /></main>
    </div>
  </div>
</template>
