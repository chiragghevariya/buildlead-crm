<script setup>
import { RouterLink } from 'vue-router'

defineProps({
  icon: { type: [Object, Function], default: null },
  to: { type: [String, Object], default: null },
  danger: Boolean,
  shortcut: { type: String, default: null },
})
</script>

<template>
  <component
    :is="to ? RouterLink : 'button'" :to="to || undefined" :type="to ? undefined : 'button'"
    class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[13.5px] transition"
    :class="danger ? 'text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-400/10' : 'text-ink hover:bg-subtle'"
  >
    <component :is="icon" v-if="icon" class="h-4 w-4 shrink-0" :class="danger ? '' : 'text-faint'" />
    <span class="flex-1 truncate"><slot /></span>
    <span v-if="shortcut" class="kbd">{{ shortcut }}</span>
  </component>
</template>
