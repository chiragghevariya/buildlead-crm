<script setup>
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: null },
  illustration: { type: String, default: 'leads' }, // leads | search | inbox | chart | board | error
  compact: Boolean,
})
</script>

<template>
  <div class="flex flex-col items-center justify-center text-center" :class="compact ? 'px-4 py-8' : 'px-6 py-14'">
    <!-- Friendly inline illustrations (theme-aware via currentColor + tokens) -->
    <svg :class="compact ? 'h-20 w-28' : 'h-28 w-40'" viewBox="0 0 160 112" fill="none" aria-hidden="true">
      <ellipse cx="80" cy="102" rx="54" ry="6" class="fill-subtle" />
      <template v-if="illustration === 'search'">
        <rect x="30" y="18" width="78" height="64" rx="10" class="fill-surface stroke-line" stroke-width="2" />
        <rect x="42" y="32" width="40" height="6" rx="3" class="fill-line" /><rect x="42" y="46" width="54" height="6" rx="3" class="fill-subtle" /><rect x="42" y="60" width="30" height="6" rx="3" class="fill-subtle" />
        <circle cx="106" cy="66" r="18" class="fill-surface" stroke="#F59E0B" stroke-width="4" /><path d="m119 79 14 14" stroke="#F59E0B" stroke-width="6" stroke-linecap="round" />
        <path d="M100 66h12" stroke="#F59E0B" stroke-width="3" stroke-linecap="round" opacity=".5" />
      </template>
      <template v-else-if="illustration === 'inbox'">
        <path d="M34 54 50 24h60l16 30v30a8 8 0 0 1-8 8H42a8 8 0 0 1-8-8z" class="fill-surface stroke-line" stroke-width="2" />
        <path d="M34 54h28a18 18 0 0 0 36 0h28" class="stroke-line" stroke-width="2" />
        <circle cx="116" cy="26" r="10" fill="#F59E0B" /><path d="m112 26 3 3 5-6" stroke="#0B1220" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
      </template>
      <template v-else-if="illustration === 'error'">
        <path d="M50 80a22 22 0 0 1-2-43.9A30 30 0 0 1 106 32a24 24 0 0 1 4 48z" class="fill-surface stroke-line" stroke-width="2" />
        <circle cx="112" cy="74" r="16" fill="#EF4444" /><path d="M112 66v9" stroke="#fff" stroke-width="3.2" stroke-linecap="round" /><circle cx="112" cy="81" r="1.9" fill="#fff" />
        <path d="M60 58h28M60 48h40" class="stroke-line" stroke-width="5" stroke-linecap="round" />
      </template>
      <template v-else-if="illustration === 'board'">
        <rect x="24" y="20" width="34" height="70" rx="7" class="fill-subtle" /><rect x="63" y="20" width="34" height="70" rx="7" class="fill-subtle" /><rect x="102" y="20" width="34" height="70" rx="7" class="fill-subtle" />
        <rect x="28" y="26" width="26" height="16" rx="4" class="fill-surface stroke-line" stroke-width="1.5" /><rect x="67" y="26" width="26" height="16" rx="4" class="fill-surface stroke-line" stroke-width="1.5" />
        <rect x="106" y="26" width="26" height="16" rx="4" fill="#F59E0B" fill-opacity=".25" stroke="#F59E0B" stroke-width="1.5" stroke-dasharray="3 3" />
      </template>
      <template v-else>
        <rect x="38" y="16" width="84" height="72" rx="12" class="fill-surface stroke-line" stroke-width="2" />
        <rect x="50" y="62" width="10" height="16" rx="3" fill="#F59E0B" fill-opacity=".45" /><rect x="65" y="50" width="10" height="28" rx="3" fill="#F59E0B" fill-opacity=".7" /><rect x="80" y="36" width="10" height="42" rx="3" fill="#F59E0B" />
        <rect x="95" y="56" width="10" height="22" rx="3" class="fill-line" />
        <circle cx="122" cy="20" r="11" class="fill-surface stroke-line" stroke-width="2" /><path d="M122 15v10M117 20h10" class="stroke-faint" stroke-width="2" stroke-linecap="round" />
      </template>
    </svg>
    <h3 class="mt-4 text-[15px] font-semibold text-ink">{{ title }}</h3>
    <p v-if="description" class="mt-1 max-w-sm text-[13px] text-muted">{{ description }}</p>
    <div v-if="$slots.default" class="mt-5 flex items-center gap-2"><slot /></div>
  </div>
</template>
