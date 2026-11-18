<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { refDebounced } from '@vueuse/core'
import { ArrowUpRight, Download, Flame, Mail, Phone, Plus, Search, Snowflake, ThermometerSun, UserRound, X } from 'lucide-vue-next'
import PageHeader from '@/components/ui/PageHeader.vue'
import DataTable from '@/components/ui/DataTable.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import FilterMenu from '@/components/ui/FilterMenu.vue'
import Dropdown from '@/components/ui/Dropdown.vue'
import Modal from '@/components/ui/Modal.vue'
import ScoreRing from '@/components/ui/ScoreRing.vue'
import TemperatureBadge from '@/components/ui/TemperatureBadge.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import Avatar from '@/components/ui/Avatar.vue'
import { leads as leadsApi } from '@/api'
import { budgetRangeShort, date, timeAgo } from '@/lib/format'
import { statusTone } from '@/lib/status'
import { useAuthStore } from '@/stores/auth'
import { useMetaStore } from '@/stores/meta'
import { useToastStore } from '@/stores/toasts'
import { useUiStore } from '@/stores/ui'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const meta = useMetaStore()
const ui = useUiStore()
const toasts = useToastStore()

// ---- Filters (kept in the URL so views are shareable) -------------------------
const q = route.query
const filters = reactive({
  temperature: q.temperature ?? '', status: q.status ?? '', source: q.source ?? '', owner: q.owner ?? '',
  search: q.search ?? '', sort: q.sort ?? '-created_at', page: Number(q.page ?? 1),
})
const search = ref(filters.search)
watch(refDebounced(search, 250), (v) => { filters.search = v; filters.page = 1 })
watch(() => [filters.temperature, filters.status, filters.source, filters.owner], () => { filters.page = 1 })

const rows = ref([])
const metaInfo = ref(null)
const summary = ref({ hot: 0, warm: 0, cold: 0, unscored: 0 })
const loading = ref(true)
const loadError = ref(null)
const seen = new Map() // id → row, so CSV export works across pages

let seq = 0
async function load() {
  const mine = ++seq
  loading.value = true
  loadError.value = null
  try {
    const params = Object.fromEntries(Object.entries({ ...filters, per_page: 15 }).filter(([, v]) => v !== '' && v !== null))
    const res = await leadsApi.list(params)
    if (mine !== seq) return
    rows.value = res.data
    metaInfo.value = res.meta
    summary.value = res.summary
    res.data.forEach((r) => seen.set(r.id, r))
  } catch (e) {
    if (mine === seq) loadError.value = e.message
    toasts.error('Could not load leads', e.message)
  } finally {
    if (mine === seq) loading.value = false
  }
}
watch(filters, () => {
  const query = Object.fromEntries(Object.entries(filters).filter(([k, v]) => v !== '' && !(k === 'page' && v === 1) && !(k === 'sort' && v === '-created_at')))
  router.replace({ query })
  load()
}, { deep: true })
watch(() => ui.leadsVersion, load)
watch(() => route.query.temperature, (t) => { if ((t ?? '') !== filters.temperature) filters.temperature = t ?? '' })
load()
meta.load()

const total = computed(() => summary.value.hot + summary.value.warm + summary.value.cold + (summary.value.unscored ?? 0))
const chips = computed(() => [
  { value: '', label: 'All', count: total.value },
  { value: 'hot', label: 'Hot', count: summary.value.hot, icon: Flame, active: 'bg-gradient-to-r from-red-500 to-orange-500 text-white ring-transparent shadow-[0_2px_10px_-3px_rgb(239_68_68/0.6)]' },
  { value: 'warm', label: 'Warm', count: summary.value.warm, icon: ThermometerSun, active: 'bg-amber-400 text-navy ring-transparent' },
  { value: 'cold', label: 'Cold', count: summary.value.cold, icon: Snowflake, active: 'bg-slate-600 text-white ring-transparent dark:bg-slate-500' },
])
const ownerOptions = computed(() => [{ value: 'me', label: 'Me' }, { value: 'unassigned', label: 'Unassigned' }, ...meta.salesReps.map((r) => ({ value: String(r.id), label: r.name }))])
const hasFilters = computed(() => filters.status || filters.source || filters.owner || filters.search || filters.temperature)
function clearFilters() {
  Object.assign(filters, { temperature: '', status: '', source: '', owner: '', search: '', page: 1 })
  search.value = ''
}

