<script setup>
import { ref, useId, watch } from 'vue'
import { onKeyStroke, useScrollLock } from '@vueuse/core'
import { X } from 'lucide-vue-next'
import { useFocusTrap } from '@/composables/useFocusTrap'

const props = defineProps({
  open: Boolean,
  title: { type: String, default: null },
  description: { type: String, default: null },
  size: { type: String, default: 'md' }, // sm | md | lg | xl
  dismissible: { type: Boolean, default: true },
})
const emit = defineEmits(['update:open', 'close'])

const panel = ref(null)
const lock = useScrollLock(typeof document !== 'undefined' ? document.body : null)
const close = () => { if (props.dismissible) { emit('update:open', false); emit('close') } }

const titleId = useId()
watch(() => props.open, (open) => { lock.value = open })
useFocusTrap(panel, () => props.open, { initial: '[autofocus], input:not([type=checkbox]):not([type=radio]), select, textarea' })
onKeyStroke('Escape', (e) => { if (props.open) { e.preventDefault(); close() } })

const widths = { sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' }
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="open" class="fixed inset-0 z-50 bg-navy-950/40 backdrop-blur-[2px] dark:bg-black/60" @click="close" />
    </Transition>
    <Transition name="pop">
      <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto p-0 sm:items-start sm:p-4 sm:pt-[8vh]" @mousedown.self="close">
        <div
          ref="panel"
          role="dialog"
          aria-modal="true"
          data-focus-trap
          :aria-labelledby="title ? titleId : undefined"
          class="relative flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-2xl border border-line bg-surface shadow-pop sm:max-h-[84vh] sm:rounded-card"
          :class="widths[size]"
        >
          <header v-if="title || $slots.header" class="flex items-start justify-between gap-4 border-b border-line px-6 py-4">
            <slot name="header">
              <div>
                <h2 :id="titleId" class="text-base font-semibold tracking-[-0.01em] text-ink">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 text-[13px] text-muted">{{ description }}</p>
              </div>
            </slot>
            <button v-if="dismissible" type="button" class="focus-ring -mr-1.5 rounded-lg p-1.5 text-faint transition hover:bg-subtle hover:text-ink" aria-label="Close" @click="close">
              <X class="h-4 w-4" />
            </button>
          </header>
          <div class="flex-1 overflow-y-auto px-6 py-5"><slot /></div>
          <footer v-if="$slots.footer" class="flex items-center justify-end gap-2 border-t border-line bg-subtle/50 px-6 py-3.5">
            <slot name="footer" />
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
