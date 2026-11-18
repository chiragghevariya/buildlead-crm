<script setup>
import { nextTick, ref } from 'vue'
import { onClickOutside, onKeyStroke } from '@vueuse/core'

defineProps({
  align: { type: String, default: 'right' }, // left | right
  width: { type: String, default: 'w-56' },
  placement: { type: String, default: 'bottom' }, // bottom | top
})

const open = ref(false)
const root = ref(null)
const menu = ref(null)
const ITEMS = 'a[href], button:not([disabled]), input:not([disabled]), [role="menuitem"]'

const items = () => [...(menu.value?.querySelectorAll(ITEMS) ?? [])]
const trigger = () => root.value?.querySelector('button, [tabindex]')

async function show() {
  open.value = true
  await nextTick()
  items()[0]?.focus({ preventScroll: true })
}
function close(restore = false) {
  if (!open.value) return
  open.value = false
  if (restore) trigger()?.focus()
}
const toggle = () => (open.value ? close() : show())

onClickOutside(root, () => close())
onKeyStroke('Escape', (e) => { if (open.value) { e.preventDefault(); e.stopPropagation(); close(true) } })

/** Menu keyboard model: ↑/↓ move, Home/End jump, Tab leaves and closes. */
function onKeydown(e) {
  const list = items()
  const i = list.indexOf(document.activeElement)
  const go = (n) => { e.preventDefault(); list[(n + list.length) % list.length]?.focus() }
  if (e.key === 'ArrowDown') go(i + 1)
  else if (e.key === 'ArrowUp') go(i - 1)
  else if (e.key === 'Home') go(0)
  else if (e.key === 'End') go(list.length - 1)
  else if (e.key === 'Tab') close()
}
defineExpose({ close, toggle })
</script>

<template>
  <div ref="root" class="relative">
    <slot name="trigger" :open="open" :toggle="toggle" />
    <Transition name="pop">
      <div
        v-if="open"
        ref="menu"
        class="absolute z-30 max-h-[min(70vh,420px)] overflow-y-auto rounded-xl border border-line bg-surface p-1 shadow-pop"
        :class="[width, align === 'right' ? 'right-0' : 'left-0', placement === 'top' ? 'bottom-full mb-2 origin-bottom' : 'top-full mt-2 origin-top']"
        @keydown="onKeydown"
      >
        <slot :close="close" />
      </div>
    </Transition>
  </div>
</template>
