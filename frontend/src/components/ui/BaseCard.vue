<script setup>
defineProps({
  title: { type: String, default: null },
  subtitle: { type: String, default: null },
  padded: { type: Boolean, default: true },
  hover: Boolean,
})
</script>

<template>
  <section class="card overflow-hidden" :class="hover && 'transition hover:-translate-y-0.5 hover:shadow-lift'">
    <header v-if="title || $slots.header || $slots.actions" class="flex items-start justify-between gap-4 px-5 pt-5">
      <slot name="header">
        <div class="min-w-0">
          <h3 class="text-[15px] font-semibold tracking-[-0.01em] text-ink">{{ title }}</h3>
          <p v-if="subtitle" class="mt-0.5 text-[13px] text-muted">{{ subtitle }}</p>
        </div>
      </slot>
      <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2"><slot name="actions" /></div>
    </header>
    <div :class="padded ? 'p-5' : ''"><slot /></div>
    <footer v-if="$slots.footer" class="border-t border-line bg-subtle/40 px-5 py-3"><slot name="footer" /></footer>
  </section>
</template>
