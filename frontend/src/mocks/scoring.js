// JS port of App\Services\Scoring\RuleBasedScorer + config/scoring.php (demo mode only).

const BUDGET_BANDS = [[500_000, 1], [250_000, 0.9], [100_000, 0.75], [50_000, 0.6], [25_000, 0.4], [10_000, 0.25], [0, 0.1]]
const TYPES = { new_build: 1, commercial_fit_out: 1, extension: 0.8, renovation: 0.75, roofing: 0.6, interior: 0.5, repair: 0.3 }
const URGENCY = { asap: 1, within_3_months: 0.9, within_6_months: 0.65, this_year: 0.4, just_researching: 0.1 }
const SOURCES = { referral: 1, phone_call: 0.9, trade_fair: 0.75, website_form: 0.7, email: 0.6, portal: 0.4 }
const CORE = ['10', '12', '13', '14']
const SECONDARY = ['15', '16', '03', '04']
const eur = (n) => `€ ${new Intl.NumberFormat('de-DE').format(n)}`

export function ruleScore(lead, settings, labels) {
  const w = settings.weights
  const max = lead.budget_max ?? lead.budget_min
  const band = max ? BUDGET_BANDS.find(([min]) => max >= min) : null
  const prefix = (lead.postcode || '').slice(0, 2)
  const place = `${lead.postcode || ''} ${lead.city || ''}`.trim()
  const fields = { phone: lead.phone, email: lead.email, budget: lead.budget_max, postcode: lead.postcode, area: lead.area_sqm, description: (lead.description || '').trim().length >= 40 ? 'x' : null }
  const missing = Object.entries(fields).filter(([, v]) => v === null || v === undefined || v === '').map(([k]) => k)

  const f = {
    budget: band ? [band[1], `Budget ${lead.budget_min ? `${eur(lead.budget_min)}–` : ''}${eur(max)}`] : [0, 'No budget given'],
    project_type: lead.project_type ? [TYPES[lead.project_type] ?? 0, `${labels.project_types[lead.project_type]} — ${TYPES[lead.project_type] >= 0.9 ? 'high-value project type' : TYPES[lead.project_type] >= 0.6 ? 'good fit' : 'lower-value job type'}`] : [0, 'Project type unknown'],
    location: !prefix ? [0, 'No postcode given'] : CORE.includes(prefix) ? [1, `${place} — core service area`] : SECONDARY.includes(prefix) ? [0.6, `${place} — outer service area`] : [0, `${place} — outside service area`],
    urgency: lead.timeline ? [URGENCY[lead.timeline], `Timeline: ${labels.timelines[lead.timeline]}`] : [0, 'No timeline given'],
    source: [SOURCES[lead.source] ?? 0, `Source: ${labels.sources[lead.source]}`],
    completeness: [(6 - missing.length) / 6, missing.length ? `${6 - missing.length} of 6 key details (missing: ${missing.join(', ')})` : 'All key details provided'],
  }

  const factors = {}
  let total = 0
  for (const [key, weight] of Object.entries(w)) {
    const [fraction, note] = f[key]
    const points = Math.round(weight * fraction * 10) / 10
    total += points
    factors[key] = { points, max: weight, fraction: Math.round(fraction * 100) / 100, note }
  }
  return { score: Math.round(Math.min(100, total)), factors }
}

export function temperatureFor(score, bands) {
  return score >= bands.hot ? 'hot' : score >= bands.warm ? 'warm' : 'cold'
}
