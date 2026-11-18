<script setup>
import { computed, ref } from 'vue'
import { useElementSize } from '@vueuse/core'
import { COMPANY as C } from '@/lib/company'
import { date, money } from '@/lib/format'

/**
 * Live A4 preview that mirrors resources/views/pdf/quote.blade.php.
 * Rendered at 794 px (A4 @ 96 dpi) and scaled to the container width.
 */
const props = defineProps({
  lead: { type: Object, default: null },
  items: { type: Array, default: () => [] },
  vatRate: { type: Number, default: 19 },
  validUntil: { type: String, default: null },
  notes: { type: String, default: '' },
  number: { type: String, default: null },
  status: { type: String, default: 'draft' },
  creator: { type: Object, default: null },
  issuedAt: { type: String, default: null },
})

const box = ref(null)
const { width } = useElementSize(box)
const scale = computed(() => (width.value ? Math.min(1, width.value / 794) : 0.6))

const lineTotal = (it) => Math.round((Number(it.quantity) || 0) * (Number(it.unit_price) || 0) * 100) / 100
const subtotal = computed(() => props.items.reduce((s, it) => s + lineTotal(it), 0))
const vat = computed(() => Math.round(subtotal.value * props.vatRate) / 100)
const qty = (v) => new Intl.NumberFormat('de-DE', { maximumFractionDigits: 2 }).format(Number(v) || 0)
const eur = (v) => money(v, { cents: true })
</script>

