<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { FileText, Plus } from 'lucide-vue-next'
import { useRouter } from 'vue-router'
import BaseButton from '@/components/ui/BaseButton.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import DataTable from '@/components/ui/DataTable.vue'
import Tabs from '@/components/ui/Tabs.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import Avatar from '@/components/ui/Avatar.vue'
import { quotes as quotesApi } from '@/api'
import { date, money } from '@/lib/format'
import { quoteTone } from '@/lib/status'
import { useToastStore } from '@/stores/toasts'

const router = useRouter()
const toasts = useToastStore()
const rows = ref([])
const meta = ref(null)
const loading = ref(true)
const loadError = ref(null)
const status = ref('')
const page = ref(1)

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const res = await quotesApi.list({ status: status.value || undefined, page: page.value, per_page: 15 })
    rows.value = res.data
    meta.value = res.meta
  } catch (e) { loadError.value = e.message; toasts.error('Could not load quotes', e.message) } finally { loading.value = false }
}
onMounted(load)
watch(status, () => { page.value = 1; load() })
watch(page, load)

const tabs = [{ value: '', label: 'All' }, { value: 'draft', label: 'Draft' }, { value: 'sent', label: 'Sent' }, { value: 'accepted', label: 'Accepted' }, { value: 'declined', label: 'Declined' }]
const columns = [
  { key: 'number', label: 'Quote' },
  { key: 'customer', label: 'Customer' },
  { key: 'status', label: 'Status' },
  { key: 'total', label: 'Total (gross)', align: 'right' },
  { key: 'valid_until', label: 'Valid until', hideBelow: 'md' },
  { key: 'creator', label: 'Created by', hideBelow: 'lg' },
]
const openValue = computed(() => rows.value.filter((q) => q.status === 'sent').reduce((s, q) => s + q.subtotal, 0))
</script>

<template>
  <div>
    <PageHeader title="Quotes" description="Angebote sent to customers. Accepted quotes become projects automatically.">
      <template #actions><Tabs v-model="status" :tabs="tabs" /><BaseButton :icon="Plus" :to="{ name: 'quote-new' }">New quote</BaseButton></template>
    </PageHeader>
    <DataTable :columns="columns" :rows="rows" :loading="loading" :error="loadError" :meta="meta" clickable @retry="load" empty-title="No quotes yet" empty-description="Create a quote from a lead once you've done the site visit." empty-illustration="inbox" @page="(p) => (page = p)" @row-click="(q) => router.push({ name: 'quote', params: { id: q.id } })">
      <template #cell-number="{ row }">
        <div class="flex items-center gap-3">
          <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-subtle ring-1 ring-inset ring-line"><FileText class="h-4 w-4 text-muted" /></span>
          <div><p class="num font-medium text-ink">{{ row.number }}</p><p class="text-[12px] text-muted">{{ row.items_count }} items · {{ date(row.created_at) }}</p></div>
        </div>
      </template>
      <template #cell-customer="{ row }">
        <p class="truncate font-medium text-ink">{{ row.lead.company || row.lead.contact_name }}</p>
        <p class="truncate text-[12px] text-muted">{{ row.lead.reference }}</p>
      </template>
      <template #cell-status="{ row }">
        <BaseBadge :tone="row.is_expired ? 'orange' : quoteTone(row.status)" dot>{{ row.is_expired ? 'Expired' : row.status_label }}</BaseBadge>
        <span v-if="row.project" class="ml-1.5 text-[12px] text-muted">→ {{ row.project.code }}</span>
      </template>
      <template #cell-total="{ row }"><span class="num font-semibold text-ink">{{ money(row.total) }}</span><p class="num text-[12px] text-muted">net {{ money(row.subtotal) }}</p></template>
      <template #cell-valid_until="{ row }"><span class="num text-muted">{{ date(row.valid_until) }}</span></template>
      <template #cell-creator="{ row }"><span v-if="row.creator" class="flex items-center gap-2"><Avatar :name="row.creator.name" size="sm" /><span class="text-[13px] text-muted">{{ row.creator.name }}</span></span></template>
    </DataTable>
    <p v-if="openValue" class="mt-3 text-right text-[12.5px] text-muted">Open (sent) on this page: <span class="num font-semibold text-ink">{{ money(openValue) }}</span> net</p>
  </div>
</template>
