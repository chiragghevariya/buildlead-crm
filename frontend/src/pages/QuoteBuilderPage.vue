<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { refDebounced } from '@vueuse/core'
import { ArrowLeft, Check, CircleCheck, Download, FolderKanban, Plus, Search, Send, Trash2, X } from 'lucide-vue-next'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseCard from '@/components/ui/BaseCard.vue'
import Avatar from '@/components/ui/Avatar.vue'
import Modal from '@/components/ui/Modal.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import TemperatureBadge from '@/components/ui/TemperatureBadge.vue'
import QuotePreview from '@/components/quotes/QuotePreview.vue'
import { leads as leadsApi, quotes as quotesApi } from '@/api'
import { DEMO_MODE } from '@/api/http'
import { date, money } from '@/lib/format'
import { quoteTone } from '@/lib/status'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toasts'
import { useUiStore } from '@/stores/ui'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toasts = useToastStore()
const ui = useUiStore()

const UNITS = ['m²', 'h', 'pcs', 'flat']
/** Starting lines per project type (editable). */
const TEMPLATES = {
  new_build: [['Shell construction', 'm²', 1450], ['Building services (heating, plumbing, electrics)', 'flat', 85000], ['Project management & site supervision', 'h', 68]],
  renovation: [['Demolition & disposal', 'flat', 4800], ['Electrical installation (new)', 'm²', 85], ['Flooring & wall finishes', 'm²', 120]],
  extension: [['Foundation & structural works', 'flat', 18000], ['Extension shell (timber frame / masonry)', 'm²', 1650], ['Windows & glazing', 'pcs', 1250]],
  commercial_fit_out: [['Drywall partitions & acoustic ceilings', 'm²', 145], ['HVAC & ventilation', 'flat', 38000], ['Electrical, data & lighting', 'm²', 95]],
  roofing: [['Scaffolding', 'flat', 3200], ['Insulation & vapour barrier', 'm²', 68], ['New roof covering incl. flashing', 'm²', 95]],
  interior: [['Custom joinery', 'pcs', 4500], ['Flooring', 'm²', 89], ['Painting & finishes', 'm²', 28]],
  repair: [['Damage assessment', 'h', 68], ['Repair works', 'm²', 145], ['Finishing & cleanup', 'flat', 650]],
}

const quote = ref(null) // persisted quote (QuoteResource) once saved
const lead = ref(null)
const loading = ref(true)
const loadError = ref(null)
const saving = ref('')
const errors = ref({})
const form = reactive({ items: [], vat_rate: 19, valid_until: '', notes: '' })

const isNew = computed(() => route.name === 'quote-new')
const editable = computed(() => isNew.value || quote.value?.status === 'draft')
const status = computed(() => quote.value?.status ?? 'draft')

/**
 * Starter line items for the lead's project type. Quantities come from the lead (area etc.); unit prices
 * are then scaled so the net subtotal lands on the lead's estimated deal value (or ~90 % of its max budget).
 */
function templateFor(l) {
  const area = l?.area_sqm || 100
  const items = (TEMPLATES[l?.project_type] ?? [['Construction works', 'flat', 10000]]).map(([description, unit, unit_price]) => ({
    description, unit, unit_price, quantity: unit === 'm²' ? area : unit === 'h' ? 40 : unit === 'pcs' ? 4 : 1,
  }))
  const target = l?.estimated_value || (l?.budget?.max ? l.budget.max * 0.9 : null) || (l?.budget?.min ?? null)
  const base = items.reduce((sum, i) => sum + i.quantity * i.unit_price, 0)
  if (target && base) {
    const factor = target / base
    const step = (v) => (v >= 10000 ? 500 : v >= 1000 ? 50 : v >= 100 ? 5 : 0.5)
    items.forEach((i) => { const v = i.unit_price * factor; i.unit_price = Math.max(step(v), Math.round(v / step(v)) * step(v)) })
  }
  return items
}

