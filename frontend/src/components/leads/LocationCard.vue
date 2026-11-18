<script setup>
import { computed } from 'vue'
import { ExternalLink, MapPin } from 'lucide-vue-next'

/**
 * Map-style location card: a stylised, theme-aware street map drawn in SVG
 * (deterministic per postcode — no map tiles, works offline / in demo mode).
 */
const props = defineProps({ address: { type: Object, required: true } })

const seed = computed(() => [...String(props.address.postcode || props.address.city || 'x')].reduce((h, c) => (h * 33 + c.charCodeAt(0)) >>> 0, 5381))
const rand = (i) => ((Math.sin(seed.value + i * 97.13) + 1) / 2)
const roads = computed(() => Array.from({ length: 7 }, (_, i) => ({
  x1: -20, y1: 20 + i * 26 + rand(i) * 16, x2: 380, y2: 10 + i * 26 + rand(i + 9) * 30, w: i === 3 ? 7 : rand(i + 3) > 0.7 ? 4 : 2,
})))
const cross = computed(() => Array.from({ length: 6 }, (_, i) => ({
  x1: 30 + i * 62 + rand(i + 20) * 20, y1: -10, x2: 10 + i * 62 + rand(i + 30) * 40, y2: 200, w: i === 2 ? 6 : 2,
})))
const parks = computed(() => [0, 1].map((i) => ({ x: 40 + rand(i + 40) * 240, y: 20 + rand(i + 50) * 110, w: 50 + rand(i + 60) * 40, h: 30 + rand(i + 70) * 30 })))
const zone = computed(() => {
  const p = (props.address.postcode || '').slice(0, 2)
  return ['10', '12', '13', '14'].includes(p) ? { label: 'Core service area', class: 'bg-emerald-500/90' }
    : ['15', '16', '03', '04'].includes(p) ? { label: 'Outer service area', class: 'bg-amber-500/90' }
      : { label: 'Outside service area', class: 'bg-slate-500/90' }
})
const mapsUrl = computed(() => `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(props.address.full || `${props.address.postcode} ${props.address.city}`)}`)
</script>

<template>
  <div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <div class="relative h-40 overflow-hidden bg-[#EEF1F4] dark:bg-[#0F1A2B]">
      <svg viewBox="0 0 360 180" preserveAspectRatio="xMidYMid slice" class="absolute inset-0 h-full w-full" aria-hidden="true">
        <rect v-for="(p, i) in parks" :key="`p${i}`" :x="p.x" :y="p.y" :width="p.w" :height="p.h" rx="8" class="fill-emerald-200/70 dark:fill-emerald-500/10" />
        <path d="M-10 150 C 80 120, 140 175, 220 140 S 330 120, 380 150 L380 200 L-10 200Z" class="fill-sky-200/80 dark:fill-sky-500/10" />
        <line v-for="(r, i) in [...roads, ...cross]" :key="`r${i}`" v-bind="{ x1: r.x1, y1: r.y1, x2: r.x2, y2: r.y2 }" :stroke-width="r.w" stroke-linecap="round" class="stroke-white dark:stroke-white/10" />
      </svg>
      <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-full">
        <span class="absolute left-1/2 top-full h-3 w-8 -translate-x-1/2 -translate-y-1.5 rounded-[50%] bg-black/15 blur-[2px]" />
        <span class="relative flex h-9 w-9 items-center justify-center rounded-full rounded-bl-none bg-brand shadow-lg ring-4 ring-white -rotate-45 dark:ring-navy">
          <MapPin class="h-4 w-4 rotate-45 text-navy" :stroke-width="2.5" />
        </span>
      </div>
      <span class="absolute left-3 top-3 rounded-full px-2 py-0.5 text-[11px] font-semibold text-white shadow-sm" :class="zone.class">{{ zone.label }}</span>
    </div>
    <div class="flex items-start justify-between gap-3 p-4">
      <div class="min-w-0">
        <p class="truncate text-[13.5px] font-medium text-ink">{{ address.street || 'No street given' }}</p>
        <p class="num text-[13px] text-muted">{{ address.postcode }} {{ address.city }}</p>
      </div>
      <a :href="mapsUrl" target="_blank" rel="noopener noreferrer" class="inline-flex shrink-0 items-center gap-1 text-[12.5px] font-medium text-brand-700 hover:underline dark:text-brand-400">Maps <ExternalLink class="h-3 w-3" /></a>
    </div>
  </div>
</template>
