# BuildLead CRM — Project Spec

Portfolio project. Monorepo:

```
buildlead-crm/
├── backend/    Laravel 11 API
├── frontend/   Vue 3 SPA
└── docs/       screenshots/, video/, diagrams/
```

---

## 1. Product

BuildLead CRM is an **AI lead-scoring CRM + light ERP for construction companies**
(demo company based in Berlin, Germany).

- Leads come from website forms, email and phone calls.
- Each lead is scored **0–100** by a hybrid engine (rules + OpenAI), labelled **Hot / Warm / Cold**,
  auto-assigned to the best sales rep, and hot leads trigger alerts.
- Sales move leads through a pipeline, send quotes, and won quotes become projects with invoices.

---

## 2. Stack

### Backend
- Laravel 11, PHP 8.2+ — **implemented on Laravel 12** (see Deviations)
- MySQL 8
- Redis queues
- Laravel Sanctum (token auth)
- OpenAI API via Laravel `Http` client (**no extra SDK**)
- dompdf for PDFs (quotes, invoices)
- PHPUnit tests

### Frontend
- Vue 3 (Composition API, `<script setup>`), Vite
- Tailwind CSS
- Pinia, Vue Router, Axios
- ApexCharts (`vue3-apexcharts`)
- `vuedraggable` (pipeline board)
- `@vueuse/core`
- `lucide-vue-next` icons
- Inter font (`@fontsource/inter`)

---

## 3. Roles

| Role      | Access                                              |
|-----------|-----------------------------------------------------|
| `admin`   | Everything + scoring settings                       |
| `manager` | All leads, projects, invoices                       |
| `sales`   | Only own leads, quotes                              |

---

## 4. Data model

### users
`name`, `email`, `password`, `role` (admin|manager|sales), `phone`,
`regions` (json — postcode prefixes), `project_types` (json), `max_open_leads`, `is_active`

### leads
| Field | Notes |
|---|---|
| `reference` | `BL-2026-0001` |
| `contact_name`, `company`, `email`, `phone` | |
| `source` | website_form \| email \| phone_call \| referral \| trade_fair \| portal |
| `project_type` | new_build \| renovation \| extension \| commercial_fit_out \| roofing \| interior \| repair |
| `description` | |
| `budget_min`, `budget_max` | |
| `timeline` | asap \| within_3_months \| within_6_months \| this_year \| just_researching |
| `street`, `postcode`, `city`, `area_sqm` | |
| `score`, `rule_score`, `ai_score` | 0–100 |
| `temperature` | hot \| warm \| cold |
| `score_breakdown` | json |
| `ai_summary`, `ai_next_action`, `scored_at` | |
| `status` | new \| contacted \| site_visit \| quote_sent \| negotiation \| won \| lost |
| `assigned_to`, `assigned_at`, `first_contacted_at` | |
| `estimated_value`, `lost_reason`, `position` | `position` = order within pipeline column |
| `consent_given`, `consent_at` | GDPR |
| `raw_payload` | original inbound payload |
| soft deletes | |

### lead_activities
`lead_id`, `user_id`, `type` (note|call|email|meeting|site_visit|status_change|score|assignment|quote|created),
`title`, `body`, `meta`, `due_at`

### scoring_settings
`key`, `value` (json) — admin overrides of `config/scoring.php`

### quotes
`number` (`Q-2026-0001`), `lead_id`, `created_by`, `status` (draft|sent|accepted|declined|expired),
`subtotal`, `vat_rate` (default 19), `vat_amount`, `total`, `valid_until`, `sent_at`, `accepted_at`, `notes`

### quote_items
`quote_id`, `description`, `quantity`, `unit` (m²|h|pcs|flat), `unit_price`, `line_total`, `sort`

### projects
`code` (`P-2026-0001`), `lead_id`, `quote_id`, `manager_id`, `name`, `site_address`,
`status` (planning|in_progress|on_hold|completed), `progress`, `budget`, `spent`, `start_date`, `due_date`

### invoices
`number` (`INV-2026-0001`), `project_id`, `type` (deposit|progress|final),
`status` (draft|sent|paid|overdue), `amount`, `vat_amount`, `total`, `issued_at`, `due_at`, `paid_at`

---

## 5. AI scoring

### Rule score (sums to 100)
| Factor | Weight | Notes |
|---|---|---|
| budget | 30 | |
| project_type | 20 | |
| location | 15 | core postcodes `10, 12, 13, 14` = full; `15, 16, 03, 04` = 60% |
| urgency (timeline) | 20 | |
| source | 10 | |
| completeness | 5 | |

### OpenAI score
- Model `gpt-4o-mini`, **JSON mode**.
- Prompt: read the lead like a head of sales.
- Returns `{ score, reasons[], summary, next_action, project_type }`.
- **Never send email or phone to OpenAI (GDPR).**

