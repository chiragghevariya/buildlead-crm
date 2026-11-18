<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { Pencil, X } from 'lucide-vue-next'
import BaseCard from '@/components/ui/BaseCard.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import Avatar from '@/components/ui/Avatar.vue'
import Modal from '@/components/ui/Modal.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import { team as teamApi } from '@/api'
import { pct } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import { useMetaStore } from '@/stores/meta'
import { useToastStore } from '@/stores/toasts'

const auth = useAuthStore()
const meta = useMetaStore()
const toasts = useToastStore()
const members = ref([])
const loading = ref(true)
const loadError = ref(null)

async function load() {
  loading.value = true
  loadError.value = null
  try { members.value = await teamApi.list() } catch (e) { loadError.value = e.message; toasts.error('Could not load team', e.message) } finally { loading.value = false }
}
onMounted(() => { load(); meta.load() })

const reps = computed(() => members.value.filter((m) => m.role === 'sales'))
const staff = computed(() => members.value.filter((m) => m.role !== 'sales'))
const typeLabel = (v) => meta.labelOf('project_types', v)
const capacityTone = (p) => (p >= 90 ? 'bg-red-500' : p >= 70 ? 'bg-orange-400' : 'bg-emerald-500')

// ---- edit (admin) -----------------------------------------------------------------
const editing = ref(null)
const form = reactive({ regions: [], project_types: [], max_open_leads: 15, is_active: true })
const regionInput = ref('')
const saving = ref(false)
const errors = ref({})
function edit(m) {
  editing.value = m
  Object.assign(form, { regions: [...m.regions], project_types: [...m.project_types], max_open_leads: m.max_open_leads, is_active: m.is_active })
  regionInput.value = ''
  errors.value = {}
}
function addRegion() {
  const v = regionInput.value.trim()
  if (/^\d{2}$/.test(v) && !form.regions.includes(v)) form.regions.push(v)
  regionInput.value = ''
}
const toggleType = (t) => { form.project_types = form.project_types.includes(t) ? form.project_types.filter((x) => x !== t) : [...form.project_types, t] }
async function save() {
  saving.value = true
  errors.value = {}
  try {
    const updated = await teamApi.update(editing.value.id, { ...form, regions: [...form.regions].sort() })
    members.value = members.value.map((m) => (m.id === updated.id ? updated : m))
    toasts.success(`${updated.name} updated`, 'New leads are routed with these settings.')
    editing.value = null
  } catch (e) {
    errors.value = e.errors ?? {}
    toasts.error('Could not save', e.message)
  } finally { saving.value = false }
}
</script>

