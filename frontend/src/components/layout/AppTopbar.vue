<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useIntervalFn } from '@vueuse/core'
import { Bell, ChevronRight, Flame, Keyboard, LogOut, Moon, Plus, Search, Settings, Sun, User } from 'lucide-vue-next'
import BaseButton from '@/components/ui/BaseButton.vue'
import Avatar from '@/components/ui/Avatar.vue'
import Dropdown from '@/components/ui/Dropdown.vue'
import DropdownItem from '@/components/ui/DropdownItem.vue'
import Logo from '@/components/ui/Logo.vue'
import { notifications as notificationsApi } from '@/api'
import { NAV } from '@/lib/navigation'
import { timeAgo } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()
const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform)

const crumbs = computed(() => {
  const parent = { lead: 'leads', quote: 'quotes', 'quote-new': 'quotes', project: 'projects' }
  const name = parent[route.name] ?? route.name
  const item = NAV.find((n) => n.name === name)
  const list = [{ label: 'BuildLead Bau', to: { name: 'dashboard' } }]
  if (item && item.name !== 'dashboard') list.push({ label: item.label, to: { name: item.name } })
  if (item?.name === 'dashboard') list.push({ label: 'Dashboard' })
  const leaf = { lead: 'Lead details', quote: 'Quote', 'quote-new': 'New quote', project: 'Project' }[route.name]
  if (leaf) list.push({ label: leaf })
  return list
})

// Notifications (polled; hot lead alerts land here too).
const notes = ref([])
const unread = ref(0)
async function loadNotes() {
  try { const r = await notificationsApi.list(); notes.value = r.data; unread.value = r.unread_count } catch { /* ignore */ }
}
async function markRead() {
  unread.value = 0
  notes.value = notes.value.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() }))
  try { await notificationsApi.markAllRead() } catch { /* ignore */ }
}
onMounted(loadNotes)
useIntervalFn(loadNotes, 60_000)

function openNote(n, close) {
  close()
  if (n.data?.lead_id) ui.openLead(n.data.lead_id)
}

