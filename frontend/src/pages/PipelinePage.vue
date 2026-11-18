<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import draggable from 'vuedraggable'
import { Clock, Flame, Plus, XCircle } from 'lucide-vue-next'
import PageHeader from '@/components/ui/PageHeader.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import FilterMenu from '@/components/ui/FilterMenu.vue'
import ScoreRing from '@/components/ui/ScoreRing.vue'
import Avatar from '@/components/ui/Avatar.vue'
import Modal from '@/components/ui/Modal.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import { leads as leadsApi, pipeline as pipelineApi } from '@/api'
import { moneyShort, number } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import { useMetaStore } from '@/stores/meta'
import { useToastStore } from '@/stores/toasts'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
const auth = useAuthStore()
const meta = useMetaStore()
const toasts = useToastStore()

const columns = ref([])
const totals = ref(null)
const loading = ref(true)
const loadError = ref(null)
const temperature = ref('')
const owner = ref('')

async function load() {
  loading.value = !columns.value.length
  loadError.value = null
  try {
    const res = await pipelineApi.get({ temperature: temperature.value || undefined, owner: owner.value || undefined, limit: 100 })
    columns.value = res.columns
    totals.value = res.totals
  } catch (e) {
    if (!columns.value.length) loadError.value = e.message
    toasts.error('Could not load pipeline', e.message)
  } finally {
    loading.value = false
  }
}
onMounted(() => { load(); meta.load() })
watch([temperature, owner, () => ui.leadsVersion], load)

const accents = { new: 'bg-sky-500', contacted: 'bg-violet-500', site_visit: 'bg-amber-500', quote_sent: 'bg-orange-500', negotiation: 'bg-rose-500', won: 'bg-emerald-500', lost: 'bg-slate-400' }

function recount() {
  columns.value.forEach((c) => {
    c.count = c.leads.length
    c.value = c.leads.reduce((s, l) => s + (l.estimated_value || 0), 0)
  })
}

// Lost needs a reason → confirm in a modal; cancelling reloads the board.
const lostPrompt = ref(null)
const lostReason = ref('')
const savingLost = ref(false)

async function onChange(column, evt) {
  const moved = evt.added ?? evt.moved
  if (!moved) return
  const lead = moved.element
  recount()
  if (evt.added && column.status === 'lost') {
    lostPrompt.value = { lead, position: moved.newIndex }
    lostReason.value = ''
    return
  }
  await save(lead, column, moved.newIndex)
}

async function save(lead, column, position, lostReasonValue) {
  try {
    await leadsApi.setStatus(lead.id, { status: column.status, position, lost_reason: lostReasonValue })
    if (evtIsStatusChange(lead, column)) {
      lead.status = column.status
      toasts.success(`${lead.company || lead.contact_name} → ${column.label}`)
    }
  } catch (e) {
    toasts.error('Could not move lead', e.message)
    load()
  }
}
const evtIsStatusChange = (lead, column) => lead.status !== column.status

async function confirmLost() {
  savingLost.value = true
  const { lead, position } = lostPrompt.value
  await save(lead, lostColumn.value, position, lostReason.value || 'Not specified')
  savingLost.value = false
  lostPrompt.value = null
  load()
}
function cancelLost() {
  lostPrompt.value = null
  load()
}

const reasons = ['Chose another contractor', 'Budget too low for scope', 'Project postponed', 'No response after follow-ups', 'Price']
const isEmpty = computed(() => !loading.value && columns.value.every((c) => !c.leads.length))
// Spec: six working columns; Lost is a drop zone (with reason), not a column.
const board = computed(() => columns.value.filter((c) => c.status !== 'lost'))
const lostColumn = computed(() => columns.value.find((c) => c.status === 'lost'))
const lostDrop = ref([])
const dragging = ref(false)
/** New leads should be called within days; later stages naturally take weeks. */
const stageAgeTone = (l, status) => {
  const d = daysInStage(l)
  if (status === 'won') return 'text-faint'
  if (status === 'new') return d > 2 ? 'font-semibold text-red-600 dark:text-red-400' : 'text-faint'
  return d > 21 ? 'font-semibold text-red-600 dark:text-red-400' : d > 10 ? 'font-medium text-amber-600 dark:text-amber-400' : 'text-faint'
}
const daysInStage = (l) => Math.max(0, Math.floor((Date.now() - new Date(l.stage_entered_at ?? l.created_at).getTime()) / 86_400_000))
const ownerOptions = computed(() => meta.salesReps.map((r) => ({ value: String(r.id), label: r.name })))
const tempChips = [{ value: '', label: 'All' }, { value: 'hot', label: 'Hot' }, { value: 'warm', label: 'Warm' }, { value: 'cold', label: 'Cold' }]

