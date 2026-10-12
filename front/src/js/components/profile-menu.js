// Lógica do componente ProfileMenu.vue: dados reais do usuário logado (/auth/me) e saída.
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { label } from '@/js/labels'
import { go } from '@/js/router'
import { auth, hasPermission, logout } from '@/js/stores/auth'
import { flash } from '@/js/stores/ui'

export function useProfileMenu() {
  const open = ref(false)
  const root = ref(null)
  const leaving = ref(false)
  const user = computed(() => auth.user)
  const name = computed(() => user.value?.person?.full_name || user.value?.email || 'Usuário')
  const role = computed(() => user.value?.role?.name || label('role', user.value?.role?.code, ''))
  const sector = computed(() => user.value?.sector?.name || '')

  async function signOut() {
    if (leaving.value) return
    leaving.value = true
    open.value = false
    try {
      await logout()
    } catch {
      flash('Não foi possível confirmar a saída no servidor. A sessão foi encerrada neste navegador.', 'err')
    } finally {
      leaving.value = false
    }
  }

  function run(action) {
    open.value = false
    action()
  }

  function onPointer(event) {
    if (open.value && root.value && !root.value.contains(event.target)) open.value = false
  }

  function onKey(event) {
    if (open.value && event.key === 'Escape') open.value = false
  }

  onMounted(() => {
    document.addEventListener('mousedown', onPointer)
    document.addEventListener('keydown', onKey)
  })
  onUnmounted(() => {
    document.removeEventListener('mousedown', onPointer)
    document.removeEventListener('keydown', onKey)
  })

  return { open, root, user, name, role, sector, leaving, signOut, run, go, hasPermission }
}
