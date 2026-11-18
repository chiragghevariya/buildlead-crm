<script setup>
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: null },
})
</script>

<template>
  <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div class="min-w-0">
      <slot name="eyebrow" />
      <h1 class="text-[22px] font-semibold leading-8 tracking-[-0.025em] text-ink sm:text-2xl">{{ title }}</h1>
      <p v-if="description || $slots.description" class="mt-1 text-[14px] text-muted"><slot name="description">{{ description }}</slot></p>
    </div>
    <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2"><slot name="actions" /></div>
  </div>
</template>