const columns = [
  { key: 'reference', label: 'Ref.', sortable: true, hideBelow: 'xl', width: '120px' },
  { key: 'contact_name', label: 'Contact', sortable: true },
  { key: 'project_type', label: 'Project', hideBelow: 'md' },
  { key: 'budget_max', label: 'Budget', sortable: true, align: 'right', hideBelow: 'lg' },
  { key: 'score', label: 'Score', sortable: true, hideBelow: 'sm' },
  { key: 'status', label: 'Status', hideBelow: 'md' },
  { key: 'owner', label: 'Owner', hideBelow: 'lg' },
  { key: 'created_at', label: 'Created', sortable: true, align: 'right', hideBelow: 'sm' },
]

// ---- Bulk actions ----------------------------------------------------------------------
const selected = ref([])
const bulkBusy = ref(false)
const lostPrompt = ref(false)
const lostReason = ref('')

async function bulk(payload, label) {
  bulkBusy.value = true
  try {
    const { updated } = await leadsApi.bulk({ ids: selected.value, ...payload })
    toasts.success(`${updated} ${updated === 1 ? 'lead' : 'leads'} ${label}`)
    selected.value = []
    ui.leadsVersion++
  } catch (e) {
    toasts.error('Bulk action failed', e.message)
  } finally {
    bulkBusy.value = false
  }
}
const assignTo = (rep, close) => { close(); bulk({ action: 'assign', assigned_to: rep.id }, `assigned to ${rep.name}`) }
function setStatus(s, close) {
  close()
  if (s.value === 'lost') { lostReason.value = ''; lostPrompt.value = true; return }
  bulk({ action: 'status', status: s.value }, `moved to ${s.label}`)
}
function confirmLost() {
  lostPrompt.value = false
  bulk({ action: 'status', status: 'lost', lost_reason: lostReason.value }, 'marked as lost')
}

