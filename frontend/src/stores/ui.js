import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { useColorMode, useLocalStorage, useMediaQuery } from '@vueuse/core'

export const useUiStore = defineStore('ui', () => {
  // 'light' | 'dark' | 'auto' — remembered; applied as the `dark` class on <html>.
  const theme = useColorMode({ storageKey: 'buildlead-theme', initialValue: 'auto' })
  const sidebarCollapsed = useLocalStorage('buildlead-sidebar-collapsed', false)
  // Tablets (768–1023px) always get the icon rail so content keeps ~700px of width.
  const isTablet = useMediaQuery('(min-width: 768px) and (max-width: 1023.98px)')
  const sidebarRail = computed(() => sidebarCollapsed.value || isTablet.value)

  const paletteOpen = ref(false)
  const newLeadOpen = ref(false)
  const shortcutsOpen = ref(false)
  const moreOpen = ref(false) // mobile "More" sheet
  const leadDrawerId = ref(null)
  /** Bumped whenever leads change, so lists/boards can refetch. */
  const leadsVersion = ref(0)

  const isDark = () => theme.state.value === 'dark'
  const toggleTheme = () => { theme.value = isDark() ? 'light' : 'dark' }
  const openLead = (id) => { leadDrawerId.value = id }

  return { theme, sidebarCollapsed, sidebarRail, isTablet, paletteOpen, newLeadOpen, shortcutsOpen, moreOpen, leadDrawerId, leadsVersion, isDark, toggleTheme, openLead }
})
