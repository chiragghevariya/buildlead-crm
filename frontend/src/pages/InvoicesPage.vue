<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { AlertTriangle, Check, CheckCircle2, Clock, Download, FileText, Send } from 'lucide-vue-next'
import PageHeader from '@/components/ui/PageHeader.vue'
import DataTable from '@/components/ui/DataTable.vue'
import Tabs from '@/components/ui/Tabs.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import StatCard from '@/components/ui/StatCard.vue'
import { invoices as invoicesApi } from '@/api'
import { DEMO_MODE } from '@/api/http'
import { date, money, moneyShort } from '@/lib/format'
import { invoiceTone } from '@/lib/status'
import { useToastStore } from '@/stores/toasts'

const toasts = useToastStore()
const router = useRouter()
const rows = ref([])
const meta = ref(null)
const summary = ref({})
const loading = ref(true)
const loadError = ref(null)
const status = ref('')
const page = ref(1)
const busy = ref(null)

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const res = await invoicesApi.list({ status: status.value || undefined, page: page.value, per_page: 15 })
    rows.value = res.data; meta.value = res.meta; summary.value = res.summary
  } catch (e) { loadError.value = e.message; toasts.error('Could not load invoices', e.message) } finally { loading.value = false }
}
onMounted(load)
watch(status, () => { page.value = 1; load() })
watch(page, load)

const s = (k) => summary.value?.[k] ?? { count: 0, total: 0 }
const outstanding = computed(() => ({ count: s('sent').count + s('overdue').count, total: s('sent').total + s('overdue').total }))
const month = new Date().toLocaleString('en-GB', { month: 'long' })
const tabs = computed(() => [
  { value: '', label: 'All' },
  { value: 'overdue', label: 'Overdue', count: s('overdue').count },
  { value: 'sent', label: 'Sent', count: s('sent').count },
  { value: 'paid', label: 'Paid' },
  { value: 'draft', label: 'Draft', count: s('draft').count },
])
const columns = [
  { key: 'number', label: 'Invoice' },
  { key: 'project', label: 'Project', hideBelow: 'md' },
  { key: 'status', label: 'Status' },
  { key: 'total', label: 'Amount (gross)', align: 'right' },
  { key: 'due_at', label: 'Due', hideBelow: 'sm' },
  { key: 'actions', label: '', align: 'right' },
]

async function act(row, kind) {
  busy.value = `${row.id}-${kind}`
  try {
    if (kind === 'pdf') return await invoicesApi.openPdf(row.id)
    if (kind === 'send') await invoicesApi.send(row.id)
    if (kind === 'paid') await invoicesApi.markPaid(row.id)
    toasts.success(kind === 'paid' ? `${row.number} marked as paid` : `${row.number} issued`, kind === 'paid' ? money(row.total, { cents: true }) : 'Due in 14 days')
    await load()
  } catch (e) { toasts.error('Could not update invoice', e.message) } finally { busy.value = null }
}
</script>

<template>
  <div>
    <PageHeader title="Invoices" description="Deposit, progress and final invoices. Overdue status is checked every morning at 06:00." />

    <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3 sm:gap-4">
      <StatCard label="Outstanding" :value="moneyShort(outstanding.total)" :icon="Clock" tone="blue" :hint="`${outstanding.count} invoices awaiting payment`" :loading="loading && !meta" />
      <StatCard label="Overdue" :value="moneyShort(s('overdue').total)" :icon="AlertTriangle" tone="red" :hint="`${s('overdue').count} past due date`" :loading="loading && !meta" />
      <StatCard :label="`Paid in ${month}`" :value="moneyShort(s('paid_this_month').total)" :icon="CheckCircle2" tone="green" :hint="`${s('paid_this_month').count} invoices this month`" :loading="loading && !meta" />
    </div>

    <Tabs v-model="status" :tabs="tabs" class="mb-4 w-fit max-w-full" />
    <DataTable :columns="columns" :rows="rows" :loading="loading" :error="loadError" :meta="meta" @retry="load" empty-title="No invoices here" empty-description="Invoices are created from a project." empty-illustration="inbox" @page="(p) => (page = p)">
      <template #cell-number="{ row }">
        <div class="flex items-center gap-3">
          <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-subtle ring-1 ring-inset ring-line"><FileText class="h-4 w-4 text-muted" /></span>
          <div><p class="num whitespace-nowrap font-medium text-ink">{{ row.number }}</p><p class="text-[12px] text-muted">{{ row.type_label }} · {{ date(row.issued_at) }}</p></div>
        </div>
      </template>
      <template #cell-project="{ row }">
        <button class="max-w-[260px] text-left" @click="router.push({ name: 'project', params: { id: row.project.id } })">
          <p class="truncate text-ink hover:text-brand-700 hover:underline dark:hover:text-brand-400">{{ row.project?.name }}</p><p class="num text-[12px] text-muted">{{ row.project?.code }}</p>
        </button>
      </template>
      <template #cell-status="{ row }"><BaseBadge :tone="invoiceTone(row.status)" dot>{{ row.status_label }}</BaseBadge></template>
      <template #cell-total="{ row }"><span class="num whitespace-nowrap font-semibold text-ink">{{ money(row.total, { cents: true }) }}</span><p class="num text-[12px] text-muted">net {{ money(row.amount, { cents: true }) }}</p></template>
      <template #cell-due_at="{ row }">
        <span class="num whitespace-nowrap" :class="row.status === 'overdue' ? 'font-medium text-red-600 dark:text-red-400' : 'text-muted'">{{ date(row.due_at) }}</span>
        <p v-if="row.days_overdue" class="text-[12px] text-red-600 dark:text-red-400">{{ row.days_overdue }} days overdue</p>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex items-center justify-end gap-1">
          <BaseButton v-if="row.status === 'draft'" size="sm" variant="secondary" :icon="Send" :loading="busy === `${row.id}-send`" @click="act(row, 'send')">Issue</BaseButton>
          <BaseButton v-if="['sent', 'overdue'].includes(row.status)" size="sm" variant="secondary" :icon="Check" :loading="busy === `${row.id}-paid`" @click="act(row, 'paid')">Mark paid</BaseButton>
          <button v-if="!DEMO_MODE" class="focus-ring rounded-lg p-1.5 text-faint hover:bg-subtle hover:text-ink" title="Open PDF" aria-label="Open PDF" @click="act(row, 'pdf')"><Download class="h-4 w-4" /></button>
        </div>
      </template>
    </DataTable>
  </div>
</template>