function exportCsv() {
  const header = ['Reference', 'Contact', 'Company', 'Email', 'Phone', 'Project type', 'Postcode', 'City', 'Budget min', 'Budget max', 'Score', 'Temperature', 'Status', 'Owner', 'Created']
  const esc = (v) => (v === null || v === undefined ? '' : /[";\n]/.test(String(v)) ? `"${String(v).replace(/"/g, '""')}"` : String(v))
  const lines = selected.value.map((id) => seen.get(id)).filter(Boolean).map((l) => [
    l.reference, l.contact_name, l.company, l.email, l.phone, l.project_type_label, l.address.postcode, l.address.city,
    l.budget.min, l.budget.max, l.score, l.temperature, l.status_label, l.owner?.name, l.created_at?.slice(0, 10),
  ].map(esc).join(';'))
  // ";" + BOM so it opens cleanly in German Excel
  const blob = new Blob(['﻿' + [header.join(';'), ...lines].join('\r\n')], { type: 'text/csv;charset=utf-8' })
  const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: `buildlead-leads-${new Date().toISOString().slice(0, 10)}.csv` })
  a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
  toasts.success(`Exported ${lines.length} ${lines.length === 1 ? 'lead' : 'leads'} to CSV`)
}
const tel = (p) => `tel:${p.replace(/[^\d+]/g, '')}`
</script>

<template>
  <div>
    <PageHeader title="Leads">
      <template #description><span class="num">{{ total }}</span> leads · scored by rules + AI</template>
      <template #actions><BaseButton :icon="Plus" @click="ui.newLeadOpen = true">New lead</BaseButton></template>
    </PageHeader>

    <!-- Filter chips + filters -->
    <div class="mb-4 space-y-3">
      <div class="flex flex-wrap items-center gap-2">
        <button
          v-for="c in chips" :key="c.value" type="button"
          class="focus-ring inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-[13px] font-medium ring-1 ring-inset transition"
          :class="filters.temperature === c.value ? (c.active ?? 'bg-navy text-white ring-transparent dark:bg-white dark:text-navy') : 'bg-surface text-muted ring-line hover:text-ink hover:ring-faint/60'"
          @click="filters.temperature = c.value"
        >
          <component :is="c.icon" v-if="c.icon" class="h-3.5 w-3.5" />{{ c.label }}
          <span class="num text-[11.5px] font-semibold opacity-70">{{ c.count }}</span>
        </button>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <div class="relative min-w-[220px] flex-1 sm:max-w-xs">
          <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-faint" />
          <input v-model="search" type="search" class="field-input h-9 pl-9" placeholder="Search name, company, city, BL-…" aria-label="Search leads" />
        </div>
        <FilterMenu v-model="filters.status" label="Status" :options="meta.statuses" />
        <FilterMenu v-model="filters.source" label="Source" :options="meta.sources" />
        <FilterMenu v-if="auth.canSeeAll" v-model="filters.owner" label="Owner" :options="ownerOptions" />
        <BaseButton v-if="hasFilters" variant="ghost" size="sm" :icon="X" @click="clearFilters">Clear</BaseButton>
      </div>
    </div>

    <DataTable
      v-model:sort="filters.sort" v-model:selected="selected"
      :columns="columns" :rows="rows" :loading="loading" :error="loadError" :meta="metaInfo" clickable selectable
      @retry="load"
      empty-title="No leads match these filters"
      empty-description="Try another search or clear the filters. New enquiries from your website appear here automatically."
      @update:sort="filters.page = 1" @page="(p) => (filters.page = p)" @row-click="(row) => ui.openLead(row.id)"
    >
      <template #cell-reference="{ row }"><span class="num whitespace-nowrap text-[12.5px] font-medium text-muted">{{ row.reference }}</span></template>
      <template #cell-contact_name="{ row }">
        <div class="flex min-w-0 max-w-[calc(100vw-8rem)] items-center gap-3 sm:max-w-[280px]">
          <Avatar :name="row.contact_name" />
          <div class="min-w-0 flex-1">
            <p class="truncate font-medium text-ink group-hover:text-brand-700 dark:group-hover:text-brand-400">{{ row.contact_name }}</p>
            <p class="truncate text-[12px] text-muted">{{ row.company || 'Private client' }}<span class="text-faint xl:hidden"> · {{ row.reference }}</span></p>
          </div>
          <TemperatureBadge class="sm:hidden" :temperature="row.temperature" :score="row.score" size="sm" />
        </div>
      </template>
      <template #cell-project_type="{ row }">
        <p class="truncate text-ink">{{ row.project_type_label || '—' }}</p>
        <p class="truncate text-[12px] text-muted">{{ row.address.city || '—' }}</p>
      </template>
      <template #cell-budget_max="{ row }"><span class="num whitespace-nowrap text-ink">{{ budgetRangeShort(row.budget) }}</span></template>
      <template #cell-score="{ row }">
        <div class="flex items-center gap-2"><ScoreRing :score="row.score" :temperature="row.temperature" :size="32" /><TemperatureBadge :temperature="row.temperature" size="sm" /></div>
      </template>
      <template #cell-status="{ row }"><BaseBadge :tone="statusTone(row.status)" dot>{{ row.status_label }}</BaseBadge></template>
      <template #cell-owner="{ row }">
        <span v-if="row.owner" class="flex items-center gap-2" :title="row.owner.name"><Avatar :name="row.owner.name" size="sm" /><span class="hidden truncate text-[13px] text-muted 2xl:inline">{{ row.owner.name }}</span></span>
        <span v-else class="text-[13px] text-faint">—</span>
      </template>
      <template #cell-created_at="{ row }"><span class="num whitespace-nowrap text-[13px] text-muted" :title="date(row.created_at)">{{ timeAgo(row.created_at) }}</span></template>

      <template #row-actions="{ row }">
        <a v-if="row.phone" :href="tel(row.phone)" class="rounded-lg p-1.5 text-muted hover:bg-subtle hover:text-ink" title="Call" @click.stop><Phone class="h-4 w-4" /></a>
        <a v-if="row.email" :href="`mailto:${row.email}`" class="rounded-lg p-1.5 text-muted hover:bg-subtle hover:text-ink" title="Email" @click.stop><Mail class="h-4 w-4" /></a>
        <RouterLink :to="{ name: 'lead', params: { id: row.id } }" class="rounded-lg p-1.5 text-muted hover:bg-subtle hover:text-ink" title="Open full page" @click.stop><ArrowUpRight class="h-4 w-4" /></RouterLink>
      </template>

      <template #empty-actions>
        <BaseButton variant="secondary" @click="clearFilters">Clear filters</BaseButton>
        <BaseButton :icon="Plus" @click="ui.newLeadOpen = true">New lead</BaseButton>
      </template>
    </DataTable>

    <!-- Floating bulk action bar -->
    <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="translate-y-6 opacity-0" leave-active-class="transition duration-150 ease-in" leave-to-class="translate-y-6 opacity-0">
      <div v-if="selected.length" class="fixed inset-x-0 bottom-20 z-30 flex justify-center px-4 md:bottom-6 md:pl-[248px]">
        <div class="flex flex-wrap items-center gap-1 rounded-2xl bg-navy p-1.5 pl-4 text-white shadow-pop ring-1 ring-white/10 dark:bg-navy-700">
          <span class="num mr-2 text-[13px] font-medium">{{ selected.length }} selected</span>
          <Dropdown v-if="auth.canSeeAll" align="left" placement="top" width="w-56">
            <template #trigger="{ toggle }"><button class="inline-flex h-8 items-center gap-1.5 rounded-lg px-3 text-[13px] font-medium hover:bg-white/10" :disabled="bulkBusy" @click="toggle"><UserRound class="h-4 w-4" />Assign</button></template>
            <template #default="{ close }">
              <button v-for="r in meta.salesReps" :key="r.id" class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[13.5px] text-ink hover:bg-subtle" @click="assignTo(r, close)"><Avatar :name="r.name" size="sm" />{{ r.name }}</button>
            </template>
          </Dropdown>
          <Dropdown align="left" placement="top" width="w-52">
            <template #trigger="{ toggle }"><button class="inline-flex h-8 items-center gap-1.5 rounded-lg px-3 text-[13px] font-medium hover:bg-white/10" :disabled="bulkBusy" @click="toggle">Change status</button></template>
            <template #default="{ close }">
              <button v-for="s in meta.statuses" :key="s.value" class="flex w-full items-center rounded-lg px-2.5 py-2 text-left text-[13.5px] text-ink hover:bg-subtle" @click="setStatus(s, close)">{{ s.label }}</button>
            </template>
          </Dropdown>
          <button class="inline-flex h-8 items-center gap-1.5 rounded-lg px-3 text-[13px] font-medium hover:bg-white/10" @click="exportCsv"><Download class="h-4 w-4" />Export CSV</button>
          <button class="ml-1 rounded-lg p-1.5 text-slate-400 hover:bg-white/10 hover:text-white" aria-label="Clear selection" @click="selected = []"><X class="h-4 w-4" /></button>
        </div>
      </div>
    </Transition>

    <Modal v-model:open="lostPrompt" :title="`Mark ${selected.length} leads as lost`" description="The reason is saved on each lead." size="sm">
      <input v-model="lostReason" class="field-input" placeholder="e.g. Chose another contractor" @keydown.enter.prevent="lostReason && confirmLost()" />
      <template #footer>
        <BaseButton variant="secondary" @click="lostPrompt = false">Cancel</BaseButton>
        <BaseButton variant="dark" :disabled="!lostReason" @click="confirmLost">Mark as lost</BaseButton>
      </template>
    </Modal>
  </div>
</template>