### Final score
- `final = ai * 0.6 + rules * 0.4` (AI weight configurable).
- **Hot ≥ 75**, **Warm ≥ 50**, else **Cold**.
- If no `OPENAI_API_KEY` or the call fails → use rules only.

### Pipeline
1. Scoring runs in a **queued job**.
2. Then **auto-assigns**: active sales reps under capacity (`max_open_leads`),
   preferring region + project type match.
   - Hot leads → rep with best win rate.
   - Others → least busy rep.
3. Hot leads fire `HotLeadDetected` event → mail + database notification + optional Slack webhook.

---

## 6. Design rules (frontend — must feel premium)

### Brand & tokens
- Brand **"BuildLead"** with a simple geometric logo mark.
- Accent amber `#F59E0B` (hover `#D97706`).
- Dark navy sidebar `#0B1220`.
- Content background `#F7F8FA`, cards white, border `#E5E7EB`.
- Radius **14px**, soft shadows, Inter font, generous spacing, **14px** base text.
- Tabular numbers for money.
- **Dark mode** toggle (remembered), elegant in both themes.

### Easy access
- **Cmd/Ctrl+K** command palette (search leads, jump to pages, actions).
- Global **"+ New lead"** button.
- Shortcuts: `N` new lead, `/` search, `G` then `D` / `L` / `P` → Dashboard / Leads / Pipeline.
- Breadcrumbs, collapsible sidebar.

### Polish
- Skeleton loaders, page transitions, toasts, hover states.
- Empty states with friendly illustrations (inline SVG).
- Score badges: **Hot** = red/orange with flame icon, **Warm** = amber, **Cold** = slate.
- Animated circular **ScoreRing**.

### Responsive
- Desktop, tablet, mobile (bottom nav on mobile).

### Formatting
- Money (German): `€ 125.000`
- Dates: `13 Nov 2026`

### Demo mode
- `VITE_DEMO_MODE=true` serves realistic mock data from `/src/mocks`
  so the UI works without the backend (used for screenshots and video).

---

## 7. Demo data

- Company **"BuildLead Bau GmbH"**, Berlin.
- **6 sales reps** with German names.
- **60 leads** with realistic German names/companies, Berlin & Brandenburg postcodes,
  mixed English/German descriptions, budgets **€8k–€1.2M**, spread over the **last 12 weeks**.

---

## 8. Deviations log

