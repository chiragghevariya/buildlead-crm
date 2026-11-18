<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'
import { PanelLeftClose, PanelLeftOpen, Plus } from 'lucide-vue-next'
import Logo from '@/components/ui/Logo.vue'
import Avatar from '@/components/ui/Avatar.vue'
import { navFor } from '@/lib/navigation'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const auth = useAuthStore()
const ui = useUiStore()
const { sidebarRail: collapsed } = storeToRefs(ui)
const items = computed(() => navFor(auth.role))
const route = useRoute()
const CHILDREN = { leads: ['lead'], quotes: ['quote', 'quote-new'], projects: ['project'] }
const isChildOf = (name) => CHILDREN[name]?.includes(route.name)
</script>

<template>
  <aside
    class="fixed inset-y-0 left-0 z-30 hidden flex-col bg-navy text-slate-300 transition-[width] duration-200 ease-out md:flex"
    :class="collapsed ? 'w-[72px]' : 'w-[248px]'"
  >
    <!-- soft amber glow for depth -->
    <div class="pointer-events-none absolute inset-x-0 top-0 h-48 bg-[radial-gradient(120%_60%_at_0%_0%,rgb(245_158_11/0.10),transparent_60%)]" />

    <div class="relative flex h-16 items-center px-5" :class="collapsed && 'justify-center px-0'">
      <RouterLink :to="{ name: 'dashboard' }" class="rounded-lg focus-visible:ring-2 focus-visible:ring-brand/60" aria-label="BuildLead home">
        <Logo :collapsed="collapsed" tone="light" />
      </RouterLink>
    </div>

    <div class="relative px-3 pb-2">
      <button
        type="button"
        class="flex h-9 w-full items-center justify-center gap-2 rounded-[10px] bg-white/[0.06] text-[13px] font-medium text-white ring-1 ring-inset ring-white/10 transition hover:bg-white/10"
        :title="collapsed ? 'New lead (N)' : undefined"
        @click="ui.newLeadOpen = true"
      >
        <Plus class="h-4 w-4 text-brand" :stroke-width="2.5" />
        <template v-if="!collapsed">New lead <span class="ml-auto mr-1 rounded bg-white/10 px-1.5 text-[10px] text-slate-400">N</span></template>
      </button>
    </div>

    <nav class="relative mt-2 flex-1 space-y-0.5 overflow-y-auto px-3" aria-label="Main">
      <p v-if="!collapsed" class="px-3 pb-1.5 pt-2 text-[10.5px] font-semibold uppercase tracking-[0.1em] text-slate-500">Workspace</p>
      <RouterLink
        v-for="item in items" :key="item.name" :to="{ name: item.name }" custom
        v-slot="{ href, navigate, isActive, isExactActive }"
      >
        <a
          :href="href"
          class="group relative flex h-9 items-center gap-3 rounded-[10px] px-3 text-[13.5px] font-medium transition"
          :class="[
            (item.name === 'dashboard' ? isExactActive : isActive || isChildOf(item.name))
              ? 'bg-white/[0.08] text-white'
              : 'text-slate-400 hover:bg-white/[0.04] hover:text-slate-200',
            collapsed && 'justify-center px-0',
          ]"
          :title="collapsed ? item.label : undefined"
          @click="navigate"
        >
          <span
            v-if="item.name === 'dashboard' ? isExactActive : isActive || (isChildOf(item.name))"
            class="absolute left-0 top-1/2 h-5 w-[3px] -translate-x-3 -translate-y-1/2 rounded-r-full bg-brand"
          />
          <component :is="item.icon" class="h-[18px] w-[18px] shrink-0" :stroke-width="2" />
          <span v-if="!collapsed" class="flex-1 truncate">{{ item.label }}</span>
          <span v-if="!collapsed && item.shortcut" class="hidden text-[10px] tracking-wider text-slate-600 group-hover:inline">{{ item.shortcut }}</span>
        </a>
      </RouterLink>
    </nav>

    <div class="relative border-t border-white/[0.06] p-3">
      <div class="flex items-center gap-3 rounded-[10px] p-1.5" :class="collapsed && 'justify-center'">
        <Avatar :name="auth.user?.name" size="md" />
        <div v-if="!collapsed" class="min-w-0 flex-1">
          <p class="truncate text-[13px] font-medium text-white">{{ auth.user?.name }}</p>
          <p class="truncate text-[11.5px] text-slate-500">{{ auth.user?.role_label }} · BuildLead Bau</p>
        </div>
      </div>
      <button
        v-if="!ui.isTablet"
        type="button"
        class="focus-ring mt-1 flex h-8 w-full items-center gap-2 rounded-lg px-2.5 text-[12.5px] text-slate-500 transition hover:bg-white/[0.04] hover:text-slate-300"
        :class="collapsed && 'justify-center px-0'"
        :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
        @click="ui.sidebarCollapsed = !ui.sidebarCollapsed"
      >
        <PanelLeftOpen v-if="collapsed" class="h-4 w-4" />
        <template v-else><PanelLeftClose class="h-4 w-4" /> Collapse</template>
      </button>
    </div>
  </aside>
</template>
