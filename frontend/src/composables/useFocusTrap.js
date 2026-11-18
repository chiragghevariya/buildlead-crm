import { nextTick, onBeforeUnmount, watch } from 'vue'

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

/**
 * Keeps Tab / Shift+Tab inside an open overlay, focuses it on open and returns focus to the
 * element that opened it on close (WAI-ARIA dialog pattern).
 *   useFocusTrap(panelRef, () => props.open, { initial: '[autofocus], input' })
 */
export function useFocusTrap(container, isOpen, { initial = null } = {}) {
  let opener = null

  const focusables = () => [...(container.value?.querySelectorAll(FOCUSABLE) ?? [])].filter((el) => el.offsetParent !== null || el === document.activeElement)

  function onKeydown(e) {
    if (e.key !== 'Tab' || !container.value) return
    // Only the top-most overlay traps (a modal opened from a drawer wins).
    const overlays = document.querySelectorAll('[data-focus-trap]')
    if (overlays[overlays.length - 1] !== container.value) return
    const els = focusables()
    if (!els.length) { e.preventDefault(); return }
    const first = els[0]
    const last = els[els.length - 1]
    if (!container.value.contains(document.activeElement)) { e.preventDefault(); first.focus() } else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus() } else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus() }
  }

  watch(isOpen, async (open) => {
    if (open) {
      opener = document.activeElement
      document.addEventListener('keydown', onKeydown)
      await nextTick()
      const el = (initial && container.value?.querySelector(initial)) || focusables()[0] || container.value
      el?.focus({ preventScroll: true })
    } else {
      document.removeEventListener('keydown', onKeydown)
      const back = opener
      opener = null
      // Restore after the leave transition has detached the overlay.
      if (back && typeof back.focus === 'function') setTimeout(() => { if (document.contains(back)) back.focus({ preventScroll: true }) }, 0)
    }
  }, { immediate: true })

  onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))
}
