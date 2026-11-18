<script setup>
import { Flame, Snowflake, ThermometerSun } from 'lucide-vue-next'

defineProps({
  temperature: { type: String, default: null }, // hot | warm | cold
  score: { type: Number, default: null },
  size: { type: String, default: 'md' },
})

const styles = {
  hot: { icon: Flame, label: 'Hot', class: 'bg-gradient-to-r from-red-500 to-orange-500 text-white ring-red-600/20 shadow-[0_1px_6px_-1px_rgb(239_68_68/0.45)]' },
  warm: { icon: ThermometerSun, label: 'Warm', class: 'bg-amber-100 text-amber-800 ring-amber-500/30 dark:bg-amber-400/15 dark:text-amber-300' },
  cold: { icon: Snowflake, label: 'Cold', class: 'bg-slate-100 text-slate-600 ring-slate-500/20 dark:bg-slate-400/10 dark:text-slate-300' },
}
</script>

<template>
  <span
    v-if="temperature && styles[temperature]"
    class="inline-flex items-center gap-1 whitespace-nowrap rounded-full font-semibold ring-1 ring-inset"
    :class="[styles[temperature].class, size === 'sm' ? 'px-1.5 py-px text-[11px]' : 'px-2 py-0.5 text-[12px]']"
  >
    <component :is="styles[temperature].icon" :class="[size === 'sm' ? 'h-3 w-3' : 'h-3.5 w-3.5', temperature === 'hot' && 'animate-flicker']" :stroke-width="2.4" />
    {{ styles[temperature].label }}
    <span v-if="score !== null" class="num font-medium opacity-80">· {{ score }}</span>
  </span>
  <span v-else class="inline-flex items-center gap-1 rounded-full bg-subtle px-2 py-0.5 text-[12px] text-muted ring-1 ring-inset ring-line">Scoring…</span>
</template>
