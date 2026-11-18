import { onBeforeUnmount, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useUiStore } from '@/stores/ui'

const isTyping = (el) => el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName))

/**
 * Global shortcuts (spec): ⌘/Ctrl+K palette · / search · N new lead · G then D/L/P · ? help.
 */
export function useShortcuts() {
  const router = useRouter()
  const ui = useUiStore()
  let pendingG = null

  function onKey(e) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault()
      ui.paletteOpen = !ui.paletteOpen
      return
    }
    if (e.metaKey || e.ctrlKey || e.altKey || isTyping(document.activeElement)) return
    if (ui.paletteOpen || ui.newLeadOpen || ui.shortcutsOpen || document.querySelector('[role="dialog"][aria-modal="true"]')) return

    const key = e.key.toLowerCase()
    if (pendingG) {
      clearTimeout(pendingG)
      pendingG = null
      const target = { d: 'dashboard', l: 'leads', p: 'pipeline' }[key]
      if (target) { e.preventDefault(); router.push({ name: target }) }
      return
    }
    if (e.key === '?') { e.preventDefault(); ui.shortcutsOpen = true; return }
    if (key === 'g') { pendingG = setTimeout(() => { pendingG = null }, 1200); return }
    if (key === '/') { e.preventDefault(); ui.paletteOpen = true; return }
    if (key === 'n') { e.preventDefault(); ui.newLeadOpen = true }
  }

  onMounted(() => window.addEventListener('keydown', onKey))
  onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
}
