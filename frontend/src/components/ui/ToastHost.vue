<script setup>
import { storeToRefs } from 'pinia'
import { CircleCheck, CircleAlert, Flame, Info, X } from 'lucide-vue-next'
import { useToastStore } from '@/stores/toasts'

const store = useToastStore()
const { items } = storeToRefs(store)

const look = {
  success: { icon: CircleCheck, class: 'text-emerald-500' },
  error: { icon: CircleAlert, class: 'text-red-500' },
  info: { icon: Info, class: 'text-sky-500' },
  hot: { icon: Flame, class: 'text-white' },
}
</script>

<template>
  <Teleport to="body">
    <div class="pointer-events-none fixed inset-x-0 bottom-20 z-[60] flex flex-col items-center gap-2 px-4 sm:bottom-6 sm:right-6 sm:left-auto sm:items-end" aria-live="polite">
      <TransitionGroup
        enter-active-class="transition duration-300 ease-[cubic-bezier(.2,.8,.2,1)]" enter-from-class="translate-y-3 opacity-0 sm:translate-y-0 sm:translate-x-6"
        leave-active-class="transition duration-200 ease-in absolute" leave-to-class="opacity-0 scale-95"
        move-class="transition duration-300"
      >
        <div
          v-for="t in items" :key="t.id"
          class="pointer-events-auto flex w-full max-w-sm items-start gap-3 overflow-hidden rounded-xl border border-line bg-surface p-3.5 pr-2.5 shadow-pop"
        >
          <span
            class="mt-px flex h-7 w-7 shrink-0 items-center justify-center rounded-lg"
            :class="t.type === 'hot' ? 'bg-gradient-to-br from-red-500 to-orange-500 shadow-[0_2px_8px_-2px_rgb(239_68_68/0.6)]' : 'bg-subtle'"
          >
            <component :is="look[t.type]?.icon ?? Info" class="h-4 w-4" :class="[look[t.type]?.class, t.type === 'hot' && 'animate-flicker']" :stroke-width="2.25" />
          </span>
          <div class="min-w-0 flex-1 pt-0.5">
            <p class="text-[14px] font-semibold leading-5 text-ink">{{ t.title }}</p>
            <p v-if="t.description" class="mt-0.5 text-[13px] leading-5 text-muted">{{ t.description }}</p>
            <button v-if="t.action" class="mt-1.5 text-[13px] font-semibold text-brand-600 hover:text-brand-700 dark:text-brand-400" @click="t.action.run(); store.dismiss(t.id)">
              {{ t.action.label }} →
            </button>
          </div>
          <button class="rounded-md p-1 text-faint hover:bg-subtle hover:text-ink" aria-label="Dismiss" @click="store.dismiss(t.id)"><X class="h-3.5 w-3.5" /></button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>
