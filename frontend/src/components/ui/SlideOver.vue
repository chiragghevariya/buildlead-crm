<script setup>
import { ref, watch } from 'vue'
import { onKeyStroke, useScrollLock } from '@vueuse/core'
import { X } from 'lucide-vue-next'
import { useFocusTrap } from '@/composables/useFocusTrap'

const props = defineProps({
  open: Boolean,
  title: { type: String, default: null },
  subtitle: { type: String, default: null },
  width: { type: String, default: 'max-w-xl' },
})
const emit = defineEmits(['update:open', 'close'])

const lock = useScrollLock(typeof document !== 'undefined' ? document.body : null)
const close = () => { emit('update:open', false); emit('close') }
watch(() => props.open, (open) => { lock.value = open })
const panel = ref(null)
useFocusTrap(panel, () => props.open)
onKeyStroke('Escape', (e) => {
  // Let an open modal/palette handle Escape first.
  if (props.open && !document.querySelector('[role="dialog"][aria-modal="true"]')) { e.preventDefault(); close() }
})
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="open" class="fixed inset-0 z-40 bg-navy-950/30 backdrop-blur-[1px] dark:bg-black/50" @click="close" />
    </Transition>
    <Transition
      enter-active-class="transition duration-300 ease-[cubic-bezier(.2,.8,.2,1)]" enter-from-class="translate-x-full" enter-to-class="translate-x-0"
      leave-active-class="transition duration-200 ease-in" leave-from-class="translate-x-0" leave-to-class="translate-x-full"
    >
      <aside v-if="open" ref="panel" role="dialog" data-focus-trap :aria-label="title || 'Details'" class="fixed inset-y-0 right-0 z-40 flex w-full flex-col border-l border-line bg-surface shadow-pop" :class="width">
        <header class="flex items-start justify-between gap-4 border-b border-line px-6 py-4">
          <slot name="header">
            <div class="min-w-0">
              <h2 class="truncate text-base font-semibold text-ink">{{ title }}</h2>
              <p v-if="subtitle" class="mt-0.5 text-[13px] text-muted">{{ subtitle }}</p>
            </div>
          </slot>
          <button type="button" class="focus-ring -mr-1.5 rounded-lg p-1.5 text-faint transition hover:bg-subtle hover:text-ink" aria-label="Close panel" @click="close">
            <X class="h-4 w-4" />
          </button>
        </header>
        <div class="flex-1 overflow-y-auto"><slot /></div>
        <footer v-if="$slots.footer" class="flex items-center gap-2 border-t border-line bg-subtle/50 px-6 py-3.5"><slot name="footer" /></footer>
      </aside>
    </Transition>
  </Teleport>
</template>
