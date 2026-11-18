<script setup>
defineProps({
  modelValue: { type: [String, Number], default: null },
  tabs: { type: Array, required: true }, // [{ value, label, count?, icon? }]
  variant: { type: String, default: 'pills' }, // pills | underline
})
defineEmits(['update:modelValue'])
</script>

<template>
  <div
    role="tablist"
    class="flex items-center gap-1 overflow-x-auto"
    :class="variant === 'pills' ? 'rounded-xl border border-line bg-subtle/70 p-1' : 'border-b border-line'"
  >
    <button
      v-for="tab in tabs" :key="tab.value"
      role="tab" type="button" :aria-selected="modelValue === tab.value"
      class="focus-ring group relative inline-flex shrink-0 items-center gap-2 whitespace-nowrap text-[13px] font-medium transition"
      :class="variant === 'pills'
        ? ['rounded-lg px-3 py-1.5', modelValue === tab.value ? 'bg-surface text-ink shadow-card ring-1 ring-line' : 'text-muted hover:text-ink']
        : ['-mb-px border-b-2 px-1 pb-2.5 pt-1 mr-4', modelValue === tab.value ? 'border-brand text-ink' : 'border-transparent text-muted hover:border-line hover:text-ink']"
      @click="$emit('update:modelValue', tab.value)"
    >
      <component :is="tab.icon" v-if="tab.icon" class="h-3.5 w-3.5" />
      {{ tab.label }}
      <span
        v-if="tab.count !== undefined && tab.count !== null"
        class="num rounded-md px-1.5 text-[11px] font-semibold"
        :class="modelValue === tab.value ? 'bg-brand/15 text-brand-700 dark:text-brand-300' : 'bg-line/70 text-muted'"
      >{{ tab.count }}</span>
    </button>
  </div>
</template>
