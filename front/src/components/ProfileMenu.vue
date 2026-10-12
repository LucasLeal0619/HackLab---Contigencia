<script setup>
import Icon from './Icon.vue'
import { useProfileMenu } from '@/js/components/profile-menu'

const { open, root, user, name, role, sector, leaving, signOut, run, go, hasPermission } = useProfileMenu()
</script>

<template>
  <div ref="root" class="tool profile-tool">
    <button class="user-btn" type="button" :aria-expanded="open" aria-haspopup="dialog" @click="open = !open">
      <span class="avatar"><Icon name="users" :size="16" /></span>
      <span class="user-meta">
        <b>{{ name }}</b>
        <span><span class="status-dot" /> {{ role }}{{ sector ? ` · ${sector}` : "" }}</span>
      </span>
    </button>
    <div v-if="open" class="profile-back" aria-hidden="true" @click="open = false" />
    <div v-if="open" class="popover profile-menu" role="dialog" aria-label="Meu perfil">
      <div class="row-between"><h3>Meu perfil</h3><button class="icon-btn" type="button" aria-label="Fechar" title="Fechar" @click="open = false"><Icon name="x" :size="16" /></button></div>
      <p class="profile-who"><b>{{ name }}</b><span>{{ user?.email }}</span></p>
      <p class="profile-current"><span>Perfil</span><b>{{ role }}{{ sector ? ` · ${sector}` : "" }}</b></p>
      <div class="menu-list">
        <button v-if="hasPermission('events.manage')" type="button" @click="run(() => go('config'))">Configuração do evento</button>
        <button type="button" :disabled="leaving" @click="signOut"><Icon name="logout" :size="16" /> {{ leaving ? 'Saindo…' : 'Sair' }}</button>
      </div>
    </div>
  </div>
</template>
