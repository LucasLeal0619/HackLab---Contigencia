<script setup>
import { computed } from 'vue'
import Logo from './Logo.vue'
import ProfileMenu from './ProfileMenu.vue'
import { canAccess, focusFor } from '@/js/config/access'
import { go, useRoute } from '@/js/router'
import { auth, logout } from '@/js/stores/auth'

defineProps({
  title: { type: String, default: '' },
})

const route = useRoute()
// Atalhos da experiência focada (ex.: Votação / Resultado oficial), só os que o perfil acessa.
const tabs = computed(() => (focusFor(auth.user?.role?.code)?.tabs || []).filter(([id]) => canAccess(auth.user, id)))
const active = computed(() => (route.value.path === 'avaliar' ? 'area-jurado' : route.value.path))
</script>

<template>
  <div class="focus-app focus-shell">
    <a class="skip" href="#conteudo">Ir para o conteúdo</a>
    <header class="focus-bar">
      <Logo light />
      <strong class="focus-title">{{ title }}</strong>
      <nav v-if="tabs.length > 1" class="focus-tabs" aria-label="Seções">
        <button
          v-for="[id, name] in tabs"
          :key="id"
          type="button"
          class="btn ghost small"
          :class="{ on: active === id }"
          :aria-current="active === id ? 'page' : undefined"
          @click="go(id)"
        >
          {{ name }}
        </button>
      </nav>
      <div class="top-tools focus-tools">
        <ProfileMenu />
        <button class="btn ghost focus-exit" type="button" @click="logout()">Sair</button>
      </div>
    </header>
    <main id="conteudo"><slot /></main>
  </div>
</template>