async function logout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-canvas/80 px-4 backdrop-blur-xl supports-[backdrop-filter]:bg-canvas/70 sm:px-6">
    <RouterLink :to="{ name: 'dashboard' }" class="md:hidden"><Logo :size="28" /></RouterLink>

    <nav class="hidden min-w-0 items-center gap-1.5 text-[13px] md:flex" aria-label="Breadcrumb">
      <template v-for="(c, i) in crumbs" :key="i">
        <ChevronRight v-if="i" class="h-3.5 w-3.5 shrink-0 text-faint" />
        <RouterLink v-if="c.to && i < crumbs.length - 1" :to="c.to" class="truncate text-muted transition hover:text-ink">{{ c.label }}</RouterLink>
        <span v-else class="truncate font-medium text-ink">{{ c.label }}</span>
      </template>
    </nav>

    <div class="flex flex-1 justify-end md:justify-center">
      <button
        type="button"
        class="focus-ring group flex h-9 w-9 items-center justify-center gap-2 rounded-[10px] border border-line bg-surface text-[13px] text-faint shadow-sm transition hover:border-faint/50 hover:text-muted sm:w-full sm:max-w-[360px] sm:justify-start sm:px-3"
        aria-label="Search or jump to (⌘K)"
        @click="ui.paletteOpen = true"
      >
        <Search class="h-4 w-4 shrink-0" />
        <span class="hidden min-w-0 flex-1 truncate whitespace-nowrap text-left sm:inline">Search leads, pages, actions…</span>
        <span class="hidden shrink-0 items-center gap-1 lg:flex"><kbd class="kbd">{{ isMac ? '⌘' : 'Ctrl' }}</kbd><kbd class="kbd">K</kbd></span>
      </button>
    </div>

    <div class="flex items-center gap-1.5">
      <BaseButton class="hidden lg:inline-flex" :icon="Plus" size="sm" @click="ui.newLeadOpen = true">New lead</BaseButton>

      <Dropdown width="w-80 sm:w-96">
        <template #trigger="{ toggle }">
          <button class="focus-ring relative flex h-9 w-9 items-center justify-center rounded-[10px] text-muted transition hover:bg-subtle hover:text-ink" aria-label="Notifications" @click="toggle">
            <Bell class="h-[18px] w-[18px]" />
            <span v-if="unread" class="absolute right-1.5 top-1.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white ring-2 ring-canvas">{{ unread }}</span>
          </button>
        </template>
        <template #default="{ close }">
          <div class="flex items-center justify-between px-3 pb-2 pt-2">
            <p class="text-[13px] font-semibold text-ink">Notifications</p>
            <button v-if="unread" class="text-[12px] font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400" @click="markRead">Mark all read</button>
          </div>
          <div class="max-h-96 overflow-y-auto">
            <button
              v-for="n in notes" :key="n.id"
              class="flex w-full items-start gap-3 rounded-lg px-3 py-2.5 text-left transition hover:bg-subtle"
              @click="openNote(n, close)"
            >
              <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-red-500 to-orange-500"><Flame class="h-3.5 w-3.5 text-white" :stroke-width="2.5" /></span>
              <span class="min-w-0 flex-1">
                <span class="flex items-center gap-2">
                  <span class="truncate text-[13px] font-medium text-ink">{{ n.data.title }}</span>
                  <span v-if="!n.read_at" class="h-1.5 w-1.5 shrink-0 rounded-full bg-brand" />
                </span>
                <span class="mt-0.5 line-clamp-2 block text-[12px] text-muted">Score {{ n.data.score }} · {{ n.data.summary }}</span>
                <span class="mt-1 block text-[11px] text-faint">{{ timeAgo(n.created_at) }}<template v-if="n.data.assigned_to"> · {{ n.data.assigned_to }}</template></span>
              </span>
            </button>
            <p v-if="!notes.length" class="px-3 py-8 text-center text-[13px] text-muted">You're all caught up ✨</p>
          </div>
        </template>
      </Dropdown>

      <button class="focus-ring flex h-9 w-9 items-center justify-center rounded-[10px] text-muted transition hover:bg-subtle hover:text-ink" :aria-label="ui.isDark() ? 'Switch to light mode' : 'Switch to dark mode'" @click="ui.toggleTheme()">
        <Sun v-if="ui.isDark()" class="h-[18px] w-[18px]" />
        <Moon v-else class="h-[18px] w-[18px]" />
      </button>

      <Dropdown width="w-60">
        <template #trigger="{ toggle }">
          <button class="focus-ring ml-1 rounded-full" aria-label="Account menu" @click="toggle"><Avatar :name="auth.user?.name" size="md" /></button>
        </template>
        <template #default="{ close }">
          <div class="px-3 pb-2 pt-2">
            <p class="truncate text-[13px] font-semibold text-ink">{{ auth.user?.name }}</p>
            <p class="truncate text-[12px] text-muted">{{ auth.user?.email }}</p>
          </div>
          <div class="my-1 h-px bg-line" />
          <template v-if="auth.canSeeAll">
            <DropdownItem :icon="User" :to="{ name: 'settings', query: { tab: 'profile' } }" @click="close">Profile</DropdownItem>
            <DropdownItem :icon="Settings" :to="{ name: 'settings' }" @click="close">Settings</DropdownItem>
          </template>
          <DropdownItem :icon="Keyboard" shortcut="?" @click="close(); ui.shortcutsOpen = true">Keyboard shortcuts</DropdownItem>
          <DropdownItem :icon="ui.isDark() ? Sun : Moon" @click="ui.toggleTheme()">{{ ui.isDark() ? 'Light mode' : 'Dark mode' }}</DropdownItem>
          <div class="my-1 h-px bg-line" />
          <DropdownItem :icon="LogOut" danger @click="logout">Log out</DropdownItem>
        </template>
      </Dropdown>
    </div>
  </header>
</template>
