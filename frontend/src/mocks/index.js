/**
 * Demo mode (VITE_DEMO_MODE=true): an axios adapter that answers the real API
 * routes from a snapshot of the seeded demo company (data/demo.json, exported
 * with `php artisan demo:export-mocks`). All dates are shifted so the snapshot
 * always looks like "today"; writes live in memory until reload.
 */
import { AxiosError } from 'axios'
import raw from './data/demo.json'
import { ruleScore, temperatureFor } from './scoring'

// ---------------------------------------------------------------------------
// Snapshot → live dataset
// ---------------------------------------------------------------------------
const DAY = 86_400_000
const ISO_DATETIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/
const shiftMs = Date.now() - new Date(raw.generated_at).getTime()
const shiftDays = Math.round(shiftMs / DAY)

function shiftDates(value) {
  if (Array.isArray(value)) return value.map(shiftDates)
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, shiftDates(v)]))
  }
  if (typeof value === 'string') {
    if (ISO_DATETIME.test(value)) return new Date(new Date(value).getTime() + shiftMs).toISOString()
    if (ISO_DATE.test(value)) return new Date(new Date(`${value}T12:00:00Z`).getTime() + shiftDays * DAY).toISOString().slice(0, 10)
  }
  return value
}

const db = shiftDates(structuredClone(raw))
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
db.dashboard.leads_per_week.forEach((w) => {
  const d = new Date(`${w.week_start}T12:00:00Z`)
  w.label = `${d.getUTCDate()} ${MONTHS[d.getUTCMonth()]}`
})

const labels = Object.fromEntries(['project_types', 'timelines', 'sources', 'statuses'].map((k) => [k, Object.fromEntries(db.meta[k].map((o) => [o.value, o.label]))]))
const OPEN = ['new', 'contacted', 'site_visit', 'quote_sent', 'negotiation']
const PASSWORD = 'password'

let notificationsRead = false
let currentUserId = null

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const userFromToken = (config) => {
  const id = Number(String(config.headers?.Authorization || '').replace('Bearer demo-', ''))
  return db.users.find((u) => u.id === id) || (currentUserId ? db.users.find((u) => u.id === currentUserId) : null)
}
const summary = (u) => u && { id: u.id, name: u.name, initials: u.initials, role: u.role }
const canSeeAll = (u) => ['admin', 'manager'].includes(u?.role)
const visibleLeads = (u) => (canSeeAll(u) ? db.leads : db.leads.filter((l) => l.owner?.id === u.id))
const list = (v) => (Array.isArray(v) ? v : String(v ?? '').split(',')).map((s) => s.trim()).filter(Boolean)
const card = (l) => ({
  id: l.id, reference: l.reference, contact_name: l.contact_name, company: l.company,
  project_type: l.project_type, project_type_label: l.project_type_label, city: l.address.city,
  score: l.score, temperature: l.temperature, status: l.status, estimated_value: l.estimated_value,
  ai_next_action: l.ai_next_action, phone: l.phone, email: l.email, owner: l.owner,
  stage_entered_at: l.activities.find((a) => a.type === 'status_change')?.created_at ?? l.created_at, position: l.position, created_at: l.created_at, updated_at: l.updated_at,
})
const compact = (l) => {
  const { score_breakdown, raw_payload, activities, quotes, project, ...rest } = l
  return rest
}

class HttpError extends Error {
  constructor(status, body) { super(body.message); this.status = status; this.body = body }
}
const fail = (status, message, errors) => { throw new HttpError(status, { message, ...(errors ? { errors } : {}) }) }

function paginate(items, query, path) {
  const perPage = Math.max(1, Math.min(100, Number(query.per_page) || 25))
  const total = items.length
  const lastPage = Math.max(1, Math.ceil(total / perPage))
  const page = Math.min(Math.max(1, Number(query.page) || 1), lastPage)
  return {
    data: items.slice((page - 1) * perPage, page * perPage),
    links: {},
    meta: { current_page: page, last_page: lastPage, per_page: perPage, total, from: total ? (page - 1) * perPage + 1 : null, to: Math.min(page * perPage, total), path },
  }
}

function filterLeads(user, q, except = []) {
  let items = visibleLeads(user)
  const temps = list(q.temperature)
  const statuses = list(q.status)
  const sources = list(q.source)
  if (temps.length && !except.includes('temperature')) items = items.filter((l) => temps.includes(l.temperature))
  if (statuses.length && !except.includes('status')) items = items.filter((l) => statuses.includes(l.status))
  if (sources.length) items = items.filter((l) => sources.includes(l.source))
  if (q.owner === 'me') items = items.filter((l) => l.owner?.id === user.id)
  else if (q.owner === 'unassigned') items = items.filter((l) => !l.owner)
  else if (q.owner) items = items.filter((l) => l.owner?.id === Number(q.owner))
  const s = String(q.search || q.q || '').trim().toLowerCase()
  if (s) items = items.filter((l) => [l.reference, l.contact_name, l.company, l.email, l.phone, l.address.city, l.address.postcode].some((v) => v && String(v).toLowerCase().includes(s)))
  return items
}

function sortLeads(items, sort = '-created_at') {
  const desc = sort.startsWith('-')
  const key = sort.replace(/^-/, '')
  const get = (l) => (key === 'budget_max' ? l.budget.max : l[key])
  return [...items].sort((a, b) => {
    const va = get(a); const vb = get(b)
    if (va === null || va === undefined) return 1
    if (vb === null || vb === undefined) return -1
    const cmp = typeof va === 'number' ? va - vb : String(va).localeCompare(String(vb))
    return desc ? -cmp : cmp
  })
}

