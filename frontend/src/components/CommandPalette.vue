<script setup>
import { useFocusTrap } from '@/composables/useFocusTrap'
import { computed, nextTick, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useScrollLock } from '@vueuse/core'
import { ArrowRight, CornerDownLeft, FilePlus2, FileText, FolderKanban, Gauge, LogOut, Moon, PanelLeft, Plus, Search, SquareKanban, Sun, UserCog, Users, Keyboard } from 'lucide-vue-next'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import { moneyShort } from '@/lib/format'
import { projectTone, quoteTone } from '@/lib/status'
import TemperatureBadge from '@/components/ui/TemperatureBadge.vue'
import { leads as leadsApi, projects as projectsApi, quotes as quotesApi } from '@/api'
import { navFor } from '@/lib/navigation'
import { fuzzyFilter, fuzzyScore } from '@/lib/fuzzy'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
const auth = useAuthStore()
const router = useRouter()
const query = ref('')
const active = ref(0)
const input = ref(null)
const list = ref(null)
const leadIndex = ref([])
const quoteIndex = ref([])
const projectIndex = ref([])
const loadingLeads = ref(false)
let loadedAt = 0

const lock = useScrollLock(typeof document !== 'undefined' ? document.body : null)

async function loadLeads() {
  if (Date.now() - loadedAt < 60_000 && leadIndex.value.length) return
  loadingLeads.value = true
  try {
    const [l, q, p] = await Promise.all([
      leadsApi.list({ per_page: 100, sort: '-created_at' }),
      quotesApi.list({ per_page: 100 }).catch(() => ({ data: [] })),
      auth.canSeeAll ? projectsApi.list({ per_page: 100 }).catch(() => ({ data: [] })) : { data: [] },
    ])
    leadIndex.value = l.data
    quoteIndex.value = q.data
    projectIndex.value = p.data
    loadedAt = Date.now()
  } catch { /* palette still works for pages/actions */ } finally { loadingLeads.value = false }
}

watch(() => ui.paletteOpen, async (open) => {
  lock.value = open
  if (!open) return
  query.value = ''
  active.value = 0
  loadLeads()
  await nextTick()
  input.value?.focus()
})
watch(() => ui.leadsVersion, () => { loadedAt = 0 })

const close = () => { ui.paletteOpen = false }
const dialog = ref(null)
useFocusTrap(dialog, () => ui.paletteOpen, { initial: 'input' })

