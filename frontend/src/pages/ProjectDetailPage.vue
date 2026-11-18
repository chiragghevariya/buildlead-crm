<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowLeft, CalendarCheck, CalendarDays, Check, ChevronDown, CircleCheck, CircleDot, Download, FileText, Flag, Hammer, MapPin, Receipt, Send, Trophy,
} from 'lucide-vue-next'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseCard from '@/components/ui/BaseCard.vue'
import Avatar from '@/components/ui/Avatar.vue'
import Dropdown from '@/components/ui/Dropdown.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import { invoices as invoicesApi, projects as projectsApi } from '@/api'
import { DEMO_MODE } from '@/api/http'
import { date, money, moneyShort } from '@/lib/format'
import { invoiceTone, projectTone } from '@/lib/status'
import { useToastStore } from '@/stores/toasts'

const route = useRoute()
const router = useRouter()
const toasts = useToastStore()

const project = ref(null)
const loading = ref(true)
const notFound = ref(false)
const loadError = ref(null)

async function load(silent = false) {
  if (!silent) loading.value = true
  notFound.value = false
  loadError.value = null
  try {
    project.value = await projectsApi.get(route.params.id)
    progress.value = project.value.progress
    spent.value = project.value.spent
    document.title = `${project.value.code} · BuildLead`
  } catch (e) {
    if ([403, 404].includes(e.status)) notFound.value = true
    else { if (!silent) loadError.value = e.message; toasts.error('Could not load project', e.message) }
  } finally {
    loading.value = false
  }
}
watch(() => route.params.id, () => load(), { immediate: true })

// ---- updates ---------------------------------------------------------------------
const STATUSES = [{ value: 'planning', label: 'Planning' }, { value: 'in_progress', label: 'In progress' }, { value: 'on_hold', label: 'On hold' }, { value: 'completed', label: 'Completed' }]
const progress = ref(0)
const spent = ref(0)
async function update(payload, message) {
  try {
    project.value = { ...project.value, ...(await projectsApi.update(project.value.id, payload)) }
    progress.value = project.value.progress
    spent.value = project.value.spent
    if (message) toasts.success(message)
  } catch (e) { toasts.error('Could not update project', e.message) }
}
const setStatus = (s, close) => { close(); update({ status: s.value }, `Project ${s.label.toLowerCase()}`) }

// ---- invoices --------------------------------------------------------------------
const inv = reactive({ type: 'deposit', mode: 'percent', percent: 30, amount: '', send: true })
const invErrors = ref({})
const creating = ref(false)
watch(() => inv.type, (t) => { if (t === 'final') inv.mode = 'remainder'; else if (inv.mode === 'remainder') inv.mode = 'percent'; if (t === 'deposit') inv.percent = 30 })
const previewAmount = computed(() => {
  if (!project.value) return 0
  if (inv.mode === 'remainder') return Math.max(0, project.value.budget - project.value.invoiced)
  return inv.mode === 'percent' ? (project.value.budget * (Number(inv.percent) || 0)) / 100 : Number(inv.amount) || 0
})
const overCap = computed(() => project.value && previewAmount.value > project.value.invoiceable_remaining + 0.001)
const hasFinal = computed(() => project.value?.invoices?.some((i) => i.type === 'final'))

async function createInvoice() {
  creating.value = true
  invErrors.value = {}
  try {
    const payload = { type: inv.type, send: inv.send }
    if (inv.mode === 'percent') payload.percent = Number(inv.percent)
    if (inv.mode === 'amount') payload.amount = Number(inv.amount)
    const created = await projectsApi.createInvoice(project.value.id, payload)
    toasts.success(`Invoice ${created.number} ${inv.send ? 'issued' : 'saved as draft'}`, `${money(created.total, { cents: true })} incl. VAT`)
    await load(true)
  } catch (e) {
    invErrors.value = e.errors ?? {}
    if (!Object.keys(invErrors.value).length) toasts.error('Could not create invoice', e.message)
  } finally { creating.value = false }
}
async function invoiceAction(i, kind) {
  try {
    if (kind === 'send') await invoicesApi.send(i.id)
    if (kind === 'paid') await invoicesApi.markPaid(i.id)
    if (kind === 'pdf') return await invoicesApi.openPdf(i.id)
    toasts.success(kind === 'paid' ? `${i.number} marked as paid` : `${i.number} issued`)
    await load(true)
  } catch (e) { toasts.error('Could not update invoice', e.message) }
}