async function init() {
  // Just saved a new quote (/quotes/new → /quotes/:id): the data is already here, so don't reload.
  if (!isNew.value && quote.value && String(quote.value.id) === String(route.params.id)) return
  loading.value = true
  errors.value = {}
  loadError.value = null
  try {
    if (isNew.value) {
      quote.value = null
      lead.value = route.query.lead ? await leadsApi.get(route.query.lead) : null
      Object.assign(form, {
        items: lead.value ? templateFor(lead.value) : [{ description: '', quantity: 1, unit: 'flat', unit_price: 0 }],
        vat_rate: 19,
        valid_until: new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10),
        notes: 'Alle Preise netto zzgl. MwSt. Angebot gültig 30 Tage. Unvorhergesehene statische Arbeiten werden nach Aufwand abgerechnet.',
      })
    } else {
      quote.value = await quotesApi.get(route.params.id)
      lead.value = await leadsApi.get(quote.value.lead.id).catch(() => ({ ...quote.value.lead, address: {} }))
      Object.assign(form, {
        items: quote.value.items.map(({ description, quantity, unit, unit_price }) => ({ description, quantity, unit, unit_price })),
        vat_rate: quote.value.vat_rate, valid_until: quote.value.valid_until, notes: quote.value.notes ?? '',
      })
    }
  } catch (e) {
    loadError.value = [403, 404].includes(e.status) ? 'This quote does not exist or belongs to another sales rep.' : e.message
    toasts.error('Could not load quote', e.message)
  } finally {
    loading.value = false
  }
}
watch(() => route.fullPath, init, { immediate: true })

// ---- lead picker ------------------------------------------------------------------
const pickerQuery = ref('')
const pickerResults = ref([])
watch(refDebounced(pickerQuery, 200), async (q) => {
  if (!q.trim()) { pickerResults.value = []; return }
  pickerResults.value = (await leadsApi.list({ search: q, per_page: 8, status: 'new,contacted,site_visit,quote_sent,negotiation', sort: '-score' })).data
})
async function pickLead(l) {
  lead.value = await leadsApi.get(l.id)
  pickerQuery.value = ''
  pickerResults.value = []
  if (!form.items.some((it) => it.description)) form.items = templateFor(lead.value)
  router.replace({ query: { lead: l.id } })
}

// ---- lines & totals -------------------------------------------------------------------
const lineTotal = (it) => Math.round((Number(it.quantity) || 0) * (Number(it.unit_price) || 0) * 100) / 100
const subtotal = computed(() => form.items.reduce((s, it) => s + lineTotal(it), 0))
const vat = computed(() => Math.round(subtotal.value * form.vat_rate) / 100)
const budgetPct = computed(() => (lead.value?.budget?.max && subtotal.value ? Math.round((subtotal.value / lead.value.budget.max) * 100) : null))
const addLine = () => form.items.push({ description: '', quantity: 1, unit: 'flat', unit_price: 0 })
const err = (k) => errors.value[k]?.[0]

// ---- actions --------------------------------------------------------------------------
async function persist() {
  const payload = { items: form.items, vat_rate: form.vat_rate, valid_until: form.valid_until, notes: form.notes }
  quote.value = isNew.value
    ? await quotesApi.create({ ...payload, lead_id: lead.value.id })
    : await quotesApi.update(quote.value.id, payload)
  return quote.value
}

async function run(kind, fn) {
  saving.value = kind
  errors.value = {}
  try {
    await fn()
    ui.leadsVersion++
  } catch (e) {
    errors.value = e.errors ?? {}
    toasts.error('Something went wrong', e.message)
  } finally {
    saving.value = ''
  }
}

const saveDraft = () => run('save', async () => {
  const q = await persist()
  toasts.success(`Quote ${q.number} saved`, `${money(q.total, { cents: true })} incl. VAT`)
  if (isNew.value) router.replace({ name: 'quote', params: { id: q.id } })
})

const send = () => run('send', async () => {
  const q = editable.value ? await persist() : quote.value
  quote.value = await quotesApi.send(q.id)
  toasts.success(`Quote ${q.number} sent`, `PDF emailed to ${lead.value.email}. Lead moved to “Quote sent”.`)
  if (isNew.value) router.replace({ name: 'quote', params: { id: q.id } })
})

