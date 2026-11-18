<script setup>
import { computed } from 'vue'
import { ArrowDownRight, ArrowUpRight } from 'lucide-vue-next'
import Skeleton from './Skeleton.vue'

const props = defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], default: null },
  icon: { type: [Object, Function], default: null },
  tone: { type: String, default: 'amber' }, // amber | red | green | blue | violet | slate
  delta: { type: Number, default: null },    // change vs. previous period
  deltaSuffix: { type: String, default: '%' }, // '%' or ' pts'
  deltaLabel: { type: String, default: 'vs. previous 30 days' },
  hint: { type: String, default: null },
  trend: { type: Array, default: null },     // sparkline values
  loading: Boolean,
  invertDelta: Boolean,                       // lower is better
})

const tones = {
  amber: 'bg-amber-50 text-amber-600 ring-amber-500/15 dark:bg-amber-400/10 dark:text-amber-400',
  red: 'bg-red-50 text-red-600 ring-red-500/15 dark:bg-red-400/10 dark:text-red-400',
  green: 'bg-emerald-50 text-emerald-600 ring-emerald-500/15 dark:bg-emerald-400/10 dark:text-emerald-400',
  blue: 'bg-sky-50 text-sky-600 ring-sky-500/15 dark:bg-sky-400/10 dark:text-sky-400',
  violet: 'bg-violet-50 text-violet-600 ring-violet-500/15 dark:bg-violet-400/10 dark:text-violet-400',
  slate: 'bg-slate-100 text-slate-600 ring-slate-500/15 dark:bg-slate-400/10 dark:text-slate-300',
}
const gradientId = `sg-${Math.random().toString(36).slice(2, 9)}` // must be a valid, unique SVG id
const good = computed(() => (props.delta ?? 0) >= 0 !== props.invertDelta)
const spark = computed(() => {
  if (!props.trend?.length) return null
  const max = Math.max(...props.trend, 1)
  const step = 100 / Math.max(1, props.trend.length - 1)
  const pts = props.trend.map((v, i) => [i * step, 28 - (v / max) * 24])
  const line = pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ')
  return { line, area: `${line} L100,30 L0,30 Z` }
})
</script>

<template>
  <div class="card group relative overflow-hidden p-5 transition hover:shadow-lift" :class="trend?.length && 'pb-12'">
    <div class="flex items-start justify-between gap-3">
      <p class="text-[13px] font-medium text-muted">{{ label }}</p>
      <span v-if="icon" class="flex h-8 w-8 items-center justify-center rounded-lg ring-1 ring-inset" :class="tones[tone]">
        <component :is="icon" class="h-4 w-4" :stroke-width="2.2" />
      </span>
    </div>
    <template v-if="loading">
      <Skeleton class="mt-3 h-7 w-28" /><Skeleton class="mt-3 h-4 w-36" />
    </template>
    <template v-else>
      <p class="num mt-2 text-[26px] font-semibold leading-8 tracking-[-0.02em] text-ink">{{ value ?? '—' }}</p>
      <div class="mt-2 flex items-center gap-1.5 text-[12px]">
        <span v-if="delta !== null" class="num inline-flex shrink-0 items-center gap-0.5 whitespace-nowrap rounded-md px-1 py-px font-semibold" :class="good ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-400' : 'bg-red-50 text-red-700 dark:bg-red-400/10 dark:text-red-400'">
          <component :is="delta >= 0 ? ArrowUpRight : ArrowDownRight" class="h-3 w-3" :stroke-width="2.5" />{{ Math.abs(delta).toFixed(0) }}{{ deltaSuffix }}
        </span>
        <span class="truncate text-muted">{{ delta !== null ? deltaLabel : hint }}</span>
      </div>
    </template>
    <svg v-if="spark && !loading" viewBox="0 0 100 30" preserveAspectRatio="none" class="pointer-events-none absolute inset-x-0 bottom-0 h-9 w-full opacity-60 transition group-hover:opacity-100">
      <defs><linearGradient :id="gradientId" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#F59E0B" stop-opacity=".22" /><stop offset="100%" stop-color="#F59E0B" stop-opacity="0" /></linearGradient></defs>
      <path :d="spark.area" :fill="`url(#${gradientId})`" />
      <path :d="spark.line" fill="none" stroke="#F59E0B" stroke-width="1.5" vector-effect="non-scaling-stroke" />
    </svg>
  </div>
</template>