function activity(type, title, extra = {}, user = null) {
  return { id: Date.now() + Math.random(), type, type_label: type.replace('_', ' '), title, body: null, meta: null, due_at: null, user: summary(user), created_at: new Date().toISOString(), ...extra }
}

function nextReference() {
  const year = new Date().getFullYear()
  const n = Math.max(0, ...db.leads.map((l) => Number(l.reference.split('-')[2]) || 0)) + 1
  return `BL-${year}-${String(n).padStart(4, '0')}`
}

/** Mirrors LeadAssignmentService: capacity → region/type match → hot: best win rate, else least busy. */
function pickOwner(lead, temperature) {
  const reps = db.users.filter((u) => u.role === 'sales' && u.is_active)
  const open = (u) => db.leads.filter((l) => l.owner?.id === u.id && OPEN.includes(l.status)).length
  const winRate = (u) => db.dashboard.leaderboard.find((r) => r.user.id === u.id)?.win_rate ?? 0
  const available = reps.filter((u) => open(u) < u.max_open_leads)
  const region = (u) => (u.regions || []).some((p) => (lead.postcode || '').startsWith(p))
  const type = (u) => (u.project_types || []).includes(lead.project_type)
  const pool = [available.filter((u) => region(u) && type(u)), available.filter(region), available.filter(type), available].find((p) => p.length)
  if (!pool) return null
  return temperature === 'hot'
    ? [...pool].sort((a, b) => winRate(b) - winRate(a) || open(a) - open(b))[0]
    : [...pool].sort((a, b) => open(a) - open(b) || winRate(b) - winRate(a))[0]
}

function findLead(user, id) {
  const lead = db.leads.find((l) => l.id === Number(id))
  if (!lead) fail(404, 'Not found.')
  if (!canSeeAll(user) && lead.owner?.id !== user.id) fail(403, 'This action is unauthorized.')
  return lead
}

function moveLead(lead, status, user, lostReason = null) {
  if (lead.status === status) return
  const from = lead.status
  lead.status = status
  lead.status_label = labels.statuses[status]
  lead.lost_reason = status === 'lost' ? lostReason : null
  lead.first_contacted_at ??= status !== 'new' ? new Date().toISOString() : null
  lead.updated_at = new Date().toISOString()
  lead.activities.unshift(activity('status_change', `${labels.statuses[from]} → ${labels.statuses[status]}`, { body: lostReason ? `Reason: ${lostReason}` : null, meta: { from, to: status } }, user))
}

function assignLead(lead, owner, user, bulk = false) {
  if (lead.owner?.id === owner.id) return
  lead.owner = summary(owner)
  lead.assigned_at = new Date().toISOString()
  lead.activities.unshift(activity('assignment', `Reassigned to ${owner.name}`, { body: `${bulk ? 'Bulk action' : 'Changed manually'} by ${user.name}.` }, user))
}

/** Inputs for the rule scorer from a lead resource. */
const scoringInput = (l) => ({ ...l, budget_min: l.budget.min, budget_max: l.budget.max, postcode: l.address.postcode, city: l.address.city })

function recalcProject(p) {
  p.invoiced = Math.round(p.invoices.reduce((s, i) => s + i.amount, 0) * 100) / 100
  p.invoiced_pct = p.budget ? Math.round((p.invoiced / p.budget) * 1000) / 10 : 0
  p.paid = Math.round(p.invoices.filter((i) => i.status === 'paid').reduce((s, i) => s + i.amount, 0) * 100) / 100
  p.outstanding = Math.round(p.invoices.filter((i) => ['sent', 'overdue'].includes(i.status)).reduce((s, i) => s + i.total, 0) * 100) / 100
  p.invoiceable_remaining = Math.max(0, Math.round((p.budget * 1.1 - p.invoiced) * 100) / 100)
}
function syncInvoice(inv) {
  const p = db.projects.find((x) => x.id === inv.project.id)
  if (!p) return
  const i = p.invoices.findIndex((x) => x.id === inv.id)
  if (i >= 0) p.invoices[i] = inv
  recalcProject(p)
}
function teamStats(t) {
  const mine = db.leads.filter((l) => l.owner?.id === t.id)
  const open = mine.filter((l) => OPEN.includes(l.status)).length
  const won = mine.filter((l) => l.status === 'won').length
  const closed = won + mine.filter((l) => l.status === 'lost').length
  return { open_leads: open, won, hot_open: mine.filter((l) => OPEN.includes(l.status) && l.temperature === 'hot').length, win_rate: closed ? Math.round((won / closed) * 1000) / 10 : null, capacity_pct: t.role === 'sales' ? Math.round((open / t.max_open_leads) * 100) : null }
}

let quoteSeq = 0
const money = (n) => `€ ${new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)}`

