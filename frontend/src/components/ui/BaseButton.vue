<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { LoaderCircle } from 'lucide-vue-next'

const props = defineProps({
  variant: { type: String, default: 'primary' }, // primary | secondary | ghost | danger | dark
  size: { type: String, default: 'md' },          // sm | md | lg | icon
  icon: { type: [Object, Function], default: null },
  iconRight: { type: [Object, Function], default: null },
  loading: Boolean,
  disabled: Boolean,
  block: Boolean,
  to: { type: [String, Object], default: null },
  type: { type: String, default: 'button' },
})

const classes = computed(() => [
  'focus-ring relative inline-flex select-none items-center justify-center gap-2 whitespace-nowrap font-medium transition-all duration-150 active:translate-y-px disabled:pointer-events-none disabled:opacity-50',
  {
    primary: 'bg-brand text-navy shadow-[0_1px_0_0_rgb(255_255_255/0.35)_inset,0_1px_2px_0_rgb(180_83_9/0.3)] hover:bg-brand-hover hover:text-white',
    secondary: 'border border-line bg-surface text-ink shadow-sm hover:bg-subtle hover:border-faint/50',
    ghost: 'text-muted hover:bg-subtle hover:text-ink',
    danger: 'bg-red-600 text-white shadow-sm hover:bg-red-700',
    dark: 'bg-navy text-white shadow-sm hover:bg-navy-700 dark:bg-white dark:text-navy dark:hover:bg-white/90',
  }[props.variant],
  {
    sm: 'h-8 rounded-lg px-3 text-[13px]',
    md: 'h-9 rounded-[10px] px-3.5 text-[14px]',
    lg: 'h-11 rounded-xl px-5 text-[15px]',
    icon: 'h-9 w-9 rounded-[10px]',
  }[props.size],
  props.block && 'w-full',
])
</script>

<template>
  <component
    :is="to ? RouterLink : 'button'"
    :to="to || undefined"
    :type="to ? undefined : type"
    :disabled="disabled || loading"
    :class="classes"
  >
    <LoaderCircle v-if="loading" class="h-4 w-4 animate-spin" />
    <component :is="icon" v-else-if="icon" class="h-4 w-4 shrink-0" :stroke-width="2" />
    <slot />
    <component :is="iconRight" v-if="iconRight && !loading" class="h-4 w-4 shrink-0" :stroke-width="2" />
  </component>
</template>
