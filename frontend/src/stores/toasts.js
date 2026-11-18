import { defineStore } from 'pinia'
import { ref } from 'vue'

let seq = 0

export const useToastStore = defineStore('toasts', () => {
  const items = ref([])

  /** push({ title, description?, type: 'success'|'error'|'info'|'hot', duration? }) */
  function push(toast) {
    // Several requests failing at once (API down) should not stack identical toasts.
    const twin = items.value.find((t) => t.type === (toast.type ?? 'info') && t.title === toast.title && t.description === toast.description)
    if (twin) return twin.id
    const id = ++seq
    const item = { id, type: 'info', duration: 4500, ...toast }
    items.value.push(item)
    if (item.duration) setTimeout(() => dismiss(id), item.duration)
    return id
  }

  const dismiss = (id) => { items.value = items.value.filter((t) => t.id !== id) }

  return {
    items,
    push,
    dismiss,
    success: (title, description) => push({ type: 'success', title, description }),
    error: (title, description) => push({ type: 'error', title, description, duration: 6000 }),
    info: (title, description) => push({ type: 'info', title, description }),
  }
})
