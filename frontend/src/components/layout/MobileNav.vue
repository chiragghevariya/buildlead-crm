<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { LayoutDashboard, LogOut, MoreHorizontal, Plus, SquareKanban, Users } from 'lucide-vue-next'
import Modal from '@/components/ui/Modal.vue'
import { navFor } from '@/lib/navigation'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const left = [{ name: 'dashboard', label: 'Home', icon: LayoutDashboard }, { name: 'leads', label: 'Leads', icon: Users }]
const pipeline = { name: 'pipeline', label: 'Pipeline', icon: SquareKanban }

// Everything that doesn't fit the bar. One leftover (sales: Quotes) gets its own tab; more go into a sheet.
const rest = computed(() => navFor(auth.role).filter((n) => !['dashboard', 'leads', 'pipeline'].includes(n.name)))
const right = computed(() => (rest.value.length === 1 ? [pipeline, rest.value[0]] : [pipeline]))
const parentOf = { quote: 'quotes', 'quote-new': 'quotes', project: 'projects', lead: 'leads' }
const moreActive = computed(() => rest.value.length > 1 && rest.value.some((n) => n.name === (parentOf[route.name] ?? route.name)))

async function logout() {
  ui.moreOpen = false
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/90 pb-[env(safe-area-inset-bottom)] backdrop-blur-xl md:hidden" aria-label="Mobile">
    <div class="mx-auto grid h-16 max-w-md grid-cols-5 items-center">
      <RouterLink v-for="item in left" :key="item.name" :to="{ name: item.name }" custom v-slot="{ href, navigate, isActive, isExactActive }">
        <a :href="href" class="flex flex-col items-center gap-1 rounded-lg py-1 text-[10.5px] font-medium" :class="(item.name === 'dashboard' ? isExactActive : isActive) ? 'text-ink' : 'text-muted'" :aria-current="(item.name === 'dashboard' ? isExactActive : isActive) ? 'page' : undefined" @click="navigate">
          <component :is="item.icon" class="h-5 w-5" :stroke-width="(item.name === 'dashboard' ? isExactActive : isActive) ? 2.4 : 2" />{{ item.label }}
        </a>
      </RouterLink>
      <div class="flex justify-center">
        <button class="-mt-6 flex h-12 w-12 items-center justify-center rounded-2xl bg-brand text-navy shadow-[0_8px_20px_-6px_rgb(245_158_11/0.7)] ring-4 ring-canvas transition active:scale-95" aria-label="New lead" @click="ui.newLeadOpen = true">
          <Plus class="h-6 w-6" :stroke-width="2.5" />
        </button>
      </div>
      <RouterLink v-for="item in right" :key="item.name" :to="{ name: item.name }" custom v-slot="{ href, navigate, isActive }">
        <a :href="href" class="flex flex-col items-center gap-1 rounded-lg py-1 text-[10.5px] font-medium" :class="isActive ? 'text-ink' : 'text-muted'" :aria-current="isActive ? 'page' : undefined" @click="navigate">
          <component :is="item.icon" class="h-5 w-5" :stroke-width="isActive ? 2.4 : 2" />{{ item.label }}
        </a>
      </RouterLink>
      <button v-if="rest.length > 1" type="button" class="flex flex-col items-center gap-1 rounded-lg py-1 text-[10.5px] font-medium" :class="moreActive ? 'text-ink' : 'text-muted'" aria-haspopup="dialog" @click="ui.moreOpen = true">
        <MoreHorizontal class="h-5 w-5" :stroke-width="moreActive ? 2.4 : 2" />More
      </button>
    </div>
  </nav>

  <Modal v-model:open="ui.moreOpen" title="More" size="sm">
    <div class="-mx-3 -my-2 grid grid-cols-1 gap-0.5">
      <RouterLink
        v-for="item in rest" :key="item.name" :to="{ name: item.name }"
        class="flex items-center gap-3 rounded-xl px-3 py-3 text-[14.5px] font-medium text-ink transition hover:bg-subtle"
        active-class="bg-subtle" @click="ui.moreOpen = false"
      >
        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-subtle ring-1 ring-inset ring-line"><component :is="item.icon" class="h-[18px] w-[18px] text-muted" /></span>
        {{ item.label }}
      </RouterLink>
      <div class="my-1.5 h-px bg-line" />
      <button type="button" class="flex items-center gap-3 rounded-xl px-3 py-3 text-left text-[14.5px] font-medium text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-400/10" @click="logout">
        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-red-50 dark:bg-red-400/10"><LogOut class="h-[18px] w-[18px]" /></span>
        Log out
      </button>
    </div>
  </Modal>
</template>