<template>
  <div class="max-w-5xl space-y-5 animate-fade-up">
    <BaseCard title="Sales team" subtitle="Auto-assignment uses each rep's regions, project types and capacity." :padded="false">
      <div v-if="loading" class="space-y-3 p-5"><Skeleton v-for="i in 6" :key="i" class="h-16" /></div>
      <ErrorState v-else-if="loadError" compact :message="loadError" @retry="load" />
      <ul v-else class="divide-y divide-line">
        <li v-for="m in reps" :key="m.id" class="grid gap-4 px-5 py-4 md:grid-cols-[minmax(0,1.3fr)_minmax(0,1.4fr)_minmax(0,1fr)_auto] md:items-center" :class="!m.is_active && 'opacity-55'">
          <div class="flex min-w-0 items-center gap-3">
            <Avatar :name="m.name" size="lg" />
            <div class="min-w-0">
              <p class="flex min-w-0 items-center gap-2 font-medium text-ink"><span class="truncate">{{ m.name }}</span> <BaseBadge v-if="!m.is_active" size="sm">Inactive</BaseBadge></p>
              <p class="truncate text-[12.5px] text-muted">{{ m.email }}</p>
            </div>
          </div>
          <div class="min-w-0 space-y-1.5">
            <div class="flex flex-wrap items-center gap-1"><span class="mr-1 text-[11.5px] text-faint">Regions</span><span v-for="r in m.regions" :key="r" class="num rounded-md bg-navy px-1.5 py-0.5 text-[11.5px] font-semibold text-white dark:bg-white/10">{{ r }}…</span><span v-if="!m.regions.length" class="text-[12px] text-faint">—</span></div>
            <div class="flex flex-wrap gap-1"><BaseBadge v-for="t in m.project_types" :key="t" size="sm" tone="amber">{{ typeLabel(t) }}</BaseBadge></div>
          </div>
          <div>
            <div class="mb-1 flex justify-between text-[12px]"><span class="text-muted">Capacity</span><span class="num font-medium text-ink">{{ m.open_leads }} / {{ m.max_open_leads }}</span></div>
            <div class="h-2 overflow-hidden rounded-full bg-subtle"><div class="h-full rounded-full transition-all" :class="capacityTone(m.capacity_pct)" :style="{ width: `${Math.min(100, m.capacity_pct ?? 0)}%` }" /></div>
            <p class="num mt-1 text-[11.5px] text-faint">{{ m.won }} won · {{ m.win_rate === null ? 'no closes' : `${pct(m.win_rate)} win rate` }}<template v-if="m.hot_open"> · <span class="text-orange-500">{{ m.hot_open }} hot</span></template></p>
          </div>
          <BaseButton v-if="auth.isAdmin" variant="ghost" size="sm" :icon="Pencil" @click="edit(m)">Edit</BaseButton>
        </li>
      </ul>
    </BaseCard>

    <BaseCard title="Admins & managers" :padded="false">
      <ul class="divide-y divide-line">
        <li v-for="m in staff" :key="m.id" class="flex items-center gap-3 px-5 py-3">
          <Avatar :name="m.name" /><div class="min-w-0 flex-1"><p class="truncate text-[13.5px] font-medium text-ink">{{ m.name }}</p><p class="truncate text-[12px] text-muted">{{ m.email }}</p></div>
          <BaseBadge :tone="m.role === 'admin' ? 'navy' : 'blue'">{{ m.role_label }}</BaseBadge>
        </li>
      </ul>
    </BaseCard>

    <Modal :open="!!editing" :title="editing ? `Edit ${editing.name}` : ''" description="Changes apply to the next auto-assigned leads." @update:open="(v) => !v && (editing = null)">
      <div class="space-y-5">
        <div>
          <p class="field-label">Regions <span class="font-normal text-faint">(2-digit postcode prefixes)</span></p>
          <div class="flex flex-wrap items-center gap-1.5 rounded-[10px] border border-line px-2 py-1.5 focus-within:border-brand focus-within:ring-4 focus-within:ring-brand/15">
            <span v-for="r in form.regions" :key="r" class="num inline-flex items-center gap-1 rounded-md bg-navy px-1.5 py-0.5 text-[12px] font-semibold text-white dark:bg-white/15">{{ r }}<button type="button" class="opacity-70 hover:opacity-100" @click="form.regions = form.regions.filter((x) => x !== r)"><X class="h-3 w-3" /></button></span>
            <input v-model="regionInput" inputmode="numeric" maxlength="2" class="num w-24 border-0 bg-transparent p-1 text-[14px] focus:outline-none focus:ring-0" placeholder="e.g. 13 ↵" @keydown.enter.prevent="addRegion" @keydown.,.prevent="addRegion" @blur="addRegion" />
          </div>
          <p v-if="errors.regions" class="field-error">{{ errors.regions[0] }}</p>
        </div>
        <div>
          <p class="field-label">Project types</p>
          <div class="flex flex-wrap gap-2">
            <button v-for="t in meta.projectTypes" :key="t.value" type="button" class="rounded-full border px-3 py-1 text-[12.5px] font-medium transition" :class="form.project_types.includes(t.value) ? 'border-brand bg-brand/10 text-ink' : 'border-line text-muted hover:text-ink'" @click="toggleType(t.value)">{{ t.label }}</button>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <label class="block"><span class="field-label">Max. open leads</span><input v-model.number="form.max_open_leads" type="number" min="1" max="200" class="field-input num" /></label>
          <label class="flex items-end gap-3 pb-2 text-[13.5px] text-ink"><input v-model="form.is_active" type="checkbox" class="form-checkbox h-5 w-5 rounded border-line text-brand focus:ring-brand/30" />Active <span class="text-[12px] text-muted">(inactive reps get no leads and are signed out)</span></label>
        </div>
      </div>
      <template #footer>
        <BaseButton variant="secondary" @click="editing = null">Cancel</BaseButton>
        <BaseButton :loading="saving" @click="save">Save</BaseButton>
      </template>
    </Modal>
  </div>
</template>