// ---------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------
/** Demo stand-in for the OpenAI reasons: the same short, factual bullets the real model returns. */
function aiReasons(input, factors) {
  const eur = (n) => new Intl.NumberFormat('de-DE').format(n)
  const out = []
  const lo = input.budget_min; const hi = input.budget_max
  if (hi || lo) out.push(`Budget € ${lo && hi ? `${eur(lo)}–${eur(hi)}` : eur(hi || lo)}${(hi || lo) >= 250000 ? ' — high-value project' : ''}`)
  if (factors.location?.fraction === 1) out.push(`Core service area (${[input.postcode, input.city].filter(Boolean).join(' ')})`)
  const when = { asap: 'Wants to start as soon as possible', within_3_months: 'Clear start within 3 months', within_6_months: 'Start planned within 6 months' }[input.timeline]
  if (when) out.push(when)
  if (input.source === 'referral' && out.length < 3) out.push('Referral — high-trust source')
  if ((input.description || '').length >= 40 && out.length < 3) out.push('Detailed brief with concrete scope')
  return out.length ? out.slice(0, 3) : ['Few details yet — qualify on the first call']
}

const routes = [
  ['post', /^\/auth\/login$/, ({ body }) => {
    const user = db.users.find((u) => u.email === String(body.email || '').toLowerCase().trim())
    if (!user || body.password !== PASSWORD) fail(422, 'These credentials do not match our records.', { email: ['These credentials do not match our records.'] })
    currentUserId = user.id
    return { token: `demo-${user.id}`, token_type: 'Bearer', user }
  }],
  ['get', /^\/auth\/me$/, ({ user }) => ({ data: user })],
  ['post', /^\/auth\/logout$/, () => { currentUserId = null; return { message: 'Logged out.' } }],
  ['get', /^\/meta$/, () => db.meta],
  ['get', /^\/dashboard$/, () => db.dashboard],

  ['get', /^\/notifications$/, () => {
    const hot = db.leads.filter((l) => l.temperature === 'hot' && OPEN.includes(l.status)).sort((a, b) => b.created_at.localeCompare(a.created_at)).slice(0, 6)
    const data = hot.map((l, i) => ({
      id: `demo-${l.id}`, type: 'hot_lead', read_at: notificationsRead || i > 2 ? new Date().toISOString() : null, created_at: l.created_at,
      data: { kind: 'hot_lead', lead_id: l.id, reference: l.reference, title: `Hot lead: ${l.company || l.contact_name}`, score: l.score, summary: l.ai_summary, assigned_to: l.owner?.name },
    }))
    return { data, unread_count: data.filter((n) => !n.read_at).length }
  }],
  ['post', /^\/notifications\/read$/, () => { notificationsRead = true; return { unread_count: 0 } }],

  ['get', /^\/leads$/, ({ user, query }) => {
    const items = sortLeads(filterLeads(user, query), query.sort)
    const base = filterLeads(user, query, ['temperature'])
    const count = (t) => base.filter((l) => l.temperature === t).length
    return { ...paginate(items.map(compact), query, '/api/leads'), summary: { hot: count('hot'), warm: count('warm'), cold: count('cold'), unscored: count(null) } }
  }],

  ['post', /^\/leads$/, ({ user, body }) => {
    const errors = {}
    if (!body.contact_name) errors.contact_name = ['The contact name field is required.']
    if (!body.email && !body.phone) errors.email = ['Please provide an email address or phone number.']
    if (!body.source) errors.source = ['The source field is required.']
    if (body.postcode && !/^\d{5}$/.test(body.postcode)) errors.postcode = ['Please enter a valid 5-digit postcode.']
    if (body.budget_min && body.budget_max && Number(body.budget_max) < Number(body.budget_min)) errors.budget_max = ['The maximum budget must be at least the minimum budget.']
    if (Object.keys(errors).length) fail(422, Object.values(errors)[0][0], errors)

    const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v))
    const input = { ...body, budget_min: num(body.budget_min), budget_max: num(body.budget_max), area_sqm: num(body.area_sqm) }
    const settings = db.scoring_settings
    const rules = ruleScore(input, settings, labels)
    // Simulated "head of sales" opinion around the rule score.
    const ai = Math.max(5, Math.min(98, rules.score + Math.round(Math.random() * 16 - 8)))
    const score = Math.round(ai * settings.ai_weight + rules.score * (1 - settings.ai_weight))
    const temperature = temperatureFor(score, settings.bands)
    const owner = user.role === 'sales' ? user : (body.assigned_to ? db.users.find((u) => u.id === Number(body.assigned_to)) : pickOwner(input, temperature))
    const now = new Date().toISOString()
    const typeLabel = labels.project_types[input.project_type] || 'Project'
    const nextAction = { hot: 'Call within the hour and offer a site visit this week.', warm: 'Call within 2 days and send 2–3 similar reference projects.', cold: 'Add to the nurture sequence and check back in 4 weeks.' }[temperature]

    const lead = {
      id: Math.max(...db.leads.map((l) => l.id)) + 1,
      reference: nextReference(),
      contact_name: input.contact_name, company: input.company || null, email: input.email || null, phone: input.phone || null,
      source: input.source, source_label: labels.sources[input.source],
      project_type: input.project_type || null, project_type_label: input.project_type ? typeLabel : null,
      description: input.description || null,
      budget: { min: input.budget_min, max: input.budget_max, midpoint: input.budget_min && input.budget_max ? (input.budget_min + input.budget_max) / 2 : input.budget_max ?? input.budget_min },
      timeline: input.timeline || null, timeline_label: input.timeline ? labels.timelines[input.timeline] : null,
      address: { street: input.street || null, postcode: input.postcode || null, city: input.city || null, full: [input.street, [input.postcode, input.city].filter(Boolean).join(' ')].filter(Boolean).join(', ') || null },
      area_sqm: input.area_sqm,
      score, rule_score: rules.score, ai_score: ai, temperature,
      ai_summary: `${input.company || 'Private client'}: ${typeLabel}${input.city ? ` in ${input.city}` : ''}${input.area_sqm ? `, ~${input.area_sqm} m²` : ''}.`,
      ai_next_action: nextAction, scored_at: now,
      status: 'new', status_label: 'New', owner: summary(owner), assigned_at: owner ? now : null, first_contacted_at: null,
      estimated_value: input.budget_max ?? input.budget_min ?? null, lost_reason: null, position: 0,
      consent_given: !!input.consent_given, consent_at: input.consent_given ? now : null, created_at: now, updated_at: now,
      score_breakdown: { method: 'hybrid', final: score, rule_score: rules.score, rules: rules.factors, ai: { score: ai, reasons: aiReasons(input, rules.factors), project_type: input.project_type }, ai_weight: settings.ai_weight, model: 'gpt-4o-mini' },
      raw_payload: null, quotes: [], project: null,
      activities: [],
    }
    lead.activities = [
      ...(temperature === 'hot' ? [activity('note', '🔥 Hot lead alert sent', { body: `Notified ${owner?.name ?? 'managers'}.` })] : []),
      ...(owner ? [activity('assignment', `Assigned to ${owner.name}`, { body: temperature === 'hot' ? 'Hot lead → best win rate.' : 'Least busy rep.' })] : []),
      activity('score', `Scored ${score} · ${temperature[0].toUpperCase()}${temperature.slice(1)}`, { body: lead.ai_summary }),
      activity('created', `Lead created by ${user.name}`, {}, user),
    ]
    db.leads.forEach((l) => { if (l.status === 'new') l.position += 1 })
    db.leads.push(lead)
    return { data: lead }
  }],

  ['get', /^\/leads\/(\d+)$/, ({ user, params }) => {
    const lead = db.leads.find((l) => l.id === Number(params[0]))
    if (!lead) fail(404, 'Not found.')
    if (!canSeeAll(user) && lead.owner?.id !== user.id) fail(403, 'This action is unauthorized.')
    return { data: lead }
  }],

  ['patch', /^\/leads\/(\d+)\/status$/, ({ user, params, body }) => {
    const lead = db.leads.find((l) => l.id === Number(params[0]))
    if (!lead) fail(404, 'Not found.')
    if (body.status === 'lost' && !body.lost_reason) fail(422, 'Please give a reason when marking a lead as lost.', { lost_reason: ['Please give a reason when marking a lead as lost.'] })
    const from = lead.status
    if (from !== body.status) {
      lead.status = body.status
      lead.status_label = labels.statuses[body.status]
      lead.lost_reason = body.status === 'lost' ? body.lost_reason : null
      lead.first_contacted_at ??= body.status !== 'new' ? new Date().toISOString() : null
      lead.activities.unshift(activity('status_change', `${labels.statuses[from]} → ${labels.statuses[body.status]}`, { body: lead.lost_reason ? `Reason: ${lead.lost_reason}` : null, meta: { from, to: body.status } }, user))
      lead.updated_at = new Date().toISOString()
    }
    // Renumber the target column with the lead at the requested slot.
    const column = db.leads.filter((l) => l.status === lead.status && l.id !== lead.id).sort((a, b) => a.position - b.position || b.score - a.score)
    column.splice(Math.min(Number(body.position ?? 0), column.length), 0, lead)
    column.forEach((l, i) => { l.position = i })
    return { data: lead }
  }],

  ['post', /^\/leads\/(\d+)\/activities$/, ({ user, params, body }) => {
    const lead = db.leads.find((l) => l.id === Number(params[0]))
    if (!lead) fail(404, 'Not found.')
    const a = activity(body.type, body.title, { body: body.body || null, due_at: body.due_at || null }, user)
    lead.activities.unshift(a)
    return { data: a }
  }],

  ['get', /^\/pipeline$/, ({ user, query }) => {
    const items = filterLeads(user, query, ['status'])
    const columns = db.meta.statuses.map(({ value, label }) => {
      const leads = items.filter((l) => l.status === value).sort((a, b) => a.position - b.position || (b.score ?? 0) - (a.score ?? 0))
      return { status: value, label, is_closed: ['won', 'lost'].includes(value), count: leads.length, value: leads.reduce((s, l) => s + (l.estimated_value || 0), 0), leads: leads.slice(0, Number(query.limit) || 50).map(card) }
    })
    const open = columns.filter((c) => !c.is_closed)
    return { columns, totals: { open_count: open.reduce((s, c) => s + c.count, 0), open_value: open.reduce((s, c) => s + c.value, 0) } }
  }],

  ['patch', /^\/leads\/(\d+)$/, ({ user, params, body }) => {
    const lead = findLead(user, params[0])
    if ('assigned_to' in body) {
      if (!canSeeAll(user)) fail(422, 'Only managers can assign leads.', { assigned_to: ['Only managers can assign leads.'] })
      const owner = db.users.find((u) => u.id === Number(body.assigned_to))
      if (owner) assignLead(lead, owner, user)
    }
    return { data: lead, meta: { rescoring: false } }
  }],

  ['post', /^\/leads\/bulk$/, ({ user, body }) => {
    const leads = (body.ids || []).map((id) => findLead(user, id))
    if (body.action === 'assign') {
      if (!canSeeAll(user)) fail(403, 'This action is unauthorized.')
      const owner = db.users.find((u) => u.id === Number(body.assigned_to))
      if (!owner) fail(422, 'Choose a sales rep.', { assigned_to: ['Choose a sales rep.'] })
      leads.forEach((l) => assignLead(l, owner, user, true))
    } else {
      if (body.status === 'lost' && !body.lost_reason) fail(422, 'Please give a reason when marking leads as lost.', { lost_reason: ['Please give a reason when marking leads as lost.'] })
      leads.forEach((l) => moveLead(l, body.status, user, body.lost_reason))
    }
    return { updated: leads.length }
  }],

  ['post', /^\/leads\/(\d+)\/rescore$/, ({ user, params }) => {
    const lead = findLead(user, params[0])
    const settings = db.scoring_settings
    const rules = ruleScore(scoringInput(lead), settings, labels)
    const ai = lead.ai_score ?? Math.max(5, Math.min(98, rules.score + Math.round(Math.random() * 10 - 5)))
    lead.rule_score = rules.score
    lead.score = Math.round(ai * settings.ai_weight + rules.score * (1 - settings.ai_weight))
    lead.temperature = temperatureFor(lead.score, settings.bands)
    lead.score_breakdown = { ...lead.score_breakdown, rules: rules.factors, rule_score: rules.score, final: lead.score }
    lead.scored_at = new Date().toISOString()
    lead.activities.unshift(activity('score', `Scored ${lead.score} · ${lead.temperature[0].toUpperCase()}${lead.temperature.slice(1)}`, { body: lead.ai_summary }))
    return { message: 'Re-scoring queued.', lead_id: lead.id }
  }],

  ['post', /^\/quotes$/, ({ user, body }) => {
    const lead = findLead(user, body.lead_id)
    const items = body.items || []
    if (!items.length) fail(422, 'A quote needs at least one line item.', { items: ['A quote needs at least one line item.'] })
    const lines = items.map((it, sort) => ({ id: Date.now() + sort, description: it.description, quantity: Number(it.quantity), unit: it.unit, unit_price: Number(it.unit_price), line_total: Math.round(Number(it.quantity) * Number(it.unit_price) * 100) / 100, sort }))
    const subtotal = Math.round(lines.reduce((s, l) => s + l.line_total, 0) * 100) / 100
    const vatRate = Number(body.vat_rate ?? 19)
    const vat = Math.round(subtotal * vatRate) / 100
    const number = `Q-${new Date().getFullYear()}-${String(db.quotes.length + 1 + ++quoteSeq).padStart(4, '0')}`
    const now = new Date().toISOString()
    const validUntil = body.valid_until || new Date(Date.now() + 30 * DAY).toISOString().slice(0, 10)
    const quote = {
      id: Math.max(...db.quotes.map((q) => q.id)) + 1, number, status: 'draft', status_label: 'Draft',
      subtotal, vat_rate: vatRate, vat_amount: vat, total: Math.round((subtotal + vat) * 100) / 100,
      valid_until: validUntil, is_expired: false, sent_at: null, accepted_at: null, notes: body.notes || null,
      lead: { id: lead.id, reference: lead.reference, contact_name: lead.contact_name, company: lead.company, email: lead.email, status: lead.status },
      creator: summary(user), items: lines, items_count: lines.length, project: null, pdf_url: null, created_at: now, updated_at: now,
    }
    db.quotes.push(quote)
    lead.quotes.unshift({ id: quote.id, number, status: 'draft', status_label: 'Draft', subtotal, vat_amount: vat, total: quote.total, valid_until: validUntil, sent_at: null, accepted_at: null, created_at: now })
    lead.activities.unshift(activity('quote', `Quote ${number} drafted`, { body: `Net ${money(subtotal)} · ${lines.length} line items` }, user))
    return { data: quote }
  }],

  ['post', /^\/quotes\/(\d+)\/send$/, ({ user, params, body }) => {
    const quote = db.quotes.find((q) => q.id === Number(params[0])) ?? fail(404, 'Not found.')
    const lead = findLead(user, quote.lead.id)
    const to = body.to || lead.email
    if (!to) fail(422, 'The lead has no email address. Add one or provide "to".', { to: ['The lead has no email address.'] })
    const now = new Date().toISOString()
    Object.assign(quote, { status: 'sent', status_label: 'Sent', sent_at: now })
    const summaryRow = lead.quotes.find((q) => q.id === quote.id)
    if (summaryRow) Object.assign(summaryRow, { status: 'sent', status_label: 'Sent', sent_at: now })
    lead.estimated_value = quote.subtotal
    if (['new', 'contacted', 'site_visit'].includes(lead.status)) moveLead(lead, 'quote_sent', user)
    lead.activities.unshift(activity('quote', `Quote ${quote.number} sent`, { body: `Emailed to ${to} · total ${money(quote.total)}` }, user))
    return { data: quote }
  }],

  ['patch', /^\/quotes\/(\d+)$/, ({ user, params, body }) => {
    const quote = db.quotes.find((q) => q.id === Number(params[0])) ?? fail(404, 'Not found.')
    findLead(user, quote.lead.id)
    if (quote.status !== 'draft') fail(422, 'Only draft quotes can be edited.', { status: ['Only draft quotes can be edited.'] })
    if (body.items) {
      quote.items = body.items.map((it, sort) => ({ id: Date.now() + sort, description: it.description, quantity: Number(it.quantity), unit: it.unit, unit_price: Number(it.unit_price), line_total: Math.round(Number(it.quantity) * Number(it.unit_price) * 100) / 100, sort }))
      quote.items_count = quote.items.length
    }
    Object.assign(quote, Object.fromEntries(['valid_until', 'notes'].filter((k) => k in body).map((k) => [k, body[k]])))
    if ('vat_rate' in body) quote.vat_rate = Number(body.vat_rate)
    quote.subtotal = Math.round(quote.items.reduce((s, i) => s + i.line_total, 0) * 100) / 100
    quote.vat_amount = Math.round(quote.subtotal * quote.vat_rate) / 100
    quote.total = Math.round((quote.subtotal + quote.vat_amount) * 100) / 100
    quote.updated_at = new Date().toISOString()
    const row = db.leads.find((l) => l.id === quote.lead.id)?.quotes.find((q) => q.id === quote.id)
    if (row) Object.assign(row, { subtotal: quote.subtotal, vat_amount: quote.vat_amount, total: quote.total, valid_until: quote.valid_until })
    return { data: quote }
  }],

  ['post', /^\/quotes\/(\d+)\/accept$/, ({ user, params }) => {
    const quote = db.quotes.find((q) => q.id === Number(params[0])) ?? fail(404, 'Not found.')
    const lead = findLead(user, quote.lead.id)
    if (quote.status !== 'sent') fail(422, 'Only sent quotes can be accepted.', { status: ['Only sent quotes can be accepted.'] })
    const now = new Date().toISOString()
    Object.assign(quote, { status: 'accepted', status_label: 'Accepted', accepted_at: now })
    db.quotes.filter((q) => q.lead.id === lead.id && q.id !== quote.id && ['draft', 'sent'].includes(q.status)).forEach((q) => Object.assign(q, { status: 'declined', status_label: 'Declined' }))
    lead.quotes.forEach((q) => { if (q.id === quote.id) Object.assign(q, { status: 'accepted', status_label: 'Accepted', accepted_at: now }); else if (['draft', 'sent'].includes(q.status)) Object.assign(q, { status: 'declined', status_label: 'Declined' }) })
    lead.estimated_value = quote.subtotal
    moveLead(lead, 'won', user)
    const manager = db.users.find((u) => u.role === 'manager')
    const project = {
      id: Math.max(...db.projects.map((p) => p.id)) + 1,
      code: `P-${new Date().getFullYear()}-${String(db.projects.length + 1).padStart(4, '0')}`,
      name: [lead.project_type_label || 'Project', lead.company || lead.contact_name.split(' ').pop(), lead.address.city].filter(Boolean).join(' · '),
      site_address: lead.address.full, status: 'planning', status_label: 'Planning', progress: 0,
      budget: quote.subtotal, spent: 0, budget_used_pct: 0, invoiced: 0, invoiced_pct: 0, paid: 0, outstanding: 0, invoiceable_remaining: Math.round(quote.subtotal * 110) / 100,
      start_date: null, due_date: null, is_late: false, manager: summary(manager),
      lead: { id: lead.id, reference: lead.reference, contact_name: lead.contact_name, company: lead.company },
      quote: { id: quote.id, number: quote.number, total: quote.total, accepted_at: now }, invoices: [], created_at: now, updated_at: now,
    }
    db.projects.push(project)
    quote.project = { id: project.id, code: project.code }
    lead.project = { id: project.id, code: project.code, name: project.name, status: 'planning', progress: 0 }
    lead.activities.unshift(activity('quote', `Quote ${quote.number} accepted 🎉`, { body: `Project ${project.code} created · budget ${money(project.budget)}` }, user))
    return { quote: { data: quote }, project: { data: project } }
  }],

  ['post', /^\/quotes\/(\d+)\/decline$/, ({ user, params, body }) => {
    const quote = db.quotes.find((q) => q.id === Number(params[0])) ?? fail(404, 'Not found.')
    const lead = findLead(user, quote.lead.id)
    Object.assign(quote, { status: 'declined', status_label: 'Declined' })
    const row = lead.quotes.find((q) => q.id === quote.id)
    if (row) Object.assign(row, { status: 'declined', status_label: 'Declined' })
    lead.activities.unshift(activity('quote', `Quote ${quote.number} declined`, { body: body.reason || null }, user))
    return { data: quote }
  }],

  ['patch', /^\/projects\/(\d+)$/, ({ user, params, body }) => {
    if (!canSeeAll(user)) fail(403, 'You do not have permission to perform this action.')
    const p = db.projects.find((x) => x.id === Number(params[0])) ?? fail(404, 'Not found.')
    const labelsP = { planning: 'Planning', in_progress: 'In progress', on_hold: 'On hold', completed: 'Completed' }
    if (body.status) Object.assign(p, { status: body.status, status_label: labelsP[body.status] })
    if (body.status === 'completed' && body.progress === undefined) p.progress = 100
    for (const k of ['progress', 'spent', 'start_date', 'due_date', 'name']) if (k in body) p[k] = ['progress', 'spent'].includes(k) ? Number(body[k]) : body[k]
    p.budget_used_pct = p.budget ? Math.round((p.spent / p.budget) * 100) : 0
    p.is_late = !!p.due_date && new Date(p.due_date) < new Date() && p.status !== 'completed'
    return { data: p }
  }],

  ['post', /^\/projects\/(\d+)\/invoices$/, ({ user, params, body }) => {
    if (!canSeeAll(user)) fail(403, 'You do not have permission to perform this action.')
    const p = db.projects.find((x) => x.id === Number(params[0])) ?? fail(404, 'Not found.')
    const invoiced = p.invoices.reduce((s, i) => s + i.amount, 0)
    if (body.type === 'final' && p.invoices.some((i) => i.type === 'final')) fail(422, 'This project already has a final invoice.', { type: ['This project already has a final invoice.'] })
    const amount = Math.round((body.amount ? Number(body.amount) : body.percent ? p.budget * Number(body.percent) / 100 : body.type === 'deposit' ? p.budget * 0.3 : p.budget - invoiced) * 100) / 100
    const cap = Math.round(p.budget * 110) / 100
    if (amount <= 0) fail(422, 'Nothing left to invoice: the budget is already fully invoiced.', { amount: ['Nothing left to invoice.'] })
    if (invoiced + amount > cap + 0.001) {
      const msg = `This invoice would bring the project to ${money(invoiced + amount)}, above 110% of its budget (${money(p.budget)}). At most ${money(Math.max(0, cap - invoiced))} can still be invoiced.`
      fail(422, msg, { amount: [msg] })
    }
    const vat = Math.round(amount * 19) / 100
    const now = new Date()
    const send = !!body.send
    const inv = {
      id: Math.max(...db.invoices.map((i) => i.id)) + 1,
      number: `INV-${now.getFullYear()}-${String(db.invoices.length + 1).padStart(4, '0')}`,
      type: body.type, type_label: { deposit: 'Deposit', progress: 'Progress', final: 'Final' }[body.type],
      status: send ? 'sent' : 'draft', status_label: send ? 'Sent' : 'Draft',
      amount, vat_amount: vat, total: Math.round((amount + vat) * 100) / 100,
      issued_at: send ? now.toISOString().slice(0, 10) : null, due_at: send ? new Date(now.getTime() + 14 * DAY).toISOString().slice(0, 10) : null,
      paid_at: null, days_overdue: null, project: { id: p.id, code: p.code, name: p.name }, pdf_url: null, created_at: now.toISOString(),
    }
    db.invoices.push(inv)
    p.invoices.push(inv)
    recalcProject(p)
    return { data: inv }
  }],

  ['post', /^\/invoices\/(\d+)\/send$/, ({ user, params }) => {
    if (!canSeeAll(user)) fail(403, 'You do not have permission to perform this action.')
    const inv = db.invoices.find((i) => i.id === Number(params[0])) ?? fail(404, 'Not found.')
    if (inv.status !== 'draft') fail(422, 'Only draft invoices can be issued.', { status: ['Only draft invoices can be issued.'] })
    const now = new Date()
    Object.assign(inv, { status: 'sent', status_label: 'Sent', issued_at: now.toISOString().slice(0, 10), due_at: new Date(now.getTime() + 14 * DAY).toISOString().slice(0, 10) })
    syncInvoice(inv)
    return { data: inv }
  }],

  ['post', /^\/invoices\/(\d+)\/mark-paid$/, ({ user, params, body }) => {
    if (!canSeeAll(user)) fail(403, 'You do not have permission to perform this action.')
    const inv = db.invoices.find((i) => i.id === Number(params[0])) ?? fail(404, 'Not found.')
    if (!['sent', 'overdue'].includes(inv.status)) fail(422, 'Only issued (sent or overdue) invoices can be marked as paid.', { status: ['Only issued invoices can be marked as paid.'] })
    Object.assign(inv, { status: 'paid', status_label: 'Paid', paid_at: body.paid_at || new Date().toISOString(), days_overdue: null })
    syncInvoice(inv)
    return { data: inv }
  }],

  ['get', /^\/team$/, ({ user }) => {
    if (!canSeeAll(user)) fail(403, 'You do not have permission to perform this action.')
    return { data: db.team.map((t) => ({ ...t, ...teamStats(t) })) }
  }],
  ['patch', /^\/team\/(\d+)$/, ({ user, params, body }) => {
    if (user.role !== 'admin') fail(403, 'Only admins can change team settings.')
    const t = db.team.find((x) => x.id === Number(params[0])) ?? fail(404, 'Not found.')
    if (body.regions?.some((r) => !/^\d{2}$/.test(r))) fail(422, 'Regions are 2-digit postcode prefixes, e.g. 10 or 14.', { regions: ['Regions are 2-digit postcode prefixes, e.g. 10 or 14.'] })
    if (body.is_active === false && t.id === user.id) fail(422, 'You cannot deactivate your own account.')
    if (body.regions) t.regions = [...new Set(body.regions)].sort()
    for (const k of ['project_types', 'max_open_leads', 'is_active', 'phone']) if (k in body) t[k] = body[k]
    const u = db.users.find((x) => x.id === t.id)
    if (u) Object.assign(u, { regions: t.regions, project_types: t.project_types, max_open_leads: t.max_open_leads, is_active: t.is_active })
    return { data: { ...t, ...teamStats(t) } }
  }],

  ['get', /^\/quotes$/, ({ user, query }) => {
    const ids = new Set(visibleLeads(user).map((l) => l.id))
    const statuses = list(query.status)
    const s = String(query.search || '').toLowerCase()
    const items = db.quotes
      .filter((q) => ids.has(q.lead.id) && (!statuses.length || statuses.includes(q.status)))
      .filter((q) => !s || [q.number, q.lead.contact_name, q.lead.company].some((v) => v && v.toLowerCase().includes(s)))
      .sort((a, b) => b.created_at.localeCompare(a.created_at))
    return paginate(items, query, '/api/quotes')
  }],
  ['get', /^\/quotes\/(\d+)$/, ({ params }) => ({ data: db.quotes.find((q) => q.id === Number(params[0])) ?? fail(404, 'Not found.') })],

  ['get', /^\/projects$/, ({ user, query }) => {
    if (!canSeeAll(user)) fail(403, 'You do not have permission to perform this action.')
    const statuses = list(query.status)
    return paginate(db.projects.filter((p) => !statuses.length || statuses.includes(p.status)).sort((a, b) => b.created_at.localeCompare(a.created_at)), query, '/api/projects')
  }],
  ['get', /^\/projects\/(\d+)$/, ({ params }) => ({ data: db.projects.find((p) => p.id === Number(params[0])) ?? fail(404, 'Not found.') })],

  ['get', /^\/invoices$/, ({ user, query }) => {
    if (!canSeeAll(user)) fail(403, 'You do not have permission to perform this action.')
    const statuses = list(query.status)
    const items = db.invoices.filter((i) => !statuses.length || statuses.includes(i.status))
      .sort((a, b) => (b.issued_at || '9999').localeCompare(a.issued_at || '9999'))
    const totals = {}
    db.invoices.forEach((i) => { totals[i.status] ??= { count: 0, total: 0 }; totals[i.status].count++; totals[i.status].total += i.total })
    const monthStart = new Date(); monthStart.setDate(1); monthStart.setHours(0, 0, 0, 0)
    const paidMonth = db.invoices.filter((i) => i.status === 'paid' && new Date(i.paid_at) >= monthStart)
    totals.paid_this_month = { count: paidMonth.length, total: paidMonth.reduce((s, i) => s + i.total, 0) }
    return { ...paginate(items, query, '/api/invoices'), summary: totals }
  }],

  ['get', /^\/settings\/scoring$/, ({ user }) => {
    if (user.role !== 'admin') fail(403, 'You do not have permission to perform this action.')
    return db.scoring_settings
  }],
  ['put', /^\/settings\/scoring$/, ({ user, body }) => {
    if (user.role !== 'admin') fail(403, 'You do not have permission to perform this action.')
    if (body.weights) {
      const sum = Object.values(body.weights).reduce((s, v) => s + Number(v), 0)
      if (sum !== 100) fail(422, `Weights must add up to 100 (currently ${sum}).`, { weights: [`Weights must add up to 100 (currently ${sum}).`] })
    }
    if (body.bands && Number(body.bands.hot) <= Number(body.bands.warm)) fail(422, 'The hot threshold must be higher than the warm threshold.', { 'bands.hot': ['The hot threshold must be higher than the warm threshold.'] })
    const s = db.scoring_settings
    Object.assign(s, {
      weights: body.weights ? Object.fromEntries(Object.entries(body.weights).map(([k, v]) => [k, Number(v)])) : s.weights,
      bands: body.bands ? { hot: Number(body.bands.hot), warm: Number(body.bands.warm) } : s.bands,
      ai_weight: body.ai_weight !== undefined ? Number(body.ai_weight) : s.ai_weight,
      ai_enabled: body.ai_enabled !== undefined ? !!body.ai_enabled : s.ai_enabled,
    })
    s.overridden = ['weights', 'bands', 'ai_weight'].filter((k) => JSON.stringify(s[k]) !== JSON.stringify(s.defaults[k]))
    return { ...s, rescore_queued: body.rescore ? s.open_leads : null }
  }],
  ['post', /^\/settings\/scoring\/reset$/, () => {
    const s = db.scoring_settings
    Object.assign(s, structuredClone(s.defaults), { overridden: [] })
    return { ...s, rescore_queued: null }
  }],
]

