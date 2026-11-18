<script setup>
defineProps({
  collapsed: { type: Boolean, default: false },
  /** "light" = white wordmark (dark backgrounds) */
  tone: { type: String, default: 'auto' },
  size: { type: Number, default: 30 },
})
</script>

<template>
  <div class="flex items-center gap-2.5 select-none">
    <svg :width="size" :height="size" viewBox="0 0 32 32" class="shrink-0 drop-shadow-sm" aria-hidden="true">
      <rect width="32" height="32" rx="8" fill="#0B1220" />
      <rect x=".5" y=".5" width="31" height="31" rx="7.5" fill="none" stroke="#fff" stroke-opacity=".08" />
      <rect x="7.5" y="17" width="4.6" height="8" rx="1.3" fill="#F59E0B" fill-opacity=".5" />
      <rect x="13.7" y="12" width="4.6" height="13" rx="1.3" fill="#F59E0B" fill-opacity=".78" />
      <rect x="19.9" y="7" width="4.6" height="18" rx="1.3" fill="#F59E0B" />
    </svg>
    <span
      v-if="!collapsed"
      class="text-[17px] font-semibold tracking-[-0.02em]"
      :class="tone === 'light' ? 'text-white' : 'text-ink'"
    >Build<span class="text-brand">Lead</span></span>
  </div>
</template>
