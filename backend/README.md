# BuildLead CRM — Backend API

Laravel 12 REST API (Sanctum token auth) for BuildLead CRM. See [`../PROJECT_SPEC.md`](../PROJECT_SPEC.md) for the full product spec.

## Requirements

- PHP 8.2+ with `pdo_mysql` (or `pdo_pgsql`), `mbstring`, `intl`, `gd`
- Composer 2
- MySQL 8 (Postgres 14+ and SQLite also work — migrations are portable)
- Redis (for queues in production; `QUEUE_CONNECTION=database` is fine locally). Uses `predis`, so no PHP extension is needed.

## Setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate

# Create the database, then set DB_* in .env
mysql -u root -p -e "CREATE DATABASE buildlead CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

php artisan migrate:fresh --seed   # schema + demo company data
php artisan serve --port=8020      # http://localhost:8020
php artisan queue:work --queue=scoring,notifications,default   # scoring + hot lead alerts
```

Optional `.env` values:

| Key | Purpose |
|---|---|
| `OPENAI_API_KEY` | Enables AI scoring. If empty, leads are scored with rules only. |
| `SCORING_AI_WEIGHT` | AI share of the final score (default `0.6`) |
| `SCORING_HOT_THRESHOLD` / `SCORING_WARM_THRESHOLD` | Temperature bands (default 75 / 50) |
| `LEAD_INGEST_TOKEN` | Shared secret for the public lead ingest endpoint |
| `SLACK_HOT_LEAD_WEBHOOK_URL` | Optional Slack alert for hot leads |

## Demo logins

All passwords are `password`.

| Role | Email |
|---|---|
| Admin | `admin@buildlead.test` (Katrin Hoffmann) |
| Manager | `manager@buildlead.test` (Thomas Becker) |
| Sales | `lukas.schneider@`, `anna.weber@`, `jonas.fischer@`, `sophie.wagner@`, `felix.krueger@`, `mia.schulz@buildlead.test` |

The demo seeder is deterministic (fixed Faker seed) and its dates are relative to today. It creates:

- 60 leads over the last 12 weeks: Berlin and Brandenburg postcodes, budgets €8k–€1.2M, English and German descriptions
- about 420 activities: the full timeline per lead, plus upcoming and overdue follow-ups
- 12 quotes (6 accepted, 5 sent, 1 declined)
- 6 projects (completed, in progress, on hold, planning)
- 15 invoices (paid, sent, overdue, draft)

## AI lead scoring (Phase 2)

```
ScoreLeadJob (queue "scoring", unique per lead, 3 tries, backoff 10s/60s)
 ├─ LeadScoringService::evaluate()     ← no DB writes, runs outside the transaction
 │    ├─ RuleBasedScorer               6 factors → points + human-readable note
 │    └─ OpenAiScorer                  gpt-4o-mini, JSON mode, 2 retries (429/5xx/timeouts), null on failure
 │    final = ai × 0.6 + rules × 0.4   (rules only when there's no key or the AI call fails)
 └─ DB transaction
      ├─ LeadScoringService::apply()   saves score, breakdown, summary; logs a "score" activity;
      │                                 fires HotLeadDetected when an open lead becomes hot
      └─ LeadAssignmentService         active sales reps under capacity → region+type match →
                                        hot: best win rate / others: least busy
HotLeadDetected (dispatched after commit, so the assignee is already set)
 └─ NotifyHotLead (queue "notifications") → assignee + managers/admins: mail + database,
                                            optional Slack webhook, "alert sent" activity
```

- **GDPR:** `OpenAiScorer::payload()` is a whitelist. Name, email, phone, street and the raw payload are never sent, and emails or phone numbers typed into the description are replaced with `[email]` / `[phone]`.
- **Admin overrides:** `ScoringSetting::put('weights.budget', 35)` stores a dot path into `config/scoring.php`. `ScoringSetting::config()` returns the merged config. It is cached and flushed on save or delete.
- **Rescoring:**

  ```bash
  php artisan leads:rescore                       # only leads that were never scored (queued)
  php artisan leads:rescore --all                 # every lead
  php artisan leads:rescore --lead=BL-2026-0012 --lead=7 --sync   # specific leads, run now
  php artisan leads:rescore --all --no-assign
  ```

- **Demo data:** the seeder runs every lead through the real `RuleBasedScorer` and the production `blend()`. It uses a simulated AI opinion, so no API key is needed and the breakdowns have the same shape as live ones.

## API

Full request examples are in [`../docs/postman/BuildLead.postman_collection.json`](../docs/postman/BuildLead.postman_collection.json): import it, run **Auth → Login (admin)**, then set `ingestToken`.

### Lead ingest webhook (public)

`POST /api/ingest/{website|email|call}` with the header `X-Ingest-Token: <LEAD_INGEST_TOKEN>`.

- **Rate limiting:** per IP (`INGEST_RATE_PER_MINUTE`, default 20). The limit applies before the token check, so the token can't be brute-forced.
- **Field names:** German or English are both accepted, as JSON or form-encoded. For example `name` or `vorname` + `nachname`, `E-Mail`, `telefon`, `plz`, `ort`, `nachricht`, `projektart`, `zeitraum` and `datenschutz`.
- **Normalization:**
  - Budgets: `150k`, `80.000-120.000 €`, `1,2 Mio`, `bis 50.000`, `ab 200k`.
  - Project types: `Neubau`, `Dachausbau`, `Büroausbau`, `Wasserschaden` …
  - Timelines: `sofort`, `in 1–3 Monaten`, `nur informieren` …
- **GDPR consent is required:** without it the request returns 422.
- **Spam:** a filled honeypot field `_hp` returns 202 and nothing is stored.
- **Duplicates:** the same email, or the same phone in any format, within 30 days is merged into the existing lead. Empty fields are filled in, a "Repeat enquiry" activity is logged, and the response is `200 {"status":"duplicate"}`.
- **New leads:** return `201 {"status":"created","reference":"BL-2026-0061"}` and queue `ScoreLeadJob`, which scores and auto-assigns the lead.
- **CORS:** any origin by default (`INGEST_ALLOWED_ORIGINS`). The authenticated API only allows the SPA origins (`CORS_ALLOWED_ORIGINS`, default `localhost:5173` and `5190`).
- **Example:** [`../docs/website-form.html`](../docs/website-form.html) is a German contact form for the demo company that posts to this webhook. Open it with `?token=<LEAD_INGEST_TOKEN>`.

### Authenticated endpoints (Bearer token)

| Method | Path | Notes |
|---|---|---|
| `POST` | `/api/auth/login` `{email, password, device_name?}` → `{token, user}` | throttled 10/min |
| `GET` | `/api/auth/me` · `POST /api/auth/logout` | |
| `GET` | `/api/leads` | Filters: `temperature`, `status`, `source`, `project_type` (comma lists), `owner=me\|unassigned\|{id}`, `search`, `created_from`/`created_to`. Sort: `sort=-score` (fields: score, created_at, updated_at, budget_max, estimated_value, contact_name, company, status, first_contacted_at). Paging: `per_page` (max 100). The response includes `summary` counts per temperature. |
| `POST` | `/api/leads` | Queues scoring. Sales reps own the leads they create; only admins and managers may set `assigned_to`. |
| `GET` | `/api/leads/{id}` | Adds `score_breakdown`, `activities`, `quotes` and `project` |
| `PATCH` | `/api/leads/{id}` | Re-scores only if a scoring input changed (`meta.rescoring`). A manual reassignment is logged. |
| `PATCH` | `/api/leads/{id}/status` `{status, position?, lost_reason?}` | Logs the status change and sets `first_contacted_at`. `position` orders the pipeline column. Lost requires a reason. |
| `POST` | `/api/leads/{id}/activities` `{type, title, body?, due_at?}` | `note`, `call`, `email`, `meeting`, `site_visit`. A logged contact counts as the first response. |
| `POST` | `/api/leads/{id}/rescore` | 202, queued |
| `DELETE` | `/api/leads/{id}` | Soft delete, managers and admins only |
| `GET` | `/api/pipeline` | One column per status with cards, `count` and `value`. Takes the lead filters plus `limit` (cards per column). |
| `GET` | `/api/dashboard` | KPIs, leads per week by temperature (12 weeks), leads by source, funnel, top hot leads, team leaderboard |
| `GET` | `/api/meta` | Options for selects: statuses, sources, types, timelines, sales reps, score bands |

### ERP (Phase 4)

| Method | Path | Who | Notes |
|---|---|---|---|
| `GET` / `POST` | `/api/quotes` | all, own leads only | Create: `{lead_id, items: [{description, quantity, unit: m²\|h\|pcs\|flat, unit_price}], vat_rate?: 0\|7\|19, valid_until?, notes?}`. Runs in one DB transaction; computes line totals, subtotal, VAT and total. |
| `GET` / `PATCH` | `/api/quotes/{id}` | | PATCH works on drafts only and replaces the line items |
| `POST` | `/api/quotes/{id}/send` `{to?, message?}` | | Status → sent. Lead → quote_sent (never moves backwards). Branded PDF emailed to the lead with Reply-To the sales rep. |
| `POST` | `/api/quotes/{id}/accept` | | Sent and not expired only. Lead → won, competing quotes declined, **project created** (budget = quote net, status planning, a manager assigned). |
| `POST` | `/api/quotes/{id}/decline` `{reason?}` | | |
| `GET` | `/api/quotes/{id}/pdf[?download=1]` | | |
| `GET` / `PATCH` | `/api/projects[/{id}]` | admin, manager | List with `invoiced`, `paid`, `outstanding` and `invoiceable_remaining`. Update status, progress, spent, dates and manager; completing a project sets progress to 100. |
| `POST` | `/api/projects/{id}/invoices` `{type, amount? \| percent?, send?, issued_at?}` | admin, manager | Deposit defaults to 30 %; a final invoice defaults to the remainder (one per project). **Blocked when total invoiced (net, including drafts) would exceed 110 % of the budget.** |
| `GET` | `/api/invoices[/{id}]` | admin, manager | Filters `status`, `project_id`, `type`. The list includes a `summary` per status. |
| `POST` | `/api/invoices/{id}/send` · `/mark-paid` | admin, manager | Send: draft → sent, due after 14 days. Mark paid: sent or overdue → paid. |
| `GET` | `/api/invoices/{id}/pdf` | admin, manager | |
| `GET` / `PUT` | `/api/settings/scoring` | admin | Weights must sum to 100, hot must be above warm, `ai_weight` between 0 and 1, plus `ai_enabled`. `rescore: true` re-scores all open leads (queued). Values equal to the defaults aren't stored. |
| `POST` | `/api/settings/scoring/reset` · `/rescore` | admin | |

- **Overdue detection:** `php artisan invoices:mark-overdue` runs daily at 06:00 Europe/Berlin. Run the scheduler locally with `php artisan schedule:work`, or in production with cron `* * * * * php artisan schedule:run`.
- **PDFs:** `resources/views/pdf/{layout,quote,invoice}.blade.php`, rendered with dompdf. DejaVu Sans covers € and umlauts, and fonts are subset, so a file is about 35 KB. The header and footer repeat on every page, with "Seite x / y" page numbers. Company details are in `config/company.php`.

**Permissions:** sales reps only see and change their own leads (`LeadPolicy` plus `Lead::visibleTo()`), and their dashboard shows only their own numbers, with no invoice or project figures. Deactivated users are rejected even if their token is still valid.

### Phase 1 auth reference


| Method | Path | Auth |
|---|---|---|
| `POST` | `/api/auth/login` `{email, password, device_name?}` → `{token, user}` | — (throttled 10/min) |
| `GET` | `/api/auth/me` | Bearer |
| `POST` | `/api/auth/logout` (revokes current token) | Bearer |

Send `Authorization: Bearer <token>`. Role-gate routes with `->middleware('role:admin,manager')`.

```bash
curl -X POST http://localhost:8020/api/auth/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"admin@buildlead.test","password":"password"}'
```

## Code map

| Path | What |
|---|---|
| `app/Enums` | `LeadStatus` (`label()`, `pipeline()`, `open()`), `LeadTemperature::fromScore()`, `UserRole`, and the enums for sources, types, timelines, quotes, projects and invoices |
| `app/Models` | `User`, `Lead` (scopes: `hot`, `open`, `visibleTo($user)`, `search($term)`), `LeadActivity`, `ScoringSetting`, `Quote`, `QuoteItem`, `Project`, `Invoice` |
| `app/Models/Concerns/HasReference.php` | Auto references `BL-` / `Q-` / `P-` / `INV-YYYY-0001`, sequential per year, including soft-deleted rows |
| `config/scoring.php` | Factor weights, temperature bands, budget bands, and lookups for type, location, urgency and source |
| `app/Services/Scoring` | `ScoreResult`, `RuleBasedScorer`, `OpenAiScorer`, `LeadScoringService` |
| `app/Services/Assignment/LeadAssignmentService.php` | Auto-assignment rules |
| `app/Jobs/ScoreLeadJob.php`, `app/Events/HotLeadDetected.php`, `app/Listeners/NotifyHotLead.php`, `app/Notifications/HotLeadNotification.php` | Queue + alerts |
| `app/Console/Commands/RescoreLeads.php` | `leads:rescore` |
| `app/Support/LeadNormalizer.php` | Budget, project-type, timeline and phone normalization for German and English input |
| `app/Http/Requests/IngestLeadRequest.php`, `app/Services/Leads/LeadIngestService.php` | Webhook parsing, validation and de-duplication |
| `app/Services/Leads/LeadFilters.php`, `LeadPipelineService.php` | List/board filters; status changes and column ordering |
| `app/Services/DashboardService.php` | All dashboard numbers |
| `app/Policies/LeadPolicy.php` | Lead permissions |
| `app/Services/Erp/QuoteService.php` | Quote lifecycle: draft → sent → accepted (→ project) / declined |
| `app/Services/Erp/InvoiceService.php` | Invoice amounts, 110 % budget cap, send, paid, overdue |
| `app/Services/Erp/PdfRenderer.php`, `resources/views/pdf/*` | Branded A4 PDFs |
| `app/Mail/QuoteMail.php` | Quote email with the PDF attached (queued) |
| `app/Http/Controllers/Api/ScoringSettingsController.php` | Admin scoring settings |
| `app/Models/ScoringSetting.php` | Admin overrides merged over config (cached) |
| `app/Http/Middleware/EnsureRole.php` | The `role` middleware alias |
| `database/seeders/DemoSeeder.php` | The demo company dataset |

## Tests

```bash
php artisan test    # PHPUnit, in-memory SQLite
./vendor/bin/pint   # code style
```
