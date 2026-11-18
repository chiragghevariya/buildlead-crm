<script setup>
import { computed } from 'vue'

const props = defineProps({
  name: { type: String, default: '' },
  initials: { type: String, default: null },
  size: { type: String, default: 'md' }, // xs | sm | md | lg
  ring: Boolean,
})

const palette = [
  'from-amber-400 to-orange-500', 'from-sky-400 to-indigo-500', 'from-emerald-400 to-teal-500',
  'from-fuchsia-400 to-pink-500', 'from-violet-400 to-purple-500', 'from-rose-400 to-red-500', 'from-cyan-400 to-blue-500',
]
const text = computed(() => props.initials || props.name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || '?')
const color = computed(() => palette[[...props.name].reduce((h, c) => (h * 31 + c.charCodeAt(0)) >>> 0, 7) % palette.length])
const sizes = { xs: 'h-5 w-5 text-[9px]', sm: 'h-6 w-6 text-[10px]', md: 'h-8 w-8 text-[12px]', lg: 'h-10 w-10 text-sm', xl: 'h-14 w-14 text-lg' }
</script>

<template>
  <span
    class="inline-flex shrink-0 select-none items-center justify-center rounded-full bg-gradient-to-br font-semibold text-white"
    :class="[color, sizes[size], ring && 'ring-2 ring-surface']"
    :title="name"
  >{{ text }}</span>
</template>