const actions = computed(() => [
  { id: 'new-lead', label: 'Create new lead', icon: Plus, shortcut: 'N', keywords: 'add create', run: () => { ui.newLeadOpen = true } },
  { id: 'new-quote', label: 'Create new quote', icon: FilePlus2, keywords: 'angebot offer builder', run: () => router.push({ name: 'quote-new' }) },
  ...(auth.canSeeAll ? [{ id: 'team', label: 'Team & capacity', icon: UserCog, keywords: 'reps regions capacity assignment people', run: () => router.push({ name: 'settings', query: { tab: 'team' } }) }] : []),
  { id: 'shortcuts', label: 'Keyboard shortcuts', icon: Keyboard, keywords: 'help keys hotkeys', run: () => { ui.shortcutsOpen = true } },
  ...(auth.isAdmin ? [{ id: 'scoring', label: 'AI scoring settings', icon: Gauge, keywords: 'weights thresholds openai model rescore', run: () => router.push({ name: 'settings', query: { tab: 'scoring' } }) }] : []),
  { id: 'hot-board', label: 'Pipeline: hot leads only', icon: SquareKanban, keywords: 'kanban board flame', run: () => router.push({ name: 'pipeline' }) },
  { id: 'theme', label: ui.isDark() ? 'Switch to light mode' : 'Switch to dark mode', icon: ui.isDark() ? Sun : Moon, keywords: 'theme dark light appearance', run: () => ui.toggleTheme() },
  { id: 'hot', label: 'Show hot leads', icon: Users, keywords: 'filter temperature flame', run: () => router.push({ name: 'leads', query: { temperature: 'hot' } }) },
  { id: 'sidebar', label: ui.sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar', icon: PanelLeft, keywords: 'navigation menu', run: () => { ui.sidebarCollapsed = !ui.sidebarCollapsed } },
  { id: 'logout', label: 'Log out', icon: LogOut, keywords: 'sign out exit', run: async () => { await auth.logout(); router.push({ name: 'login' }) } },
])

const groups = computed(() => {
  const q = query.value
  const pages = fuzzyFilter(q, navFor(auth.role), ['label', 'keywords'], 7).map((p) => ({
    id: `page-${p.name}`, label: p.label, icon: p.icon, shortcut: p.shortcut, hint: 'Jump to', run: () => router.push({ name: p.name }),
  }))
  const leadSource = q.trim() ? leadIndex.value : [...leadIndex.value].filter((l) => l.temperature === 'hot').sort((a, b) => b.score - a.score)
  const leadItems = fuzzyFilter(q, leadSource, ['contact_name', 'company', 'reference', (l) => l.address?.city, 'email'], q.trim() ? 8 : 4).map((l) => ({
    id: `lead-${l.id}`, lead: l, label: l.contact_name, run: () => router.push({ name: 'lead', params: { id: l.id } }),
  }))
  const acts = fuzzyFilter(q, actions.value, ['label', 'keywords'], q.trim() ? 5 : 4)
  const quoteItems = q.trim() ? fuzzyFilter(q, quoteIndex.value, ['number', (x) => x.lead?.company, (x) => x.lead?.contact_name], 4).map((x) => ({
    id: `quote-${x.id}`, quote: x, label: x.number, run: () => router.push({ name: 'quote', params: { id: x.id } }),
  })) : []
  const projectItems = q.trim() ? fuzzyFilter(q, projectIndex.value, ['code', 'name', 'site_address'], 4).map((x) => ({
    id: `project-${x.id}`, project: x, label: x.name, run: () => router.push({ name: 'project', params: { id: x.id } }),
  })) : []

  const groups = [
    { title: q.trim() ? 'Leads' : 'Hottest leads', items: leadItems },
    { title: 'Quotes', items: quoteItems },
    { title: 'Projects', items: projectItems },
    { title: 'Pages', items: pages },
    { title: 'Actions', items: acts },
  ].filter((g) => g.items.length)
  if (!q.trim()) return groups

  // Best match first: an exact page/action name ("team") must beat a loose fuzzy hit on a lead.
  const text = (it) => [it.label, it.lead?.company, it.lead?.reference, it.quote?.number, it.quote?.lead?.company, it.project?.code].filter(Boolean)
  const best = (g) => Math.max(...g.items.map((it) => Math.max(...text(it).map((t) => fuzzyScore(q, t)))))
  return groups.map((g) => ({ ...g, score: best(g) })).sort((a, b) => b.score - a.score)
})
const flat = computed(() => groups.value.flatMap((g) => g.items))
watch(query, () => { active.value = 0 })

function move(delta) {
  if (!flat.value.length) return
  active.value = (active.value + delta + flat.value.length) % flat.value.length
  nextTick(() => list.value?.querySelector(`[data-index="${active.value}"]`)?.scrollIntoView({ block: 'nearest' }))
}
function run(item) {
  close()
  item?.run()
}
const indexOf = (item) => flat.value.indexOf(item)
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="ui.paletteOpen" class="fixed inset-0 z-50 bg-navy-950/40 backdrop-blur-sm dark:bg-black/60" @click="close" />
    </Transition>
    <Transition name="pop">
      <div v-if="ui.paletteOpen" class="fixed inset-x-0 top-0 z-50 flex justify-center px-3 pt-[12vh]" @mousedown.self="close">
        <div ref="dialog" role="dialog" aria-modal="true" aria-label="Command palette" data-focus-trap class="w-full max-w-[640px] overflow-hidden rounded-2xl border border-line bg-surface shadow-pop ring-1 ring-black/5">
          <div class="flex items-center gap-3 border-b border-line px-4">
            <Search class="h-[18px] w-[18px] shrink-0 text-faint" />
            <input
              ref="input" v-model="query" type="text" placeholder="Search leads, jump to a page, or run an action…"
              class="h-14 w-full border-0 bg-transparent text-[15px] text-ink placeholder:text-faint focus:outline-none focus:ring-0"
              autocomplete="off" spellcheck="false"
              role="combobox" aria-label="Search" aria-autocomplete="list" aria-expanded="true" aria-controls="palette-list"
              :aria-activedescendant="flat.length ? `palette-opt-${active}` : undefined"
              @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
              @keydown.enter.prevent="run(flat[active])" @keydown.esc.prevent="close"
            />
            <kbd class="kbd shrink-0">esc</kbd>
          </div>

          <div id="palette-list" ref="list" role="listbox" aria-label="Results" class="max-h-[min(60vh,440px)] overflow-y-auto p-2">
            <div v-for="group in groups" :key="group.title" role="group" :aria-label="group.title" class="mb-1">
              <p class="eyebrow px-3 pb-1.5 pt-2" aria-hidden="true">{{ group.title }}</p>
              <button
                v-for="item in group.items" :id="`palette-opt-${indexOf(item)}`" :key="item.id" type="button" role="option" tabindex="-1" :aria-selected="indexOf(item) === active" :data-index="indexOf(item)"
                class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition-colors"
                :class="indexOf(item) === active ? 'bg-subtle' : ''"
                @mousemove="active = indexOf(item)" @click="run(item)"
              >
                <template v-if="item.lead">
                  <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-subtle text-[11px] font-semibold text-muted ring-1 ring-inset ring-line">{{ item.lead.score ?? '–' }}</span>
                  <span class="min-w-0 flex-1">
                    <span class="block truncate text-[14px] font-medium text-ink">{{ item.lead.contact_name }}<span v-if="item.lead.company" class="font-normal text-muted"> · {{ item.lead.company }}</span></span>
                    <span class="block truncate text-[12px] text-faint">{{ item.lead.reference }} · {{ item.lead.project_type_label || 'Project' }} · {{ item.lead.address?.city || '—' }}</span>
                  </span>
                  <TemperatureBadge :temperature="item.lead.temperature" size="sm" />
                </template>
                <template v-else-if="item.quote">
                  <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-subtle ring-1 ring-inset ring-line"><FileText class="h-4 w-4 text-muted" /></span>
                  <span class="min-w-0 flex-1">
                    <span class="num block truncate text-[14px] font-medium text-ink">{{ item.quote.number }}<span class="font-normal text-muted"> · {{ item.quote.lead.company || item.quote.lead.contact_name }}</span></span>
                    <span class="num block text-[12px] text-faint">{{ moneyShort(item.quote.total) }} gross</span>
                  </span>
                  <BaseBadge :tone="quoteTone(item.quote.status)" size="sm">{{ item.quote.status_label }}</BaseBadge>
                </template>
                <template v-else-if="item.project">
                  <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-subtle ring-1 ring-inset ring-line"><FolderKanban class="h-4 w-4 text-muted" /></span>
                  <span class="min-w-0 flex-1">
                    <span class="block truncate text-[14px] font-medium text-ink">{{ item.project.name }}</span>
                    <span class="num block text-[12px] text-faint">{{ item.project.code }} · {{ item.project.progress }}% · {{ moneyShort(item.project.budget) }}</span>
                  </span>
                  <BaseBadge :tone="projectTone(item.project.status)" size="sm">{{ item.project.status_label }}</BaseBadge>
                </template>
                <template v-else>
                  <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-subtle ring-1 ring-inset ring-line"><component :is="item.icon" class="h-4 w-4 text-muted" /></span>
                  <span class="flex-1 truncate text-[14px] font-medium text-ink">{{ item.label }}</span>
                  <span v-if="item.shortcut" class="flex gap-1"><kbd v-for="k in item.shortcut.split(' ')" :key="k" class="kbd">{{ k }}</kbd></span>
                  <ArrowRight v-else-if="indexOf(item) === active" class="h-4 w-4 text-faint" />
                </template>
              </button>
            </div>

            <div v-if="!flat.length" class="px-6 py-12 text-center">
              <p class="text-[14px] font-medium text-ink">No results for “{{ query }}”</p>
              <p class="mt-1 text-[13px] text-muted">Try a name, company, city, BL-2026-0042, Q-2026-0003 or P-2026-0001.</p>
            </div>
            <p v-if="loadingLeads && !leadIndex.length" class="px-3 py-2 text-[12px] text-faint">Loading leads…</p>
          </div>

          <div class="flex items-center gap-4 border-t border-line bg-subtle/50 px-4 py-2.5 text-[11.5px] text-muted">
            <span class="flex items-center gap-1.5"><kbd class="kbd">↑</kbd><kbd class="kbd">↓</kbd> navigate</span>
            <span class="flex items-center gap-1.5"><kbd class="kbd"><CornerDownLeft class="h-3 w-3" /></kbd> open</span>
            <span class="ml-auto hidden items-center gap-1.5 sm:flex">Shortcuts: <kbd class="kbd">N</kbd> new lead <kbd class="kbd">G</kbd><kbd class="kbd">L</kbd> leads</span>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
