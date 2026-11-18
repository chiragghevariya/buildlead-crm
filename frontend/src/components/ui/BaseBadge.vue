<script setup>
defineProps({
  tone: { type: String, default: 'gray' }, // gray | amber | green | red | orange | blue | violet | navy
  dot: Boolean,
  size: { type: String, default: 'md' },
})

const tones = {
  gray: 'bg-slate-100 text-slate-700 ring-slate-500/15 dark:bg-slate-400/10 dark:text-slate-300 dark:ring-slate-400/20',
  amber: 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/25',
  green: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/25',
  red: 'bg-red-50 text-red-700 ring-red-600/15 dark:bg-red-400/10 dark:text-red-300 dark:ring-red-400/25',
  orange: 'bg-orange-50 text-orange-700 ring-orange-600/20 dark:bg-orange-400/10 dark:text-orange-300 dark:ring-orange-400/25',
  blue: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-400/10 dark:text-sky-300 dark:ring-sky-400/25',
  violet: 'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-400/10 dark:text-violet-300 dark:ring-violet-400/25',
  navy: 'bg-navy text-white ring-navy dark:bg-white/10 dark:ring-white/15',
}
const dots = { gray: 'bg-slate-400', amber: 'bg-amber-500', green: 'bg-emerald-500', red: 'bg-red-500', orange: 'bg-orange-500', blue: 'bg-sky-500', violet: 'bg-violet-500', navy: 'bg-brand' }
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full font-medium ring-1 ring-inset"
    :class="[tones[tone] ?? tones.gray, size === 'sm' ? 'px-1.5 py-px text-[11px]' : 'px-2 py-0.5 text-[12px]']"
  >
    <span v-if="dot" class="h-1.5 w-1.5 rounded-full" :class="dots[tone]" />
    <slot />
  </span>
</template>
