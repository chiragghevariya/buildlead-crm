<script setup>
/** Label + control + hint/error wrapper. Use with any input in the default slot. */
defineProps({
  label: { type: String, default: null },
  for: { type: String, default: null },
  error: { type: [String, Array], default: null },
  hint: { type: String, default: null },
  optional: Boolean,
})
</script>

<template>
  <div>
    <label v-if="label" :for="$props.for" class="field-label">
      {{ label }} <span v-if="optional" class="font-normal text-faint">(optional)</span>
    </label>
    <slot />
    <p v-if="error" class="field-error">{{ Array.isArray(error) ? error[0] : error }}</p>
    <p v-else-if="hint" class="mt-1 text-xs text-muted">{{ hint }}</p>
  </div>
</template>
