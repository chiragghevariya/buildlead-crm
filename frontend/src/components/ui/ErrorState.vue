<script setup>
import { RotateCw } from 'lucide-vue-next'
import BaseButton from './BaseButton.vue'
import EmptyState from './EmptyState.vue'

/** Shown when a screen's data failed to load (network down, 5xx). The toast says what; this offers a retry. */
defineProps({
  title: { type: String, default: 'Could not load this page' },
  message: { type: String, default: null },
  compact: Boolean,
  retrying: Boolean,
})
defineEmits(['retry'])
</script>

<template>
  <EmptyState role="alert" illustration="error" :compact="compact" :title="title" :description="message || 'Check your connection or try again in a moment.'">
    <BaseButton variant="secondary" :icon="RotateCw" :loading="retrying" @click="$emit('retry')">Try again</BaseButton>
  </EmptyState>
</template>
