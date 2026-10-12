import { createApp } from 'vue'
import App from './App.vue'
import { enableResponsiveTables } from '@/js/utils/responsive-tables'
import { bootstrap } from '@/js/stores/auth'
import { bindUi } from '@/js/stores/ui'
import './css/index.css'

bindUi()
createApp(App).mount('#app')
enableResponsiveTables()
// Descobre a sessão no servidor (GET /auth/me); até lá a tela de carregamento fica visível.
bootstrap()