async function onLostDrop(evt) {
  if (!evt.added) return
  const lead = evt.added.element
  lostDrop.value = []
  recount()
  lostPrompt.value = { lead, position: 0 }
  lostReason.value = ''
}
</script>

<template>
  <div>
    <PageHeader title="Pipeline">
      <template #description>
        <template v-if="totals"><span class="num font-medium text-ink">{{ number(totals.open_count) }}</span> open leads worth <span class="num font-medium text-ink">{{ moneyShort(totals.open_value) }}</span><template v-if="lostColumn"> · {{ lostColumn.count }} lost</template> · drag cards to move them</template>
        <template v-else>Drag cards between stages</template>
      </template>
      <template #actions>
        <div class="flex items-center gap-1 rounded-full border border-line bg-surface p-0.5">
          <button v-for="c in tempChips" :key="c.value" type="button" class="rounded-full px-3 py-1 text-[12.5px] font-medium transition" :class="temperature === c.value ? 'bg-navy text-white dark:bg-white dark:text-navy' : 'text-muted hover:text-ink'" @click="temperature = c.value">{{ c.label }}</button>
        </div>
        <FilterMenu v-if="auth.canSeeAll" v-model="owner" label="Owner" :options="ownerOptions" />
        <BaseButton :icon="Plus" @click="ui.newLeadOpen = true">New lead</BaseButton>
      </template>
    </PageHeader>

    <div v-if="loadError" class="card"><ErrorState title="Could not load the pipeline" :message="loadError" @retry="load" /></div>
    <div v-else class="-mx-4 select-none snap-x snap-mandatory overflow-x-auto px-4 pb-4 sm:snap-none sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
      <div class="flex min-h-[60vh] gap-3">
        <template v-if="loading">
          <div v-for="i in 7" :key="i" class="w-[calc(100vw-56px)] max-w-[288px] shrink-0 space-y-3 rounded-card bg-subtle/60 p-3 sm:w-[288px]">
            <Skeleton class="h-5 w-32" /><Skeleton v-for="j in 4 - (i % 3)" :key="j" class="h-28 w-full !rounded-xl" />
          </div>
        </template>

        <section
          v-for="col in board" v-else :key="col.status"
          class="flex w-[calc(100vw-56px)] max-w-[288px] shrink-0 snap-start scroll-ml-4 flex-col rounded-card sm:w-[288px] border border-line/60 bg-subtle/50"
          :class="col.is_closed && 'opacity-[0.92]'"
        >
          <header class="flex items-center gap-2 px-3.5 pb-2 pt-3">
            <span class="h-2 w-2 rounded-full" :class="accents[col.status]" />
            <h2 class="text-[13px] font-semibold text-ink">{{ col.label }}</h2>
            <span class="num rounded-md bg-surface px-1.5 text-[11px] font-semibold text-muted ring-1 ring-inset ring-line">{{ col.count }}</span>
            <span class="num ml-auto text-[12px] font-medium text-muted">{{ moneyShort(col.value) }}</span>
          </header>

          <draggable
            v-model="col.leads" item-key="id" group="pipeline" :animation="180"
            ghost-class="pipeline-ghost" drag-class="pipeline-drag" :delay="120" :delay-on-touch-only="true"
            :force-fallback="true" :fallback-on-body="true" :fallback-tolerance="4"
            class="flex min-h-[120px] flex-1 flex-col gap-2 px-2.5 pb-3"
            @change="(evt) => onChange(col, evt)"
            @start="dragging = true" @end="dragging = false"
          >
            <template #item="{ element: lead }">
              <article
                class="group relative cursor-grab overflow-hidden rounded-xl border border-line bg-surface p-3 shadow-card transition hover:-translate-y-px hover:shadow-lift active:cursor-grabbing"
                @click="ui.openLead(lead.id)"
              >
                <span v-if="lead.temperature === 'hot'" class="absolute inset-y-0 left-0 w-[3px] bg-gradient-to-b from-red-500 to-orange-400" />
                <div class="flex items-start gap-2.5">
                  <ScoreRing :score="lead.score" :temperature="lead.temperature" :size="34" :animate="false" />
                  <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-1 truncate text-[13.5px] font-semibold text-ink">{{ lead.contact_name }}<Flame v-if="lead.temperature === 'hot'" class="h-3.5 w-3.5 shrink-0 text-orange-500" /></p>
                    <p class="truncate text-[12px] text-muted">{{ lead.company || 'Private client' }}</p>
                  </div>
                </div>
                <p class="mt-2 truncate text-[12px] text-faint">{{ lead.project_type_label || 'Project' }} · {{ lead.city || '—' }}</p>
                <div class="mt-2 flex items-center justify-between gap-2 border-t border-line/70 pt-2">
                  <span class="num text-[13px] font-semibold text-ink">{{ moneyShort(lead.estimated_value) }}</span>
                  <span class="flex items-center gap-2">
                    <span class="num inline-flex items-center gap-1 text-[11.5px]" :class="stageAgeTone(lead, col.status)" :title="'Days in this stage'"><Clock class="h-3 w-3" />{{ daysInStage(lead) }}d</span>
                    <Avatar v-if="lead.owner" :name="lead.owner.name" size="xs" />
                  </span>
                </div>
              </article>
            </template>
            <template #footer>
              <p v-if="!col.leads.length" class="pointer-events-none m-auto rounded-xl border border-dashed border-line px-4 py-6 text-center text-[12px] text-faint">Drop leads here</p>
            </template>
          </draggable>
        </section>


      </div>
    </div>
    <!-- Lost: a floating drop target that appears while dragging (always reachable, even with the board scrolled). -->
    <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="translate-y-6 opacity-0" leave-active-class="transition duration-150" leave-to-class="translate-y-6 opacity-0">
      <div v-show="dragging" class="fixed inset-x-0 bottom-6 z-30 flex justify-center px-4 md:pl-[248px]">
        <draggable
          v-model="lostDrop" item-key="id" group="pipeline" ghost-class="lost-ghost" :force-fallback="true"
          class="flex h-16 w-full max-w-md items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-red-300 bg-red-50/95 text-[13.5px] font-semibold text-red-700 shadow-pop backdrop-blur dark:border-red-500/40 dark:bg-red-950/80 dark:text-red-300"
          @change="onLostDrop"
        >
          <template #item="{ element }"><div class="hidden">{{ element.id }}</div></template>
          <template #header><XCircle class="h-5 w-5" /> Drop here to mark as lost</template>
        </draggable>
      </div>
    </Transition>

    <p v-if="isEmpty" class="text-center text-[13px] text-muted">No leads match this filter.</p>

    <Modal :open="!!lostPrompt" title="Mark lead as lost" description="A short reason helps the team learn what to improve." size="sm" @update:open="(v) => !v && cancelLost()">
      <div class="space-y-3">
        <div class="flex flex-wrap gap-2">
          <button v-for="r in reasons" :key="r" type="button" class="rounded-full border px-3 py-1 text-[12.5px] transition" :class="lostReason === r ? 'border-brand bg-brand/10 text-ink' : 'border-line text-muted hover:border-faint hover:text-ink'" @click="lostReason = r">{{ r }}</button>
        </div>
        <input v-model="lostReason" class="field-input" placeholder="Or type a reason…" @keydown.enter.prevent="confirmLost" />
      </div>
      <template #footer>
        <BaseButton variant="secondary" @click="cancelLost">Cancel</BaseButton>
        <BaseButton variant="dark" :loading="savingLost" :disabled="!lostReason" @click="confirmLost">Mark as lost</BaseButton>
      </template>
    </Modal>
  </div>
</template>

<style scoped>
:deep(.pipeline-ghost) { opacity: .4; border-style: dashed; border-color: #F59E0B; box-shadow: none; }
:deep(.lost-ghost) { display: none; }
</style>
