<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { TransitionPresets, useTransition } from '@vueuse/core'

const props = defineProps({
  score: { type: Number, default: null },
  temperature: { type: String, default: null },
  size: { type: Number, default: 48 },
  stroke: { type: Number, default: null },
  /** show "/100" under the number (large rings) */
  showMax: Boolean,
  animate: { type: Boolean, default: true },
})

// Animate 0 → score on mount and between values.
const target = ref(0)
// Big rings get a showy fill; small list rings settle fast so lists never look inconsistent.
const animated = useTransition(target, { duration: props.size >= 60 ? 1100 : 450, transition: TransitionPresets.easeOutCubic })
onMounted(() => requestAnimationFrame(() => { target.value = props.score ?? 0 }))
watch(() => props.score, (v) => { target.value = v ?? 0 })
const value = computed(() => (props.animate ? animated.value : props.score ?? 0))

const temp = computed(() => props.temperature ?? (props.score >= 75 ? 'hot' : props.score >= 50 ? 'warm' : 'cold'))
const strokeWidth = computed(() => props.stroke ?? Math.max(3, Math.round(props.size / 11)))
const r = computed(() => (props.size - strokeWidth.value) / 2)
const circumference = computed(() => 2 * Math.PI * r.value)
const offset = computed(() => circumference.value * (1 - Math.min(100, Math.max(0, value.value)) / 100))
const uid = `sr-${Math.random().toString(36).slice(2, 9)}`

const gradients = {
  hot: ['#EF4444', '#F97316'],
  warm: ['#F59E0B', '#FBBF24'],
  cold: ['#94A3B8', '#CBD5E1'],
}
const fontSize = computed(() => Math.max(10, Math.round(props.size * (props.showMax ? 0.3 : 0.32))))
</script>

<template>
  <div class="relative inline-flex shrink-0 items-center justify-center" :style="{ width: `${size}px`, height: `${size}px` }">
    <svg :width="size" :height="size" class="-rotate-90" aria-hidden="true">
      <defs>
        <linearGradient :id="uid" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" :stop-color="gradients[temp][0]" />
          <stop offset="100%" :stop-color="gradients[temp][1]" />
        </linearGradient>
      </defs>
      <circle :cx="size / 2" :cy="size / 2" :r="r" fill="none" class="stroke-line" :stroke-width="strokeWidth" />
      <circle
        v-if="score !== null"
        :cx="size / 2" :cy="size / 2" :r="r" fill="none"
        :stroke="`url(#${uid})`" :stroke-width="strokeWidth" stroke-linecap="round"
        :stroke-dasharray="circumference" :stroke-dashoffset="offset"
      />
    </svg>
    <div class="absolute inset-0 flex flex-col items-center justify-center leading-none">
      <span class="num font-semibold tracking-tight text-ink" :style="{ fontSize: `${fontSize}px` }">{{ score === null ? '–' : Math.round(value) }}</span>
      <span v-if="showMax" class="mt-1 text-[11px] font-medium text-faint">/ 100</span>
    </div>
    <span class="sr-only">Score {{ score ?? 'pending' }} of 100</span>
  </div>
</template>
