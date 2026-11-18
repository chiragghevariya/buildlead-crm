import { createApp } from 'vue'
import { createPinia } from 'pinia'
import '@fontsource/inter/400.css'
import '@fontsource/inter/500.css'
import '@fontsource/inter/600.css'
import '@fontsource/inter/700.css'
import './styles/main.css'
import App from './App.vue'
import range from './directives/range'
import router from './router'
import { onUnauthorized } from './api/http'
import { useAuthStore } from './stores/auth'
import { useToastStore } from './stores/toasts'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia).use(router)
app.directive('range', range)

// Expired/revoked token anywhere → back to login.
onUnauthorized(() => {
  const auth = useAuthStore(pinia)
  if (auth.isAuthenticated) useToastStore(pinia).info('Your session has expired', 'Please sign in again.')
  auth.forget()
  if (router.currentRoute.value.name !== 'login') {
    router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
  }
})

app.mount('#app')