| Date | Decision | Why |
|---|---|---|
| 2026-11-13 | Laravel **12** instead of 11 | Composer blocks every Laravel 11.x release because of security advisories that are unpatched in 11.x. Laravel 12 has the same app skeleton (`bootstrap/app.php`, `install:api`) and still receives security fixes. |
| 2026-11-13 | `predis/predis` as the Redis client | Works without the `phpredis` PHP extension. |
| 2026-11-13 | Local dev runs on Postgres + database queue | Local MySQL credentials were unavailable. `.env.example` still targets MySQL 8 + Redis, migrations are portable, and tests run on SQLite. |
| 2026-11-13 | OpenAI payload is a whitelist that also excludes **contact name and street**, and redacts emails/phone numbers inside the description | Stricter than the spec, which only names email and phone. Personal data in free text would otherwise leak. |
| 2026-11-13 | `HotLeadDetected` is dispatched **after the DB commit** and the listener runs on the `notifications` queue | The alert is sent once the lead has been auto-assigned, so the rep is notified and named. Worker: `queue:work --queue=scoring,notifications,default`. |
| 2026-11-13 | Hot alerts fire only when an **open** lead *becomes* hot | Rescoring an already-hot or won/lost lead must not send alerts again. |
| 2026-11-13 | CORS allows `localhost:5173` **and** `5190` (`CORS_ALLOWED_ORIGINS`); the ingest webhook has its own origin list (`INGEST_ALLOWED_ORIGINS`, default `*`) | 5173 is taken locally by another project, so the SPA dev server will use 5190. Customer websites must be able to post to the webhook. |
| 2026-11-13 | Added `GET /api/meta` (select options + sales reps) and the `active` middleware (deactivated users are blocked even with a valid token) | Needed by the SPA; security. |
| 2026-11-13 | The demo seeder spreads leads over the last 12 **calendar** weeks (Monday-based) | Matches the dashboard chart window, so every demo lead appears in "leads per week". |
| 2026-11-13 | Projects and invoices are **admin and manager only**; quotes follow lead visibility | Matches §3 Roles ("sales: only own leads, quotes"). |
| 2026-11-13 | The 110 % cap counts **all** invoices of a project, drafts included; at most one final invoice per project; a final invoice defaults to the remainder | Drafts are committed billing intents; this stops "draft, draft, send" from slipping past the cap. |
| 2026-11-13 | Added `POST /quotes/{id}/decline`, `PATCH /quotes/{id}` (drafts only), `POST /invoices/{id}/send`, `POST /settings/scoring/reset` | Complete lifecycles the SPA will need. Accepting a quote declines the lead's other open quotes. |
| 2026-11-13 | Expired quotes (past `valid_until`) cannot be accepted | A new quote must be sent first. |
| 2026-11-13 | PDF labels are bilingual (German document titles, English line labels); company data lives in `config/company.php` | Demo company is German; the UI is English. |
| 2026-11-13 | Tailwind **3.4** (not 4) | The spec asks for `darkMode: 'class'` in a Tailwind config; v3 supports that directly. |
| 2026-11-13 | SPA dev port **5190** (CORS also allows 5173) | 5173 is taken locally by another project. |
| 2026-11-13 | Demo mode data is a snapshot of the **seeded backend's real API responses** (`php artisan demo:export-mocks`), date-shifted to "now" at load | Mocks always match the API exactly and use the same German demo company; no second data generator to maintain. |
| 2026-11-13 | Added `GET /api/notifications` and `POST /api/notifications/read` | The top-bar bell shows the hot-lead database notifications from Phase 2. |
| 2026-11-13 | Library versions resolved by npm: Vite 8, Vue Router 5, Pinia 4, lucide-vue-next 1.0, ApexCharts 8 (tree-shaken core + bar) | Latest stable releases; their APIs were checked before use. |
| 2026-11-14 | Added `POST /api/leads/bulk` (assign / status, all-or-nothing, per-lead permission checks); `/dashboard` gained `kpis.trends`, `recent_activity` and weekly `value`; lead cards include phone/email | Bulk actions, trend arrows, recent activity and the "call now" list. Assigning stays manager/admin only. |
| 2026-11-14 | The win-rate trend is reported in **percentage points** vs. the previous 45 days and only when each window has ≥ 3 closed leads | Avoids misleading swings from tiny samples. |
| 2026-11-14 | Demo seeder: arrival dates are evenly spaced quantiles with jitter (not pure random), and won/lost leads keep their real close date | Realistic weekly volume and trends; fixed a bug where accepting quotes stamped won leads with today's date. |
| 2026-11-14 | `/leads/:id` is the full detail page; clicking a row in the list opens the quick-view drawer, which links to it | Spec item 2 (SlideOver quick view) plus item 3 (detail page). |
| 2026-11-14 | Added `GET /api/team`, `PATCH /api/team/{user}` (admin), `stage_entered_at` on pipeline cards, `summary.paid_this_month` on `/invoices` | Team page, days-in-stage, "paid this month" card. Deactivating a rep revokes their tokens. |
| 2026-11-14 | Pipeline shows six columns (New → Won); **Lost is a floating drop target** that appears while dragging | Matches the spec's column list; Lost stays reachable without horizontal scrolling and still asks for a reason. |
| 2026-11-14 | Quotes are built on a dedicated page with a live preview that mirrors the dompdf template; the earlier quote modal was removed | One quote-building flow (spec item 2). |
| 2026-11-14 | **Settings is admin/manager only**, as Phase 8 asks; sales reps get theme and keyboard-shortcut help from the account menu and the `?` dialog instead | "Sales cannot see invoices/settings". |
| 2026-11-14 | Tablets (768–1023px) always use the icon sidebar rail; phones get a "More" sheet for pages that don't fit the bottom nav | Keeps about 700px of content width on tablets; Projects, Invoices and Settings stay reachable on phones. |
| 2026-11-14 | `--faint` text token darkened (light ≈3.9:1, dark ≈4.8:1) | slate-400 on white was 2.6:1. |
| 2026-11-14 | Vite `chunkSizeWarningLimit` raised to 750 kB | The only larger chunk is ApexCharts, already tree-shaken and lazy-loaded with the dashboard charts. |
| 2026-11-14 | Docker Compose adds `queue` and `scheduler` services from the same PHP image (on top of php, mysql, redis, node); MySQL is published on host port 3307 | One command starts a working stack including scoring jobs and the overdue check; 3307 avoids clashing with a local MySQL. |
| 2026-11-14 | Pipeline drag uses SortableJS **fallback mode** (a real lifted card follows the pointer) | Native HTML5 drag images are faint and missing in headless capture; fallback works the same on mouse and touch. |
| 2026-11-14 | Range sliders are custom-styled (light track, amber fill via `v-range`) instead of `accent-color` | Chrome drew a near-black track with the amber accent. |
| 2026-11-14 | The quote builder's starter line items are scaled to the lead's estimated value (or ~90 % of max budget) | Fixed template prices ranged from 2 % to 1,197 % of the customer's budget. |
| 2026-11-14 | Portfolio media is reproducible: `docs/media-src` (Playwright + ffmpeg) regenerates screenshots, cover, diagram, MP4 and GIF from demo mode | Media stays in sync with the UI. The video is captured via a CDP screencast at 1920×1080 for sharp text. |