// ---------------------------------------------------------------------------
// Adapter
// ---------------------------------------------------------------------------
export async function mockAdapter(config) {
  const method = (config.method || 'get').toLowerCase()
  const base = (config.baseURL || '').replace(/\/$/, '')
  const url = new URL((config.url.startsWith('http') ? config.url : base + config.url).replace(/^\/api/, ''), 'http://demo.local')
  const path = url.pathname.replace(/^\/api/, '').replace(/\/$/, '') || '/'
  const query = { ...Object.fromEntries(url.searchParams), ...(config.params || {}) }
  const body = typeof config.data === 'string' ? JSON.parse(config.data || '{}') : (config.data || {})

  await sleep(method === 'get' ? 180 + Math.random() * 320 : 350 + Math.random() * 400)

  // Serialise like a real HTTP response: callers must never share objects with the in-memory db
  // (otherwise re-fetching returns the same reference and the UI can't see changes).
  const respond = (status, data) => ({ data: JSON.parse(JSON.stringify(data)), status, statusText: String(status), headers: { 'content-type': 'application/json' }, config, request: {} })

  try {
    const route = routes.find(([m, re]) => m === method && re.test(path))
    if (!route) fail(404, `Not available in demo mode (${method.toUpperCase()} ${path}).`)
    const user = userFromToken(config)
    if (!/^\/auth\/login$/.test(path) && !user) fail(401, 'Unauthenticated.')
    const params = path.match(route[1]).slice(1)
    const data = route[2]({ user, query, body, params })
    return respond(method === 'post' && /^\/leads$/.test(path) ? 201 : 200, data)
  } catch (e) {
    if (!(e instanceof HttpError)) throw e
    const response = respond(e.status, e.body)
    throw new AxiosError(e.body.message, e.status >= 500 ? AxiosError.ERR_BAD_RESPONSE : AxiosError.ERR_BAD_REQUEST, config, {}, response)
  }
}
