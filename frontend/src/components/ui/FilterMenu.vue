<script setup>
import { computed } from 'vue'
import { Check, ChevronDown, X } from 'lucide-vue-next'
import Dropdown from './Dropdown.vue'

/** Compact single-select filter button: "Status: Contacted ▾". */
const props = defineProps({
  label: { type: String, required: true },
  modelValue: { type: [String, Number], default: '' },
  options: { type: Array, default: () => [] }, // [{ value, label }]
})
const emit = defineEmits(['update:modelValue'])
const current = computed(() => props.options.find((o) => String(o.value) === String(props.modelValue)))
</script>

<template>
  <Dropdown align="left" width="w-60">
    <template #trigger="{ toggle }">
      <button
        type="button"
        class="focus-ring inline-flex h-9 items-center gap-1.5 rounded-[10px] border px-3 text-[13px] font-medium transition"
        :class="current ? 'border-brand/40 bg-brand/10 text-ink' : 'border-line bg-surface text-muted shadow-sm hover:border-faint/50 hover:text-ink'"
        @click="toggle"
      >
        {{ label }}<template v-if="current">: <span class="max-w-[120px] truncate text-ink">{{ current.label }}</span></template>
        <X v-if="current" class="-mr-1 h-3.5 w-3.5 text-muted hover:text-ink" @click.stop="emit('update:modelValue', '')" />
        <ChevronDown v-else class="h-3.5 w-3.5" />
      </button>
    </template>
    <template #default="{ close }">
      <div class="max-h-72 overflow-y-auto">
        <button
          v-for="o in [{ value: '', label: `Any ${label.toLowerCase()}` }, ...options]" :key="o.value" type="button"
          class="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-[13.5px] text-ink hover:bg-subtle"
          @click="emit('update:modelValue', o.value); close()"
        >
          <span class="flex-1 truncate">{{ o.label }}</span>
          <Check v-if="String(o.value) === String(modelValue)" class="h-4 w-4 text-brand" />
        </button>
      </div>
    </template>
  </Dropdown>
</template>