<template>
  <div ref="box" class="relative w-full" :style="{ height: `${1123 * scale}px` }">
    <div
      class="absolute left-0 top-0 origin-top-left overflow-hidden bg-white text-[#1F2937] shadow-[0_30px_60px_-20px_rgb(16_24_40/0.35),0_0_0_1px_rgb(16_24_40/0.06)]"
      :style="{ width: '794px', height: '1123px', transform: `scale(${scale})`, fontFamily: 'Inter, sans-serif' }"
    >
      <div class="h-[6px] bg-[#F59E0B]" />
      <div class="flex h-[calc(100%-6px)] flex-col px-[48px] pb-[28px] pt-[26px] text-[12.5px] leading-[1.45]">
        <!-- header -->
        <div class="flex items-start justify-between">
          <div class="flex items-center gap-3">
            <svg width="40" height="40" viewBox="0 0 32 32"><rect width="32" height="32" rx="8" fill="#0B1220" /><rect x="7.5" y="17" width="4.6" height="8" rx="1.3" fill="#F59E0B" fill-opacity=".5" /><rect x="13.7" y="12" width="4.6" height="13" rx="1.3" fill="#F59E0B" fill-opacity=".78" /><rect x="19.9" y="7" width="4.6" height="18" rx="1.3" fill="#F59E0B" /></svg>
            <div><p class="text-[18px] font-bold text-[#0B1220]">{{ C.name }}</p><p class="text-[10.5px] text-[#6B7280]">{{ C.tagline }}</p></div>
          </div>
          <p class="text-right text-[10.5px] leading-[1.6] text-[#6B7280]">{{ C.street }} · {{ C.postcode }} {{ C.city }}<br>{{ C.phone }} · {{ C.email }}<br>{{ C.website }}</p>
        </div>

        <!-- address + meta -->
        <div class="mt-9 flex justify-between gap-8">
          <div class="w-[58%]">
            <p class="border-b border-[#E5E7EB] pb-0.5 text-[9px] text-[#9CA3AF]">{{ C.name }} · {{ C.street }} · {{ C.postcode }} {{ C.city }}</p>
            <div class="mt-2 text-[13px] leading-[1.55]">
              <template v-if="lead">
                <p class="font-bold">{{ lead.company || lead.contact_name }}</p>
                <p v-if="lead.company">{{ lead.contact_name }}</p>
                <p v-if="lead.address?.street">{{ lead.address.street }}</p>
                <p>{{ [lead.address?.postcode, lead.address?.city].filter(Boolean).join(' ') }}</p>
              </template>
              <p v-else class="italic text-[#9CA3AF]">Choose a lead…</p>
            </div>
          </div>
          <table class="text-[11.5px]">
            <tr><td class="pr-4 text-[#6B7280]">Quote no.</td><td class="font-bold">{{ number || 'Q-····-····' }}</td></tr>
            <tr><td class="pr-4 text-[#6B7280]">Date</td><td>{{ date(issuedAt || new Date()) }}</td></tr>
            <tr><td class="pr-4 text-[#6B7280]">Valid until</td><td>{{ validUntil ? date(validUntil) : '—' }}</td></tr>
            <tr v-if="lead"><td class="pr-4 text-[#6B7280]">Your enquiry</td><td>{{ lead.reference }}</td></tr>
            <tr v-if="creator"><td class="pr-4 text-[#6B7280]">Contact</td><td>{{ creator.name }}</td></tr>
          </table>
        </div>

        <p class="mt-8 text-[26px] font-bold tracking-[-0.02em] text-[#0B1220]">
          Angebot / Quote {{ number || '' }}
          <span v-if="status === 'draft'" class="ml-2 rounded-full bg-[#FEF3C7] px-2.5 py-0.5 align-middle text-[10px] font-bold text-[#92400E]">DRAFT</span>
          <span v-else-if="status === 'accepted'" class="ml-2 rounded-full bg-[#D1FAE5] px-2.5 py-0.5 align-middle text-[10px] font-bold text-[#065F46]">ACCEPTED</span>
        </p>
        <p class="text-[#6B7280]">{{ lead?.project_type_label || 'Construction works' }}<template v-if="lead?.address?.city"> · {{ lead.address.street ? `${lead.address.street}, ` : '' }}{{ lead.address.city }}</template></p>
        <p class="mt-4">Guten Tag {{ lead?.contact_name || '…' }},<br>vielen Dank für Ihre Anfrage. Gerne bieten wir Ihnen die folgenden Leistungen an:</p>

        <!-- items -->
        <table class="mt-4 w-full text-[11.5px]">
          <thead><tr class="bg-[#0B1220] text-[9.5px] uppercase tracking-[0.06em] text-white">
            <th class="px-2.5 py-2 text-left">Pos.</th><th class="px-2.5 py-2 text-left">Description</th><th class="px-2.5 py-2 text-right">Qty</th><th class="px-2.5 py-2 text-left">Unit</th><th class="px-2.5 py-2 text-right">Unit price</th><th class="px-2.5 py-2 text-right">Total</th>
          </tr></thead>
          <tbody>
            <tr v-for="(it, i) in items" :key="i" class="border-b border-[#E5E7EB]" :class="i % 2 && 'bg-[#F9FAFB]'">
              <td class="px-2.5 py-2 text-[#6B7280]">{{ i + 1 }}</td>
              <td class="px-2.5 py-2">{{ it.description || '—' }}</td>
              <td class="whitespace-nowrap px-2.5 py-2 text-right tabular-nums">{{ qty(it.quantity) }}</td>
              <td class="px-2.5 py-2">{{ it.unit }}</td>
              <td class="whitespace-nowrap px-2.5 py-2 text-right tabular-nums">{{ eur(it.unit_price) }}</td>
              <td class="whitespace-nowrap px-2.5 py-2 text-right tabular-nums">{{ eur(lineTotal(it)) }}</td>
            </tr>
          </tbody>
        </table>

        <div class="ml-auto mt-3 w-[44%] text-[12px]">
          <div class="flex justify-between px-2.5 py-1"><span>Subtotal (net)</span><span class="tabular-nums">{{ eur(subtotal) }}</span></div>
          <div class="flex justify-between px-2.5 py-1"><span>VAT {{ vatRate }} %</span><span class="tabular-nums">{{ eur(vat) }}</span></div>
          <div class="flex justify-between border-t-2 border-[#F59E0B] bg-[#FEF3C7] px-2.5 py-2 text-[14px] font-bold text-[#0B1220]"><span>Total</span><span class="tabular-nums">{{ eur(subtotal + vat) }}</span></div>
        </div>

        <div class="mt-5 rounded-lg border border-[#E5E7EB] bg-[#F9FAFB] px-3.5 py-3 text-[11.5px]">
          <p class="mb-1 font-bold text-[#0B1220]">Validity &amp; terms</p>
          This quote is valid until <b>{{ validUntil ? date(validUntil) : '—' }}</b>. Payment plan: 30 % deposit on order, progress invoices by construction stage, final invoice on handover. Payable within {{ C.paymentTermsDays }} days without deduction.
          <p v-if="notes" class="mt-2 whitespace-pre-line">{{ notes }}</p>
        </div>

        <div class="mt-auto">
          <div class="mb-6 flex gap-[8%] text-[10px] text-[#6B7280]">
            <div class="w-[46%] border-t border-[#9CA3AF] pt-1.5">Place, date · {{ C.name }}<br>{{ creator?.name }}</div>
            <div class="w-[46%] border-t border-[#9CA3AF] pt-1.5">Place, date · Signature of client (order confirmation)<br>{{ lead?.contact_name }}</div>
          </div>
          <div class="grid grid-cols-4 gap-3 border-t border-[#E5E7EB] pt-2 text-[8.5px] leading-[1.5] text-[#6B7280]">
            <p><b>{{ C.name }}</b><br>{{ C.street }}<br>{{ C.postcode }} {{ C.city }}</p>
            <p>Geschäftsführung: {{ C.managingDirector }}<br>{{ C.register }}</p>
            <p>USt-IdNr.: {{ C.vatId }}<br>Steuer-Nr.: {{ C.taxNumber }}</p>
            <p>{{ C.bank }}<br>IBAN {{ C.iban }}<br>BIC {{ C.bic }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
