import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { auth as authApi } from '@/api'
import { token } from '@/api/http'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const ready = ref(false)

  const isAuthenticated = computed(() => !!user.value)
  const role = computed(() => user.value?.role)
  const canSeeAll = computed(() => ['admin', 'manager'].includes(role.value))
  const isAdmin = computed(() => role.value === 'admin')
  const firstName = computed(() => user.value?.name.split(' ')[0] ?? '')

  /** Restore the session from a stored token (once, before the first navigation). */
  async function init() {
    if (ready.value) return
    if (token.get()) {
      try { user.value = await authApi.me() } catch { token.clear() }
    }
    ready.value = true
  }

  async function login(email, password) {
    user.value = await authApi.login(email, password)
  }

  async function logout() {
    try { await authApi.logout() } catch { /* token already invalid */ }
    user.value = null
  }

  function forget() {
    user.value = null
    token.clear()
  }

  return { user, ready, isAuthenticated, role, canSeeAll, isAdmin, firstName, init, login, logout, forget }
})
