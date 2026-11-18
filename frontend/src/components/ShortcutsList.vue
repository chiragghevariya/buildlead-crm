<script setup>
import { SHORTCUTS } from '@/lib/shortcuts'
</script>

<template>
  <ul class="divide-y divide-line">
    <li v-for="s in SHORTCUTS" :key="s.label" class="flex items-center justify-between gap-4 px-5 py-3 text-[13.5px]">
      <span class="text-ink">{{ s.label }}</span>
      <span class="flex shrink-0 items-center gap-1"><template v-for="(k, i) in s.keys" :key="k"><span v-if="i && s.then" class="px-0.5 text-[11px] text-faint">then</span><kbd class="kbd">{{ k }}</kbd></template></span>
    </li>
  </ul>
</template>