const accept = () => run('accept', async () => {
  const res = await quotesApi.accept(quote.value.id)
  quote.value = res.quote.data ?? res.quote
  const project = res.project.data ?? res.project
  toasts.push({ type: 'success', title: 'Quote accepted 🎉', description: `Lead won · project ${project.code} created.`, ...(auth.canSeeAll ? { action: { label: 'Open project', run: () => router.push({ name: 'project', params: { id: project.id } }) } } : {}), duration: 8000 })
})

const declineOpen = ref(false)
const declineReason = ref('')
const decline = () => run('decline', async () => {
  quote.value = await quotesApi.decline(quote.value.id, declineReason.value || null)
  declineOpen.value = false
  toasts.info(`Quote ${quote.value.number} declined`)
})

async function pdf() {
  try { await quotesApi.openPdf(quote.value.id) } catch (e) { toasts.error('Could not open PDF', e.message) }
}
const expired = computed(() => quote.value?.is_expired)
</script>

<template>
  <div>
    <button class="mb-4 inline-flex items-center gap-1.5 text-[13px] font-medium text-muted hover:text-ink" @click="router.push({ name: 'quotes' })"><ArrowLeft class="h-4 w-4" /> Quotes</button>

    <div v-if="loading" class="grid gap-5 xl:grid-cols-2"><Skeleton class="h-[640px] !rounded-card" /><Skeleton class="h-[640px] !rounded-card" /></div>

    <div v-else-if="loadError" class="card"><ErrorState title="Could not load this quote" :message="loadError" @retry="init" /></div>
    <template v-else>
      <!-- Title bar -->
      <div class="mb-5 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <div class="flex items-center gap-2.5">
            <h1 class="text-[22px] font-semibold tracking-[-0.025em] text-ink">{{ quote ? `Quote ${quote.number}` : 'New quote' }}</h1>
            <BaseBadge v-if="quote" :tone="expired ? 'orange' : quoteTone(quote.status)" dot>{{ expired ? 'Expired' : quote.status_label }}</BaseBadge>
          </div>
          <p class="mt-1 text-[13.5px] text-muted">
            <template v-if="quote">Created {{ date(quote.created_at) }} by {{ quote.creator?.name ?? '—' }}<template v-if="quote.sent_at"> · sent {{ date(quote.sent_at) }}</template></template>
            <template v-else>Line items, VAT and the PDF are generated for you.</template>
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <BaseButton v-if="quote && !DEMO_MODE" variant="secondary" :icon="Download" @click="pdf">PDF</BaseButton>
          <template v-if="editable">
            <BaseButton variant="secondary" :loading="saving === 'save'" :disabled="!lead || !subtotal || !!saving" @click="saveDraft">Save draft</BaseButton>
            <BaseButton :icon="Send" :loading="saving === 'send'" :disabled="!lead || !subtotal || !lead.email || !!saving" :title="lead && !lead.email ? 'Lead has no email address' : ''" @click="send">Save & send</BaseButton>
          </template>
          <template v-else-if="quote.status === 'sent'">
            <BaseButton variant="secondary" :icon="X" :disabled="!!saving" @click="declineOpen = true">Declined</BaseButton>
            <BaseButton variant="secondary" :icon="Send" :loading="saving === 'send'" @click="send">Resend</BaseButton>
            <BaseButton :icon="CircleCheck" :loading="saving === 'accept'" :disabled="expired" :title="expired ? 'Expired — send an updated quote first' : ''" @click="accept">Mark accepted</BaseButton>
          </template>
          <BaseBadge v-else-if="quote.project && !auth.canSeeAll" tone="green">Project {{ quote.project.code }} created</BaseBadge>
          <BaseButton v-else-if="quote.project" variant="dark" :icon="FolderKanban" :to="{ name: 'project', params: { id: quote.project.id } }">Project {{ quote.project.code }}</BaseButton>
        </div>
      </div>

      <div class="grid grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
        <!-- Builder -->
        <div class="space-y-5">
          <BaseCard title="Customer">
            <div v-if="lead" class="flex items-center gap-3">
              <Avatar :name="lead.contact_name" size="lg" />
              <div class="min-w-0 flex-1">
                <p class="flex min-w-0 items-center gap-2 font-semibold text-ink"><span class="truncate">{{ lead.contact_name }}</span> <TemperatureBadge v-if="lead.temperature" :temperature="lead.temperature" size="sm" /></p>
                <p class="truncate text-[13px] text-muted">{{ lead.company || 'Private client' }} · {{ lead.reference }}<template v-if="lead.budget?.max"> · budget up to {{ money(lead.budget.max) }}</template></p>
              </div>
              <button v-if="isNew" class="text-[13px] font-medium text-muted hover:text-ink" @click="lead = null">Change</button>
              <RouterLink v-else :to="{ name: 'lead', params: { id: lead.id } }" class="text-[13px] font-medium text-brand-700 hover:underline dark:text-brand-400">Open lead</RouterLink>
            </div>
            <div v-else class="relative">
              <Search class="pointer-events-none absolute left-3 top-[11px] h-4 w-4 text-faint" />
              <input v-model="pickerQuery" class="field-input pl-9" placeholder="Search an open lead by name, company or BL-…" autofocus />
              <ul v-if="pickerResults.length" class="absolute inset-x-0 top-full z-20 mt-1 overflow-hidden rounded-xl border border-line bg-surface p-1 shadow-pop">
                <li v-for="l in pickerResults" :key="l.id">
                  <button class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left hover:bg-subtle" @click="pickLead(l)">
                    <Avatar :name="l.contact_name" size="sm" />
                    <span class="min-w-0 flex-1"><span class="block truncate text-[13.5px] font-medium text-ink">{{ l.contact_name }}<span class="font-normal text-muted"> · {{ l.company || 'Private client' }}</span></span><span class="block text-[12px] text-faint">{{ l.reference }} · {{ l.project_type_label }} · {{ l.status_label }}</span></span>
                    <TemperatureBadge :temperature="l.temperature" size="sm" />
                  </button>
                </li>
              </ul>
              <p v-else-if="pickerQuery" class="mt-2 text-[12.5px] text-muted">Searching open leads…</p>
            </div>
          </BaseCard>

          <BaseCard title="Line items" :padded="false">
            <div class="overflow-x-auto">
              <table class="w-full min-w-[560px] table-fixed text-[13.5px]">
                <thead class="border-y border-line bg-subtle/60 text-[11px] font-semibold uppercase tracking-[0.05em] text-muted">
                  <tr><th class="px-4 py-2 text-left">Description</th><th class="w-[72px] px-1 py-2 text-right">Qty</th><th class="w-[76px] px-1 py-2 text-left">Unit</th><th class="w-[104px] px-1 py-2 text-right">Unit price</th><th class="w-[118px] px-3 py-2 text-right">Total</th><th class="w-9" /></tr>
                </thead>
                <tbody class="divide-y divide-line">
                  <tr v-for="(it, i) in form.items" :key="i" class="group">
                    <td class="py-1.5 pl-3 pr-1"><input v-model="it.description" :disabled="!editable" class="field-input h-9 disabled:bg-transparent disabled:shadow-none" :class="err(`items.${i}.description`) && 'is-invalid'" placeholder="Describe the work…" /></td>
                    <td class="px-1 py-1.5"><input v-model.number="it.quantity" :disabled="!editable" type="number" min="0" step="0.5" class="field-input num h-9 px-2 text-right disabled:bg-transparent disabled:shadow-none" /></td>
                    <td class="px-1 py-1.5"><select v-model="it.unit" :disabled="!editable" class="field-input h-9 py-0 pl-2 disabled:bg-transparent disabled:shadow-none"><option v-for="u in UNITS" :key="u">{{ u }}</option></select></td>
                    <td class="px-1 py-1.5"><input v-model.number="it.unit_price" :disabled="!editable" type="number" min="0" step="0.01" class="field-input num h-9 px-2 text-right disabled:bg-transparent disabled:shadow-none" /></td>
                    <td class="num whitespace-nowrap px-3 py-1.5 text-right text-[13px] font-medium text-ink">{{ money(lineTotal(it), { cents: true }) }}</td>
                    <td class="pr-2"><button v-if="editable" type="button" class="rounded-lg p-1.5 text-faint opacity-60 hover:bg-red-50 hover:text-red-600 group-hover:opacity-100 dark:hover:bg-red-400/10" :disabled="form.items.length === 1" aria-label="Remove line" @click="form.items.splice(i, 1)"><Trash2 class="h-4 w-4" /></button></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="flex items-center justify-between border-t border-line px-4 py-2.5">
              <button v-if="editable" type="button" class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-[13px] font-medium text-brand-700 hover:bg-brand/10 dark:text-brand-400" @click="addLine"><Plus class="h-4 w-4" /> Add line</button>
              <span v-else class="text-[12.5px] text-muted">Only drafts can be edited.</span>
              <span v-if="budgetPct" class="text-[12px]" :class="budgetPct > 100 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'">{{ budgetPct }} % of the customer's max. budget</span>
            </div>
          </BaseCard>

          <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <BaseCard title="Terms">
              <div class="space-y-3">
                <label class="block"><span class="field-label">VAT</span><select v-model.number="form.vat_rate" :disabled="!editable" class="field-input"><option :value="19">19 % (standard)</option><option :value="7">7 %</option><option :value="0">0 % (§13b reverse charge)</option></select></label>
                <label class="block"><span class="field-label">Valid until</span><input v-model="form.valid_until" :disabled="!editable" type="date" class="field-input" :class="err('valid_until') && 'is-invalid'" /></label>
                <label class="block"><span class="field-label">Notes</span><textarea v-model="form.notes" :disabled="!editable" rows="3" class="field-input resize-none" /></label>
              </div>
            </BaseCard>
            <BaseCard title="Totals">
              <dl class="space-y-2.5 text-[14px]">
                <div class="flex justify-between"><dt class="text-muted">Subtotal (net)</dt><dd class="num font-medium text-ink">{{ money(subtotal, { cents: true }) }}</dd></div>
                <div class="flex justify-between"><dt class="text-muted">VAT {{ form.vat_rate }} %</dt><dd class="num text-ink">{{ money(vat, { cents: true }) }}</dd></div>
                <div class="flex justify-between border-t border-line pt-2.5"><dt class="font-semibold text-ink">Total</dt><dd class="num text-[18px] font-semibold tracking-tight text-ink">{{ money(subtotal + vat, { cents: true }) }}</dd></div>
              </dl>
              <p v-if="lead && !lead.email" class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-[12.5px] text-amber-800 dark:bg-amber-400/10 dark:text-amber-300">This lead has no email address — add one on the lead to send the PDF.</p>
              <p v-else-if="lead && editable" class="mt-4 flex items-start gap-2 text-[12.5px] text-muted"><Check class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-500" />“Save & send” emails the PDF to {{ lead.email }} and moves the lead to Quote sent.</p>
            </BaseCard>
          </div>
        </div>

        <!-- Preview -->
        <div class="xl:sticky xl:top-24">
          <p class="eyebrow mb-2 flex items-center justify-between">Preview <span class="normal-case tracking-normal text-faint">matches the PDF</span></p>
          <div class="rounded-card bg-subtle/70 p-4 ring-1 ring-inset ring-line sm:p-6">
            <QuotePreview :lead="lead" :items="form.items" :vat-rate="form.vat_rate" :valid-until="form.valid_until" :notes="form.notes" :number="quote?.number" :status="status" :creator="quote?.creator ?? auth.user" :issued-at="quote?.sent_at ?? quote?.created_at" />
          </div>
        </div>
      </div>
    </template>

    <Modal v-model:open="declineOpen" title="Customer declined" description="The lead stays open — you can send an updated quote." size="sm">
      <input v-model="declineReason" class="field-input" placeholder="Reason (optional), e.g. price too high" />
      <template #footer>
        <BaseButton variant="secondary" @click="declineOpen = false">Cancel</BaseButton>
        <BaseButton variant="dark" :loading="saving === 'decline'" @click="decline">Mark declined</BaseButton>
      </template>
    </Modal>
  </div>
</template>