// ---- timeline --------------------------------------------------------------------
const timeline = computed(() => {
  const p = project.value
  if (!p) return []
  const now = Date.now()
  const events = []
  if (p.quote?.accepted_at) events.push({ at: p.quote.accepted_at, icon: Trophy, title: `Quote ${p.quote.number} accepted`, body: `Contract value ${money(p.budget)}`, tone: 'green' })
  events.push({ at: p.created_at, icon: Hammer, title: 'Project created', body: p.manager ? `Managed by ${p.manager.name}` : null })
  if (p.start_date) events.push({ at: p.start_date, icon: Flag, title: 'Construction start', body: p.site_address })
  for (const i of p.invoices ?? []) {
    if (i.issued_at) events.push({ at: i.issued_at, icon: Receipt, title: `${i.number} issued`, body: `${i.type_label} · ${money(i.total, { cents: true })}`, tone: i.status === 'overdue' ? 'red' : null })
    if (i.paid_at) events.push({ at: i.paid_at, icon: CircleCheck, title: `${i.number} paid`, body: money(i.total, { cents: true }), tone: 'green' })
  }
  if (p.due_date) events.push({ at: p.due_date, icon: p.status === 'completed' ? CalendarCheck : CalendarDays, title: p.status === 'completed' ? 'Handover' : 'Planned completion', body: p.is_late ? 'Overdue' : null, tone: p.is_late ? 'red' : null })
  // Sort by calendar day, then natural milestone order (date-only values would otherwise sort as midnight).
  const day = (e) => String(e.at).slice(0, 10)
  return events
    .map((e, i) => ({ ...e, order: i, future: new Date(e.at).getTime() > now }))
    .sort((a, b) => day(a).localeCompare(day(b)) || a.order - b.order)
})
const toneClass = (e) => (e.future ? 'border border-dashed border-line bg-surface text-faint' : e.tone === 'green' ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-400/10 dark:text-emerald-400' : e.tone === 'red' ? 'bg-red-50 text-red-600 dark:bg-red-400/10 dark:text-red-400' : 'bg-subtle text-muted')
</script>

<template>
  <div>
    <button class="mb-4 inline-flex items-center gap-1.5 text-[13px] font-medium text-muted hover:text-ink" @click="router.push({ name: 'projects' })"><ArrowLeft class="h-4 w-4" /> Projects</button>

    <div v-if="loading" class="space-y-5"><Skeleton class="h-36 !rounded-card" /><div class="grid gap-5 lg:grid-cols-3"><Skeleton class="h-96 !rounded-card" /><Skeleton class="h-96 !rounded-card lg:col-span-2" /></div></div>
    <div v-else-if="notFound" class="card"><EmptyState illustration="board" title="Project not found"><BaseButton :to="{ name: 'projects' }">All projects</BaseButton></EmptyState></div>

    <div v-else-if="loadError" class="card"><ErrorState title="Could not load this project" :message="loadError" @retry="load()" /></div>
    <template v-else-if="project">
      <!-- Header -->
      <header class="card p-5 sm:p-6">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
          <div class="min-w-0">
            <p class="num text-[12.5px] font-medium text-faint">{{ project.code }}</p>
            <h1 class="mt-0.5 text-[22px] font-semibold tracking-[-0.025em] text-ink">{{ project.name }}</h1>
            <p class="mt-1 flex items-center gap-1.5 text-[13.5px] text-muted"><MapPin class="h-3.5 w-3.5" />{{ project.site_address || '—' }}</p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
              <Dropdown align="left" width="w-48">
                <template #trigger="{ toggle }">
                  <button class="focus-ring inline-flex h-8 items-center gap-2 rounded-full border border-line bg-surface pl-3 pr-2 text-[13px] font-medium text-ink shadow-sm hover:border-faint/60" @click="toggle">
                    <BaseBadge :tone="projectTone(project.status)" dot size="sm" class="!ring-0 !bg-transparent !px-0">{{ project.status_label }}</BaseBadge><ChevronDown class="h-3.5 w-3.5 text-faint" />
                  </button>
                </template>
                <template #default="{ close }">
                  <button v-for="s in STATUSES" :key="s.value" class="flex w-full items-center rounded-lg px-2.5 py-2 text-left text-[13.5px] text-ink hover:bg-subtle" @click="setStatus(s, close)"><span class="flex-1">{{ s.label }}</span><Check v-if="s.value === project.status" class="h-4 w-4 text-brand" /></button>
                </template>
              </Dropdown>
              <span v-if="project.manager" class="inline-flex h-8 items-center gap-2 rounded-full border border-line pl-1 pr-3 text-[13px] text-ink"><Avatar :name="project.manager.name" size="sm" />{{ project.manager.name }}</span>
              <RouterLink v-if="project.lead" :to="{ name: 'lead', params: { id: project.lead.id } }" class="inline-flex h-8 items-center gap-1.5 rounded-full border border-line px-3 text-[13px] text-muted hover:text-ink">{{ project.lead.company || project.lead.contact_name }} · {{ project.lead.reference }}</RouterLink>
              <RouterLink v-if="project.quote" :to="{ name: 'quote', params: { id: project.quote.id } }" class="inline-flex h-8 items-center gap-1.5 rounded-full border border-line px-3 text-[13px] text-muted hover:text-ink"><FileText class="h-3.5 w-3.5" />{{ project.quote.number }}</RouterLink>
            </div>
          </div>
          <div class="text-left lg:text-right">
            <p class="text-[12px] text-muted">Due</p>
            <p class="text-[15px] font-semibold" :class="project.is_late ? 'text-red-600 dark:text-red-400' : 'text-ink'">{{ date(project.due_date) }}</p>
            <p class="mt-1 text-[12px] text-muted">Start {{ date(project.start_date) }}</p>
          </div>
        </div>

        <div class="mt-6 grid gap-5 border-t border-line pt-5 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <p class="text-[12px] text-muted">Progress</p>
            <div class="mt-1 flex items-center gap-3">
              <input v-model.number="progress" v-range type="range" min="0" max="100" step="5" class="flex-1" aria-label="Progress" @change="update({ progress }, `Progress ${progress}%`)" />
              <span class="num w-11 text-right text-[15px] font-semibold text-ink">{{ progress }}%</span>
            </div>
          </div>
          <div>
            <p class="text-[12px] text-muted">Budget vs. spent</p>
            <p class="num mt-0.5 text-[15px] font-semibold text-ink">{{ moneyShort(project.spent) }} <span class="font-normal text-muted">/ {{ moneyShort(project.budget) }}</span></p>
            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-subtle"><div class="h-full rounded-full" :class="project.budget_used_pct > 100 ? 'bg-red-500' : project.budget_used_pct > project.progress + 10 ? 'bg-orange-400' : 'bg-navy dark:bg-slate-300'" :style="{ width: `${Math.min(100, project.budget_used_pct)}%` }" /></div>
            <label class="mt-2 flex items-center gap-2 text-[12px] text-muted">Spent € <input v-model.number="spent" type="number" min="0" step="100" class="field-input num h-7 w-28 px-2 py-0 text-[12.5px]" @change="update({ spent }, 'Spent updated')" /></label>
          </div>
          <div>
            <p class="text-[12px] text-muted">Invoiced</p>
            <p class="num mt-0.5 text-[15px] font-semibold text-ink">{{ moneyShort(project.invoiced) }} <span class="font-normal text-muted">· {{ Math.round(project.invoiced_pct) }}%</span></p>
            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-subtle"><div class="h-full rounded-full bg-gradient-to-r from-amber-300 to-brand" :style="{ width: `${Math.min(100, project.invoiced_pct / 1.1)}%` }" /></div>
            <p class="mt-2 text-[12px] text-muted">Paid {{ moneyShort(project.paid) }} net</p>
          </div>
          <div>
            <p class="text-[12px] text-muted">Outstanding (gross)</p>
            <p class="num mt-0.5 text-[15px] font-semibold" :class="project.outstanding ? 'text-ink' : 'text-muted'">{{ money(project.outstanding) }}</p>
            <p class="mt-2 text-[12px] text-muted">Can still invoice {{ money(project.invoiceable_remaining) }} <span class="text-faint">(110 % cap)</span></p>
          </div>
        </div>
      </header>

      <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
        <BaseCard title="Timeline" subtitle="Milestones and billing">
          <ol class="relative space-y-5 before:absolute before:bottom-2 before:left-[15px] before:top-2 before:w-px before:bg-line">
            <li v-for="(e, i) in timeline" :key="i" class="relative flex gap-3" :class="e.future && 'opacity-70'">
              <span class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-4 ring-surface" :class="toneClass(e)"><component :is="e.icon ?? CircleDot" class="h-3.5 w-3.5" /></span>
              <div class="min-w-0 pt-0.5">
                <p class="text-[13.5px] font-medium text-ink">{{ e.title }} <span v-if="e.future" class="ml-1 text-[11px] font-normal text-faint">planned</span></p>
                <p v-if="e.body" class="truncate text-[12.5px] text-muted">{{ e.body }}</p>
                <p class="mt-0.5 text-[11.5px] text-faint">{{ date(e.at) }}</p>
              </div>
            </li>
          </ol>
        </BaseCard>

        <div class="space-y-5 lg:col-span-2">
          <BaseCard title="Invoices" :subtitle="`${project.invoices.length} ${project.invoices.length === 1 ? 'invoice' : 'invoices'} · ${money(project.invoiced)} net invoiced`" :padded="false">
            <div v-if="project.invoices.length" class="overflow-x-auto">
              <table class="w-full min-w-[540px] whitespace-nowrap text-[13.5px]">
                <thead class="border-y border-line bg-subtle/60 text-[11px] font-semibold uppercase tracking-[0.05em] text-muted"><tr>
                  <th class="px-5 py-2 text-left">Invoice</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-right">Total</th><th class="hidden px-3 py-2 text-left sm:table-cell">Due</th><th class="px-5 py-2 text-right">Actions</th>
                </tr></thead>
                <tbody class="divide-y divide-line">
                  <tr v-for="i in project.invoices" :key="i.id">
                    <td class="px-5 py-3"><p class="num font-medium text-ink">{{ i.number }}</p><p class="text-[12px] text-muted">{{ i.type_label }} · {{ date(i.issued_at) }}</p></td>
                    <td class="px-3 py-3"><BaseBadge :tone="invoiceTone(i.status)" dot>{{ i.status_label }}</BaseBadge></td>
                    <td class="num px-3 py-3 text-right font-semibold text-ink">{{ money(i.total, { cents: true }) }}</td>
                    <td class="num hidden px-3 py-3 sm:table-cell" :class="i.status === 'overdue' ? 'font-medium text-red-600 dark:text-red-400' : 'text-muted'">{{ date(i.due_at) }}</td>
                    <td class="px-5 py-3 text-right">
                      <div class="inline-flex items-center gap-1">
                        <BaseButton v-if="i.status === 'draft'" size="sm" variant="secondary" :icon="Send" @click="invoiceAction(i, 'send')">Issue</BaseButton>
                        <BaseButton v-if="['sent', 'overdue'].includes(i.status)" size="sm" variant="secondary" :icon="Check" @click="invoiceAction(i, 'paid')">Mark paid</BaseButton>
                        <button v-if="!DEMO_MODE" class="focus-ring rounded-lg p-1.5 text-faint hover:bg-subtle hover:text-ink" title="PDF" aria-label="Download PDF" @click="invoiceAction(i, 'pdf')"><Download class="h-4 w-4" /></button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <EmptyState v-else compact illustration="inbox" title="No invoices yet" description="Start with a 30 % deposit invoice." />
          </BaseCard>

          <BaseCard title="New invoice" subtitle="Deposit, progress or final — capped at 110 % of the budget">
            <form class="grid gap-4 sm:grid-cols-[auto_1fr_auto]" @submit.prevent="createInvoice">
              <div class="flex rounded-xl border border-line bg-subtle/60 p-1">
                <button v-for="t in [{ v: 'deposit', l: 'Deposit' }, { v: 'progress', l: 'Progress' }, { v: 'final', l: 'Final' }]" :key="t.v" type="button" :disabled="t.v === 'final' && hasFinal" class="rounded-lg px-3 py-1.5 text-[13px] font-medium transition disabled:opacity-40" :class="inv.type === t.v ? 'bg-surface text-ink shadow-card ring-1 ring-line' : 'text-muted hover:text-ink'" @click="inv.type = t.v">{{ t.l }}</button>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <template v-if="inv.type !== 'final'">
                  <select v-model="inv.mode" class="field-input h-9 w-auto py-0"><option value="percent">% of budget</option><option value="amount">Amount (net)</option></select>
                  <div v-if="inv.mode === 'percent'" class="relative w-28"><input v-model.number="inv.percent" type="number" min="1" max="110" class="field-input num h-9 pr-8 text-right" /><span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-faint">%</span></div>
                  <div v-else class="relative w-36"><span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-faint">€</span><input v-model.number="inv.amount" type="number" min="1" step="0.01" class="field-input num h-9 pl-7 text-right" /></div>
                </template>
                <span v-else class="text-[13px] text-muted">Remaining contract value</span>
                <span class="num text-[13px] text-muted">= <span class="font-semibold text-ink">{{ money(previewAmount, { cents: true }) }}</span> net</span>
              </div>
              <div class="flex items-center gap-3">
                <label class="flex items-center gap-2 text-[13px] text-muted"><input v-model="inv.send" type="checkbox" class="form-checkbox h-4 w-4 rounded border-line text-brand focus:ring-brand/30" />Issue now</label>
                <BaseButton type="submit" :loading="creating" :disabled="!previewAmount || overCap">Create</BaseButton>
              </div>
            </form>
            <p v-if="overCap" class="field-error mt-3">Above the 110 % budget cap — at most {{ money(project.invoiceable_remaining, { cents: true }) }} can still be invoiced.</p>
            <p v-else-if="invErrors.amount || invErrors.type" class="field-error mt-3">{{ (invErrors.amount || invErrors.type)[0] }}</p>
          </BaseCard>
        </div>
      </div>
    </template>
  </div>
</template>
