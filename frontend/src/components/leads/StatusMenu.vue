<script setup>
import { ref } from 'vue'
import { Check, ChevronDown } from 'lucide-vue-next'
import Dropdown from '@/components/ui/Dropdown.vue'
import Modal from '@/components/ui/Modal.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { useMetaStore } from '@/stores/meta'

/** Status pill that doubles as a dropdown. Lost asks for a reason first. */
const props = defineProps({
  status: { type: String, required: true },
  label: { type: String, default: null },
  align: { type: String, default: 'left' },
})
const emit = defineEmits(['change']) // (status, lostReason?)
const meta = useMetaStore()

const dots = { new: 'bg-sky-500', contacted: 'bg-violet-500', site_visit: 'bg-amber-500', quote_sent: 'bg-orange-500', negotiation: 'bg-rose-500', won: 'bg-emerald-500', lost: 'bg-slate-400' }
const asking = ref(false)
const reason = ref('')
const reasons = ['Chose another contractor', 'Budget too low for scope', 'Project postponed', 'No response after follow-ups', 'Price']

function pick(value, close) {
  close()
  if (value === props.status) return
  if (value === 'lost') { reason.value = ''; asking.value = true; return }
  emit('change', value)
}
function confirmLost() {
  asking.value = false
  emit('change', 'lost', reason.value)
}
</script>

<template>
  <Dropdown :align="align" width="w-52">
    <template #trigger="{ toggle }">
      <button type="button" class="focus-ring inline-flex h-8 items-center gap-2 rounded-full border border-line bg-surface pl-3 pr-2 text-[13px] font-medium text-ink shadow-sm transition hover:border-faint/60" @click.stop="toggle">
        <span class="h-2 w-2 rounded-full" :class="dots[status]" />{{ label ?? meta.labelOf('statuses', status) }}<ChevronDown class="h-3.5 w-3.5 text-faint" />
      </button>
    </template>
    <template #default="{ close }">
      <p class="eyebrow px-2.5 pb-1 pt-1.5">Move to stage</p>
      <button v-for="s in meta.statuses" :key="s.value" type="button" class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[13.5px] text-ink hover:bg-subtle" @click.stop="pick(s.value, close)">
        <span class="h-2 w-2 rounded-full" :class="dots[s.value]" /><span class="flex-1">{{ s.label }}</span>
        <Check v-if="s.value === status" class="h-4 w-4 text-brand" />
      </button>
    </template>
  </Dropdown>

  <Modal v-model:open="asking" title="Mark as lost" description="A short reason helps the team learn what to improve." size="sm">
    <div class="space-y-3">
      <div class="flex flex-wrap gap-2">
        <button v-for="r in reasons" :key="r" type="button" class="rounded-full border px-3 py-1 text-[12.5px] transition" :class="reason === r ? 'border-brand bg-brand/10 text-ink' : 'border-line text-muted hover:text-ink'" @click="reason = r">{{ r }}</button>
      </div>
      <input v-model="reason" class="field-input" placeholder="Or type a reason…" @keydown.enter.prevent="reason && confirmLost()" />
    </div>
    <template #footer>
      <BaseButton variant="secondary" @click="asking = false">Cancel</BaseButton>
      <BaseButton variant="dark" :disabled="!reason" @click="confirmLost">Mark as lost</BaseButton>
    </template>
  </Modal>
</template>
